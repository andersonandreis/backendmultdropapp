<?php

namespace App\Jobs;

use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Services\Integrations\Marketplaces\TikTokShopService;
use App\Services\Orders\DraftOrderPromoter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * SEL-047: Sync periodico de pedidos TikTok Shop para uma conta especifica.
 *
 * Espelha o padrao exato do SyncShopeeOrdersJob:
 * - Disparado pelo scheduler (console.php) para todas as contas tiktok ativas.
 * - Janela de busca: data_inicial_import da conta (fallback: created_at, ultimo fallback: 7 dias).
 * - De-duplica por marketplace_order_id sem filtro de source (licao MUL-187).
 * - Pedido nasce rascunho (is_draft=1) e e promovido pelo DraftOrderPromoter.
 *
 * DORMANT: sem TIKTOK_APP_KEY no .env, TikTokShopService retorna [] em fetchOrders
 * (guard em getValidAccessToken retorna null quando access_token ausente).
 * Com 0 contas ativas na tabela, o cron do console.php nao despacha nenhum job.
 */
class SyncTikTokOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(
        public readonly int $accountId
    ) {}

    public function handle(TikTokShopService $tiktok): void
    {
        $account = MarketplaceAccount::find($this->accountId);

        if (! $account) {
            Log::channel('marketplace')->warning('[SyncTikTokOrdersJob] Conta nao encontrada', [
                'account_id' => $this->accountId,
            ]);
            return;
        }

        if ($account->platform !== 'tiktok') {
            return;
        }

        // Guard MUL-212 F2: pull controlado por instalacao (banco)
        $cfg = app(\App\Services\InstallationConfig::class);
        if (! $cfg->pullsOrders('tiktok') || $cfg->skipsCentralAccountPull((bool) $account->centrally_managed)) {
            Log::channel('marketplace')->info('[SyncTikTokOrdersJob] Pull desativado nesta instalacao (MUL-212 F2) -- skip', [
                'account_id'        => $this->accountId,
                'centrally_managed' => (bool) $account->centrally_managed,
            ]);
            return;
        }

        if (! $account->shop_id || ! $account->access_token) {
            Log::channel('marketplace')->warning('[SyncTikTokOrdersJob] shop_id ou access_token ausente -- abortando', [
                'account_id' => $this->accountId,
                'status'     => $account->status,
            ]);
            return;
        }

        if ($account->sync_blocked_at !== null) {
            Log::channel('marketplace')->warning('[SyncTikTokOrdersJob] Conta bloqueada -- abortando', [
                'account_id' => $this->accountId,
            ]);
            return;
        }

        if (! $account->supplier_id) {
            Log::channel('marketplace')->warning('[SyncTikTokOrdersJob] supplier_id ausente -- abortando sem criar pedidos', [
                'account_id' => $this->accountId,
                'client_id'  => $account->client_id,
            ]);
            return;
        }

        Log::channel('marketplace')->info('[SyncTikTokOrdersJob] Iniciando sync de pedidos', [
            'account_id' => $this->accountId,
            'shop_id'    => $account->shop_id,
            'client_id'  => $account->client_id,
        ]);

        // Janela de busca: data_inicial_import (fallback: created_at; fallback final: 7 dias)
        $sinceDate = $account->data_inicial_import
            ? \Carbon\Carbon::parse($account->data_inicial_import)->toDateTimeString()
            : ($account->created_at ? $account->created_at->toDateTimeString() : now()->subDays(7)->toDateTimeString());

        try {
            $orders = $tiktok->fetchOrders($account, $sinceDate);
        } catch (\Throwable $e) {
            Log::channel('marketplace')->error('[SyncTikTokOrdersJob] fetchOrders excecao', [
                'account_id' => $this->accountId,
                'error'      => $e->getMessage(),
            ]);
            $this->fail($e);
            return;
        }

        if (empty($orders)) {
            Log::channel('marketplace')->info('[SyncTikTokOrdersJob] Nenhum pedido retornado pela API', [
                'account_id' => $this->accountId,
            ]);
            return;
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($orders as $rawOrder) {
            // TikTok Shop Partner API 202309: identificador principal e order_id
            $orderId = $rawOrder['id'] ?? $rawOrder['order_id'] ?? null;
            if (! $orderId) {
                continue;
            }

            $tiktokStatus = strtolower($rawOrder['status'] ?? '');

            // MUL-187: dedup sem filtro de source -- pedido pode existir via outro canal
            $existing = Order::where('client_id', $account->client_id)
                ->where(function ($q) use ($orderId) {
                    $q->where('marketplace_order_id', $orderId)
                      ->orWhere('external_order_id', $orderId);
                })
                ->first();

            if ($existing) {
                $alreadyFinal = in_array($existing->status, ['paid', 'shipped', 'completed', 'delivered']);

                if ($alreadyFinal) {
                    $skipped++;
                    continue;
                }

                $existing->update([
                    'status'           => $this->mapTikTokStatus($tiktokStatus),
                    'canonical_status' => $this->mapTikTokStatus($tiktokStatus),
                    'tracking_number'  => $rawOrder['tracking_number'] ?? $existing->tracking_number,
                    'updated_at'       => now(),
                ]);
                $updated++;
            } else {
                // create_time: epoch seconds (TikTok Partner API 202309)
                $createTime = ! empty($rawOrder['create_time'])
                    ? \Carbon\Carbon::createFromTimestamp((int) $rawOrder['create_time'])->setTimezone(config('app.timezone'))
                    : null;

                // Valor total: campo payment.total_amount (202309) ou total_amount raiz
                $total = $rawOrder['payment']['total_amount']
                    ?? $rawOrder['total_amount']
                    ?? 0;

                // Nome do comprador: recipient_address.name ou buyer_username
                $buyerName = trim($rawOrder['recipient_address']['name'] ?? $rawOrder['buyer_username'] ?? '');
                if ($buyerName === '' || ! preg_match('/[\p{L}0-9]/u', $buyerName)) {
                    $buyerName = null;
                }

                $newOrder = Order::create([
                    'client_id'              => $account->client_id,
                    'supplier_id'            => $account->supplier_id,
                    'marketplace_account_id' => $account->id,
                    'source'                 => 'tiktok',
                    'marketplace_order_id'   => $orderId,
                    'order_number'           => $orderId,
                    'status'                 => $this->mapTikTokStatus($tiktokStatus),
                    'canonical_status'       => $this->mapTikTokStatus($tiktokStatus),
                    'total'                  => $total,
                    'customer_name'          => $buyerName,
                    'paid_at'                => $createTime,
                    'buyer_username'         => $rawOrder['buyer_username'] ?? null,
                    'tracking_number'        => $rawOrder['tracking_number'] ?? null,
                    'raw_payload'            => json_encode($rawOrder),
                    'is_draft'               => true,
                    'draft_reason'           => 'awaiting_validation',
                    'created_at'             => now(),
                    'updated_at'             => now(),
                ]);
                $created++;

                // MUL-197: promocao imediata se payload ja vier completo
                try {
                    app(DraftOrderPromoter::class)->promote($newOrder, 'sync_tiktok_job');
                } catch (\Throwable $e) {
                    Log::channel('marketplace')->warning('[SyncTikTokOrdersJob] promocao falhou (nao critico)', [
                        'order_id' => $orderId,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }
        }

        $account->update(['last_sync_at' => now()]);

        Log::channel('marketplace')->info('[SyncTikTokOrdersJob] Sync concluido', [
            'account_id' => $this->accountId,
            'shop_id'    => $account->shop_id,
            'total_api'  => count($orders),
            'created'    => $created,
            'updated'    => $updated,
            'skipped'    => $skipped,
        ]);
    }

    /**
     * Mapeamento TikTok Shop status -> status canônico HubAI.
     * Referencia: TikTok Shop Partner API 202309 order status enum.
     */
    private function mapTikTokStatus(string $status): string
    {
        return match ($status) {
            'unpaid'              => 'pending',
            'awaiting_shipment',
            'on_hold'             => 'processing',
            'in_transit'          => 'shipped',
            'delivered',
            'completed'           => 'completed',
            'cancelled'           => 'cancelled',
            default               => 'pending',
        };
    }
}
