<?php

namespace App\Jobs;

use App\Models\BridgeRelayQueue;
use App\Services\GoolhubBridgeService;
use App\Services\Webhooks\LegacyMLRelayService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * RetryBridgeRelayJob
 *
 * Processa a fila bridge_relay_queue a cada 5 minutos.
 * Reprocessa eventos ML e Shopee que falharam no relay ao legado.
 *
 * Backoff exponencial: 30s -> 5min -> 30min -> 2h -> failed_max
 * Apos failed_max o item fica registrado para auditoria manual.
 *
 * NOV-046 P0 #2: antes de processar pendentes, recupera itens stuck em
 * 'processing' ha mais de STUCK_THRESHOLD_MIN (worker crashou).
 *
 * Agendado em routes/console.php via:
 * Schedule::job(new RetryBridgeRelayJob)->everyFiveMinutes()->withoutOverlapping()->name('retry-bridge-relay');
 */
class RetryBridgeRelayJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 120;

    public function handle(
        LegacyMLRelayService $mlRelay,
        GoolhubBridgeService $bridge
    ): void {
        // NOV-046 P0 #2: recupera primeiro os stuck (worker crashou)
        $this->recoverStuckProcessing();

        $now = now();

        $items = BridgeRelayQueue::where('status', 'pending')
            ->where('next_try_at', '<=', $now)
            ->orderBy('next_try_at')
            ->limit(50)
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        Log::info('[RetryBridgeRelayJob] Iniciando processamento', ['count' => $items->count()]);

        $done      = 0;
        $retried   = 0;
        $failedMax = 0;

        foreach ($items as $item) {
            // Lock otimista: marca como processing + timestamp ATOMICAMENTE
            $updated = BridgeRelayQueue::where('id', $item->id)
                ->where('status', 'pending')
                ->update([
                    'status'                => 'processing',
                    'processing_started_at' => now(),
                ]);

            if (! $updated) {
                continue;
            }

            try {
                $success = $this->processItem($item, $mlRelay, $bridge);

                if ($success) {
                    $item->update([
                        'status'                => 'done',
                        'processing_started_at' => null,
                    ]);
                    $done++;
                    Log::info('[RetryBridgeRelayJob] Item processado com sucesso', [
                        'id'       => $item->id,
                        'platform' => $item->platform,
                        'order_id' => $item->order_id,
                    ]);
                } else {
                    $newAttempts = $item->attempts + 1;

                    if ($newAttempts >= BridgeRelayQueue::MAX_ATTEMPTS) {
                        $item->update([
                            'status'                => 'failed_max',
                            'attempts'              => $newAttempts,
                            'processing_started_at' => null,
                        ]);
                        $failedMax++;
                        Log::error('[RetryBridgeRelayJob] Item esgotou tentativas - failed_max', [
                            'id'         => $item->id,
                            'platform'   => $item->platform,
                            'ml_order'   => $item->ml_order_id,
                            'shopee_sn'  => $item->shopee_order_sn,
                            'last_error' => $item->last_error,
                        ]);
                    } else {
                        $item->attempts = $newAttempts;
                        $nextTry        = $item->calcNextTryAt();
                        $item->update([
                            'status'                => 'pending',
                            'attempts'              => $newAttempts,
                            'next_try_at'           => $nextTry,
                            'processing_started_at' => null,
                        ]);
                        $retried++;
                    }
                }

            } catch (\Throwable $e) {
                Log::error('[RetryBridgeRelayJob] Excecao ao processar item', [
                    'id'    => $item->id,
                    'error' => $e->getMessage(),
                ]);
                $item->update([
                    'status'                => 'pending',
                    'processing_started_at' => null,
                ]);
            }
        }

        Log::info('[RetryBridgeRelayJob] Concluido', [
            'done'      => $done,
            'retried'   => $retried,
            'failedMax' => $failedMax,
        ]);
    }

    /**
     * NOV-046 P0 #2: recupera itens stuck em 'processing'.
     *
     * Quando o worker crasha (timeout, OOM, deploy) apos marcar processing,
     * o item fica orfao porque o job so le WHERE status='pending'.
     *
     * Devolve para 'pending' qualquer item com:
     *   status = 'processing' AND processing_started_at < now() - STUCK_THRESHOLD_MIN
     *
     * Tambem cobre items legados sem processing_started_at (NULL) que ficaram
     * stuck antes desta migration entrar no ar.
     */
    protected function recoverStuckProcessing(): void
    {
        $threshold = now()->subMinutes(BridgeRelayQueue::STUCK_THRESHOLD_MIN);

        $recovered = BridgeRelayQueue::where('status', 'processing')
            ->where(function ($q) use ($threshold) {
                $q->whereNull('processing_started_at')
                  ->orWhere('processing_started_at', '<', $threshold);
            })
            ->update([
                'status'                => 'pending',
                'processing_started_at' => null,
            ]);

        if ($recovered > 0) {
            Log::warning('[RetryBridgeRelayJob] Recuperados itens stuck em processing', [
                'count'             => $recovered,
                'threshold_minutes' => BridgeRelayQueue::STUCK_THRESHOLD_MIN,
            ]);
        }
    }

    private function processItem(
        BridgeRelayQueue     $item,
        LegacyMLRelayService $mlRelay,
        GoolhubBridgeService $bridge
    ): bool {
        $payload = $item->payload;

        if ($item->platform === 'mercadolivre') {
            return $this->processMLItem($item, $payload, $mlRelay);
        }

        if ($item->platform === 'shopee') {
            return $this->processShopeeItem($item, $payload, $bridge);
        }

        Log::warning('[RetryBridgeRelayJob] Plataforma desconhecida', ['platform' => $item->platform]);
        return false;
    }

    private function processMLItem(
        BridgeRelayQueue     $item,
        array                $payload,
        LegacyMLRelayService $mlRelay
    ): bool {
        $topic    = $payload['_topic']      ?? $item->event_type ?? 'orders_v2';
        $resource = $payload['_resource']   ?? "/orders/{$item->ml_order_id}";
        $mlUserId = $payload['_ml_user_id'] ?? null;

        if (! $mlUserId || ! $resource) {
            $item->update(['last_error' => 'missing ml_user_id or resource']);
            return false;
        }

        // NOV-195: pedido de seller ORFAO (sem conta no hub) — relay dedicado.
        // relayIfLegacy nao serve aqui: exige MarketplaceAccount + legacy_id_login.
        if (! empty($payload['_orphan'])) {
            $result = $mlRelay->relayOrphanOrder($topic, $resource, (string) $mlUserId, $payload);

            if ($result === 'relayed' || $result === 'discard') {
                return true; // discard = seller nao e do legado, nada a entregar
            }

            $item->update(['last_error' => 'orphan relay retry: bridge indisponivel ou erro']);
            return false;
        }

        try {
            $legacyOrderId = $mlRelay->relayIfLegacy($topic, $resource, (string) $mlUserId, $payload);

            if ($legacyOrderId) {
                if ($item->order_id) {
                    \App\Models\Order::where('id', $item->order_id)
                        ->whereNull('legacy_id')
                        ->update(['legacy_id' => $legacyOrderId]);
                }
                return true;
            }

            // NOV-041 fix: topicos nao-pedido nao retornam legacy_order_id.
            // Sem excecao ao relayar = sucesso. Evita retry infinito.
            $isOrderTopic = in_array($topic, ['orders', 'orders_v2', 'merchant_orders'], true);
            if (! $isOrderTopic) {
                return true;
            }

            $item->update(['last_error' => 'bridge online but no legacy_order_id returned']);
            return false;

        } catch (\Throwable $e) {
            $item->update(['last_error' => substr($e->getMessage(), 0, 500)]);
            return false;
        }
    }

    private function processShopeeItem(
        BridgeRelayQueue     $item,
        array                $payload,
        GoolhubBridgeService $bridge
    ): bool {
        $code   = (int) ($payload['_code']    ?? $item->event_type ?? 0);
        $shopId = (int) ($payload['_shop_id'] ?? 0);

        $cleanPayload = array_filter($payload, fn($k) => ! str_starts_with($k, '_'), ARRAY_FILTER_USE_KEY);

        try {
            $result = $bridge->relayShopeeEvent($code, array_merge($cleanPayload, ['shop_id' => $shopId]));

            if ($result['success']) {
                return true;
            }

            $item->update(['last_error' => substr($result['error'] ?? 'unknown', 0, 500)]);
            return false;

        } catch (\Throwable $e) {
            $item->update(['last_error' => substr($e->getMessage(), 0, 500)]);
            return false;
        }
    }
}
