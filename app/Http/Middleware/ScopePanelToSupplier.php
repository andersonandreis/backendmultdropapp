<?php

namespace App\Http\Middleware;

use App\Models\Supplier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ScopePanelToSupplier — isola o painel Filament por domínio.
 *
 * Resolve supplier por admin_domain, tentando host exato e depois sem www.
 * O TenantSupplierScope lê panel_supplier_id e filtra automaticamente todos
 * os models com BelongsToTenantSupplier.
 */
class ScopePanelToSupplier
{
    private const UNRESTRICTED_HOSTS = [
        'api.hubai.io',
        'localhost',
        '127.0.0.1',
        '66.94.100.155',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();

        if (in_array($host, self::UNRESTRICTED_HOSTS, true)) {
            return $next($request);
        }

        // Tenta host exato, depois sem prefixo www.
        $candidates = array_unique([$host, preg_replace('/^www\./', '', $host)]);

        $supplier = Supplier::withoutGlobalScopes()
            ->whereIn('admin_domain', $candidates)
            ->first();

        if ($supplier) {
            app()->instance('panel_supplier_id', (int) $supplier->id);
        }

        return $next($request);
    }
}
