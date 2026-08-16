<?php

namespace App\Console\Commands;

use App\Jobs\FanoutOrderWebhookJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * MUL-310: reenvia order.created para pedidos que existem no hub e nao existem na WL.
 *
 * Nasceu da investigacao da MUL-304: 298 pedidos do supplier 30 nunca chegaram ao MultDrop
 * porque quem tirou o pedido do rascunho nao emitiu o evento (EnrichDraftOrdersCommand) ou
 * porque o receptor recusava a casca com HTTP 200 (guard MUL-181).
 *
 * NAO rodar antes da correcao do receptor estar no ar — sem ela o reenvio vira recusa nova.
 *
 * Uso:
 *   php artisan orders:reconcile-mirror --supplier=30 --connection=multdrop --dry-run
 *   php artisan orders:reconcile-mirror --supplier=30 --connection=multdrop
 */
class ReconcileMirrorCommand extends Command
{
    protected $signature = 'orders:reconcile-mirror
        {--supplier= : supplier_id no hub (obrigatorio)}
        {--connection= : nome da conexao da WL em config/database.php (obrigatorio)}
        {--desde= : so pedidos criados a partir desta data (Y-m-d)}
        {--limite=0 : maximo de pedidos por execucao, 0 = sem limite}
        {--sem-contas= : ids de marketplace_accounts a EXCLUIR, separados por virgula}
        {--dry-run : so mostra o que faria}';

    protected $description = 'MUL-310: reenvia order.created dos pedidos do hub que faltam na WL';

    public function handle(): int
    {
        $supplier   = (int) $this->option('supplier');
        $conexao    = (string) $this->option('connection');
        $desde      = $this->option('desde');
        $limite     = (int) $this->option('limite');
        $dryRun     = (bool) $this->option('dry-run');

        if (! $supplier || ! $conexao) {
            $this->error('--supplier e --connection sao obrigatorios.');
            return self::FAILURE;
        }

        try {
            DB::connection($conexao)->getPdo();
        } catch (\Throwable $e) {
            $this->error("Conexao '{$conexao}' inacessivel: " . $e->getMessage());
            return self::FAILURE;
        }

        $q = DB::table('orders')->where('supplier_id', $supplier);
        if ($desde) {
            $q->where('created_at', '>=', $desde);
        }
        $semContas = array_filter(array_map('intval', explode(',', (string) $this->option('sem-contas'))));
        if ($semContas) {
            $q->where(function ($w) use ($semContas) {
                $w->whereNull('marketplace_account_id')->orWhereNotIn('marketplace_account_id', $semContas);
            });
            $this->line('excluindo contas: ' . implode(', ', $semContas));
        }
        $idsHub = $q->orderBy('id')->pluck('id')->all();
        $this->info('hub supplier ' . $supplier . ': ' . count($idsHub) . ' pedidos');

        // ids que a WL ja conhece
        $conhecidos = [];
        foreach (array_chunk($idsHub, 2000) as $lote) {
            foreach (DB::connection($conexao)->table('orders')
                ->whereIn('hubai_order_id', $lote)->pluck('hubai_order_id') as $h) {
                $conhecidos[(int) $h] = true;
            }
        }

        $faltando = array_values(array_filter($idsHub, static fn ($id) => ! isset($conhecidos[(int) $id])));
        $this->info('presentes na WL: ' . count($conhecidos));
        $this->warn('FALTANDO na WL: ' . count($faltando));

        if (! $faltando) {
            return self::SUCCESS;
        }

        if ($limite > 0) {
            $faltando = array_slice($faltando, 0, $limite);
            $this->line('limitado a ' . count($faltando) . ' nesta execucao');
        }

        // so reenvia o que tem substancia — casca sem item e sem total nao ajuda ninguem
        $comSubstancia = [];
        $semSubstancia = 0;
        foreach (array_chunk($faltando, 500) as $lote) {
            foreach (DB::table('orders as o')
                ->whereIn('o.id', $lote)
                ->selectRaw('o.id, COALESCE(o.total,0) total, (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) itens')
                ->get() as $r) {
                if ((float) $r->total > 0 || (int) $r->itens > 0) {
                    $comSubstancia[] = (int) $r->id;
                } else {
                    $semSubstancia++;
                }
            }
        }

        $this->line('com itens ou total > 0: ' . count($comSubstancia));
        $this->line('ainda sem substancia (nao reenviados): ' . $semSubstancia);

        if ($dryRun) {
            $this->comment('DRY-RUN — nada despachado.');
            $this->line('primeiros 10: ' . implode(', ', array_slice($comSubstancia, 0, 10)));
            return self::SUCCESS;
        }

        $n = 0;
        foreach ($comSubstancia as $id) {
            FanoutOrderWebhookJob::dispatch($id, 'order.created', ['origem' => 'reconcile-mirror'])
                ->delay(now()->addSeconds(5 + ($n % 60)));
            $n++;
        }
        $this->info("despachados: {$n}");

        return self::SUCCESS;
    }
}
