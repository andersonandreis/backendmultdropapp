<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SEL-198: Seller (lojista) do TikTok Shop BR.
 *
 * @property int    $id
 * @property string $handle
 * @property string $name
 * @property string|null $avatar_url
 * @property int    $followers
 * @property int    $products_count
 * @property float|null $avg_rating
 * @property int|null   $top_product_id
 * @property float  $gmv_estimate
 * @property int|null   $rank_position
 * @property string|null $category_l1
 * @property array|null  $raw
 * @property bool   $is_visible
 */
class TiktokShopSeller extends Model
{
    protected $table = 'tiktok_shop_sellers';

    protected $fillable = [
        'handle', 'name', 'avatar_url', 'followers', 'products_count',
        'avg_rating', 'avg_commission_rate', 'top_product_id', 'gmv_estimate',
        'rank_position', 'category_l1', 'raw', 'is_visible',
    ];

    protected $casts = [
        'raw'                 => 'array',
        'is_visible'          => 'boolean',
        'avg_rating'          => 'float',
        'avg_commission_rate' => 'float',
        'gmv_estimate'        => 'float',
    ];

    public function products()
    {
        return $this->hasMany(TiktokShopSellerProduct::class, 'seller_id');
    }
}
