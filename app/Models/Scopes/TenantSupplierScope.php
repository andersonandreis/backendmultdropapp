<?php

namespace App\Models\Scopes;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * TenantSupplierScope — filtra registros pelo supplier_id visível ao contexto atual.
 *
 * Ordem de prioridade:
 *   1. panel_supplier_id — bindado por ScopePanelToSupplier middleware (domínio Filament WL).
 *   2. current_tenant    — bindado por EnsureTenantContext middleware (APIs com X-Tenant-Slug).
 *   3. Sem contexto (CLI, api.hubai.io super_admin) → sem filtro.
 */
class TenantSupplierScope implements Scope
{
    /** Cache em memória de supplier IDs por tenant (por request). */
    private static array $supplierIdsCache = [];

    public function apply(Builder $builder, Model $model): void
    {
        // 1. Filament panel com domínio de WL (ex: mestoredrop.com.br)
        if (app()->bound('panel_supplier_id')) {
            $builder->where(
                $model->qualifyColumn('supplier_id'),
                (int) app('panel_supplier_id')
            );
            return;
        }

        // 2. API com X-Tenant-Slug header
        $tenant = static::resolveTenant();
        if ($tenant === null) {
            return;
        }

        if (($tenant->default_supplier_visibility ?? 'all') === 'all') {
            return;
        }

        $ids = static::supplierIdsFor($tenant);
        if (empty($ids)) {
            $builder->whereRaw('1 = 0');
            return;
        }

        $builder->whereIn($model->qualifyColumn('supplier_id'), $ids);
    }

    private static function resolveTenant(): ?Tenant
    {
        if (! app()->bound('current_tenant')) {
            return null;
        }

        $tenant = app('current_tenant');

        return $tenant instanceof Tenant ? $tenant : null;
    }

    public static function supplierIdsFor(Tenant $tenant): array
    {
        $key = $tenant->id;

        if (! isset(static::$supplierIdsCache[$key])) {
            static::$supplierIdsCache[$key] = \DB::table('tenant_supplier')
                ->where('tenant_id', $tenant->id)
                ->pluck('supplier_id')
                ->map(fn($id) => (int) $id)
                ->all();
        }

        return static::$supplierIdsCache[$key];
    }

    public static function flushCache(): void
    {
        static::$supplierIdsCache = [];
    }
}
