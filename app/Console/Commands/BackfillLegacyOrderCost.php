<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill do custo do fornecedor nos pedidos importados do legado.
 *
 * Problema original: 18.990 orders com legacy_id tinham supplier_total=0
 * e 15.846 order_items tinham supplier_unit_cost=0, pois o ImportLegacyOrdersJob
 * nao buscava as colunas de custo em pedidos_produtos.
 *
 * Fonte dos custos (legado):
 *   - legacy.pedidos_produtos.custo_dia      (primario, 18.325 itens com valor)
 *   - legacy.pedidos_produtos.custo_pago_dia (fallback,  18.268 itens com valor)
 *
 * Join: order_items.legacy_sku_pai_id = pedidos_produtos.id_sku_pai
 *       AND orders.legacy_id          = pedidos_produtos.id_pedido
 * (Validado com order_id=19212/legacy_id=1719637: custo_dia=16.12, custo_pago_dia=16.12)
 *
 * Uso:
 *   php artisan backfill:legacy-order-cost
 *   php artisan backfill:legacy-order-cost --dry-run          # sem persistir
 *   php artisan backfill:legacy-order-cost --chunk=500        # lotes menores
 */
class BackfillLegacyOrderCost extends Command
{
    protected $signature = 'backfill:legacy-order-cost
                            {--dry-run    : Simula sem persistir alteracoes}
                            {--chunk=200  : Tamanho do lote de pedidos por iteracao}';

    protected $description = 'Preenche supplier_total em orders e supplier_unit_cost/supplier_total_cost em order_items para pedidos importados do legado.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk  = max(1, (int) $this->option('chunk'));

        if ($dryRun) {
            $this->warn('[DRY-RUN] Nenhuma alteracao sera persistida.');
        }

        $this->info('Buscando orders com legacy_id sem custo...');

        // Todos os orders com legacy_id (custo zero OU null — backfill idempotente)
        $orderIds = DB::table('orders')
            ->whereNotNull('legacy_id')
            ->where(function ($q) {
                $q->whereNull('supplier_total')
                  ->orWhere('supplier_total', 0);
            })
            ->orderBy('id')
            ->pluck('legacy_id', 'id'); // [order_id => legacy_id]

        $total      = $orderIds->count();
        $updated    = 0;
        $skipped    = 0;
        $noData     = 0;

        $this->info("Total de orders a processar: {$total}");

