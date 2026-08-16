<?php

namespace App\Http\Middleware;

use App\Models\Scopes\TenantSupplierScope;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureTenantContext — valida que o tenant existe e está ativo antes de prosseguir.
 *
 * Usado em rotas que requerem tenant obrigatório via header X-Tenant-Slug.
 * Rotas públicas (health, login) e admin NÃO devem usar este middleware.
 *
 * Retorna 403 se:
 *   - X-Tenant-Slug aponta para tenant inexistente
 *   - Tenant está suspenso ou arquivado
 *
 * Quando o header não é enviado, deixa passar sem filtro (contexto genérico).
 */
class EnsureTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->header('X-Tenant-Slug');

        // Só valida se o header foi enviado explicitamente
        if (! $slug) {
            return $next($request);
        }

        $tenant = Tenant::where('slug', $slug)->first();

        if (! $tenant) {
            return response()->json([
                'error'   => 'tenant_not_found',
                'message' => "Tenant '{$slug}' não encontrado.",
            ], Response::HTTP_FORBIDDEN);
        }

        if ($tenant->status !== Tenant::STATUS_ACTIVE) {
            return response()->json([
                'error'   => 'tenant_inactive',
                'message' => "Tenant '{$slug}' está {$tenant->status}.",
            ], Response::HTTP_FORBIDDEN);
        }

        // Binda o tenant resolvido no container e no CurrentTenant service
        app()->instance('current_tenant', $tenant);

        // Limpa cache de supplier IDs para garantir dados frescos nesta request
        TenantSupplierScope::flushCache();

        // Injeta no CurrentTenant service (se já instanciado)
        if (app()->bound(CurrentTenant::class)) {
            app(CurrentTenant::class)->set($tenant);
        }

        return $next($request);
    }
}
