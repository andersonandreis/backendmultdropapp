<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantWebhookEndpoint;
use App\Models\WebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Fase 1 — Stub de despacho de webhook por pedido importado.
 *
 * Fluxo:
 *   1. Recebe orderId + event (ex: 'order.created', 'order.updated')
 *   2. Resolve o tenant via orders.tenant_slug
 *   3. Busca endpoints ativos do tenant que subscrevem ao event
 *   4. Para cada endpoint, cria um WebhookDelivery e delega ao DispatchWebhookJob
 *
 * Este job é um STUB — enquanto tenant_webhook_endpoints.url estiver vazio
 * para os tenants atuais (fornecefy, multdrop.app), nenhuma entrega é feita.
 * A ativação real ocorre quando o webhook_url for configurado no painel.
 *
 * Decisão de arquitetura: usa o sistema de delivery existente (WebhookDelivery +
 * DispatchWebhookJob) em vez de HTTP direto, para aproveitar retry exponencial
 * e log de entregas já implementados.
 *
 * @see DispatchWebhookJob — executa a entrega HTTP com retry
 * @see TenantWebhookEndpoint — configuração do endpoint por tenant
 */
class DispatchTenantOrderWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // retry é responsabilidade do DispatchWebhookJob

    public function __construct(
        public readonly int $orderId,
        public readonly string $event
    ) {}

    public function handle(): void
    {
        $order = Order::find($this->orderId);
        if (!$order) {
            Log::debug('DispatchTenantOrderWebhookJob: pedido não encontrado', [
                'order_id' => $this->orderId,
            ]);
            return;
        }

        // INF-039: coletar tenants elegiveis — (1) tenant_slug da order + (2) tenants do supplier (via tenant_supplier)
        $tenantSlugs = collect([$order->tenant_slug])->filter();
        if ($order->supplier_id) {
            $supplierTenants = \Illuminate\Support\Facades\DB::table('tenant_supplier as ts')
                ->join('tenants as t', 't.id', '=', 'ts.tenant_id')
                ->where('ts.supplier_id', $order->supplier_id)
                ->where('t.status', 'active')
                ->pluck('t.slug');
            $tenantSlugs = $tenantSlugs->merge($supplierTenants)->unique()->values();
        }
        if ($tenantSlugs->isEmpty()) {
            return;
        }

        $tenants = Tenant::whereIn('slug', $tenantSlugs)->where('status', 'active')->get();
        if ($tenants->isEmpty()) {
            return;
        }

        $endpoints = TenantWebhookEndpoint::whereIn('tenant_id', $tenants->pluck('id'))
            ->where('active', true)
            ->get()
            ->filter(fn(TenantWebhookEndpoint $ep) => $ep->subscribesTo($this->event));

        if ($endpoints->isEmpty()) {
            // Sem endpoints configurados ainda — esperado durante Fase 1.
            Log::debug('DispatchTenantOrderWebhookJob: sem endpoints para tenant', [
                'tenants' => $tenantSlugs->all(),
                'event'  => $this->event,
            ]);
            return;
        }

        // MUL-339: itens, canonical_status e supplier_total sao montados UMA vez por pedido —
        // `preparar()` explode o kit quando a autoridade e do hub, e isso nao pode repetir por
        // endpoint.
        $preparado = \App\Support\PayloadDoPedidoParaWl::preparar($order, $this->event);

        foreach ($endpoints as $endpoint) {
            $hubDeliveryId = Str::uuid()->toString();
            $tenantEndpoint = $tenants->firstWhere('id', $endpoint->tenant_id);
            $destTenantSlug = $tenantEndpoint?->slug;

            // MUL-339: o mesmo payload do FanoutOrderWebhookJob, pela mesma classe. Antes daqui
            // saiam 11 campos na raiz — sem itens, sem canonical_status, sem supplier_total — e
            // este e o unico canal dos importadores de Shopee e Mercado Livre. O WL recebia um
            // aviso de que o pedido mudou sem receber o pedido.
            $payload = \App\Support\PayloadDoPedidoParaWl::montar(
                $order,
                $preparado['items'],
                $preparado['hubExplodesKits'],
                $endpoint->tenant_id,
                $this->event
            );

            // os campos que o formato antigo trazia na raiz seguem presentes: existe receptor
            // que le hub_order_id/tenant, e tirar isso quebraria quem ainda nao foi atualizado.
            $payload['tenant']             = $destTenantSlug;
            $payload['hub_delivery_id']    = $hubDeliveryId;
            $payload['hub_order_id']       = $order->id;
            $payload['order_id']           = $order->id;
            $payload['supplier_id']        = $order->supplier_id;
            $payload['origin_tenant']      = $order->origin_tenant_slug ?? $order->tenant_slug;
            $payload['origin_tenant_slug'] = $order->origin_tenant_slug ?? $order->tenant_slug;
            $payload['status']             = $order->status;
            $payload['total']              = $order->total;

            $delivery = WebhookDelivery::create([
                'endpoint_id'      => $endpoint->id,
                'event'            => $this->event,
                'payload'          => $payload,
                'status'           => WebhookDelivery::STATUS_PENDING,
                'attempt'          => 0,
                'idempotency_key'  => $hubDeliveryId,
            ]);

            DispatchWebhookJob::dispatch($delivery->id)->onQueue(DispatchWebhookJob::queueFor($delivery));

            Log::info('DispatchTenantOrderWebhookJob: delivery enfileirado', [
                'tenant'      => $destTenantSlug,
                'event'       => $this->event,
                'order_id'    => $order->id,
                'delivery_id' => $delivery->id,
                'endpoint'    => $endpoint->url,
            ]);
        }
    }
}