        if ($total === 0) {
            $this->info('Nenhum pedido para backfill. Tudo ja populado.');
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        // Processar em chunks para nao explodir memoria
        foreach ($orderIds->chunk($chunk) as $chunkMap) {
            $legacyIds = $chunkMap->values()->all();
            $orderMap  = $chunkMap->flip(); // [legacy_id => order_id]

            // Buscar todos os itens legados do chunk de uma vez
            $legacyItems = DB::connection('legacy')
                ->table('pedidos_produtos')
                ->whereIn('id_pedido', $legacyIds)
                ->select([
                    'id_pedido',
                    'id_sku_pai',
                    'sku',
                    'qtd',
                    'custo_dia',
                    'custo_pago_dia',
                ])
                ->get()
                ->groupBy('id_pedido'); // Collection indexada por id_pedido

            foreach ($chunkMap as $orderId => $legacyId) {
                $items = $legacyItems->get($legacyId);

                if (!$items || $items->isEmpty()) {
                    $noData++;
                    $bar->advance();
                    continue;
                }

                // Calcular supplier_total do pedido
                $supplierTotal = 0.0;
                $itemCosts     = []; // [order_item_id => [unit_cost, total_cost]]

                // Buscar order_items deste pedido para cruzar com os itens legados
                $orderItems = DB::table('order_items')
                    ->where('order_id', $orderId)
                    ->select(['id', 'legacy_sku_pai_id', 'sku', 'quantity'])
                    ->get();

                foreach ($orderItems as $oi) {
                    // Match por legacy_sku_pai_id (mais confiavel) ou SKU (fallback)
                    $legacyItem = null;

                    if ($oi->legacy_sku_pai_id) {
                        $legacyItem = $items->firstWhere('id_sku_pai', $oi->legacy_sku_pai_id);
                    }
                    if (!$legacyItem && $oi->sku) {
                        $legacyItem = $items->firstWhere('sku', $oi->sku);
                    }

                    if (!$legacyItem) {
                        continue;
                    }

                    $unitCost = $this->resolveItemCost($legacyItem);
                    if ($unitCost <= 0) {
                        continue;
                    }

                    $qty        = max(1, (int) ($oi->quantity ?? 1));
                    $totalCost  = round($unitCost * $qty, 2);

                    $itemCosts[$oi->id] = [
                        'supplier_unit_cost'  => $unitCost,
                        'supplier_total_cost' => $totalCost,
                    ];

                    $supplierTotal += $totalCost;
                }

                if ($supplierTotal <= 0 && empty($itemCosts)) {
                    $noData++;
                    $bar->advance();
                    continue;
                }

                if (!$dryRun) {
                    DB::transaction(function () use ($orderId, $supplierTotal, $itemCosts) {
                        // Atualizar order.supplier_total
                        if ($supplierTotal > 0) {
                            DB::table('orders')
                                ->where('id', $orderId)
                                ->update([
                                    'supplier_total' => round($supplierTotal, 2),
                                    'updated_at'     => now(),
                                ]);
                        }

                        // Atualizar cada order_item com seu custo unitario e total
                        foreach ($itemCosts as $itemId => $costs) {
                            DB::table('order_items')
                                ->where('id', $itemId)
                                ->update(array_merge($costs, ['updated_at' => now()]));
                        }
                    });
                }

                $updated++;
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine(2);

        // Estagio 2: fallback para itens que nao tem custo no legado
        // mas o produto existe no catalogo local (products.cost por SKU ou legacy_sku_pai_id).
        // Cobre pedidos de fornecedores como D498/D773 que tem SKUs no catalogo.
        $this->info('Estagio 2: fallback via catalogo local (products.cost)...');

        $itemsStillNull = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereNull('oi.supplier_unit_cost')
            ->whereNotNull('o.legacy_id')
            ->select(['oi.id', 'oi.sku', 'oi.legacy_sku_pai_id', 'oi.quantity', 'o.id as order_id'])
            ->get();

        $stage2Updated = 0;
        foreach ($itemsStillNull as $oi) {
            $cost = null;

            // Prioridade: via legacy_sku_pai_id
            if ($oi->legacy_sku_pai_id) {
                $cost = DB::table('products')
                    ->where('legacy_sku_pai_id', $oi->legacy_sku_pai_id)
                    ->where('cost', '>', 0)
                    ->value('cost');
            }

            // Fallback: via SKU direto
            if (!$cost && $oi->sku) {
                $cost = DB::table('products')
                    ->where('sku', $oi->sku)
                    ->where('cost', '>', 0)
                    ->value('cost');
            }

            if (!$cost || (float) $cost <= 0) {
                continue;
            }

            $unitCost = (float) $cost;
            $qty      = max(1, (int) ($oi->quantity ?? 1));

            if (!$dryRun) {
                DB::table('order_items')
                    ->where('id', $oi->id)
                    ->update([
                        'supplier_unit_cost'  => $unitCost,
                        'supplier_total_cost' => round($unitCost * $qty, 2),
                        'updated_at'          => now(),
                    ]);
            }
            $stage2Updated++;
        }

        $this->info("Concluido:");
        $this->line("  Pedidos atualizados : {$updated}");
        $this->line("  Sem dados de custo  : {$noData}");
        $this->line("  Total processado    : {$total}");
        $this->line("  Fallback catalogo   : {$stage2Updated} itens");

        if ($dryRun) {
            $this->warn('[DRY-RUN] Nenhuma alteracao foi gravada.');
        }

        return self::SUCCESS;
    }

    /**
     * Resolve custo unitario do item legado.
     * Primario: custo_dia | Fallback: custo_pago_dia
     */
    private function resolveItemCost(object $li): float
    {
        $costDia     = (float) ($li->custo_dia ?? 0);
        $costPagoDia = (float) ($li->custo_pago_dia ?? 0);

        if ($costDia > 0) {
            return $costDia;
        }
        if ($costPagoDia > 0) {
            return $costPagoDia;
        }
        return 0.0;
    }
}
