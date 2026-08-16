<?php

namespace App\Providers;

use App\Services\CurrentTenant;
use Illuminate\Support\ServiceProvider;

/**
 * TenantServiceProvider — registra CurrentTenant service e binding legado.
 *
 * CurrentTenant é scoped (nova instância por request HTTP, singleton em CLI).
 * O binding legado 'current_tenant' é mantido para compatibilidade com código
 * que usa app('current_tenant') diretamente.
 */
class TenantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // CurrentTenant: scoped = nova instância por request (stateful dentro do request)
        $this->app->scoped(CurrentTenant::class, fn() => new CurrentTenant());

        // Binding legado 'current_tenant': lazy, resolve via CurrentTenant service
        // Mantido para compatibilidade com código existente (Order::scopeForTenant, etc.)
        $this->app->bind('current_tenant', function ($app) {
            return $app->make(CurrentTenant::class)->tenant();
        });
    }

    public function boot(): void
    {
        //
    }
}
