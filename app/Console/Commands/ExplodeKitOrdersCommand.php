<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\KitExplosionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * MUL-147-C: Explode retroativamente os pedidos ABERTOS afetados (146 order_items
 * com legacy_kit_id preenchido, que ainda nao foram shipped/delivered/cancelled).
 *
 * Regras:
 *  - Apenas pedidos com status nao-fechado (nao shipped, delivered, cancelled)
 *  - Apenas se o lojista do pedido tiver o kit convertido em client_kits (ATIVO)
 *  - Idempotente: ignora order_items que ja sao is_kit_component=true
 *  - Nao toca em financeiro/estoque retroativo
 */
class ExplodeKitOrdersCommand extends Command
{
    protected $signature = 'kits:explode-open-orders
        {--supplier=1 : Supplier ID (padrao MultDrop = 1)}
        {--dry-run    : Simula sem persistir}
        {--order=     : Processar apenas um order_id especifico}
        {--include-closed : Inclui pedidos shipped/delivered/cancelled/refunded}';

    protected $description = 'MUL-147-C: Explode pedidos abertos com SKU de kit nos seus componentes';

    private const CLOSED_STATUSES = ['shipped', 'delivered', 'cancelled', 'refunded'];

    public function handle(KitExplosionService $explosionService): int
    {
        $supplierId = (int) $this->option('supplier');
        $dryRun     = (bool) $this->option('dry-run');
        $onlyOrder  = $this->option('order') ? (int) $this->option('order') : null;

        $this->info('=== MUL-147-C: EXPLOSAO RETROATIVA DE PEDIDOS ABERTOS ===');
        if ($dryRun) {
            $this->warn('[DRY-RUN] Nenhuma alteracao persistida.');
        }

        // Buscar order_ids afetados (com legacy_kit_id preenchido) que nao sao kit_component
        $query = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.supplier_id', $supplierId)
            ->whereIn("order_items.sku", fn($q) => $q->select("sku")->from("client_kits")->whereColumn("client_kits.client_id", "orders.client_id")->where("is_active", true))
            ->where('order_items.is_kit_component', false)
            ->when(!$this->option("include-closed"), fn($q) => $q->whereNotIn("orders.status", self::CLOSED_STATUSES))
            ->select('orders.id as order_id', 'orders.client_id', 'orders.status')
            ->distinct();

        if ($onlyOrder) {
            $query->where('orders.id', $onlyOrder);
        }

        $openOrders = $query->get();

        $this->info('Pedidos abertos com itens de kit: ' . $openOrders->count());

        $explodedOrders = 0;
        $explodedItems  = 0;
        $skippedNoKit   = 0;
        $errors         = [];

        foreach ($openOrders as $row) {
            $order = Order::withoutGlobalScopes()->find($row->order_id);
            if (! $order) {
                continue;
            }

            $result = $explosionService->explodeOrder($order, $dryRun);

            if ($result['exploded_items'] > 0) {
                $explodedOrders++;
                $explodedItems += $result['exploded_items'];
                $this->line(
                    '  [OK] order_id=' . $order->id
                    . ' client_id=' . $order->client_id
                    . ' status=' . $order->status
                    . ' → ' . $result['exploded_items'] . ' componentes'
                    . ($dryRun ? ' [DRY]' : '')
                );
            } elseif (! empty($result['errors'])) {
                foreach ($result['errors'] as $e) {
                    $errors[] = $e;
                    $this->warn('  [ERR] ' . $e);
                }
            } else {
                $skippedNoKit++;
                $this->line('  [SKIP] order_id=' . $order->id . ' — kit nao encontrado em client_kits (cliente sem kit convertido?)');
            }
        }

        // Pedidos fechados: apenas contar para o relatorio
        $closedAffected = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.supplier_id', $supplierId)
            ->whereIn("order_items.sku", fn($q) => $q->select("sku")->from("client_kits")->whereColumn("client_kits.client_id", "orders.client_id")->where("is_active", true))
            ->where('order_items.is_kit_component', false)
            ->whereIn('orders.status', self::CLOSED_STATUSES)
            ->distinct()
            ->count('orders.id');

        $this->newLine();
        $this->info('=== RESULTADO ===');
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['Pedidos abertos processados',           $openOrders->count()],
                ['Pedidos explodidos (com componentes)',  $explodedOrders],
                ['Componentes gerados',                   $explodedItems],
                ['Pedidos sem kit convertido (skip)',     $skippedNoKit],
                ['Erros',                                 count($errors)],
                ['Pedidos fechados (identificados, nao tocados)', $closedAffected],
            ]
        );

        if (! empty($errors)) {
            $this->error('Erros encontrados:');
            foreach ($errors as $e) {
                $this->line('  ' . $e);
            }
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
