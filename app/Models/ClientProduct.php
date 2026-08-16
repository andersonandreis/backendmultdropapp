<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientProduct extends Model
{
    protected $fillable = [
        'client_id',
        'product_id',
        'product_variation_id',
        'supplier_product_sku',
        'custom_sku',
        'custom_title',
        'custom_description',
        'custom_price',
        'custom_attributes',
        'custom_brand',
        'custom_model',
        'custom_gtin',
        'custom_condition',
        'custom_warranty_type',
        'custom_warranty_days',
        'custom_weight_kg',
        'custom_height_cm',
        'custom_width_cm',
        'custom_length_cm',
        'custom_images',
        'pricing_mode',
        'profit_margin',
        'listing_type_id',
        'external_listing_id',
        'external_listing_url',
        'external_variation_id',
        'external_category_id',
        'marketplace_account_id',
        'sync_status',
        'last_sync_at',
        'last_sync_error',
        'is_active',
        'listing_status',
        'sync_attempt_count',
        'ml_external_item_id',
        'shopee_external_item_id',
        'listing_quality_score',
        'listing_quality_issues',
        'image_url',
        'ml_family_name', // FOR-080: family_name do catalogo ML
    ];

    protected $casts = [
        'custom_attributes' => 'array',
        'custom_images' => 'array',
        'custom_price' => 'decimal:2',
        'profit_margin' => 'decimal:2',
        'custom_weight_kg' => 'decimal:3',
        'custom_height_cm' => 'decimal:2',
        'custom_width_cm' => 'decimal:2',
        'custom_length_cm' => 'decimal:2',
        'is_active' => 'boolean',
        'last_sync_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function originalProduct()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function originalVariation()
    {
        return $this->belongsTo(ProductVariation::class, 'product_variation_id');
    }

    public function marketplaceAccount()
    {
        return $this->belongsTo(MarketplaceAccount::class);
    }

    public function orderItems()
    {
        return $this->hasMany(\App\Models\OrderItem::class, 'client_product_id');
    }

    public function autoListingQueueItems()
    {
        return $this->hasMany(\App\Models\AutoListingQueueItem::class, 'client_product_id');
    }
}
