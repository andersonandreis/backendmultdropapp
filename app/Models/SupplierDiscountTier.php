<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierDiscountTier extends Model
{
    protected $fillable = [
        'supplier_discount_id',
        'min_quantity',
        'max_quantity',
        'min_value',
        'max_value',
        'discount_type',
        'discount_value',
    ];

    protected $casts = [
        'min_value' => 'decimal:2',
        'max_value' => 'decimal:2',
        'discount_value' => 'decimal:2',
    ];

    public function discount()
    {
        return $this->belongsTo(SupplierDiscount::class, 'supplier_discount_id');
    }
}
