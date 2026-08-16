<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVariation extends Model
{
    protected $fillable = [
        'product_id',
        'sku',
        'name',
        'price',
        'cost',
        'gtin',
        'attributes',
        'position',
        'is_active',
    ];

    protected $casts = [
        'attributes' => 'array',
        'price' => 'decimal:2',
        'cost' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function media()
    {
        return $this->hasMany(ProductMedia::class);
    }
}
