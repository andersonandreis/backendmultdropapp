<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'client_product_id',
        'product_id',
        'product_variation_id',
        'sku',
        'variation_sku',
        'name',
        'quantity',
        'unit_price',
        'total',
        'supplier_unit_cost',
        'supplier_total_cost',
        'external_item_id',
        'external_variation_id',
        'sale_fee',
        'listing_type_id',
        'scanned_at',
        'product_image',
        'legacy_sku_pai_id',
        'legacy_kit_id',
        'client_kit_id',
        'is_kit_component',
        'kit_source_item_id',
    ];

    /**
     * Campos de custo do fornecedor ocultos por padrão em serialização JSON.
     * Use makeVisible(['supplier_unit_cost','supplier_total_cost']) explicitamente
     * apenas onde o contexto autoriza (super_admin, painel fornecedor, plano Pro/Scale).
     */
    protected $hidden = [
        'supplier_unit_cost',
        'supplier_total_cost',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'total' => 'decimal:2',
        'supplier_unit_cost' => 'decimal:2',
        'supplier_total_cost' => 'decimal:2',
        'sale_fee' => 'decimal:2',
        'scanned_at' => 'datetime',
        'legacy_sku_pai_id' => 'integer',
        'legacy_kit_id' => 'integer',
        'client_kit_id' => 'integer',
        'is_kit_component' => 'boolean',
        'kit_source_item_id' => 'integer',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function clientProduct()
    {
        return $this->belongsTo(ClientProduct::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariation()
    {
        return $this->belongsTo(ProductVariation::class, 'product_variation_id');
    }

    /**
     * MUL-063: Resolve a URL da imagem do item do pedido.
     * Prioridade: 1) product.media cover (eager loaded), 2) product_image do banco.
     */
    // MUL-147: Kit do lojista que originou este item (quando is_kit_component=true)
    public function clientKit()
    {
        return $this->belongsTo(ClientKit::class, 'client_kit_id');
    }

        public function getProductImageAttribute($value): ?string
    {
        // Tenta cover via relacao product.media
        if ($this->relationLoaded("product") && $this->product) {
            $coverMedia = $this->product->relationLoaded("media")
                ? $this->product->media->first()
                : null;
            if ($coverMedia && $coverMedia->url) {
                return $coverMedia->url;
            }
        }
        // Fallback: produto_image do banco
        if (empty($value)) {
            return null;
        }
        if (!str_starts_with($value, "http://") && !str_starts_with($value, "https://")) {
            return rtrim(config("app.url"), "/") . "/" . ltrim($value, "/");
        }
        return $value;
    }

}
