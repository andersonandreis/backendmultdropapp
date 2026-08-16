<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\TenantWebhookEndpoint;
use App\Models\WebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * MUL-177: fan-out de webhook de pedido com payload montado na EXECUCAO.
 *
 * Antes o payload era montado sincrono dentro do OrderObserver::created()
 * — ou seja, DENTRO do Order::create(), antes dos OrderItem::create() dos
 * importadores (BlingOrderSync etc.) rodarem. O tenant recebia order.created
 * com items:[] e sem comprador/rastreio, e como pedidos importados nascem no
 * status final (sem update posterior), os dados nunca chegavam.
 *
 * Este job roda com delay (30s para order.created) e le o pedido fresco do
 * banco, entao os items ja existem quando o payload e montado.
 */
class FanoutOrderWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // retry de entrega e responsabilidade do DispatchWebhookJob

    public function __construct(
        public readonly int $orderId,
        public readonly string $event,
        public readonly array $extra = [],
        public readonly ?string $onlyTenantId = null
    ) {}

    public function handle(): void
    {
        $order = Order::withoutGlobalScopes()->find($this->orderId);
        if (!$order || !$order->supplier_id) {
            return;
        }

        // Tenants que enxergam esse supplier
        $tenantIds = DB::table('tenant_supplier')
            ->where('supplier_id', $order->supplier_id)
            ->pluck('tenant_id');
        if ($tenantIds->isEmpty()) {
            return;
        }

        // INF-053 letra D: em events de UPDATE (order.updated + order.status_changed),
        // filtrar endpoints pra: origem do pedido (orders.tenant_slug) + tenant do fornecedor.
        // Motivo: broadcast pra TODAS WLs conectadas ao supplier faz sellerglobal receber
        // update de pedido origem=fornecefy — nao e dono, ficaria sync fantasma.
        // Em order.created mantem broadcast (marketplace novo pode aparecer em qualquer WL).
        $isUpdateEvent = in_array($this->event, ['order.updated', 'order.status_changed'], true);
        if ($isUpdateEvent && !empty($order->tenant_slug)) {
            // INF-053 letra D fix: heuristica original usava legacy_empresa_id do supplier
            // vs tenant, mas nem sempre batem (JT: supplier.legacy_empresa_id=53, tenant
            // jtdrop.legacy_empresa_id=17). Fix: matchar por slug — supplier.slug ==
            // tenant.slug identifica o tenant do fornecedor primario (ex: sup jtdrop <-> tenant jtdrop).
            $originTenantId = DB::table('tenants')->where('slug', $order->tenant_slug)->value('id');
            $supplierSlug = DB::table('suppliers')->where('id', $order->supplier_id)->value('slug');
            $filteredIds = $tenantIds->filter(function ($tid) use ($originTenantId, $supplierSlug) {
                // Manter: (a) tenant de origem OU (b) tenant cujo slug == slug do supplier.
                if ($tid === $originTenantId) return true;
                if (!$supplierSlug) return false;
                $tenantSlug = DB::table('tenants')->where('id', $tid)->value('slug');
                return $tenantSlug && $tenantSlug === $supplierSlug;
            });
            // Fallback: se esvaziou (dado historico incompleto), mantem lista completa.
            // MUL-330: tambem cai no fallback quando o filtro sobrou APENAS tenant que nao
            // assina este evento — caso do supplier 30, onde suppliers.slug='multdrop' bate
            // com um tenant que so tem endpoints federation.*, enquanto quem recebe pedido e
            // o tenant 'multdrop.app'. Sem isto o update morria calado (221 pedidos medidos
            // em 04/08/2026). Seguro por construcao: so age onde hoje a entrega e zero.
            $temDestinoReal = $filteredIds->isNotEmpty() && TenantWebhookEndpoint::query()
                ->whereIn('tenant_id', $filteredIds->all())
                ->where('active', true)
                ->get()
                ->contains(fn (TenantWebhookEndpoint $ep) => $ep->subscribesTo($this->event));

            if ($temDestinoReal) {
                $tenantIds = $filteredIds->values();
            }
        }

        $endpoints = TenantWebhookEndpoint::query()
            ->whereIn('tenant_id', $tenantIds)
            ->where('active', true)
            ->get()
            ->filter(fn (TenantWebhookEndpoint $ep) => $ep->subscribesTo($this->event));

        // INF-039: redispatch cirurgico — entrega apenas pros endpoints do tenant pedido
        if ($this->onlyTenantId !== null) {
            $endpoints = $endpoints->where('tenant_id', $this->onlyTenantId);
        }

        if ($endpoints->isEmpty()) {
            return;
        }

        // MUL-339: itens, explosao de kit e indice de SKU do anuncio saem da classe compartilhada.
        // `preparar()` roda UMA vez por pedido — tem efeito colateral (explodeOrder) e nao pode ir
        // para dentro do laco de endpoints.
        $preparado       = \App\Support\PayloadDoPedidoParaWl::preparar($order, $this->event);
        $items           = $preparado['items'];
        $hubExplodesKits = $preparado['hubExplodesKits'];

        foreach ($endpoints as $ep) {
            // MUL-339: montado pela mesma classe que o DispatchTenantOrderWebhookJob usa. Os dois
            // jobs mandavam formatos diferentes e o WL precisou de remendo (MUL-310) para aceitar
            // o segundo — depois de ele virar 422 catorze mil vezes so em julho.
            $payload = \App\Support\PayloadDoPedidoParaWl::montar(
                $order,
                $items,
                $hubExplodesKits,
                $ep->tenant_id,
                $this->event,
                $this->extra
            );

            $idemKey = "ord_{$order->id}:{$ep->id}:{$this->event}:" . substr((string) ($this->extra['to_status'] ?? ''), 0, 32) . ':' . now()->timestamp;

            // MUL-339: um endpoint com problema nao pode derrubar os outros. Antes, a excecao
            // subia e abortava o foreach — os endpoints seguintes ficavam sem entrega, e a perda
            // era invisivel porque a linha em webhook_deliveries nunca chegava a existir.
            try {
                $delivery = WebhookDelivery::create([
                    'endpoint_id'     => $ep->id,
                    'event'           => $this->event,
                    'payload'         => $payload,
                    'idempotency_key' => $idemKey,
                    'status'          => WebhookDelivery::STATUS_PENDING,
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                // Mesmo pedido, endpoint, evento e segundo: o fanout disparou duas vezes para o
                // mesmo fato. A entrega ja esta enfileirada — e' para pular, nao para falhar.
                // O timestamp na chave e' proposital: garante que o MESMO pedido possa ser
                // entregue de novo mais tarde, quando o status mudar de novo.
                // Log::warning e nao info: o hub roda com LOG_LEVEL=warning e descarta info.
                // Este skip precisa ser mensuravel — hoje ele acontece e ninguem ve.
                Log::warning('[FanoutOrderWebhookJob] entrega ja enfileirada neste segundo — pulando', [
                    'order_id'        => $order->id,
                    'event'           => $this->event,
                    'endpoint_id'     => $ep->id,
                    'idempotency_key' => $idemKey,
                ]);
                continue;
            } catch (\Throwable $e) {
                Log::error('[FanoutOrderWebhookJob] falha ao criar delivery — outros endpoints seguem', [
                    'order_id'    => $order->id,
                    'event'       => $this->event,
                    'endpoint_id' => $ep->id,
                    'error'       => $e->getMessage(),
                ]);
                continue;
            }

            DispatchWebhookJob::dispatch($delivery->id)->onQueue(DispatchWebhookJob::queueFor($delivery));

            Log::info('[FanoutOrderWebhookJob] delivery enfileirado', [
                'order_id'    => $order->id,
                'event'       => $this->event,
                'delivery_id' => $delivery->id,
                'items'       => count($items),
            ]);
        }
    }
}
