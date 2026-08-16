<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenantSupplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NOV-144 — Regra de desconto por catalogo + faixa de quantidade.
 *
 * Aplicada quando o cliente compra produtos de um catalogo especifico em
 * quantidade dentro da faixa (min_qty..max_qty).
 *
 * NAO confundir com:
 *  - SupplierDiscount (descontos por cliente/segmento)
 *  - PlatformDiscount (desconto por volume da plataforma)
 *  - PlanDiscount (descontos por plano)
 */
class CatalogDiscountRule extends Model
{
    use BelongsToTenantSupplier;

    protected $fillable = [
        'supplier_id', 'catalog_id', 'name', 'min_qty', 'max_qty',
        'discount_pct', 'active', 'starts_at', 'ends_at',
    ];

    protected $casts = [
        'min_qty'      => 'integer',
        'max_qty'      => 'integer',
        'discount_pct' => 'decimal:2',
        'active'       => 'boolean',
        'starts_at'    => 'datetime',
        'ends_at'      => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function scopeActive($query)
    {
        $now = now();
        return $query->where('active', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            });
    }
}
