<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Models\Client;
use App\Models\User;
use App\Observers\UserObserver;

/**
 * MUL-FIX-2 — Auto-criacao de Client apos User signup
 *
 * Cobre 5 cenarios:
 * 1. Observer cria Client ao criar User com role=client
 * 2. Observer nao cria Client para role=supplier
 * 3. Observer idempotente - nao duplica Client em retry
 * 4. Middleware cria Client on-demand para user existente sem Client
 * 5. GET /api/v1/orders retorna 200 (nao 403) quando Client existe
 */
class AutoCreateClientTest extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    public function observer_cria_client_ao_criar_user_client(): void
    {
        $user = User::factory()->create([
            'role'      => 'client',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('clients', [
            'user_id'   => $user->id,
            'is_active' => true,
        ]);
    }

    /** @test */
    public function observer_nao_cria_client_para_role_supplier(): void
    {
        $user = User::factory()->create([
            'role'      => 'supplier',
            'is_active' => true,
        ]);

        $this->assertDatabaseMissing('clients', [
            'user_id' => $user->id,
        ]);
    }

    /** @test */
    public function observer_idempotente_nao_duplica_client(): void
    {
        $user = User::factory()->create([
            'role'      => 'client',
            'is_active' => true,
        ]);

        // Chama created() novamente simulando retry
        (new UserObserver())->created($user);

        $this->assertSame(
            1,
            Client::where('user_id', $user->id)->count(),
            'Deve existir exatamente 1 Client mesmo apos retry do Observer'
        );
    }

    /** @test */
    public function middleware_cria_client_on_demand_para_user_sem_client(): void
    {
        // Cria user sem Client (simula usuario legado ou criado antes do fix)
        $user = User::factory()->create([
            'role'      => 'client',
            'is_active' => true,
        ]);
        Client::where('user_id', $user->id)->delete();

        $this->assertDatabaseMissing('clients', ['user_id' => $user->id]);

        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
             ->getJson('/api/v1/orders')
             ->assertStatus(200);

        $this->assertDatabaseHas('clients', [
            'user_id'   => $user->id,
            'is_active' => true,
        ]);
    }

    /** @test */
    public function orders_retorna_200_quando_client_existe(): void
    {
        $user = User::factory()->create([
            'role'      => 'client',
            'is_active' => true,
        ]);

        // Observer ja criou o Client na linha acima
        $this->assertDatabaseHas('clients', ['user_id' => $user->id]);

        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
             ->getJson('/api/v1/orders')
             ->assertStatus(200);
    }
}
