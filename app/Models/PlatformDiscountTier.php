<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformDiscountTier extends Model
{
    protected $fillable = [
        'platform_discount_id',
        'from_order',
        'to_order',
        'discount_type',
        'discount_value',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
    ];

    public function discount()
    {
        return $this->belongsTo(PlatformDiscount::class, 'platform_discount_id');
    }
}
