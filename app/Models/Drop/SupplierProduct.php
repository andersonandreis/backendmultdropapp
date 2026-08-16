<?php

namespace App\Models\Drop;

use Illuminate\Database\Eloquent\Model;
use App\Models\Client;

/**
 * Drop Internacional — Produto minerado de fornecedor externo.
 *
 * Armazena o snapshot dos produtos encontrados na pesquisa de mining
 * (CJ Dropshipping, AliExpress, etc.) antes de serem importados como
 * ImportedProduct em uma loja Shopify.
 */
class SupplierProduct extends Model
{
    protected $table = 'supplier_products';

    protected $fillable = [
        'client_id',
        'supplier_slug',
        'external_id',
        'title',
        'description',
        'images',
        'variants',
        'cost_usd',
        'shipping_usd',
        'estimated_days',
        'rating',
        'sales_count',
        'supplier_url',
        'category',
        'risk_score',
        'last_fetched_at',
    ];

    protected $casts = [
        'images'          => 'array',
        'variants'        => 'array',
        'cost_usd'        => 'decimal:4',
        'shipping_usd'    => 'decimal:4',
        'rating'          => 'decimal:1',
        'last_fetched_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
