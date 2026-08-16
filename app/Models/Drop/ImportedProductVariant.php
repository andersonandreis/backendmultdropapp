<?php

namespace App\Models\Drop;

use Illuminate\Database\Eloquent\Model;

class ImportedProductVariant extends Model
{
    protected $table = 'imported_product_variants';

    protected $fillable = [
        'imported_product_id',
        'shopify_variant_id',
        'title',
        'sku',
        'cost_usd',
        'sell_price',
        'stock',
        'option1',
        'option2',
        'option3',
    ];

    protected $casts = [
        'cost_usd'   => 'decimal:4',
        'sell_price' => 'decimal:4',
        'stock'      => 'integer',
    ];

    public function importedProduct()
    {
        return $this->belongsTo(ImportedProduct::class, 'imported_product_id');
    }
}
