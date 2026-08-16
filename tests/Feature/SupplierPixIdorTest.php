<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FOR-027 hotfix — Garante que:
 *   1. getSupplierPix NÃO vaza chave PIX de outro supplier (IDOR fix)
 *   2. requestWithdrawal ignora `pix_key` do payload e usa SEMPRE do cadastro
 *
 * Ref: hotfix segurança 2026-06-26 (IDOR ALTO + brecha de saque).
 */
class SupplierPixIdorTest extends TestCase
{
    use DatabaseTransactions;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createSupplierWithUser(string $pixKey = null, string $pixKeyType = 'cpf'): array
    {
        $user = User::factory()->create([
            'role'      => 'supplier',
            'is_active' => true,
        ]);

        $supplierId = \DB::table('suppliers')->insertGetId([
            'user_id'               => $user->id,
            'company_name'          => 'Sup ' . Str::random(6),
            'display_name'          => 'Display ' . Str::random(4),
            'type'                  => 'dropshipper',
            'document'              => fake()->numerify('##############'),
            'is_active'             => 1,
            'allows_direct_payment' => 0,
            'pix_fee'               => 0,
            'is_factory'            => 0,
            'supports_meli_flex'    => 0,
            'flex_fee'              => 0,
            'allows_direct_deposit' => 0,
            'is_private'            => 0,
            'prefix'                => 'SUP',
            'slug'                  => 'sup-' . Str::random(8),
            'pix_key'               => $pixKey,
            'pix_key_type'          => $pixKey ? $pixKeyType : null,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        return [$user->fresh(['supplier']), Supplier::find($supplierId)];
    }

    private function createSupplierBalance(int $supplierId, float $balance): void
    {
        \DB::table('supplier_balances')->insert([
            'producer_id'     => $supplierId,
            'warehouse_id'    => $supplierId,
            'balance'         => $balance,
            'total_earned'    => $balance,
            'total_withdrawn' => 0,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // IDOR — getSupplierPix
    // -------------------------------------------------------------------------

    public function test_getSupplierPix_bloqueia_acesso_a_supplier_de_outro_user(): void
    {
        [$userA, $supplierA] = $this->createSupplierWithUser('aaaa@a.com', 'email');
        [$userB, $supplierB] = $this->createSupplierWithUser('bbbb@b.com', 'email');

        // Usuário A tenta puxar PIX do supplier B
        $this->actingAs($userA);

        $response = $this->getJson("/api/v1/supplier/financial/pix/{$supplierB->id}");

        $response->assertStatus(403);
        $this->assertStringNotContainsString('bbbb@b.com', $response->getContent());
    }

    public function test_getSupplierPix_permite_acesso_ao_proprio_supplier(): void
    {
        [$userA, $supplierA] = $this->createSupplierWithUser('aaaa@a.com', 'email');

        $this->actingAs($userA);

        $response = $this->getJson("/api/v1/supplier/financial/pix/{$supplierA->id}");

        $response->assertOk();
        $response->assertJson([
            'supplier_id'  => $supplierA->id,
            'pix_key'      => 'aaaa@a.com',
            'pix_key_type' => 'email',
        ]);
    }

    public function test_getSupplierPix_404_quando_pix_nao_configurado(): void
    {
        [$userA, $supplierA] = $this->createSupplierWithUser(null);

        $this->actingAs($userA);

        $response = $this->getJson("/api/v1/supplier/financial/pix/{$supplierA->id}");

        $response->assertStatus(404);
    }

    // -------------------------------------------------------------------------
    // Saque — requestWithdrawal trava pix_key do cadastro
    // -------------------------------------------------------------------------

    public function test_requestWithdrawal_ignora_pix_key_do_payload_e_usa_do_cadastro(): void
    {
        [$user, $supplier] = $this->createSupplierWithUser('chave-real@cadastro.com', 'email');
        $this->createSupplierBalance($supplier->id, 1000.00);

        $this->actingAs($user);

        // Atacante tenta passar chave maliciosa no payload
        $response = $this->postJson('/api/v1/supplier/financial/withdraw', [
            'amount'       => 100,
            'pix_key'      => 'atacante@evil.com',
            'pix_key_type' => 'email',
        ]);

        $response->assertStatus(201);
        // PIX persistido foi o do cadastro, NUNCA o do payload
        $response->assertJsonPath('pix_key', 'chave-real@cadastro.com');
        $this->assertNotEquals('atacante@evil.com', $response->json('pix_key'));

        $this->assertDatabaseHas('withdrawal_requests', [
            'producer_id' => $supplier->id,
            'pix_key'     => 'chave-real@cadastro.com',
            'amount'      => 100,
        ]);
        $this->assertDatabaseMissing('withdrawal_requests', [
            'pix_key' => 'atacante@evil.com',
        ]);
    }

    public function test_requestWithdrawal_422_quando_pix_nao_configurado(): void
    {
        [$user, $supplier] = $this->createSupplierWithUser(null);
        $this->createSupplierBalance($supplier->id, 1000.00);

        $this->actingAs($user);

        $response = $this->postJson('/api/v1/supplier/financial/withdraw', [
            'amount' => 100,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'pix_key_not_configured']);
    }
}
