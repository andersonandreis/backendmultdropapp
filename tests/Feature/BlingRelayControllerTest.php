<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\MarketplaceAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BlingRelayControllerTest
 *
 * Cobre o endpoint POST /api/oauth/bling/wl-relay (MUL-029).
 *
 * Cenarios:
 *   1. HMAC invalida -> 401 invalid_signature
 *   2. HMAC ausente -> 401 invalid_signature
 *   3. Tenant mismatch (cross-tenant) -> 403 tenant_mismatch
 *   4. Payload incompleto (sem client_id) -> 422 missing_fields
 *   5. Client nao encontrado -> 404 client_not_found
 *   6. Payload valido -> 200 + MarketplaceAccount upsertada
 *   7. Segundo POST mesmo client -> 200 + mesmo account_id (upsert)
 *
 * Nota: supplier_id enviado como null para evitar FK constraint em banco de teste
 * (supplier_id eh nullable na tabela marketplace_accounts).
 */
class BlingRelayControllerTest extends TestCase
{
    use DatabaseTransactions;

    private string $secret = 'test-secret-hmac';
    private string $endpoint = '/api/oauth/bling/wl-relay';
    private string $appTenant = 'hubai';

    protected function setUp(): void
    {
        parent::setUp();
        config(['bling.relay_secret' => $this->secret]);
        config(['bling.app_tenant'   => $this->appTenant]);
    }

    private function makePayload(array $overrides = []): array
    {
        return array_merge([
            'tenant'        => $this->appTenant,
            'client_id'     => 1,
            'supplier_id'   => null,
            'access_token'  => 'tok_test_access_123',
            'refresh_token' => 'tok_test_refresh_456',
            'expires_in'    => 21600,
            'scope'         => '',
            'account_name'  => 'Teste Bling',
            'relayed_by'    => 'api.hubai.io',
        ], $overrides);
    }

    private function signedPost(array $payload, ?string $sig = null): \Illuminate\Testing\TestResponse
    {
        $body    = json_encode($payload);
        $sigReal = $sig ?? hash_hmac('sha256', $body, $this->secret);

        return $this->withHeaders([
            'Content-Type'       => 'application/json',
            'X-HubAI-Bridge-Sig' => $sigReal,
        ])->postJson($this->endpoint, $payload);
    }

    #[Test]
    public function it_rejects_missing_hmac_signature(): void
    {
        $response = $this->postJson($this->endpoint, $this->makePayload());
        $response->assertStatus(401)->assertJson(['error' => 'invalid_signature']);
    }

    #[Test]
    public function it_rejects_invalid_hmac_signature(): void
    {
        $response = $this->signedPost($this->makePayload(), 'bad_sig_000000000000000000000000');
        $response->assertStatus(401)->assertJson(['error' => 'invalid_signature']);
    }

    #[Test]
    public function it_rejects_cross_tenant_payload(): void
    {
        $response = $this->signedPost($this->makePayload(['tenant' => 'multdrop']));
        $response->assertStatus(403)->assertJson(['error' => 'tenant_mismatch']);
    }

    #[Test]
    public function it_rejects_missing_required_fields(): void
    {
        $response = $this->signedPost($this->makePayload(['client_id' => 0]));
        $response->assertStatus(422)->assertJson(['error' => 'missing_fields']);
    }

    #[Test]
    public function it_returns_404_when_client_not_found(): void
    {
        $response = $this->signedPost($this->makePayload(['client_id' => 99999]));
        $response->assertStatus(404)->assertJson(['error' => 'client_not_found']);
    }

    #[Test]
    public function it_saves_marketplace_account_on_valid_relay(): void
    {
        $user   = User::factory()->create();
        $client = Client::factory()->create(['user_id' => $user->id]);

        $payload  = $this->makePayload(['client_id' => $client->id]);
        $response = $this->signedPost($payload);

        $response->assertStatus(200)->assertJsonStructure(['success', 'tenant', 'account_id']);

        $this->assertDatabaseHas('marketplace_accounts', [
            'client_id'    => $client->id,
            'supplier_id'  => null,
            'platform'     => 'bling',
            'status'       => 'active',
            'account_name' => 'Teste Bling',
        ]);
    }

    #[Test]
    public function it_upserts_account_on_second_relay(): void
    {
        $user   = User::factory()->create();
        $client = Client::factory()->create(['user_id' => $user->id]);

        $payload = $this->makePayload(['client_id' => $client->id]);

        $r1 = $this->signedPost($payload);
        $r1->assertStatus(200);
        $firstAccountId = $r1->json('account_id');

        $r2 = $this->signedPost($this->makePayload([
            'client_id'    => $client->id,
            'access_token' => 'tok_updated_789',
        ]));
        $r2->assertStatus(200);

        $this->assertEquals($firstAccountId, $r2->json('account_id'));

        $this->assertEquals(1, MarketplaceAccount::where([
            'client_id'   => $client->id,
            'platform'    => 'bling',
            'supplier_id' => null,
        ])->count());
    }
}
