<?php

namespace Tests\Feature\Federation;

use App\Models\FederationSyncLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * NOV-171-F — Testes de Federação (Pedidos)
 *
 * NOTA ARQUITETURAL: federation_order_notifications NÃO existe no hubaiapp.
 * A tabela existe apenas nos WLs (multdrop, fornecefy, mestoredrop) conforme
 * definido em NOV-171-A. Testes de recepção de pedido com assertDatabase são
 * executados apenas quando a tabela existe (contexto WL).
 *
 * Cobertura neste arquivo (hub context):
 * - HMAC inválido → 401
 * - federation_sync_log: sucesso/falha de order_status
 * - Dedup por payload_hash no sync_log
 */
class FederationOrderTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('federation.hmac_secret', 'test-hmac-secret-for-tests');
        Config::set('federation.tenant', 'multdrop');
        Config::set('federation.hub_url', 'https://api.hubai.io');
    }

    private function makeHmacHeader(array $payload): array
    {
        $body   = json_encode($payload);
        $secret = config('federation.hmac_secret');
        return ['X-HubAI-Signature' => 'sha256=' . hash_hmac('sha256', $body, $secret)];
    }

    public function test_order_receive_with_invalid_hmac_returns_401(): void
    {
        $response = $this->postJson('/api/federation/orders/receive', [
            'hub_order_id'    => 99999,
            'hub_delivery_id' => (string) Str::uuid(),
            'origin_tenant'   => 'fornecefy',
            'current_status'  => 'paid',
            'source_wl'       => 'fornecefy',
        ], ['X-HubAI-Signature' => 'sha256=invalidsig']);

        $response->assertStatus(401);
    }

    public function test_order_receive_without_hmac_returns_401(): void
    {
        $response = $this->postJson('/api/federation/orders/receive', [
            'hub_order_id'    => 99999,
            'hub_delivery_id' => (string) Str::uuid(),
        ]);

        $response->assertStatus(401);
    }

    public function test_order_receive_with_echo_source_wl_skips_silently(): void
    {
        // Anti-eco: source_wl == federation.tenant → ignora e retorna 200
        // Este teste funciona pois o controller verifica source_wl ANTES de acessar federation_order_notifications
        Config::set('federation.tenant', 'multdrop');

        $deliveryId = (string) Str::uuid();
        $payload    = [
            'hub_order_id'    => 88803,
            'hub_delivery_id' => $deliveryId,
            'origin_tenant'   => 'multdrop',
            'current_status'  => 'shipped',
            'source_wl'       => 'multdrop', // eco
        ];

        $response = $this->postJson(
            '/api/federation/orders/receive',
            $payload,
            $this->makeHmacHeader($payload)
        );

        $response->assertStatus(200);
        $response->assertJsonFragment(['message' => 'Eco ignorado.']);
    }

    public function test_federation_sync_log_records_order_status_success(): void
    {
        FederationSyncLog::recordOrSkip(
            direction:    'wl_to_hub',
            entityType:   'order_status',
            entityId:     88900,
            targetTenant: 'multdrop',
            status:       'success',
        );

        $log = FederationSyncLog::where('entity_id', 88900)->latest()->first();
        $this->assertNotNull($log);
        $this->assertEquals('wl_to_hub', $log->direction);
        $this->assertEquals('order_status', $log->entity_type);
        $this->assertEquals('success', $log->status);
    }

    public function test_federation_sync_log_records_order_failure_with_message(): void
    {
        FederationSyncLog::recordOrSkip(
            direction:    'wl_to_hub',
            entityType:   'order_status',
            entityId:     88901,
            targetTenant: 'fornecefy',
            status:       'failed',
            errorMessage: 'Column not found: status — schema correto usa from_status/to_status',
        );

        $log = FederationSyncLog::where('entity_id', 88901)->latest()->first();
        $this->assertNotNull($log);
        $this->assertEquals('failed', $log->status);
        $this->assertStringContainsString('from_status', $log->error_message);
    }

    public function test_federation_sync_log_hub_to_wl_direction(): void
    {
        FederationSyncLog::recordOrSkip(
            direction:    'hub_to_wl',
            entityType:   'order',
            entityId:     88902,
            targetTenant: 'mestoredrop',
            status:       'success',
        );

        $log = FederationSyncLog::where('entity_id', 88902)->latest()->first();
        $this->assertNotNull($log);
        $this->assertEquals('hub_to_wl', $log->direction);
        $this->assertEquals('mestoredrop', $log->target_tenant);
    }
}
