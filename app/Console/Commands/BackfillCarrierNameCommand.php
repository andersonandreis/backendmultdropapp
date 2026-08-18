<?php

namespace App\Console\Commands;

use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Services\Integrations\Marketplaces\ShopeeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * MUL-379 — preenche a transportadora dos pedidos Shopee prontos pra separar.
 *
 * Por que existe: o ShopeeOrderEnricher so roda em pedido RASCUNHO
 * (`if (! $order->is_draft) return 'not_draft'`). A Shopee so define a logistica
 * depois, e a essa altura o pedido ja foi promovido — entao ninguem busca de novo
 * e carrier_name fica nulo pra sempre. Medido em 17/08/2026: 59 dos 83 pedidos
 * Shopee na fila de separacao estavam sem transportadora, e o separador nao tinha
 * como agrupar os volumes por transportadora.
 *
 * O que faz: pega os pedidos da fila (Order::readyToShip) sem carrier_name, chama
 * get_order_detail em LOTE (a API aceita varios order_sn por chamada) e preenche.
 * Nunca sobrescreve valor existente — mesma regra do enricher.
 *
 * Uso:
 *   php artisan orders:backfill-carrier --dry-run
 *   php artisan orders:backfill-carrier --limit=200 --dias=30
 */
class BackfillCarrierNameCommand extends Command
{
    protected $signature = 'orders:backfill-carrier
                            {--limit=200 : maximo de pedidos por execucao}
                            {--dias=45 : so pedidos pagos nos ultimos N dias}
                            {--dry-run : nao grava, so mostra}';

    protected $description = 'MUL-379: preenche carrier_name/tracking dos pedidos Shopee da fila de separacao que ficaram sem transportadora';

    /** Shopee aceita varios order_sn por chamada; 40 e folgado dentro do limite. */
    private const LOTE = 40;

    public function handle(ShopeeService $shopee): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit  = max(1, (int) $this->option('limit'));
        $dias   = max(1, (int) $this->option('dias'));

        $pedidos = Order::query()
            ->readyToShip()
            ->where('orders.source', 'shopee')
            ->whereNotNull('orders.marketplace_order_id')
            ->where('orders.marketplace_order_id', '<>', '')
            ->where(function ($w) {
                $w->whereNull('orders.carrier_name')->orWhere('orders.carrier_name', '');
            })
            ->where('orders.paid_at', '>=', now()->subDays($dias))
            ->orderByDesc('orders.id')
            ->limit($limit)
            ->get();

        $this->info(sprintf(
            '[MUL-379] %s — %d pedido(s) sem transportadora na fila (ultimos %d dias)',
            $dryRun ? 'DRY-RUN' : 'APLICANDO',
            $pedidos->count(),
            $dias
        ));

        if ($pedidos->isEmpty()) {
            return self::SUCCESS;
        }

        // Agrupa por conta: uma chamada serve varios pedidos da mesma loja.
        $porConta = [];
        $semConta = 0;
        foreach ($pedidos as $pedido) {
            $conta = $this->resolverConta($pedido);
            if (! $conta) {
                $semConta++;
                continue;
            }
            $porConta[$conta->id]['conta'] = $conta;
            $porConta[$conta->id]['pedidos'][] = $pedido;
        }

        if ($semConta > 0) {
            $this->warn("  {$semConta} pedido(s) sem conta Shopee resolvivel — ficam de fora");
        }

        $preenchidos = 0;
        $semRetorno  = 0;
        $falhas      = 0;

        foreach ($porConta as $grupo) {
            /** @var MarketplaceAccount $conta */
            $conta = $grupo['conta'];
            $lista = $grupo['pedidos'];

            $token = null;
            try {
                $token = $shopee->getValidAccessToken($conta);
            } catch (\Throwable $e) {
                Log::channel('marketplace')->warning('[MUL-379] token indisponivel', [
                    'account_id' => $conta->id,
                    'error'      => $e->getMessage(),
                ]);
            }
            if (! $token) {
                $this->warn("  conta {$conta->id}: sem token — " . count($lista) . ' pedido(s) adiados');
                $falhas += count($lista);
                continue;
            }

            foreach (array_chunk($lista, self::LOTE) as $lote) {
                $sns = array_map(fn ($o) => (string) $o->marketplace_order_id, $lote);

                $resp = $shopee->getOrderDetail((int) $conta->shop_id, (string) $token, $sns);
                $erro = (string) ($resp['error'] ?? '');
                if ($erro !== '') {
                    Log::channel('marketplace')->warning('[MUL-379] get_order_detail com erro', [
                        'account_id' => $conta->id,
                        'error'      => $erro,
                        'pedidos'    => count($lote),
                    ]);
                    $this->warn("  conta {$conta->id}: API respondeu '{$erro}' — " . count($lote) . ' pedido(s) adiados');
                    $falhas += count($lote);
                    sleep(1);
                    continue;
                }

                $detalhes = [];
                foreach (($resp['response']['order_list'] ?? []) as $d) {
                    if (! empty($d['order_sn'])) {
                        $detalhes[(string) $d['order_sn']] = $d;
                    }
                }

                foreach ($lote as $pedido) {
                    $d = $detalhes[(string) $pedido->marketplace_order_id] ?? null;
                    if (! $d) {
                        $semRetorno++;
                        continue;
                    }

                    $carrier = $d['package_list'][0]['shipping_carrier'] ?? $d['shipping_carrier'] ?? null;
                    if (! $carrier) {
                        $semRetorno++;
                        continue;
                    }

                    $updates = ['carrier_name' => $carrier];
                    // aproveita a mesma resposta para fechar o rastreio, se faltar
                    if (empty($pedido->tracking_number) && ! empty($d['tracking_no'])) {
                        $updates['tracking_number'] = $d['tracking_no'];
                    }

                    $this->line(sprintf(
                        '  #%s → %s%s',
                        $pedido->order_number,
                        $carrier,
                        isset($updates['tracking_number']) ? ' (+rastreio)' : ''
                    ));

                    if (! $dryRun) {
                        $pedido->forceFill($updates)->saveQuietly();
                    }
                    $preenchidos++;
                }

                sleep(1); // MUL-373: sob rate limit a Shopee mente — nao apressar
            }
        }

        $this->info(sprintf(
            '[MUL-379] preenchidos=%d · sem transportadora na API=%d · adiados=%d%s',
            $preenchidos,
            $semRetorno,
            $falhas,
            $dryRun ? ' (DRY-RUN: nada gravado)' : ''
        ));

        if (! $dryRun && $preenchidos > 0) {
            Log::info('[MUL-379] transportadoras preenchidas', [
                'preenchidos' => $preenchidos,
                'sem_retorno' => $semRetorno,
                'adiados'     => $falhas,
            ]);
        }

        return self::SUCCESS;
    }

    /** Conta pelo vinculo direto; se faltar, pela loja (shop_id) do proprio pedido. */
    private function resolverConta(Order $pedido): ?MarketplaceAccount
    {
        if ($pedido->marketplace_account_id) {
            $conta = MarketplaceAccount::find($pedido->marketplace_account_id);
            if ($conta && $conta->platform === 'shopee' && $conta->shop_id) {
                return $conta;
            }
        }

        if ($pedido->shop_id) {
            return MarketplaceAccount::where('platform', 'shopee')
                ->where('shop_id', $pedido->shop_id)
                ->first();
        }

        return null;
    }
}
