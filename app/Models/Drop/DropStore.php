<?php

namespace App\Models\Drop;

use Illuminate\Database\Eloquent\Model;
use App\Models\Client;

class DropStore extends Model
{
    protected $table = 'drop_stores';

    protected $fillable = [
        'client_id',
        'platform',
        'store_slug',
        'custom_domain',
        'store_display_name',
        'primary_color',
        'logo_url',
        'banner_url',
        'is_published',
        'published_at',
        // Shopify fields
        'shop_domain',
        'access_token',
        'shopify_shop_id',
        'status',
        'shop_name',
        'currency',
        'plan_name',
        'webhook_registered_at',
        'last_sync_at',
        'split_platform_pct',
        'payment_gateway',
    ];

    protected $casts = [
        'is_published'          => 'boolean',
        'published_at'          => 'datetime',
        'webhook_registered_at' => 'datetime',
        'last_sync_at'          => 'datetime',
        'split_platform_pct'    => 'integer',
    ];

    protected $hidden = ['access_token'];

    public function isNative(): bool
    {
        return $this->platform === 'native';
    }

    public function isShopify(): bool
    {
        return $this->platform === 'shopify';
    }

    public function getPublicUrl(): string
    {
        if ($this->custom_domain) {
            return 'https://' . $this->custom_domain;
        }
        return 'https://loja.hubai.io/' . $this->store_slug;
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function importedProducts()
    {
        return $this->hasMany(ImportedProduct::class, 'drop_store_id');
    }

    public function dropOrders()
    {
        return $this->hasMany(DropOrder::class, 'drop_store_id');
    }

    public function paymentGateways()
    {
        return $this->hasMany(StorePaymentGateway::class, 'drop_store_id');
    }

    public function defaultGateway()
    {
        return $this->hasOne(StorePaymentGateway::class, 'drop_store_id')
            ->where('is_default', true)
            ->where('is_active', true);
    }

    public function getAccessTokenAttribute($value)
    {
        return $value ? decrypt($value) : null;
    }

    public function setAccessTokenAttribute($value)
    {
        $this->attributes['access_token'] = $value ? encrypt($value) : null;
    }
}
