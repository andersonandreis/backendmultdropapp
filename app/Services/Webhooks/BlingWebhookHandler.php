<?php

namespace App\Services\Webhooks;

use App\Jobs\FetchShippingLabelJob;
use App\Jobs\ProcessBlingWebhookJob;
use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Models\ErpAccount;
use App\Models\FiscalNote;
use App\Services\Integrations\Contracts\WebhookHandlerInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Handler de webhooks do Bling ERP.
 *
 * Bling envia webhooks com HMAC-SHA256 no header X-Bling-Signature-256.
 * Formato: sha256=<hash>
 * Payload: JSON with { "event": "...", "data": { "id": ... } }
 *
 * Supported events:
 *  - order.created / order.updated / pedidos.*   -> ProcessBlingWebhookJob
 *  - invoice.created / nfe.*                     -> NF-e emitted, trigger label flow
 *  - stock.updated / estoque.*                   -> ProcessBlingWebhookJob
 *  - product.created / product.updated / produto.* -> ProcessBlingWebhookJob
 */
class BlingWebhookHandler implements WebhookHandlerInterface
{
    /**
     * Valida assinatura HMAC-SHA256 do Bling.
     * Header: X-Bling-Signature-256: sha256=<hash>
     * Hash = HMAC-SHA256(payload_json, client_secret)
     */
    public function validateSignature(Request $request): bool
    {
        $signature = $request->header("X-Bling-Signature-256");

        if (!$signature) {
            Log::warning("[BlingWebhook] Missing X-Bling-Signature-256 header");
            return false;
        }

        $secret = config("bling.client_secret");
        $payload = $request->getContent();
        $expected = "sha256=" . hash_hmac("sha256", $payload, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Extrai o topico do evento do Bling.
     */
    public function extractTopic(Request $request): string
    {
        $data = $request->all();

        // Bling v3 format: "event": "order.created", "invoice.created", etc.
        if (isset($data["event"])) {
            return $data["event"];
        }

        // Bling v3 legacy format
        if (isset($data["evento"])) {
            return $data["evento"];
        }

        return "unknown";
    }

    /**
     * Extrai o ID do recurso afetado.
     */
    public function extractResource(Request $request): string
    {
        $data = $request->all();
        return (string) ($data["data"]["id"] ?? $data["resourceId"] ?? $data["id"] ?? "");
    }

    /**
     * Extrai seller_id do webhook Bling.
     *
     * Bling v3 NAO envia userId no payload. Para multi-tenant, o seller_id
     * pode vir como query string (?seller_id=X) caso o webhook tenha sido
     * registrado com URL customizada por tenant.
     */
    public function extractUserId(Request $request): ?string
    {
        // Prioridade 1: query string (ex: URL registrada como .../bling?seller_id=X)
        $sellerId = $request->query('seller_id');
        if ($sellerId) {
            return (string) $sellerId;
        }

        // Bling v3 nao inclui userId no payload, mas tentar outros campos por compatibilidade
        return $request->input('userId')
            ?? $request->input('sellerId')
            ?? $request->input('idLoja')
            ?? null;
    }

    /**
     * Despacha o job de processamento do webhook.
     *
     * For certain events we handle inline (invoice.created triggers label flow)
     * before dispatching the standard job.
     */
    public function dispatchJob(string $topic, string $resource, ?string $userId): void
    {
        // -----------------------------------------------------------
        // order.updated — update order status in HubAI
        // -----------------------------------------------------------
        if ($this->isOrderUpdateEvent($topic)) {
            Log::info("[BlingWebhook] Order update event", [
                'topic'    => $topic,
                'resource' => $resource,
            ]);

            // Dispatch the standard job which already handles order status updates
            ProcessBlingWebhookJob::dispatch($topic, $resource, $userId);
            return;
        }

        // -----------------------------------------------------------
        // invoice.created / nfe.* — NF-e emitted in Bling
        // Triggers the shipping label flow for marketplaces (Shopee BR, etc.)
        // -----------------------------------------------------------
        if ($this->isInvoiceEvent($topic)) {
            Log::info("[BlingWebhook] Invoice/NF-e event received", [
                'topic'    => $topic,
                'resource' => $resource,
            ]);

            // Persistir NF-e em fiscal_notes (NOV-166-C)
            $this->handleNotaFiscal($resource, $userId, request()->all());
            $this->handleInvoiceCreated($resource, $userId);

            // Also dispatch the standard job for any additional processing
            ProcessBlingWebhookJob::dispatch($topic, $resource, $userId);
            return;
        }

        // -----------------------------------------------------------
        // All other events — dispatch generic job
        // -----------------------------------------------------------
        ProcessBlingWebhookJob::dispatch($topic, $resource, $userId);
    }

    // ---------------------------------------------------------------
    // Event type matchers
    // ---------------------------------------------------------------

    protected function isOrderUpdateEvent(string $topic): bool
    {
        // MÉDIO 2 fix: usar lista explícita + str_starts_with para prefixos seguros.
        // str_contains era amplo demais — 'backorder.cancelled' seria capturado como order.
        return in_array($topic, [
            'order.created',
            'order.updated',
            'order.deleted',
            'pedidos.updated',
            'pedidos.alteracao',
            'pedidos.incluido',
            'pedidos.excluido',
        ], true) || str_starts_with($topic, 'order.') || str_starts_with($topic, 'pedidos.');
    }

    protected function isInvoiceEvent(string $topic): bool
    {
        // MÉDIO 2 fix: usar lista explícita + str_starts_with para prefixos seguros.
        // str_contains era amplo demais — qualquer substring 'nfe' ou 'invoice' acionaria.
        return in_array($topic, [
            'invoice.created',
            'invoice.updated',
            'nfe.created',
            'nfe.emitida',
        ], true) || str_starts_with($topic, 'invoice.') || str_starts_with($topic, 'nfe.');
    }

    // ---------------------------------------------------------------
    // Invoice/NF-e handler — triggers label flow for Shopee BR
    // ---------------------------------------------------------------

    /**
     * When a NF-e is emitted in Bling, find the associated HubAI order
     * and dispatch the shipping label job (required for Shopee BR, etc.).
     *
     * Flow: NF-e emitted in Bling -> webhook -> find order -> update invoice fields
     *       -> dispatch FetchShippingLabelJob (if Shopee/marketplace order).
     */
    protected function handleInvoiceCreated(string $nfeResourceId, ?string $userId = null): void
    {
        if (empty($nfeResourceId)) {
            return;
        }

        try {
            // Resolve a conta Bling deste tenant para escopo do filtro.
            // Estrategia 1: usar seller_id se userId veio no payload do Bling.
            // Estrategia 2: fallback para a primeira conta ativa (comportamento original).
            $account = null;
            if ($userId) {
                $account = MarketplaceAccount::where('platform', 'bling')
                    ->where('seller_id', $userId)
                    ->whereNotNull('bling_access_token')
                    ->first();
            }
            if (!$account) {
                $account = MarketplaceAccount::where('platform', 'bling')
                    ->whereNotNull('bling_access_token')
                    ->first();
            }

            if (!$account) {
                Log::warning("[BlingWebhook] handleInvoiceCreated: no active Bling account found", [
                    'nfe_resource' => $nfeResourceId,
                    'userId'       => $userId,
                ]);
                return;
            }

            // Look for orders that may have this NF-e linked — SCOPED to this tenant client_id.
            // Strategy: find orders with source=bling that have pending label status
            $orders = Order::where('source', 'bling')
                ->where('client_id', $account->client_id)
                ->whereNotNull('external_order_id')
                ->whereNull('label_url')
                ->where('status', 'paid')
                ->get();

            foreach ($orders as $order) {
                // Dispatch label fetch job — the job itself will verify
                // if the NF-e data is complete before attempting label generation
                FetchShippingLabelJob::dispatch($order->id, 'bling_nfe_webhook');

                Log::info("[BlingWebhook] Label job dispatched for order after NF-e emission", [
                    'order_id'       => $order->id,
                    'nfe_resource'   => $nfeResourceId,
                    'order_number'   => $order->order_number,
                    'client_id'      => $account->client_id,
                ]);
            }

            // Also try to match by invoice_access_key or other identifiers
            // This will be refined once we know the exact Bling NF-e webhook payload structure
        } catch (\Throwable $e) {
            Log::error("[BlingWebhook] handleInvoiceCreated failed", [
                'nfe_resource' => $nfeResourceId,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    // ---------------------------------------------------------------
    // NOV-166-C: Persistência de NF-e em fiscal_notes
    // ---------------------------------------------------------------

    /**
     * Persiste NF-e recebida via webhook do Bling na tabela fiscal_notes.
     * Chamado dentro de handleInvoiceCreated() ANTES do fluxo de etiqueta.
     *
     * Detecta se a conta é ErpAccount (fornecedor) → source=bling_supplier
     * ou MarketplaceAccount (seller) → source=bling_seller.
     */
    protected function handleNotaFiscal(string $nfeResourceId, ?string $userId, array $rawData): void
    {
        if (empty($nfeResourceId)) {
            return;
        }

        try {
            // Determinar source: ErpAccount (fornecedor) ou MarketplaceAccount (seller)
            $source = 'bling_supplier'; // default
            $supplierId = null;
            $clientId = null;

            if ($userId) {
                $erp = ErpAccount::where('platform', 'bling')
                    ->where('external_id', $userId)
                    ->first();
                if ($erp) {
                    $source = 'bling_supplier';
                    $supplierId = $erp->supplier_id ?? null;
                } else {
                    $account = MarketplaceAccount::where('platform', 'bling')
                        ->where('seller_id', $userId)
                        ->first();
                    if ($account) {
                        $source = 'bling_seller';
                        $clientId = $account->client_id ?? null;
                    }
                }
            }

            // Extrair dados do payload
            $nfeData = $rawData['data'] ?? $rawData;
            $numeroPedido = $nfeData['numeroPedido'] ?? null;
            $chave = $nfeData['chaveAcesso'] ?? null;
            $numero = $nfeData['numero'] ?? null;
            $serie = $nfeData['serie'] ?? null;
            $valor = $nfeData['valorNota'] ?? null;
            $pdfUrl = $nfeData['linkDanfe'] ?? null;
            $xmlUrl = $nfeData['linkXmlDanfe'] ?? null;
            $externalId = (string) ($nfeData['id'] ?? $nfeResourceId);

            // Buscar pedido associado
            $order = null;
            if ($numeroPedido) {
                $order = Order::where('order_number', $numeroPedido)->first()
                    ?? Order::where('external_order_id', $numeroPedido)->first();
            }

            if (!$order) {
                Log::warning('[BlingWebhook] handleNotaFiscal: pedido não encontrado', [
                    'nfe_resource'  => $nfeResourceId,
                    'numero_pedido' => $numeroPedido,
                ]);
                return;
            }

            FiscalNote::updateOrCreate(
                ['order_id' => $order->id, 'source' => $source],
                [
                    'supplier_id' => $supplierId,
                    'client_id'   => $clientId,
                    'nf_key'      => $chave,
                    'nf_number'   => $numero,
                    'nf_series'   => $serie,
                    'status'      => 'issued',
                    'issued_at'   => now(),
                    'value'       => $valor,
                    'xml_url'     => $xmlUrl,
                    'pdf_url'     => $pdfUrl,
                    'external_id' => $externalId,
                    'raw_data'    => $nfeData,
                ]
            );

            // Atualizar order com chave NF
            $order->update([
                'nf_status' => 'issued',
                'nf_key'    => $chave,
            ]);

            Log::info('[BlingWebhook] fiscal_note criada/atualizada via webhook', [
                'order_id'   => $order->id,
                'nf_number'  => $numero,
                'source'     => $source,
            ]);

        } catch (\Throwable $e) {
            Log::error('[BlingWebhook] handleNotaFiscal falhou', [
                'nfe_resource' => $nfeResourceId,
                'error'        => $e->getMessage(),
            ]);
        }
    }

}
