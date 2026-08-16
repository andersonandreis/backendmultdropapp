<?php

namespace App\Services;

use App\Models\Scopes\TenantSupplierScope;
use App\Models\Tenant;

/**
 * CurrentTenant — service singleton (scoped por request) que expõe o tenant atual.
 *
 * Resolvido uma vez por request; subsequentes chamadas retornam o valor em cache.
 * Registrado em TenantServiceProvider como app()->scoped().
 *
 * Estratégia de resolução:
 *   1. header X-Tenant-Slug (rotas /api/v1/* consumidas por WLs Lovable)
 *   2. Usuário autenticado Sanctum/Filament com role client
 *   3. Variável de ambiente TENANT_SLUG (jobs CLI específicos)
 *   4. null → sem filtro (super_admin, supplier, CLI genérico)
 */
class CurrentTenant
{
    private ?Tenant $tenant = null;
    private bool $resolved  = false;

    // -------------------------------------------------------------------------
    // API pública
    // -------------------------------------------------------------------------

    /** UUID do tenant atual, ou null se não houver tenant. */
    public function id(): ?string
    {
        return $this->tenant()?->id;
    }

    /** Slug do tenant atual, ou null. */
    public function slug(): ?string
    {
        return $this->tenant()?->slug;
    }

    /** Visibilidade configurada: 'all' ou 'scoped'. */
    public function visibility(): string
    {
        return $this->tenant()?->default_supplier_visibility ?? 'all';
    }

    /**
     * IDs de supplier visíveis ao tenant atual.
     * Retorna array vazio se tenant null ou se visibilidade for 'all'
     * (neste caso o scope não filtra — acesso irrestrito).
     *
     * @return int[]
     */
    public function supplierIds(): array
    {
        $tenant = $this->tenant();

        if ($tenant === null || $tenant->default_supplier_visibility === 'all') {
            return [];
        }

        return TenantSupplierScope::supplierIdsFor($tenant);
    }

    /** Retorna o Model Tenant atual, ou null. */
    public function tenant(): ?Tenant
    {
        if (! $this->resolved) {
            $this->tenant   = $this->resolve();
            $this->resolved = true;

            // Binda também no container legado (app('current_tenant'))
            // para compatibilidade com TenantServiceProvider e EnsureTenantContext.
            app()->instance('current_tenant', $this->tenant);
        }

        return $this->tenant;
    }

    /** Sobrescreve o tenant (útil em testes e jobs). */
    public function set(?Tenant $tenant): static
    {
        $this->tenant   = $tenant;
        $this->resolved = true;
        app()->instance('current_tenant', $tenant);
        TenantSupplierScope::flushCache();

        return $this;
    }

    // -------------------------------------------------------------------------
    // Resolução interna
    // -------------------------------------------------------------------------

    private function resolve(): ?Tenant
    {
        // 1. Header X-Tenant-Slug (API Lovable WL)
        if (app()->bound('request')) {
            $slug = app('request')->header('X-Tenant-Slug');
            if ($slug) {
                return Tenant::where('slug', $slug)
                    ->where('status', Tenant::STATUS_ACTIVE)
                    ->first();
            }
        }

        // 2. Usuário autenticado
        if (auth()->check()) {
            $user = auth()->user();

            // super_admin e supplier: acesso global, sem filtro
            if (in_array($user->role ?? '', ['super_admin', 'supplier'])) {
                return null;
            }

            // client com tenant via legacy_empresa_id
            if ($user->client && $user->client->legacy_empresa_id) {
                return Tenant::where('legacy_empresa_id', $user->client->legacy_empresa_id)
                    ->where('status', Tenant::STATUS_ACTIVE)
                    ->first();
            }
        }

        // 3. CLI com TENANT_SLUG no ambiente (jobs específicos de tenant)
        if (app()->runningInConsole()) {
            $envSlug = env('TENANT_SLUG');
            if ($envSlug) {
                return Tenant::where('slug', $envSlug)
                    ->where('status', Tenant::STATUS_ACTIVE)
                    ->first();
            }
            return null; // CLI genérico = acesso global
        }

        return null;
    }
}
