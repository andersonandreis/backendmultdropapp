<?php

namespace Tests\Feature\Federation;

use App\Models\FederationSyncLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * NOV-171-F — Testes de Federação (Catálogo)
 * Cobertura: autenticação, HMAC, anti-loop, dedup federation_sync_log
 */
class FederationCatalogTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('federation.tokens', [
            'multdrop'    => 'test-token-multdrop-valid',
            'fornecefy'   => 'test-token-fornecefy-valid',
            'mestoredrop' => 'test-token-mestoredrop-valid',
        ]);
        Config::set('federation.hmac_secret', 'test-hmac-secret-for-tests');
        Config::set('federation.tenant', 'hubai');
        Config::set('federation.hub_url', 'https://api.hubai.io');
    }

    public function test_push_endpoint_with_invalid_token_returns_401(): void
    {
        $response = $this->postJson('/api/federation/catalog/push', [
            'sku'            => 'TEST-AUTH-INVALID',
            'name'           => 'Produto Teste Auth',
            'price'          => 99.90,
            'stock'          => 5,
            'supplier_id'    => 1,
            'source_backend' => 'multdrop',
        ], ['Authorization' => 'Bearer TOKEN_INVALIDO_XYZ']);

        $response->assertStatus(401);
    }

    public function test_push_endpoint_without_token_returns_401(): void
    {
        $response = $this->postJson('/api/federation/catalog/push', [
            'sku'  => 'TEST-AUTH-NOTOKEN',
            'name' => 'Produto sem token',
        ]);

        $response->assertStatus(401);
    }

    public function test_catalog_receive_with_invalid_hmac_returns_401(): void
    {
        $response = $this->postJson('/api/federation/catalog/receive', [
            'sku'  => 'TEST-HMAC-INVALID',
            'name' => 'Produto HMAC inválido',
        ], ['X-HubAI-Signature' => 'sha256=invalidsignatureabc']);

        $response->assertStatus(401);
    }

    public function test_catalog_receive_without_hmac_header_returns_401(): void
    {
        $response = $this->postJson('/api/federation/catalog/receive', [
            'sku'  => 'TEST-NOHMAC',
            'name' => 'Produto sem HMAC',
        ]);

        $response->assertStatus(401);
    }

    public function test_federation_sync_log_records_success(): void
    {
        $initialCount = FederationSyncLog::count();

        FederationSyncLog::recordOrSkip(
            direction:    'wl_to_hub',
            entityType:   'product',
            entityId:     999001,
            targetTenant: 'multdrop',
            status:       'success',
            payloadHash:  hash('sha256', 'test-payload-qa-shield-001'),
        );

        $this->assertEquals($initialCount + 1, FederationSyncLog::count());

        $log = FederationSyncLog::where('entity_id', 999001)->latest()->first();
        $this->assertEquals('wl_to_hub', $log->direction);
        $this->assertEquals('product', $log->entity_type);
        $this->assertEquals('success', $log->status);
        $this->assertEquals('multdrop', $log->target_tenant);
    }

    public function test_federation_sync_log_skips_duplicate_payload_hash(): void
    {
        $hash = hash('sha256', 'unique-payload-for-dedup-test-qa-shield');
        // Limpar eventual registro anterior com esse hash
        FederationSyncLog::where('payload_hash', $hash)->delete();

        FederationSyncLog::recordOrSkip(
            direction:    'wl_to_hub',
            entityType:   'product',
            entityId:     999002,
            targetTenant: 'multdrop',
            status:       'success',
            payloadHash:  $hash,
        );

        $countAfterFirst = FederationSyncLog::where('payload_hash', $hash)->count();

        // Segundo chamada com mesmo hash — deve ser ignorado (skip)
        FederationSyncLog::recordOrSkip(
            direction:    'wl_to_hub',
            entityType:   'product',
            entityId:     999002,
            targetTenant: 'multdrop',
            status:       'success',
            payloadHash:  $hash,
        );

        $this->assertEquals($countAfterFirst, FederationSyncLog::where('payload_hash', $hash)->count());
    }

    public function test_federation_sync_log_records_failure(): void
    {
        FederationSyncLog::recordOrSkip(
            direction:    'wl_to_hub',
            entityType:   'product',
            entityId:     999003,
            targetTenant: 'fornecefy',
            status:       'failed',
            errorMessage: 'Erro simulado para teste QA Shield',
        );

        $log = FederationSyncLog::where('entity_id', 999003)->latest()->first();
        $this->assertNotNull($log);
        $this->assertEquals('failed', $log->status);
        $this->assertStringContainsString('Erro simulado', $log->error_message);
    }
}
