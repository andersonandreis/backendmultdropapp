<?php

namespace App\Services;

use App\Models\ClientKit;
use App\Models\ClientKitItem;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class KitExplosionService
{
    public function explodeOrder(Order $order, bool $dryRun = false): array
    {
        $result = [
            'exploded_items'           => 0,
            'skipped_no_kit'           => 0,
            'skipped_already_exploded' => 0,
            'errors'                   => [],
        ];

        $items = OrderItem::where('order_id', $order->id)->get();
        if ($items->isEmpty()) {
            return $result;
        }

        // MUL-422b: pedido com troca manual de SKU e curadoria humana — a explosao
        // deletava o componente trocado (MUL-243 idempotente) e o recriava do cadastro
        // do kit, desfazendo a decisao do painel a cada fanout. Troca manual vence.
        $temTrocaManual = \Illuminate\Support\Facades\DB::table('order_events')
            ->where('order_id', $order->id)
            ->where('event_type', 'item_product_swapped')
            ->exists();
        if ($temTrocaManual) {
            $result['skipped_manual_swap'] = $items->count();
            return $result;
        }

        $clientKits = ClientKit::where('client_id', $order->client_id)
            ->where('is_active', true)
            ->with('items.clientProduct.product')
            ->get()
            ->keyBy('sku');

        if ($clientKits->isEmpty()) {
            $result['skipped_no_kit'] = $items->count();
            return $result;
        }

        foreach ($items as $item) {
            if ($item->is_kit_component) {
                $result['skipped_already_exploded']++;
                continue;
            }

            $kit = $clientKits->get($item->sku);
            if (! $kit) {
                $result['skipped_no_kit']++;
                continue;
            }

            try {
                $exploded = $this->explodeItem($order, $item, $kit, $dryRun);
                if ($exploded > 0) {
                    $result['exploded_items'] += $exploded;
                }
            } catch (\Throwable $e) {
                $msg = "order_id={$order->id} item_id={$item->id} sku={$item->sku}: " . $e->getMessage();
                $result['errors'][] = $msg;
                Log::error('[KitExplosionService] Erro ao explodir item', [
                    'order_id' => $order->id,
                    'item_id'  => $item->id,
                    'sku'      => $item->sku,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    private function explodeItem(Order $order, OrderItem $item, ClientKit $kit, bool $dryRun): int
    {
        $kitItems = $kit->items->filter(fn(ClientKitItem $ki) => $ki->clientProduct !== null);

        if ($kitItems->isEmpty()) {
            return 0;
        }

        $totalCost = $kitItems->sum(function (ClientKitItem $ki) {
            $product = $ki->clientProduct->product;
            // MUL-318 P2: fantasma pesa 0 no rateio (a venda vai pros irmaos confiaveis)
            return ($product?->custoConfiavel() ?? 0) * $ki->quantity;
        });

        $orderQty   = max(1, (int) $item->quantity);
        $orderTotal = (float) $item->total;

        if ($dryRun) {
            return $kitItems->count();
        }

        return DB::transaction(function () use ($order, $item, $kit, $kitItems, $orderQty, $orderTotal, $totalCost) {
            $createdCount   = 0;
            $originalItemId = $item->id;
            $n              = $kitItems->count();

            $item->delete();

            // MUL-243: webhook Shopee recria o item original e re-explode; remover
            // componentes anteriores do mesmo kit torna a explosao idempotente
            OrderItem::where('order_id', $order->id)
                ->where('client_kit_id', $kit->id)
                ->where('is_kit_component', true)
                ->delete();

            foreach ($kitItems as $ki) {
                $product      = $ki->clientProduct->product;
                $compQty      = $orderQty * $ki->quantity;
                $sku          = $ki->clientProduct->custom_sku ?: ($product?->sku ?? 'N/A');
                $name         = $ki->clientProduct->custom_title ?: ($product?->name ?? $kit->name);
                // MUL-318 P2: null = fantasma; rateio usa 0, custo gravado fica null
                $compCostReal  = $product?->custoConfiavel();
                $compCostUnit  = $compCostReal ?? 0.0;
                $compCostTotal = $compCostUnit * $ki->quantity;

                if ($totalCost > 0) {
                    $ratio         = $compCostTotal / $totalCost;
                    $compUnitPrice = round(($ratio * $orderTotal) / $compQty, 2);
                    $compTotal     = round($ratio * $orderTotal, 2);
                } else {
                    $compUnitPrice = round($orderTotal / ($n * $compQty), 2);
                    $compTotal     = round($orderTotal / $n, 2);
                }

                $coverImg = null;
                if ($product) {
                    $cover    = \App\Models\ProductMedia::where('product_id', $product->id)
                        ->orderByDesc('is_cover')->orderBy('position')->first();
                    $coverImg = $cover?->url ?: $cover?->original_url;
                }

                OrderItem::create([
                    'order_id'            => $order->id,
                    'client_product_id'   => $ki->client_product_id,
                    'product_id'          => $product?->id,
                    'sku'                 => $sku,
                    'name'                => $name,
                    'quantity'            => $compQty,
                    'unit_price'          => $compUnitPrice,
                    'total'               => $compTotal,
                    'supplier_unit_cost'  => $compCostReal,
                    'supplier_total_cost' => $compCostReal !== null ? round($compCostReal * $compQty, 2) : null,
                    'product_image'       => $coverImg,
                    'client_kit_id'       => $kit->id,
                    'is_kit_component'    => true,
                    'kit_source_item_id'  => $originalItemId,
                    'legacy_kit_id'       => $item->legacy_kit_id,
                ]);

                $createdCount++;
            }

            // MUL-230 fix-3: recalcula orders.supplier_total pra bater com SUM(items.supplier_total_cost) — elimina divergencia futura
            \DB::table('orders')
                ->where('id', $order->id)
                ->update([
                    'supplier_total' => \DB::table('order_items')
                        ->where('order_id', $order->id)
                        ->sum('supplier_total_cost'),
                    'updated_at' => now(),
                ]);
            return $createdCount;
        });
    }

    public function isKitSku(int $clientId, string $sku): bool
    {
        return ClientKit::where('client_id', $clientId)
            ->where('sku', $sku)
            ->where('is_active', true)
            ->exists();
    }

    public function getClientKitSkuMap(int $clientId): array
    {
        return ClientKit::where('client_id', $clientId)
            ->where('is_active', true)
            ->pluck('id', 'sku')
            ->toArray();
    }
}
