<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SEL-198: produto scrapado de um seller TikTok Shop.
 */
class TiktokShopSellerProduct extends Model
{
    protected $table = 'tiktok_shop_seller_products';
    public    $timestamps = false;

    protected $fillable = [
        'seller_id', 'external_product_id', 'product_json',
        'sales_count', 'rating', 'price', 'commission_rate', 'affiliate_link',
    ];

    protected $casts = [
        'product_json'    => 'array',
        'rating'          => 'float',
        'price'           => 'float',
        'commission_rate' => 'float',
    ];

    public function seller()
    {
        return $this->belongsTo(TiktokShopSeller::class, 'seller_id');
    }
}
