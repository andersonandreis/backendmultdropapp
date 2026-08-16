<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformDiscount extends Model
{
    protected $fillable = [
        'name',
        'description',
        'type', // graduated_order, first_purchase, coupon
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function tiers()
    {
        return $this->hasMany(PlatformDiscountTier::class);
    }
}
