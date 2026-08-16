<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use App\Models\User;

/**
 * NOV-XXX — cobre EnforceWlOwnerBillingGate (gate de cobranca do dono da WL,
 * role=supplier, painel Filament /admin). Supabase é sempre mockado via
 * Http::fake — nenhuma chamada real, nenhuma WL de verdade é tocada.
 */
class EnforceWlOwnerBillingGateTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Config::set('app.tenant', 'jtdrop');
        Config::set('services.supabase.url', 'https://fake-supabase.test');
        Config::set('services.supabase.anon_key', 'fake-key');
    }

    private function fakeSupabase(bool $isBlocked): void
    {
        Http::fake([
            '*whitelabel_billing_config*' => Http::response([
                ['is_blocked' => $isBlocked, 'blocked_at' => $isBlocked ? now()->toIso8601String() : null],
            ], 200),
        ]);
    }

    /** @test */
    public function supplier_e_redirecionado_quando_wl_bloqueada_e_flag_ligada(): void
    {
        Config::set('services.wl_owner_billing_gate.enabled', true);
        $this->fakeSupabase(true);

        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);

        $response = $this->actingAs($supplier)->get('/admin');

        $response->assertRedirect('/admin/pagamento-pendente');
    }

    /** @test */
    public function supplier_nao_e_redirecionado_quando_wl_nao_bloqueada(): void
    {
        Config::set('services.wl_owner_billing_gate.enabled', true);
        $this->fakeSupabase(false);

        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);

        $response = $this->actingAs($supplier)->get('/admin');

        $this->assertNotEquals(302, $response->status(), 'Nao deveria redirecionar quando a WL nao esta bloqueada.');
        if ($response->isRedirect()) {
            $this->assertNotEquals('/admin/pagamento-pendente', $response->headers->get('Location'));
        }
    }

    /** @test */
    public function supplier_nao_e_redirecionado_quando_flag_desligada_mesmo_com_wl_bloqueada(): void
    {
        Config::set('services.wl_owner_billing_gate.enabled', false);
        $this->fakeSupabase(true);

        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);

        $response = $this->actingAs($supplier)->get('/admin');

        if ($response->isRedirect()) {
            $this->assertNotEquals('/admin/pagamento-pendente', $response->headers->get('Location'));
        } else {
            $this->assertNotEquals(302, $response->status());
        }
    }

    /** @test */
    public function super_admin_nunca_e_bloqueado_mesmo_com_flag_ligada_e_wl_bloqueada(): void
    {
        Config::set('services.wl_owner_billing_gate.enabled', true);
        $this->fakeSupabase(true);

        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $response = $this->actingAs($superAdmin)->get('/admin');

        $this->assertNotEquals('/admin/pagamento-pendente', $response->headers->get('Location'));
    }

    /** @test */
    public function tenant_hub_nunca_bloqueia_mesmo_com_flag_ligada_e_wl_bloqueada(): void
    {
        Config::set('app.tenant', 'hubai');
        Config::set('services.wl_owner_billing_gate.enabled', true);
        $this->fakeSupabase(true);

        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);

        $response = $this->actingAs($supplier)->get('/admin');

        $this->assertNotEquals('/admin/pagamento-pendente', $response->headers->get('Location'));
    }

    /** @test */
    public function pagina_de_pagamento_pendente_nao_faz_loop_de_redirect(): void
    {
        Config::set('services.wl_owner_billing_gate.enabled', true);
        $this->fakeSupabase(true);

        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);

        $response = $this->actingAs($supplier)->get('/admin/pagamento-pendente');

        $response->assertOk();
    }
}
