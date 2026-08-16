<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierDiscount extends Model
{
    protected $fillable = [
        'supplier_id',
        'name',
        'description',
        'target',
        'target_id',
        'trigger_type',
        'is_stackable',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    protected $casts = [
        'is_stackable' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function tiers()
    {
        return $this->hasMany(SupplierDiscountTier::class);
    }
}
