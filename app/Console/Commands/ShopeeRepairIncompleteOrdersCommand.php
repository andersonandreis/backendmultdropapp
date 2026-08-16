<?php

namespace App\Console\Commands;

use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Integrations\Marketplaces\ShopeeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Re-processa pedidos Shopee criados como placeholder (total=0, sem order_items).
 *
 * Contexto (2026-06-23):
 * 658 pedidos criados entre 20-23/06 quando o bridge estava com timeout.
 * Foram gravados como placeholder (total=0, legacy_id=NULL, sem order_items).
 * Este comando re-busca os detalhes na API Shopee e tenta relay ao legado.
 *
 * Uso:
 *   php artisan shopee:repair-incomplete-orders [--dry-run] [--client-id=X] [--limit=50]
 */
class ShopeeRepairIncompleteOrdersCommand extends Command
{
    protected $signature = 'shopee:repair-incomplete-orders
                            {--dry-run : Mostra o que seria feito sem executar}
                            {--client-id= : Filtra por client_id}
                            {--limit=0 : Limita o numero de pedidos processados (0=todos)}';

    protected $description = 'Re-processa pedidos Shopee incompletos (total=0, sem order_items) buscando detalhes na API';

    public function handle(ShopeeService $shopee): int
    {
        $dryRun   = $this->option('dry-run');
        $clientId = $this->option('client-id');
        $limit    = (int) $this->option('limit');

        $this->info('[ShopeeRepairIncomplete] Buscando pedidos Shopee incompletos...');

        $query = Order::where('source', 'shopee')
            ->where(function ($q) {
                $q->whereNull('total')->orWhere('total', 0);
            })
            ->whereNull('legacy_id')
            ->whereNotNull('marketplace_order_id')
            ->whereNotNull('marketplace_account_id')
            ->orderBy('client_id');

        if ($clientId) {
            $query->where('client_id', (int) $clientId);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $orders = $query->get();
        $total  = $orders->count();

        $this->info("Encontrados {$total} pedidos incompletos.");

        if ($dryRun) {
            $this->warn('[DRY-RUN] Nenhuma alteracao sera feita.');
            $byClient = $orders->groupBy('client_id')->map->count();
            $rows = [];
            foreach ($byClient as $cid => $cnt) {
                $rows[] = [$cid, $cnt];
            }
            $this->table(['client_id', 'pedidos'], $rows);
            return self::SUCCESS;
        }

        // Pre-carregar contas para evitar N+1
        $accountIds = $orders->pluck('marketplace_account_id')->filter()->unique()->all();
        $accounts   = MarketplaceAccount::whereIn('id', $accountIds)->get()->keyBy('id');

        // Agrupar por conta para minimizar chamadas API
        $byAccount = $orders->groupBy('marketplace_account_id');

        $fetched   = 0;
        $withItems = 0;
        $noItems   = 0;
        $apiErrors = 0;
        $skipped   = 0;

        foreach ($byAccount as $accountId => $accountOrders) {
            $account = $accounts->get($accountId);

            if (!$account) {
                $this->line("  SKIP account #{$accountId}: nao encontrada");
                $skipped += $accountOrders->count();
                continue;
            }

            $shopId      = $account->shop_id;
            $accessToken = $shopee->getValidAccessToken($account);

            if (!$shopId || !$accessToken) {
                $this->warn("  SKIP account #{$accountId} (shop {$shopId}): sem token valido");
                $skipped += $accountOrders->count();
                continue;
            }

            $this->line("  Processando conta #{$accountId} (shop {$shopId}) -- {$accountOrders->count()} pedidos...");

            // Processar em lotes de 50 (limite Shopee)
            $chunks = $accountOrders->chunk(50);

            foreach ($chunks as $chunk) {
                $orderSns = $chunk->pluck('marketplace_order_id')->filter()->values()->all();

                if (empty($orderSns)) {
                    continue;
                }

                try {
                    $detailResponse = $shopee->getOrderDetail($shopId, $accessToken, $orderSns);
                    $apiOrderList   = $detailResponse['response']['order_list'] ?? [];

                    if (empty($apiOrderList)) {
                        $errCode = $detailResponse['error'] ?? 'unknown';
                        $this->warn("    API erro para lote: {$errCode} - " . ($detailResponse['message'] ?? ''));

                        Log::warning('[ShopeeRepairIncomplete] API sem resultado para lote', [
                            'account_id' => $accountId,
                            'order_sns'  => array_slice($orderSns, 0, 5),
                            'error'      => $detailResponse['error'] ?? null,
                            'message'    => $detailResponse['message'] ?? null,
                        ]);

                        $apiErrors += count($orderSns);
                        continue;
                    }

                    // Indexar por order_sn para lookup rapido
                    $apiByOrderSn = collect($apiOrderList)->keyBy('order_sn');

                    foreach ($chunk as $order) {
                        $orderSn  = $order->marketplace_order_id;
                        $apiOrder = $apiByOrderSn->get($orderSn);

                        if (!$apiOrder) {
                            Log::warning('[ShopeeRepairIncomplete] Pedido nao retornado pela API', [
                                'order_id' => $order->id,
                                'order_sn' => $orderSn,
                            ]);
                            $noItems++;
                            continue;
                        }

                        $fetched++;
                        $this->updateOrder($order, $apiOrder, $withItems, $noItems);
                    }

                    // Delay entre lotes para respeitar rate limit Shopee
                    usleep(500000); // 500ms

                } catch (\Throwable $e) {
                    $this->error("    EXCECAO no lote: " . $e->getMessage());
                    Log::error('[ShopeeRepairIncomplete] Excecao no lote', [
                        'account_id' => $accountId,
                        'error'      => $e->getMessage(),
                    ]);
                    $apiErrors += count($orderSns);
                }
            }
        }

        $this->newLine();
        $this->info('Resultado:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total encontrados',         $total],
                ['API retornou dados',         $fetched],
                ['Atualizados com itens',     $withItems],
                ['Sem itens na API',           $noItems],
                ['Erros de API',               $apiErrors],
                ['Skipped (sem token/conta)',  $skipped],
            ]
        );

