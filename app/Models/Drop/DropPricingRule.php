<?php

namespace App\Models\Drop;

use Illuminate\Database\Eloquent\Model;
use App\Models\Client;

class DropPricingRule extends Model
{
    protected $table = 'drop_pricing_rules';

    protected $fillable = [
        'client_id',
        'rule_name',
        'rule_type',
        'supplier_slug',
        'category_slug',
        'markup_pct',
        'min_margin_usd',
        'max_price_local',
        'gateway_fee_pct',
        'platform_fee_pct',
        'include_shipping_in_price',
        'is_active',
    ];

    protected $casts = [
        'markup_pct'                => 'decimal:2',
        'min_margin_usd'            => 'decimal:4',
        'max_price_local'           => 'decimal:4',
        'gateway_fee_pct'           => 'decimal:2',
        'platform_fee_pct'          => 'decimal:2',
        'include_shipping_in_price' => 'boolean',
        'is_active'                 => 'boolean',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
