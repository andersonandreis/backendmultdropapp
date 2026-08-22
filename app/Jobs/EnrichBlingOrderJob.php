<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderStatusHistory;
use App\Services\Integrations\Erps\Bling\BlingApiClient;
use App\Services\Orders\DraftOrderPromoter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * INF-036 B — Enriquecimento de pedido-rascunho Bling.
 *
 * Espelha EnrichShopeeOrderJob/EnrichMercadoLivreOrderJob:
 *  - BlingApiClient::getOrder($account, $id) — /pedidos/vendas/{id}
 *  - aplica campos ausentes SEM sobrescrever
 *  - chama DraftOrderPromoter::promote
 */
class EnrichBlingOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 4;
    public int $timeout = 90;

    public function __construct(public readonly int $orderId)
    {
        // Fila default sofre backlog de DispatchWebhookJob (400k+ em 09/07)
        $this->onQueue('high-priority');
    }

    public function backoff(): array
    {
        return [300, 900, 3600];
    }

    public function handle(BlingApiClient $bling, DraftOrderPromoter $promoter): void
    {
        $order = Order::find($this->orderId);
        if (! $order || ! $order->is_draft) {
            return;
        }
        if ($order->source !== 'bling' || ! $order->external_order_id) {
            return;
        }

        // INF-037: pedidos source=bling vem do Bling do CLIENTE conectado como
        // canal de venda (marketplace_accounts). erp_accounts e o Bling do
        // FORNECEDOR (catalogo/estoque/NF-e) e NAO enxerga esses pedidos —
        // consulta-lo gerou 404 falso em massa (3.5k not_found errados, 09/07).
        // Conta estampada no pedido vem primeiro; 404 so e veredito final
        // depois de TODAS as contas bling ativas do supplier retornarem 404.
        $accounts = MarketplaceAccount::where('platform', 'bling')
            ->where('supplier_id', $order->supplier_id)
            ->where('status', 'active')
            ->whereNotNull('bling_access_token')
            ->orderByRaw('id = ? desc', [$order->marketplace_account_id ?? 0])
            ->get();
        if ($accounts->isEmpty()) {
            $this->markDraft($order, 'no_bling_account');
            return;
        }

        $order->enrich_attempts  = (int) $order->enrich_attempts + 1;
        $order->last_enriched_at = now();
        $order->saveQuietly();

        $detail   = null;
        $found    = null;
        $all404   = true;
        $authFail = false;
        foreach ($accounts as $account) {
            try {
                $resp = $bling->getOrder($account, (int) $order->external_order_id);
            } catch (\Throwable $e) {
                $msg = mb_strtolower($e->getMessage());
                if (str_contains($msg, '404') || str_contains($msg, 'not_found')) {
                    continue;
                }
                $all404 = false;
                if (str_contains($msg, '401') || str_contains($msg, '403') || str_contains($msg, 'unauthorized')) {
                    $authFail = true;
                    continue;
                }
                Log::channel('marketplace')->warning('[INF-036] Bling getOrder falhou', [
                    'order_id'    => $this->orderId,
                    'account_id'  => $account->id,
                    'external_id' => $order->external_order_id,
                    'error'       => $e->getMessage(),
                ]);
                continue;
            }

            $data = $resp['data'] ?? null;
            if (is_array($data) && ! empty($data['id'])) {
                $detail = $data;
                $found  = $account;
                break;
            }
            $all404 = false;
        }

        if (! $detail) {
            if ($all404) {
                $this->markNotFound($order, '404 em todas as ' . $accounts->count() . ' contas bling ativas do supplier');
                return;
            }
            $this->markDraft($order, $authFail ? 'invalid_access_token' : 'bling_api_error');
            $this->releaseWithBackoff();
            return;
        }

        if ($order->marketplace_account_id !== $found->id) {
            $order->marketplace_account_id = $found->id;
            $order->saveQuietly();
        }

        $this->applyDetail($order, $detail);

        [$promoted, $missing] = $promoter->promote($order->fresh(), 'bling_enricher');
        if (! $promoted) {
            $reason = 'incomplete: ' . implode(',', $missing);
            $this->markDraft($order, mb_substr($reason, 0, 100));
        }
    }

    /**
     * Aplica dados da resposta Bling no Order SEM sobrescrever valores ja preenchidos.
     */
    private function applyDetail(Order $order, array $detail): void
    {
        $updates = [];

        // Cliente
        $contato = $detail['contato'] ?? [];
        $name    = trim((string) ($contato['nome'] ?? ''));
        if ($name !== '' && trim((string) $order->customer_name) === '') {
            $updates['customer_name'] = $name;
        }

        // Financeiro
        $total = (float) ($detail['totais']['totalVenda']
            ?? $detail['total']
            ?? $detail['totalProdutos']
            ?? 0);
        if ($total > 0 && (float) $order->total <= 0) {
            $updates['total']    = $total;
            $updates['subtotal'] = $total;
        }

        // Pagamento
        // MUL-466: dataSaida e a data PREVISTA de expedicao (futura) — usava-la como
        // paid_at mostrava "Pagamento 24/08" num pedido de 21/08. Usa `data` (dia UTC
        // do pedido no Bling) com a regra MUL-460: data no futuro clampa em agora.
        if (! $order->paid_at) {
            $paidRaw = $detail['data'] ?? null;
            if ($paidRaw) {
                try {
                    $paidEm = Carbon::parse($paidRaw, config('app.timezone'));
                    $updates['paid_at'] = $paidEm->isAfter(now()) ? now() : $paidEm;
                } catch (\Throwable) { /* ignora */ }
            }
        }

        // Rastreio
        $tracking = $detail['transporte']['volumes'][0]['codigoRastreamento'] ?? null;
        if (empty($order->tracking_number) && $tracking) {
            $updates['tracking_number'] = $tracking;
        }

        if (! empty($updates)) {
            $order->forceFill($updates)->saveQuietly();
        }
    }

    private function markDraft(Order $order, string $reason): void
    {
        $order->forceFill([
            'is_draft'     => true,
            'draft_reason' => mb_substr($reason, 0, 100),
        ])->saveQuietly();
    }

    /**
     * INF-036: pedido inexistente na API Bling — status vira not_found (para de
     * retentar) e a resposta completa fica em order_events.
     */
    private function markNotFound(Order $order, string $apiError): void
    {
        $from = (string) $order->status;
        if ($from === OrderStatus::NOT_FOUND->value) {
            return;
        }

        $order->forceFill([
            'status'           => OrderStatus::NOT_FOUND->value,
            'canonical_status' => OrderStatus::NOT_FOUND->value,
            'is_draft'         => true,
            'draft_reason'     => 'order_not_found_in_api',
        ])->saveQuietly();

        OrderEvent::create([
            'order_id'    => $order->id,
            'event_type'  => 'marketplace_not_found',
            'description' => 'Bling nao reconhece o pedido (/pedidos/vendas/{id}) — status alterado para not_found',
            'metadata'    => [
                'api'         => 'bling:/pedidos/vendas/' . $order->external_order_id,
                'response'    => $apiError,
                'checked_at'  => now()->toDateTimeString(),
            ],
        ]);

        OrderStatusHistory::record($order, 'status', $from, OrderStatus::NOT_FOUND->value, 'enricher', [
            'reason' => 'order_not_found_in_api',
        ]);
    }

    private function releaseWithBackoff(): void
    {
        if ($this->attempts() < $this->tries) {
            $delay = $this->backoff()[$this->attempts() - 1] ?? 3600;
            $this->release($delay);
        }
    }
}
