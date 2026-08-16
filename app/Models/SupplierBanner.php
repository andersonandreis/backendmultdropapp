<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenantSupplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NOV-140 — Banner promocional exibido no painel do cliente da WL.
 *
 * Cada supplier (whitelabel) gerencia seus proprios banners.
 */
class SupplierBanner extends Model
{
    use BelongsToTenantSupplier;

    protected $fillable = [
        'supplier_id', 'title', 'url', 'image_url', 'active', 'sort_order',
    ];

    protected $casts = [
        'active'     => 'boolean',
        'sort_order' => 'integer',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
