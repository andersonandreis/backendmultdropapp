<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SEL-417: Plano de assinatura do servico Criador de Videos com IA.
 *
 * @property int    $id
 * @property string $slug               free|pro|turbo
 * @property string $name
 * @property int    $price_cents_monthly
 * @property int    $price_cents_yearly
 * @property int    $videos_per_month
 * @property array  $features_json
 * @property bool   $is_featured
 * @property bool   $is_active
 * @property int    $sort_order
 */
class VideoSubscriptionPlan extends Model
{
    protected $table = "video_subscription_plans";

    protected $fillable = [
        "slug",
        "name",
        "price_cents_monthly",
        "price_cents_yearly",
        "videos_per_month",
        "features_json",
        "is_featured",
        "is_active",
        "sort_order",
    ];

    protected $casts = [
        "features_json"         => "array",
        "is_featured"           => "boolean",
        "is_active"             => "boolean",
        "price_cents_monthly"   => "integer",
        "price_cents_yearly"    => "integer",
        "videos_per_month"      => "integer",
        "sort_order"            => "integer",
    ];
}

