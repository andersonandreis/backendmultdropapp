<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * SHIELD-AUDIT: garante que Client::updateOrCreate em controllers/jobs nao
 * colide com UNIQUE clients.user_id quando UserObserver ja criou o Client.
 *
 * Cenario: User com role=client e criado -> UserObserver.created() dispara
 * Client::firstOrCreate(['user_id' => ...]). Webhook/OAuth/job que tenta
 * criar Client de novo precisa usar updateOrCreate, nao create.
 */
class ShieldAuditClientCreateTest extends TestCase
{
    use DatabaseTransactions;

    public function test_userobserver_already_created_client_when_role_client(): void
    {
        $user = User::create([
            'name'      => 'Cliente Teste',
            'email'     => 'shield-audit-' . uniqid() . '@hubai.io',
            'password'  => bcrypt('senha-teste'),
            'role'      => 'client',
            'is_active' => true,
        ]);

        // UserObserver.created() ja deve ter criado Client via firstOrCreate
        $this->assertDatabaseHas('clients', [
            'user_id' => $user->id,
        ]);
    }

    public function test_updateorcreate_user_id_does_not_throw_after_userobserver(): void
    {
        $user = User::create([
            'name'      => 'Cliente Race',
            'email'     => 'shield-race-' . uniqid() . '@hubai.io',
            'password'  => bcrypt('senha'),
            'role'      => 'client',
            'is_active' => true,
        ]);

        // Tentar criar Client de novo via updateOrCreate (padrao aplicado em FOR-026/SHIELD-AUDIT).
        // Antes do fix isso lancava QueryException UniqueConstraintViolation.
        $client = Client::updateOrCreate(
            ['user_id' => $user->id],
            [
                'company_name' => 'Empresa via webhook',
                'document'     => '12345678901',
                'is_active'    => true,
            ]
        );

        $this->assertEquals($user->id, $client->user_id);
        $this->assertEquals('Empresa via webhook', $client->company_name);
        $this->assertEquals(1, Client::where('user_id', $user->id)->count(), 'so deve existir 1 client por user_id');
    }

    public function test_double_updateorcreate_remains_idempotent(): void
    {
        $user = User::create([
            'name'      => 'Cliente Double',
            'email'     => 'shield-double-' . uniqid() . '@hubai.io',
            'password'  => bcrypt('senha'),
            'role'      => 'client',
            'is_active' => true,
        ]);

        Client::updateOrCreate(
            ['user_id' => $user->id],
            ['company_name' => 'Primeiro', 'document' => '11111111111', 'is_active' => true]
        );

        $client = Client::updateOrCreate(
            ['user_id' => $user->id],
            ['company_name' => 'Segundo', 'document' => '22222222222', 'is_active' => true]
        );

        $this->assertEquals('Segundo', $client->company_name);
        $this->assertEquals('22222222222', $client->document);
        $this->assertEquals(1, Client::where('user_id', $user->id)->count());
    }
}
