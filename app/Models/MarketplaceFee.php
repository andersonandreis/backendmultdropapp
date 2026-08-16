<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceFee extends Model
{
    protected $fillable = [
        'platform',
        'category_id',
        'category_name',
        'listing_type_id',
        'fee_percentage',
        'fixed_fee',
        'shipping_fee_type',
        'min_price',
        'max_price',
        'is_active',
        'source',
    ];

    protected $casts = [
        'fee_percentage' => 'decimal:2',
        'fixed_fee' => 'decimal:2',
        'min_price' => 'decimal:2',
        'max_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];
}
