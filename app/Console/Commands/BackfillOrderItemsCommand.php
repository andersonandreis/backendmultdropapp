<?php
namespace App\Console\Commands;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\WebhookOrderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BackfillOrderItemsCommand - MES-043
 * Backfill order_items para pedidos sem itens (supplier 25 MEStoreDrop).
 * Fontes: raw_payload (item_list/order_items) -> legado (legacy_id) -> irrecuperavel.
 */
class BackfillOrderItemsCommand extends Command
{
    protected $signature = 'orders:backfill-items
        {--supplier=25 : ID do supplier}
        {--source= : Filtrar por source (shopee,mercadolivre,ml,bling,all)}
        {--dry-run : Apenas contar sem inserir}
        {--manifest= : Arquivo de manifesto (padrao /root/mes043-backfill-manifest.txt)}
        {--limit=0 : Limite de pedidos (0=todos)}';
    protected $description = 'MES-043: Backfill de order_items para pedidos sem itens';

    private int $recovered = 0;
    private array $irrecoverable = [];
    private array $manifest = [];

    public function handle(): int
    {
        $supplierId   = (int) $this->option('supplier');
        $sourceFilter = $this->option('source');
        $dryRun       = (bool) $this->option('dry-run');
        $manifestPath = $this->option('manifest') ?? '/root/mes043-backfill-manifest.txt';
        $limit        = (int) $this->option('limit');

        $this->info("[MES-043] supplier={$supplierId}" . ($dryRun ? ' [DRY-RUN]' : ''));

        $beforeCount = $this->countWithoutItems($supplierId, $sourceFilter);
        $this->info("ANTES: {$beforeCount} pedidos sem items");

        $query = Order::withoutGlobalScopes()
            ->where('supplier_id', $supplierId)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('order_items')
                  ->whereColumn('order_items.order_id', 'orders.id');
            })
            ->with('marketplaceAccount');

        if ($sourceFilter && $sourceFilter !== 'all') {
            $query->where('source', $sourceFilter === 'ml' ? 'mercadolivre' : $sourceFilter);
        }
        if ($limit > 0) { $query->limit($limit); }

        $orders = $query->orderBy('id')->get();
        $this->info("A processar: " . $orders->count());
        $bar = $this->output->createProgressBar($orders->count());
        $bar->start();

        foreach ($orders as $order) {
            $itemsCreated = 0;
            $fonte = 'irrecuperavel';

            // Fonte 1: raw_payload (Shopee item_list / ML order_items)
            if ($order->raw_payload) {
                $raw = is_array($order->raw_payload) ? $order->raw_payload : json_decode($order->raw_payload, true);
                if (is_string($raw)) { $raw = json_decode($raw, true); }

                if (is_array($raw) && $order->marketplaceAccount) {
                    $acc = $order->marketplaceAccount;

                    if ($order->source === 'shopee' && !empty($raw['item_list'])) {
                        if (!$dryRun) {
                            try { $itemsCreated = WebhookOrderService::upsertShopeeItemsFromPayload($order, $raw, $acc); }
                            catch (\Throwable $e) { Log::warning('[Backfill] shopee err', ['id' => $order->id, 'e' => $e->getMessage()]); }
                        } else {
                            $itemsCreated = count($raw['item_list']);
                        }
                        if ($itemsCreated > 0) { $fonte = 'raw_payload_shopee'; }

                    } elseif (in_array($order->source, ['mercadolivre', 'ml']) && !empty($raw['order_items'])) {
                        if (!$dryRun) {
                            try { $itemsCreated = WebhookOrderService::upsertMLItemsFromPayload($order, $raw, $acc); }
                            catch (\Throwable $e) { Log::warning('[Backfill] ml err', ['id' => $order->id, 'e' => $e->getMessage()]); }
                        } else {
                            $itemsCreated = count($raw['order_items']);
                        }
                        if ($itemsCreated > 0) { $fonte = 'raw_payload_ml'; }
                    }
                }
            }

            // Fonte 2: legado via legacy_id
            if ($itemsCreated === 0 && $order->legacy_id) {
                try {
                    $lis = DB::connection('legacy')
                        ->table('pedidos_produtos as pp')
                        ->leftJoin('sku_pai as sp', 'sp.id', '=', 'pp.id_sku_pai')
                        ->where('pp.id_pedido', $order->legacy_id)
                        ->select('pp.id_sku_pai', 'pp.qtd', 'pp.valor_unitario',
                                 'pp.custo_dia', 'pp.custo_pago_dia',
                                 DB::raw('COALESCE(pp.sku, sp.sku) as sku'),
                                 DB::raw('COALESCE(pp.descricao, sp.descricao) as descricao'),
                                 DB::raw('COALESCE(pp.foto, sp.img) as foto')
                        )
                        ->get();

                    if ($lis->isNotEmpty()) {
                        if (!$dryRun) {
                            foreach ($lis as $li) {
                                $qty   = max(1, (int) ($li->qtd ?? 1));
                                $price = (float) ($li->valor_unitario ?? 0);
                                $cost  = (float) ($li->custo_dia ?? $li->custo_pago_dia ?? 0);
                                $prod  = $li->sku
                                    ? \App\Models\Product::where('sku', $li->sku)->where('supplier_id', $supplierId)->first()
                                    : null;

                                OrderItem::firstOrCreate(
                                    ['order_id' => $order->id, 'legacy_sku_pai_id' => $li->id_sku_pai],
                                    [
                                        'product_id'          => $prod?->id,
                                        'sku'                 => $li->sku ?? 'N/A',
                                        'name'                => $li->descricao ?? 'Produto',
                                        'quantity'            => $qty,
                                        'unit_price'          => $price,
                                        'total'               => round($price * $qty, 2),
                                        'supplier_unit_cost'  => $cost > 0 ? $cost : ($prod?->price ?? 0),
                                        'supplier_total_cost' => round(($cost ?: ($prod?->price ?? 0)) * $qty, 2),
                                        'product_image'       => $li->foto ?? null,
                                    ]
                                );
                                $itemsCreated++;
                            }
                        } else {
                            $itemsCreated = $lis->count();
                        }
                        if ($itemsCreated > 0) { $fonte = 'legacy_db'; }
                    }
                } catch (\Throwable $e) {
                    Log::warning('[Backfill] legado inacessivel', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                }
            }

            if ($itemsCreated > 0) {
                $mkId = $order->marketplace_order_id ?? $order->external_order_id ?? 'N/A';
                $legId = $order->legacy_id ?? 'NULL';
                $this->manifest[] = "{$order->id}|{$mkId}|{$order->source}|{$legId}|{$itemsCreated}|{$fonte}";
                $this->recovered++;
            } else {
                $reason = $this->reason($order);
                $legId  = $order->legacy_id ?? 'NULL';
                $this->irrecoverable[] = "{$order->id}|{$order->source}|{$order->status}|{$legId}|{$reason}";
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $afterCount = !$dryRun
            ? $this->countWithoutItems($supplierId, $sourceFilter)
            : $beforeCount - $this->recovered;

        $this->info("\n=== MES-043 RESULTADO ===");
        $this->info("ANTES:          {$beforeCount}");
        $this->info("DEPOIS:         {$afterCount}");
        $this->info("Recuperados:    {$this->recovered}");
        $this->info("Irrecuperaveis: " . count($this->irrecoverable));

        $lines = [
            "# MES-043 Backfill Order Items " . now()->format('Y-m-d H:i:s'),
            "# ANTES:{$beforeCount} DEPOIS:{$afterCount} Recuperados:{$this->recovered} Irrecup:" . count($this->irrecoverable),
            "# Formato: order_id|marketplace_order_id|source|legacy_id|items_criados|origem",
            "",
            "## RECUPERADOS (" . count($this->manifest) . "):",
        ];
        foreach ($this->manifest as $l) { $lines[] = $l; }
        $lines[] = "";
        $lines[] = "## IRRECUPERAVEIS (" . count($this->irrecoverable) . "):";
        $lines[] = "# order_id|source|status|legacy_id|motivo";
        foreach ($this->irrecoverable as $l) { $lines[] = $l; }
        file_put_contents($manifestPath, implode("\n", $lines) . "\n");
        $this->info("Manifesto salvo: {$manifestPath}");

        return self::SUCCESS;
    }

    private function countWithoutItems(int $sid, ?string $src): int
    {
        $q = Order::withoutGlobalScopes()
            ->where('supplier_id', $sid)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('order_items')->whereColumn('order_items.order_id', 'orders.id');
            });
        if ($src && $src !== 'all') { $q->where('source', $src === 'ml' ? 'mercadolivre' : $src); }
        return $q->count();
    }

    private function reason(Order $order): string
    {
        if (!$order->raw_payload) { return 'sem_raw_payload'; }
        $raw = is_array($order->raw_payload) ? $order->raw_payload : json_decode($order->raw_payload, true);
        if (is_string($raw)) { $raw = json_decode($raw, true); }
        if (!is_array($raw)) { return 'payload_invalido'; }
        if ($order->source === 'shopee' && empty($raw['item_list'])) { return 'shopee_item_list_vazio'; }
        if (in_array($order->source, ['mercadolivre', 'ml']) && empty($raw['order_items'])) { return 'ml_order_items_vazio'; }
        if (!$order->marketplaceAccount) { return 'sem_marketplace_account'; }
        return 'nao_classificado';
    }
}
