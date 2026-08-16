<?php

namespace App\Models\Traits;

use App\Models\Scopes\TenantSupplierScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * BelongsToTenantSupplier — aplica TenantSupplierScope automaticamente nos models
 * que possuem coluna supplier_id.
 *
 * Uso:
 *   use App\Models\Traits\BelongsToTenantSupplier;
 *   class Product extends Model { use BelongsToTenantSupplier; }
 *
 * Para queries admin (sem filtro de tenant):
 *   Product::withoutTenantSupplierScope()->get();
 *
 * Contexto arquitetural: tenant_supplier N:N — ver feedback_arquitetura_matriz_unica
 * e discovery NOV-047-A (2026-06-23).
 */
trait BelongsToTenantSupplier
{
    /**
     * Registra o TenantSupplierScope como global scope do model.
     * Chamado automaticamente pelo Laravel via boot<TraitName>().
     */
    public static function bootBelongsToTenantSupplier(): void
    {
        static::addGlobalScope(new TenantSupplierScope());
    }

    /**
     * Retorna query builder sem o scope de tenant/supplier.
     * Use em queries administrativas ou de sistema que precisam de acesso global.
     *
     * Exemplo:
     *   Product::withoutTenantSupplierScope()->count();
     */
    public static function withoutTenantSupplierScope(): Builder
    {
        return static::withoutGlobalScope(TenantSupplierScope::class);
    }
}
