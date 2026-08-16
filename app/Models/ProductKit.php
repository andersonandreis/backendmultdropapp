<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductKit extends Model
{
    protected $fillable = [
        'product_id',
        'child_product_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function kit(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function childProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'child_product_id');
    }
}
