<?php

namespace App\Models\Drop;

use Illuminate\Database\Eloquent\Model;
use App\Models\Client;

class DropModuleConfig extends Model
{
    protected $table = 'drop_module_configs';

    protected $fillable = [
        'client_id',
        'is_active',
        'stripe_account_id',
        'stripe_account_status',
        'aliexpress_access_token',
        'aliexpress_refresh_token',
        'aliexpress_token_expires_at',
        'target_country',
        'currency',
        'fulfillment_mode',
        'default_markup_pct',
        'platform_fee_pct',
        'gateway_fee_pct',
    ];

    protected $casts = [
        'is_active'                   => 'boolean',
        'default_markup_pct'          => 'decimal:2',
        'platform_fee_pct'            => 'decimal:2',
        'gateway_fee_pct'             => 'decimal:2',
        'aliexpress_token_expires_at' => 'datetime',
    ];

    protected $hidden = ['aliexpress_access_token', 'aliexpress_refresh_token'];

    public function getAliexpressAccessTokenAttribute(?string $value): ?string
    {
        if (!$value) return null;
        try { return decrypt($value); } catch (\Throwable) { return null; }
    }

    public function setAliexpressAccessTokenAttribute(?string $value): void
    {
        $this->attributes['aliexpress_access_token'] = $value ? encrypt($value) : null;
    }

    public function getAliexpressRefreshTokenAttribute(?string $value): ?string
    {
        if (!$value) return null;
        try { return decrypt($value); } catch (\Throwable) { return null; }
    }

    public function setAliexpressRefreshTokenAttribute(?string $value): void
    {
        $this->attributes['aliexpress_refresh_token'] = $value ? encrypt($value) : null;
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
