<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\ClientProduct;
use App\Models\MarketplaceAccount;
use App\Models\Supplier;
use App\Filament\App\Resources\ClientProductResource;
use App\Filament\App\Resources\MarketplaceAccountResource;

/**
 * P1 — Guards no Filament App
 *
 * Cobre os 6 cenarios de autorizacao da Fase 1.
 * Usa RefreshDatabase — requer banco de testes MySQL configurado em .env.testing.
 *
 * NOTA: 'excluido' e 'supplier_unit_cost' NAO estao em ClientProduct::$fillable.
 * ClientProduct::create() silencia ambas as colunas via mass-assignment protection.
 * Workaround: DB::table('client_products')->insert() para bypass de $fillable.
 * As colunas existem via migration 2026_05_15_100002_add_missing_client_products_columns.
 */
class Phase1GuardsTest extends TestCase
{
    use DatabaseTransactions;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeClientUser(Plan $plan, string $status = 'active'): User
    {
        $user = User::factory()->create(['role' => 'client', 'is_active' => true]);
        $client = Client::updateOrCreate(
            ['user_id' => $user->id],
            [
                'company_name' => 'Loja Teste',
                'document'     => fake()->numerify('###########'),
                'is_active'    => true,
            ]
        );
        Subscription::create([
            'client_id'            => $client->id,
            'plan_id'              => $plan->id,
            'status'               => $status,
            'current_period_start' => now(),
            'current_period_end'   => now()->addMonth(),
        ]);
        return $user->fresh(['client']);
    }

    private function makePlan(string $name, int $maxSkus): Plan
    {
        return Plan::create([
            'name'     => $name,
            'slug'     => \Illuminate\Support\Str::slug($name . '-' . uniqid()),
            'max_skus' => $maxSkus,
            'is_active' => true,
        ]);
    }

