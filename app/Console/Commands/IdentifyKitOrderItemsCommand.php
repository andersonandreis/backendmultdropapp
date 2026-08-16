<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * MUL-145: Identifica order_items cujo SKU corresponde a um kit do legado
 * importado em product_bundles. Preenche order_items.legacy_kit_id com o ID
 * do kit (product_bundles.legacy_kit_id).
 *
 * SEGURO: somente UPDATE em legacy_kit_id. Nao altera estoque, status, financeiro.
 * Idempotente: pode rodar N vezes sem duplicar dados.
 */
class IdentifyKitOrderItemsCommand extends Command
{
    protected $signature = 'kits:identify-orders
        {--supplier=1 : Supplier ID (padrao MultDrop = 1)}
        {--dry-run    : Mostra o que seria atualizado sem persistir}';

    protected $description = 'MUL-145: Vincula order_items a kits do legado via SKU (somente identificacao, sem impacto em estoque/status)';

    public function handle(): int
    {
        $supplierId = (int) $this->option('supplier');
        $dryRun     = (bool) $this->option('dry-run');

        $this->info('=== MUL-145: IDENTIFICACAO DE PEDIDOS COM KITS ===');
        $this->info("Supplier: {$supplierId}" . ($dryRun ? ' | DRY-RUN' : ''));

        // Buscar todos os kits do supplier com SKU definido
        $bundles = DB::table('product_bundles')
            ->where('supplier_id', $supplierId)
            ->whereNotNull('legacy_kit_id')
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->select('legacy_kit_id', 'sku')
            ->distinct()
            ->get();

        $this->info("Kits disponíveis em product_bundles com SKU: " . $bundles->count());

        if ($bundles->isEmpty()) {
            $this->warn('Nenhum kit encontrado. Execute bundles:import-legacy primeiro.');
            return Command::FAILURE;
        }

        // Construir mapa sku => legacy_kit_id
        $skuToKitId = [];
        foreach ($bundles as $b) {
            $skuToKitId[$b->sku] = $b->legacy_kit_id;
        }

        $kitSkus = array_keys($skuToKitId);
        $this->info("SKUs únicos de kits: " . count($kitSkus));

        // Buscar order_items cujo SKU bate com kit
        $allItems = collect();
        foreach (array_chunk($kitSkus, 200) as $chunk) {
            $items = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereIn('order_items.sku', $chunk)
                ->where('orders.supplier_id', $supplierId)
                ->select(
                    'order_items.id as item_id',
                    'order_items.order_id',
                    'order_items.sku',
                    'order_items.legacy_kit_id as current_kit_id',
                    'orders.client_id'
                )
                ->get();
            $allItems = $allItems->merge($items);
        }

        $this->info("order_items com SKU de kit: " . $allItems->count());

        if ($allItems->isEmpty()) {
            $this->info('Nenhum pedido afetado encontrado.');
            return Command::SUCCESS;
        }

        $updated = 0;
        $alreadySet = 0;
        $orderIds = [];

        foreach ($allItems as $item) {
            $kitId = $skuToKitId[$item->sku] ?? null;
            if (! $kitId) {
                continue;
            }

            if ((int) $item->current_kit_id === (int) $kitId) {
                $alreadySet++;
                continue;
            }

            if ($dryRun) {
                $this->line("[DRY] item_id={$item->item_id} order_id={$item->order_id} sku={$item->sku} legacy_kit_id={$kitId}");
            } else {
                DB::table('order_items')
                    ->where('id', $item->item_id)
                    ->update(['legacy_kit_id' => $kitId]);
            }

            $updated++;
            $orderIds[$item->order_id] = true;
        }

        $this->newLine();
        $this->info('=== RESULTADO ===');
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['order_items com SKU de kit',                        $allItems->count()],
                ['Ja com legacy_kit_id correto',                       $alreadySet],
                ['Atualizados' . ($dryRun ? ' (DRY-RUN)' : ''),       $updated],
                ['Pedidos distintos afetados',                          count($orderIds)],
            ]
        );

        // Detalhamento por cliente
        $byClient = $allItems->groupBy('client_id');
        $this->info('Por cliente:');
        foreach ($byClient as $clientId => $clientItems) {
            $this->line("  client_id={$clientId}: " . $clientItems->count() . " items em " . $clientItems->pluck('order_id')->unique()->count() . " pedidos");
        }

        return Command::SUCCESS;
    }
}
