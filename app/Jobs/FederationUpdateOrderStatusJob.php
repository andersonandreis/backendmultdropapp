<?php

namespace App\Jobs;

use App\Models\FederationSyncLog;
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
 * NOV-171-B -- Processa atualizacao de status de pedido vindo de um WL.
 * DECISAO RUAN: WL MANDA -- hub aceita incondicionalmente (last-write-wins).
 * Retorna 200 imediato (regra 8 do 00-INDEX) -- FederationOrderController dispara este job.
 */
class FederationUpdateOrderStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(
        public readonly int     $hubOrderId,
        public readonly string  $tenantSlug,
        public readonly string  $newStatus,
        public readonly string  $sourceWl,
        public readonly ?string $changedBy = null,
        public readonly ?string $notes = null,
    ) {}

    public function handle(): void
    {
        /** @var Order|null $order */
        $order = Order::withoutGlobalScopes()->find($this->hubOrderId);

        if (! $order) {
            Log::warning("[FederationUpdateOrderStatusJob] pedido nao encontrado", [
                "hub_order_id" => $this->hubOrderId,
                "tenant"       => $this->tenantSlug,
            ]);
            return;
        }

        // Validar acesso cross-tenant via supplier (seguranca)
        if ($order->origin_tenant_slug && $order->origin_tenant_slug !== $this->tenantSlug) {
            $hasAccess = DB::table("tenant_supplier as ts")
                ->join("tenants as t", "t.id", "=", "ts.tenant_id")
                ->where("t.slug", $this->tenantSlug)
                ->where("ts.supplier_id", $order->supplier_id)
                ->exists();

            if (! $hasAccess) {
                Log::warning("[FederationUpdateOrderStatusJob] acesso negado cross-tenant", [
                    "hub_order_id"      => $this->hubOrderId,
                    "order_tenant"      => $order->origin_tenant_slug,
                    "requesting_tenant" => $this->tenantSlug,
                ]);
                return;
            }
        }

        $oldStatus  = $order->status;
        // WL MANDA -- aceitar incondicionalmente (LEGACY_SYNC_ENABLED=false protege sync legado)
        $order->status = $this->newStatus;
        $order->save();

        FederationSyncLog::recordOrSkip(
            direction:    "wl_to_hub",
            entityType:   "order_status",
            entityId:     $order->id,
            targetTenant: $this->tenantSlug,
            status:       "success",
            payloadHash:  hash("sha256", $order->id.":$this->newStatus:$this->sourceWl:".now()->toIso8601String()),
        );

        // Registrar no historico de status usando o schema real da tabela
        // Colunas: field, from_status, to_status, actor_type, actor_id, origin, metadata
        if (DB::getSchemaBuilder()->hasTable("order_status_history")) {
            DB::table("order_status_history")->insert([
                "order_id"    => $order->id,
                "field"       => "order_status",
                "from_status" => $oldStatus,
                "to_status"   => $this->newStatus,
                "actor_type"  => "federation",
                "actor_id"    => $this->sourceWl,
                "origin"      => "federation",
                "metadata"    => json_encode([
                    "source_wl"  => $this->sourceWl,
                    "changed_by" => $this->changedBy,
                    "notes"      => $this->notes,
                ]),
                "created_at"  => now(),
            ]);
        }

        Log::info("[FederationUpdateOrderStatusJob] status atualizado", [
            "hub_order_id" => $order->id,
            "old_status"   => $oldStatus,
            "new_status"   => $this->newStatus,
            "source_wl"    => $this->sourceWl,
        ]);

        // Propagar aos OUTROS WLs (anti-eco via source_wl no payload)
        $this->propagateToOtherWls($order, $oldStatus);
    }

    private function propagateToOtherWls(Order $order, string $oldStatus): void
    {
        $federationEvent = config("federation.order_event", "federation.order.update");

        $endpoints = TenantWebhookEndpoint::query()
            ->where("active", true)
            ->where(function ($q) use ($federationEvent) {
                $q->whereJsonContains("events", $federationEvent)
                  ->orWhereJsonContains("events", "*");
            })
            ->with("tenant")
            ->get();

        if ($endpoints->isEmpty()) {
            return;
        }

        $payload = [
            "event"              => $federationEvent,
            "hub_order_id"       => $order->id,
            "status"             => $this->newStatus,
            "old_status"         => $oldStatus,
            "source_wl"          => $this->sourceWl,
            "origin_tenant_slug" => $order->origin_tenant_slug,
            "supplier_id"        => $order->supplier_id,
            "changed_at"         => now()->toIso8601String(),
        ];

        foreach ($endpoints as $endpoint) {
            $targetSlug = $endpoint->tenant?->slug ?? "";
            // Anti-eco: nao reenviar ao WL de origem
            if ($targetSlug === $this->sourceWl) {
                continue;
            }

            $delivery = WebhookDelivery::create([
                "endpoint_id"     => $endpoint->id,
                "event"           => $federationEvent,
                "payload"         => $payload,
                "idempotency_key" => (string) Str::uuid(),
                "attempt"         => 0,
                "status"          => WebhookDelivery::STATUS_PENDING,
            ]);

            DispatchWebhookJob::dispatch($delivery->id)->onQueue(DispatchWebhookJob::queueFor($delivery));

            Log::info("[FederationUpdateOrderStatusJob] propagando a WL", [
                "hub_order_id"  => $order->id,
                "target_tenant" => $targetSlug,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("[FederationUpdateOrderStatusJob] falhou", [
            "hub_order_id" => $this->hubOrderId,
            "error"        => $exception->getMessage(),
        ]);
        FederationSyncLog::recordOrSkip(
            direction:    "wl_to_hub",
            entityType:   "order_status",
            entityId:     $this->hubOrderId,
            targetTenant: $this->tenantSlug,
            status:       "failed",
            errorMessage: substr($exception->getMessage(), 0, 500),
        );
    }
}