    /**
     * Insere ClientProduct via DB::table() para garantir que excluido e
     * supplier_unit_cost sejam gravados (ambos fora de $fillable).
     */
    private function insertClientProduct(int $clientId, string $sku, int $excluido): int
    {
        return DB::table('client_products')->insertGetId([
            'client_id'  => $clientId,
            'custom_sku' => $sku,
            'excluido'   => $excluido,
            'is_active'  => $excluido === 0 ? 1 : 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Teste 1 — canCreate bloqueia user sem client
    // -------------------------------------------------------------------------

    /** @test */
    public function it_blocks_canCreate_when_user_has_no_client(): void
    {
        $user = User::factory()->create(['role' => 'client', 'is_active' => true]);
        // user sem client associado

        $this->actingAs($user);
        Cache::flush();

        $result = ClientProductResource::canCreate();

        $this->assertFalse($result, 'canCreate() deve retornar false quando user nao tem client.');
    }

    // -------------------------------------------------------------------------
    // Teste 2 — canCreate bloqueia subscription cancelada
    // -------------------------------------------------------------------------

    /** @test */
    public function it_blocks_canCreate_when_subscription_is_not_active_or_trialing(): void
    {
        $plan = $this->makePlan('Start', 50);
        $user = User::factory()->create(['role' => 'client', 'is_active' => true]);
        $client = Client::updateOrCreate(
            ['user_id' => $user->id],
            [
                'company_name' => 'Loja Cancelada',
                'document'     => fake()->numerify('###########'),
                'is_active'    => true,
            ]
        );
        Subscription::create([
            'client_id'            => $client->id,
            'plan_id'              => $plan->id,
            'status'               => 'cancelled',
            'current_period_start' => now()->subMonth(),
            'current_period_end'   => now()->subDay(),
        ]);

        $this->actingAs($user->fresh(['client']));
        Cache::flush();

        $result = ClientProductResource::canCreate();

        $this->assertFalse($result, 'canCreate() deve retornar false para subscription cancelada.');
    }

    // -------------------------------------------------------------------------
    // Teste 3 — canCreate permite subscription trialing com slots disponiveis
    // -------------------------------------------------------------------------

    /** @test */
    public function it_allows_canCreate_when_subscription_is_trialing(): void
    {
        $plan = $this->makePlan('Pro Trial', 200);
        $user = $this->makeClientUser($plan, 'trialing');

        $this->actingAs($user);
        Cache::flush();

        $result = ClientProductResource::canCreate();

        $this->assertTrue($result, 'canCreate() deve retornar true para subscription trialing com slots livres.');
    }

    // -------------------------------------------------------------------------
    // Teste 4 — canCreate bloqueia quando count(excluido=0) >= max_skus
    //            E garante que excluido=1 NAO conta
    // -------------------------------------------------------------------------

    /** @test */
    public function it_blocks_canCreate_when_product_count_reaches_max_skus(): void
    {
        $maxSkus = 3;
        $plan    = $this->makePlan('Mini', $maxSkus);
        $user    = $this->makeClientUser($plan, 'active');
        $client  = $user->client;

        // Criar exatamente max_skus produtos ativos (excluido=0) via raw insert
        for ($i = 0; $i < $maxSkus; $i++) {
            $this->insertClientProduct($client->id, 'SKU-ACTIVE-' . $i . '-' . uniqid(), 0);
        }

        // Criar 2 produtos excluidos (excluido=1) — NAO devem contar
        for ($i = 0; $i < 2; $i++) {
            $this->insertClientProduct($client->id, 'SKU-EXCLUIDO-' . $i . '-' . uniqid(), 1);
        }

        $this->actingAs($user);
        Cache::flush();

        $result = ClientProductResource::canCreate();

        $this->assertFalse($result, 'canCreate() deve retornar false quando count(excluido=0) >= max_skus.');

        // Verificacao extra: planInfo retorna current correto (excluidos nao contam)
        $info = ClientProductResource::planInfo();
        $this->assertEquals($maxSkus, $info['current'], 'planInfo()[current] deve ignorar excluido=1.');
    }

    // -------------------------------------------------------------------------
    // Teste 5 — banner upsell aparece apenas para motivo LIMITE DE PLANO
    // -------------------------------------------------------------------------

    /** @test */
    public function it_shows_upsell_banner_only_when_reason_is_plan_limit(): void
    {
        // Caso A: user sem client — canCreate false, motivo NAO e limite de plano
        $userSemClient = User::factory()->create(['role' => 'client', 'is_active' => true]);
        $this->actingAs($userSemClient);
        Cache::flush();

        $infoSemClient = ClientProductResource::planInfo();
        $this->assertFalse($infoSemClient['has_sub'], 'User sem client nao deve ter sub.');
        $this->assertEquals(0, $infoSemClient['current'], 'User sem client: current deve ser 0.');
        $this->assertFalse(
            $infoSemClient['has_sub'] && $infoSemClient['current'] >= $infoSemClient['max_skus'] && $infoSemClient['max_skus'] > 0,
            'Banner de limite de plano NAO deve aparecer para user sem client.'
        );

        // Caso B: user sem subscription ativa — canCreate false, motivo NAO e limite de plano
        $plan = $this->makePlan('Sem Sub', 10);
        $userSemSub = User::factory()->create(['role' => 'client', 'is_active' => true]);
        Client::updateOrCreate(['user_id' => $userSemSub->id], ['company_name' => 'X', 'document' => fake()->numerify('###########'), 'is_active' => true]);
        $this->actingAs($userSemSub->fresh(['client']));
        Cache::flush();

        $infoSemSub = ClientProductResource::planInfo();
        $this->assertFalse($infoSemSub['has_sub'], 'User sem subscription: has_sub deve ser false.');
        $this->assertFalse(
            $infoSemSub['has_sub'] && $infoSemSub['current'] >= $infoSemSub['max_skus'] && $infoSemSub['max_skus'] > 0,
            'Banner de limite de plano NAO deve aparecer para user sem subscription.'
        );

        // Caso C: user com plano cheio — motivo E limite de plano, banner DEVE aparecer
        $maxSkus = 2;
        $planCheio = $this->makePlan('Cheio', $maxSkus);
        $userCheio = $this->makeClientUser($planCheio, 'active');
        $clientCheio = $userCheio->client;

        for ($i = 0; $i < $maxSkus; $i++) {
            $this->insertClientProduct($clientCheio->id, 'SKU-CHEIO-' . $i . '-' . uniqid(), 0);
        }

        $this->actingAs($userCheio);
        Cache::flush();

        $infoCheio = ClientProductResource::planInfo();
        $isPlanLimitReason = $infoCheio['has_sub']
            && $infoCheio['max_skus'] > 0
            && $infoCheio['current'] >= $infoCheio['max_skus'];

        $this->assertTrue($isPlanLimitReason, 'Banner de upsell DEVE aparecer quando motivo e limite de plano.');
        $this->assertFalse(ClientProductResource::canCreate(), 'canCreate() deve ser false quando plano cheio.');
    }

    // -------------------------------------------------------------------------
    // Teste 6 — super_admin bypassa guard de MarketplaceAccountResource
    //            (via getEloquentQuery — nao bloqueia super_admin)
    // -------------------------------------------------------------------------

    /** @test */
    public function it_super_admin_bypasses_marketplace_account_resource_guards(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($superAdmin);

        // getEloquentQuery nao aplica filtro de client_id para super_admin
        $clientUser = User::factory()->create(['role' => 'client', 'is_active' => true]);
        $client = Client::updateOrCreate(
            ['user_id' => $clientUser->id],
            [
                'company_name' => 'Loja do Cliente',
                'document'     => fake()->numerify('###########'),
                'is_active'    => true,
            ]
        );

        $supplier = Supplier::create([
            'user_id'      => $clientUser->id,
            'company_name' => 'Fornecedor Teste',
            'document'     => fake()->numerify('##############'),
            'type'         => 'producer',
            'is_active'    => true,
        ]);

        $account = MarketplaceAccount::create([
            'client_id'    => $client->id,
            'supplier_id'  => $supplier->id,
            'account_name' => 'Loja ML Teste',
            'platform'     => 'mercadolivre',
            'status'       => 'active',
            'is_active'    => true,
        ]);

        // super_admin: query retorna todos os registros (sem whereRaw 1=0)
        $query = MarketplaceAccountResource::getEloquentQuery();
        $found = $query->where('id', $account->id)->exists();

        $this->assertTrue($found, 'super_admin deve ver MarketplaceAccounts de qualquer client.');

        // Cliente sem client associado: query deve retornar 0 resultados
        $userSemClient = User::factory()->create(['role' => 'client', 'is_active' => true]);
        $this->actingAs($userSemClient);

        $queryBloqueada = MarketplaceAccountResource::getEloquentQuery();
        $foundBloqueado = $queryBloqueada->where('id', $account->id)->exists();

        $this->assertFalse($foundBloqueado, 'User sem client NAO deve ver MarketplaceAccounts alheios.');
    }
}
