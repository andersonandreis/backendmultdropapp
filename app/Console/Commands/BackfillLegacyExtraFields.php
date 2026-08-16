<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill dos 5 campos criticos pro fornecedor (Fase 1) + NF a partir
 * do legado:
 *   - customer_phone (extraido do JSON dados quando disponivel)
 *   - carrier_name (legado: pedidos.carrier_name + fallback JSON)
 *   - shipping_mode (legado: pedidos.tipo_entrega)
 *   - paid_at (legado: pedidos.curso_pago)
 *   - invoice_* (legado: id_nota_erp, serie_nota_erp, danfe_nota, xml_nota,
 *     data_nfe_auto, desc_status_nota)
 *
 * Idempotente: so atualiza onde o valor difere.
 *
 *   php artisan backfill:legacy-extra-fields
 *   php artisan backfill:legacy-extra-fields --client=10
 *   php artisan backfill:legacy-extra-fields --dry-run
 */
class BackfillLegacyExtraFields extends Command
{
    protected $signature = 'backfill:legacy-extra-fields
                            {--client= : Filtra por client_id especifico}
                            {--dry-run : So mostra o que mudaria}';

    protected $description = 'Backfill dos campos extras (customer_phone/carrier_name/shipping_mode/paid_at/invoice_*) a partir do legado';

    public function handle(): int
    {
        $dry      = (bool) $this->option('dry-run');
        $clientId = $this->option('client');

        $q = DB::table('orders')->whereNotNull('legacy_id');
        if ($clientId) {
            $q->where('client_id', (int) $clientId);
        }
        $orders = $q->get(['id', 'legacy_id', 'customer_phone', 'carrier_name', 'shipping_mode', 'paid_at', 'invoice_number', 'invoice_status', 'invoice_xml']);

        $this->info('Orders alvo: ' . $orders->count() . ($dry ? ' (DRY RUN)' : ''));

        $legIds = $orders->pluck('legacy_id')->all();
        $legacyMap = [];
        foreach (array_chunk($legIds, 2000) as $chunk) {
            $rows = DB::connection('legacy')->table('pedidos')->whereIn('id', $chunk)
                ->select([
                    'id', 'id_canal', 'curso_pago', 'tipo_entrega', 'carrier_name',
                    'id_nota_erp', 'serie_nota_erp', 'danfe_nota', 'xml_nota',
                    'data_nfe_auto', 'desc_status_nota', 'dados',
                ])->get();
            foreach ($rows as $r) {
                $legacyMap[$r->id] = $r;
            }
        }

        $stats = [
            'customer_phone' => 0,
            'carrier_name'   => 0,
            'shipping_mode'  => 0,
            'paid_at'        => 0,
            'invoice_*'      => 0,
            'sem_dado'       => 0,
        ];

        $bar = $this->output->createProgressBar($orders->count());
        $bar->start();

        foreach ($orders as $o) {
            $lp = $legacyMap[$o->legacy_id] ?? null;
            if (!$lp) {
                $stats['sem_dado']++;
                $bar->advance();
                continue;
            }

            $updates = [];

            // customer_phone
            $phone = $this->extractPhone($lp);
            if ($phone && $o->customer_phone !== $phone) {
                $updates['customer_phone'] = $phone;
                $stats['customer_phone']++;
            }

            // carrier_name (coluna + fallback JSON)
            $carrier = $lp->carrier_name ?: $this->extractCarrier($lp);
            if ($carrier && $o->carrier_name !== $carrier) {
                $updates['carrier_name'] = $carrier;
                $stats['carrier_name']++;
            }

            // shipping_mode
            if ($lp->tipo_entrega && $o->shipping_mode !== $lp->tipo_entrega) {
                $updates['shipping_mode'] = $lp->tipo_entrega;
                $stats['shipping_mode']++;
            }

            // paid_at (so se vazio — nao sobrescreve se ja tem)
            if ($lp->curso_pago && !$o->paid_at) {
                $updates['paid_at'] = $lp->curso_pago;
                $stats['paid_at']++;
            }

            // invoice_*
            if ($lp->id_nota_erp && $o->invoice_number !== $lp->id_nota_erp) {
                $updates['invoice_number']     = $lp->id_nota_erp;
                $updates['invoice_series']     = $lp->serie_nota_erp ?: null;
                $updates['invoice_access_key'] = $lp->danfe_nota ?: null;
                $updates['invoice_issued_at']  = $lp->data_nfe_auto ?: null;
                $updates['invoice_status']     = $lp->desc_status_nota ?: null;
                if ($lp->xml_nota) {
                    $updates['invoice_xml'] = $lp->xml_nota;
                }
                $stats['invoice_*']++;
            }

            if (!$dry && !empty($updates)) {
                DB::table('orders')->where('id', $o->id)->update($updates);
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(['Campo', 'Atualizados'], collect($stats)->map(fn($v, $k) => [$k, $v])->values());

        return 0;
    }

    private function extractPhone(object $lp): ?string
    {
        if (empty($lp->dados)) return null;
        $d = json_decode($lp->dados, true);
        if (!is_array($d)) return null;

        if (($lp->id_canal ?? null) == 3) {
            return $d['orders'][0]['recipient_address']['phone'] ?? null;
        }
        if (($lp->id_canal ?? null) == 6) {
            $ac  = $d['buyer']['phone']['area_code'] ?? null;
            $num = $d['buyer']['phone']['number'] ?? null;
            if ($ac && $num) return $ac . $num;
            return $d['buyer']['phone']['raw'] ?? null;
        }
        return null;
    }

    private function extractCarrier(object $lp): ?string
    {
        if (empty($lp->dados)) return null;
        $d = json_decode($lp->dados, true);
        if (!is_array($d)) return null;

        if (($lp->id_canal ?? null) == 3) {
            return $d['orders'][0]['package_list'][0]['shipping_carrier'] ?? $d['orders'][0]['shipping_carrier'] ?? null;
        }
        if (($lp->id_canal ?? null) == 6) {
            return $d['shipping']['carrier_name'] ?? $d['shipping']['mode'] ?? null;
        }
        return null;
    }
}
