<?php

namespace App\Models;

use App\Models\Scopes\TenantSupplierScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepricingCostConfig extends Model
{
    protected $fillable = [
        'supplier_id', 'marketplace', 'product_category',
        'shipping_cost_pct', 'marketplace_fee_pct',
        'desired_margin_pct', 'extra_cost_fixed', 'active',
    ];

    protected $casts = [
        'shipping_cost_pct'   => 'decimal:3',
        'marketplace_fee_pct' => 'decimal:3',
        'desired_margin_pct'  => 'decimal:3',
        'extra_cost_fixed'    => 'decimal:2',
        'active'              => 'boolean',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantSupplierScope());
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
