<?php

namespace Tests\Feature\Shopee;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Models\User;
use App\Models\Client;
use App\Models\MarketplaceAccount;

/**
 * NOV-046-H — Teste de isolamento de tenant no fluxo Shopee no_state.
 *
 * Verifica que handleNoStateCallback nao atribui tokens de uma WL a outra
 * quando o shop_id existe mas pertence a um servico diferente (multdrop, fornecefy).
 */
class NoStateCallbackTenantIsolationTest extends TestCase
{
    use DatabaseTransactions;

    private const CALLBACK_URL = "/shopee/oauth-callback";
    private const FAKE_SHOP_ID = "999888777";
    private const FAKE_CODE    = "fakecode_nov046h_999888777";

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            "partner.shopeemobile.com/*"  => Http::response(["access_token" => "at_test", "refresh_token" => "rt_test", "expire_in" => 3600, "error" => ""], 200),
            "goolhub.io/api/bridge/*"     => Http::response(["success" => true], 200),
            "api.multdrop.app/*"          => Http::response(["success" => true], 200),
            "api.fornecefy.io/*"          => Http::response(["success" => true], 200),
            "*"                           => Http::response([], 200),
        ]);
    }

    /**
     * Cria um Client valido com User associado para satisfazer a FK constraint.
     */
    private function makeClient(): Client
    {
        $user = User::factory()->create(["role" => "client", "is_active" => true]);
        return Client::updateOrCreate(
            ["user_id" => $user->id],
            [
                "company_name" => "Empresa Teste NOV-046-H",
                "is_active"    => true,
            ]
        );
    }

    /**
     * Caso 1: shop_id pertence a tenant diferente (service=multdrop) — deve rejeitar com cross_tenant_rejected.
     */
    public function test_no_state_callback_rejects_cross_tenant_shop_id(): void
    {
        $client = $this->makeClient();

        $account = MarketplaceAccount::create([
            "client_id"    => $client->id,
            "platform"     => "shopee",
            "shop_id"      => self::FAKE_SHOP_ID,
            "service"      => "multdrop",
            "status"       => "active",
            "account_name" => "Loja Multdrop Teste",
        ]);

        $response = $this->get(self::CALLBACK_URL . "?" . http_build_query([
            "code"    => self::FAKE_CODE,
            "shop_id" => self::FAKE_SHOP_ID,
        ]));

        $response->assertRedirect();
        $location = $response->headers->get("Location", "");

        $this->assertStringContainsString("cross_tenant_rejected", $location,
            "Callback cross-tenant deveria ser rejeitado com reason=cross_tenant_rejected");
        $this->assertStringNotContainsString("shopee=connected", $location,
            "Callback cross-tenant NAO pode resultar em shopee=connected");

        // Tokens NAO devem ter sido gravados na account do outro tenant
        $account->refresh();
        $this->assertNull($account->access_token,
            "access_token NAO deve ser sobrescrito em conta de outro tenant");
    }

    /**
     * Caso 2: shop_id pertence ao mesmo tenant (service=hubai) — troca de tokens deve ocorrer.
     */
    public function test_no_state_callback_accepts_same_tenant_shop_id(): void
    {
        $client = $this->makeClient();

        $account = MarketplaceAccount::create([
            "client_id"    => $client->id,
            "platform"     => "shopee",
            "shop_id"      => self::FAKE_SHOP_ID,
            "service"      => "hubai",
            "status"       => "active",
            "account_name" => "Loja HubAI Teste",
        ]);

        $response = $this->get(self::CALLBACK_URL . "?" . http_build_query([
            "code"    => self::FAKE_CODE,
            "shop_id" => self::FAKE_SHOP_ID,
        ]));

        $response->assertRedirect();
        $location = $response->headers->get("Location", "");
        $this->assertStringNotContainsString("cross_tenant_rejected", $location,
            "Callback do mesmo tenant (hubai) NAO deve ser rejeitado");
    }

    /**
     * Caso 3: callback com state valido (64-char hex token) — fluxo normal nao impactado pelo fix.
     */
    public function test_callback_with_valid_state_token_bypasses_no_state_flow(): void
    {
        $stateToken = bin2hex(random_bytes(32));
        try {
            DB::table("shopee_oauth_states")->insert([
                "state_token" => $stateToken,
                "service"     => "hubai",
                "user_id"     => 1,
                "expires_at"  => now()->addMinutes(10),
                "created_at"  => now(),
                "updated_at"  => now(),
            ]);
        } catch (\Throwable $e) {
            $this->markTestSkipped("Tabela shopee_oauth_states nao disponivel no banco de teste: " . $e->getMessage());
        }

        $response = $this->get(self::CALLBACK_URL . "?" . http_build_query([
            "code"    => self::FAKE_CODE,
            "shop_id" => self::FAKE_SHOP_ID,
            "state"   => $stateToken,
        ]));

        $response->assertRedirect();
        $location = $response->headers->get("Location", "");
        $this->assertStringNotContainsString("cross_tenant_rejected", $location,
            "Fluxo com state valido nao deve ser afetado pelo fix de cross-tenant");
    }
}
