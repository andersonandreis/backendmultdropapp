<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\ClientKit;
use App\Models\Tenant;
use App\Models\TenantWebhookEndpoint;
use App\Models\WebhookDelivery;
use App\Services\Federation\KitFederationPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * MUL-236 F2 — hub empurra kit atualizado pra WL de ORIGEM (source_tenant).
 * Nunca broadcast: só os endpoints do tenant dono da tela recebem
 * (mesma regra do swap — fanout por origem).
 *
 * Entrega via TenantWebhookEndpoint com evento federation.kit.sync
 * (WebhookDelivery + DispatchWebhookJob, HMAC sha256 padrão).
 */
class FederationPushKitJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(
        public readonly int $kitId,
        public readonly ?string $previousSku = null,
    ) {}

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(): void
    {
        if (config('federation.tenant') !== 'hubai') {
            return; // só o hub empurra kit
        }

        $kit = ClientKit::with('items')->find($this->kitId);
        if (! $kit || ! $kit->source_tenant) {
            return;
        }

        $client = Client::find($kit->client_id);
        if (! $client || ! $client->legacy_id_login) {
            Log::warning('[FederationPushKit] client sem legacy_id_login — sem como mapear no WL', [
                'kit_id'    => $this->kitId,
                'client_id' => $kit->client_id,
            ]);
            return;
        }

        $tenant = Tenant::where('slug', $kit->source_tenant)->first();
        if (! $tenant) {
            Log::warning('[FederationPushKit] tenant de origem não encontrado', [
                'kit_id'        => $this->kitId,
                'source_tenant' => $kit->source_tenant,
            ]);
            return;
        }

        $event = config('federation.kit_event', 'federation.kit.sync');

        $endpoints = TenantWebhookEndpoint::where('tenant_id', $tenant->id)
            ->where('active', true)
            ->get()
            ->filter(fn ($ep) => in_array($event, (array) $ep->events, true)
                || in_array('*', (array) $ep->events, true));

        if ($endpoints->isEmpty()) {
            Log::info('[FederationPushKit] nenhum endpoint kit.sync pro tenant — push ignorado', [
                'kit_id' => $this->kitId,
                'tenant' => $kit->source_tenant,
            ]);
            return;
        }

        $data = KitFederationPayload::build($client, $kit, $this->previousSku);

        foreach ($endpoints as $ep) {
            $payload = [
                'id'          => 'evt_' . Str::ulid(),
                'event'       => $event,
                'occurred_at' => now()->toIso8601String(),
                'tenant_id'   => $ep->tenant_id,
                'data'        => $data,
            ];

            $delivery = WebhookDelivery::create([
                'endpoint_id'     => $ep->id,
                'event'           => $event,
                'payload'         => $payload,
                'idempotency_key' => "kit_{$kit->id}:{$ep->id}:" . now()->timestamp,
                'status'          => WebhookDelivery::STATUS_PENDING,
            ]);

            DispatchWebhookJob::dispatch($delivery->id)->onQueue(DispatchWebhookJob::queueFor($delivery));

            Log::info('[FederationPushKit] delivery enfileirado', [
                'kit_id'      => $kit->id,
                'kit_sku'     => $kit->sku,
                'tenant'      => $kit->source_tenant,
                'delivery_id' => $delivery->id,
            ]);
        }
    }
}
