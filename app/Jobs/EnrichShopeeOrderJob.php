<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Orders\ShopeeOrderEnricher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * MUL-197 — Enriquece pedido-rascunho Shopee com retry inteligente.
 *
 * - Token invalido/expirado: refresh pelo caminho NOV-181 + retry NA HORA
 *   (dentro do ShopeeOrderEnricher/callDirect, incl. typo invalid_acceess_token).
 * - Instabilidade da API: release com backoff 5/15/60 min (tries=4).
 * - Falha definitiva: pedido PERMANECE rascunho com motivo em draft_reason.
 *
 * Disparado por: SyncShopeeOrdersJob / ImportMarketplaceAccountDataJob (criacao
 * incompleta), scheduler orders:enrich-drafts, endpoint manual em massa.
 */
class EnrichShopeeOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 4;
    public int $timeout = 90;

    public function __construct(
        public readonly int $orderId
    ) {
        // Fila default sofre backlog de DispatchWebhookJob (400k+ em 09/07)
        $this->onQueue('high-priority');
    }

    /** Backoff entre tentativas: 5 min, 15 min, 60 min. */
    public function backoff(): array
    {
        return [300, 900, 3600];
    }

    public function handle(ShopeeOrderEnricher $enricher): void
    {
        $order = Order::find($this->orderId);

        if (! $order || ! $order->is_draft) {
            return; // apagado ou ja promovido — nada a fazer
        }

        // SEL-357: pedidos espelho readonly nao precisam de enrichment da API real
        if ($order->mirror_source_backend !== null) {
            Log::info('[EnrichShopeeOrderJob] Pedido espelho (SEL-357) — skip enrichment', [
                'order_id'              => $this->orderId,
                'mirror_source_backend' => $order->mirror_source_backend,
            ]);
            return;
        }

        $result = $enricher->enrich($order);

        if ($result['ok']) {
            return; // promovido
        }

        if (in_array($result['code'], ShopeeOrderEnricher::RETRYABLE, true)) {
            if ($this->attempts() < $this->tries) {
                $delay = $this->backoff()[$this->attempts() - 1] ?? 3600;
                Log::channel('marketplace')->info('[MUL-197] Enriquecimento retryavel — release com backoff', [
                    'order_id' => $this->orderId,
                    'code'     => $result['code'],
                    'attempt'  => $this->attempts(),
                    'delay_s'  => $delay,
                ]);
                $this->release($delay);
                return;
            }

            Log::channel('marketplace')->warning('[MUL-197] Enriquecimento esgotou tentativas — permanece rascunho', [
                'order_id' => $this->orderId,
                'code'     => $result['code'],
                'attempts' => $this->attempts(),
            ]);
            return;
        }

        // Nao-retryavel (order_not_found, still_incomplete, no_shopee_account...):
        // permanece rascunho com motivo ja gravado em draft_reason pelo enricher.
        Log::channel('marketplace')->info('[MUL-197] Enriquecimento nao promoveu — permanece rascunho', [
            'order_id' => $this->orderId,
            'code'     => $result['code'],
            'missing'  => $result['missing'],
        ]);
    }
}
