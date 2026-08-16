<?php

namespace App\Models\Drop;

use Illuminate\Database\Eloquent\Model;
use App\Models\Client;

class ImportedProduct extends Model
{
    protected $table = 'imported_products';

    protected $fillable = [
        'client_id',
        'drop_store_id',
        'supplier_slug',
        'external_supplier_id',
        'shopify_product_id',
        'title',
        'title_ai',
        'description',
        'description_ai',
        'images',
        'variants_data',
        'cost_usd',
        'shipping_usd',
        'sell_price',
        'currency',
        'markup_pct',
        'margin_usd',
        'status',
        'shopify_published_at',
    ];

    protected $casts = [
        'images'               => 'array',
        'variants_data'        => 'array',
        'cost_usd'             => 'decimal:4',
        'shipping_usd'         => 'decimal:4',
        'sell_price'           => 'decimal:4',
        'markup_pct'           => 'decimal:2',
        'margin_usd'           => 'decimal:4',
        'shopify_published_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function dropStore()
    {
        return $this->belongsTo(DropStore::class, 'drop_store_id');
    }

    public function variants()
    {
        return $this->hasMany(ImportedProductVariant::class, 'imported_product_id');
    }
}