        $remaining = Order::where('source', 'shopee')
            ->where(function ($q) {
                $q->whereNull('total')->orWhere('total', 0);
            })
            ->whereNull('legacy_id')
            ->count();

        $this->info("Shopee pedidos ainda incompletos no banco: {$remaining}");

        return self::SUCCESS;
    }

    /**
     * Atualiza o pedido local com dados da API Shopee e cria os order_items.
     */
    protected function updateOrder(
        Order $order,
        array $apiOrder,
        int   &$withItems,
        int   &$noItems
    ): void {
        $orderSn       = $order->marketplace_order_id;
        $itemList      = $apiOrder['item_list'] ?? [];
        $totalAmt      = (float) ($apiOrder['total_amount'] ?? 0);
        $shopeeStatus  = strtolower($apiOrder['order_status'] ?? '');
        $trackingNo    = $apiOrder['tracking_no'] ?? null;
        $buyerUsername = $apiOrder['buyer_username'] ?? null;

        $statusMap = [
            'unpaid'        => 'pending',
            'ready_to_ship' => 'processing',
            'processed'     => 'processing',
            'shipped'       => 'shipped',
            'in_cancel'     => 'cancelled',
            'cancelled'     => 'cancelled',
            'to_return'     => 'returned',
            'completed'     => 'completed',
        ];
        $localStatus = $statusMap[$shopeeStatus] ?? 'pending';

        $updates = [
            'total'            => $totalAmt,
            'status'           => $localStatus,
            'canonical_status' => $localStatus,
        ];

        // MUL-181: importacao retroativa entrava com a data do sistema e quebrava a ordenacao
        $createTime = (int) ($apiOrder['create_time'] ?? 0);
        if ($createTime > 0) {
            $updates['created_at'] = \Carbon\Carbon::createFromTimestamp($createTime, config('app.timezone'));
        }

        if ($trackingNo) {
            $updates['tracking_number'] = $trackingNo;
        }
        // Nao sobrescrever username real com valor mascarado pela API
        if ($buyerUsername && ! (str_contains($buyerUsername, '*') && $order->buyer_username)) {
            $updates['buyer_username'] = $buyerUsername;
            if (!$order->buyer_nickname) {
                $updates['buyer_nickname'] = $buyerUsername;
            }
        }

        // Shopee mascara o nome com '*' em pedidos antigos — só grava nome completo.
        // Fallback: raw_payload salvo na criacao (dentro da janela sem mascara).
        $recipientName = trim($apiOrder['recipient_address']['name'] ?? '');
        if ($recipientName === '' || str_contains($recipientName, '*')) {
            // raw_payload pode vir duplo-encodado (string JSON mesmo com cast array)
            $raw = $order->raw_payload;
            if (is_string($raw)) {
                $raw = json_decode($raw, true);
            }
            $raw = is_array($raw) ? $raw : [];
            $rawName = trim($raw['recipient_address']['name'] ?? '');
            if ($rawName !== '' && !str_contains($rawName, '*')) {
                $recipientName = $rawName;
            }
        }
        if ($recipientName !== '' && !str_contains($recipientName, '*') && !$order->customer_name) {
            $updates['customer_name'] = $recipientName;
        }

        // forceFill: created_at nao esta no fillable
        $order->forceFill($updates)->saveQuietly();

        if (empty($itemList)) {
            Log::warning('[ShopeeRepairIncomplete] Pedido sem item_list na API', [
                'order_id' => $order->id,
                'order_sn' => $orderSn,
            ]);
            $noItems++;
            return;
        }

        // Cria order_items
        $itemsCreated = 0;
        foreach ($itemList as $item) {
            $itemId  = (string) ($item['item_id'] ?? '');
            $qty     = (int) ($item['model_quantity_purchased'] ?? $item['quantity_purchased'] ?? 1);
            $price   = (float) ($item['model_discounted_price'] ?? $item['model_original_price'] ?? 0);
            $name    = $item['item_name'] ?? 'Produto Shopee';
            $sku     = $item['model_sku'] ?? $item['item_sku'] ?? $itemId;

            if (!$itemId) {
                continue;
            }

            // Evita duplicata
            $exists = OrderItem::where('order_id', $order->id)
                ->where('external_item_id', $itemId)
                ->exists();

            if (!$exists) {
                OrderItem::create([
                    'order_id'            => $order->id,
                    'external_item_id'    => $itemId,
                    'sku'                 => $sku,
                    'name'                => $name,
                    'quantity'            => $qty,
                    'unit_price'          => $price,
                    'total'               => round($qty * $price, 2),
                    'supplier_unit_cost'  => 0,
                    'supplier_total_cost' => 0,
                    'product_image'       => $item['image_info']['image_url'] ?? null,
                ]);
                $itemsCreated++;
            }
        }

        $withItems++;

        Log::info('[ShopeeRepairIncomplete] Pedido atualizado', [
            'order_id'      => $order->id,
            'order_sn'      => $orderSn,
            'total'         => $totalAmt,
            'status'        => $localStatus,
            'items_created' => $itemsCreated,
        ]);

        $this->line("    OK order #{$order->id} ({$orderSn}): total={$totalAmt}, status={$localStatus}, +{$itemsCreated} itens");
    }
}
