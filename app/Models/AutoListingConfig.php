<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class AutoListingConfig extends Model
{
    protected $fillable = [
        'client_id',
        'marketplace_account_id',
        'max_listings_per_hour',
        'max_listings_per_day',
        'delay_between_listings_seconds',
        'active_hours_start',
        'active_hours_end',
        'active_days',
        'ai_enabled',
        'ai_generate_title',
        'ai_generate_description',
        'ai_instructions',
        'ai_model',
        'auto_publish',
        'skip_existing',
        'overwrite_custom_fields',
        'status',
        'seller_can_customize',
        'priority',
    ];

    protected $casts = [
        'active_days' => 'array',
        'ai_enabled' => 'boolean',
        'ai_generate_title' => 'boolean',
        'ai_generate_description' => 'boolean',
        'auto_publish' => 'boolean',
        'skip_existing' => 'boolean',
        'overwrite_custom_fields' => 'boolean',
        'seller_can_customize' => 'boolean',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function marketplaceAccount()
    {
        return $this->belongsTo(MarketplaceAccount::class);
    }

    /**
     * Config padrão global (client_id = null, marketplace_account_id = null).
     */
    public static function getDefault(): self
    {
        return self::whereNull('client_id')
            ->whereNull('marketplace_account_id')
            ->firstOrCreate(
                ['client_id' => null, 'marketplace_account_id' => null],
                [] // usa defaults da migration
            );
    }

    /**
     * Config efetiva para um seller+loja (cascata: loja > seller > padrão).
     */
    public static function getEffective(int $clientId, ?int $accountId = null): self
    {
        // 1. Config específica da conta/loja
        if ($accountId) {
            $specific = self::where('client_id', $clientId)
                ->where('marketplace_account_id', $accountId)
                ->first();
            if ($specific) {
                return $specific;
            }
        }

        // 2. Config do seller (todas as lojas)
        $sellerConfig = self::where('client_id', $clientId)
            ->whereNull('marketplace_account_id')
            ->first();
        if ($sellerConfig) {
            return $sellerConfig;
        }

        // 3. Config padrão global
        return self::getDefault();
    }

    public function isWithinActiveHours(): bool
    {
        $now = now();
        $dayMap = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $today = $dayMap[$now->dayOfWeekIso - 1];

        if (! in_array($today, $this->active_days ?? [])) {
            return false;
        }

        $start = Carbon::parse($this->active_hours_start);
        $end = Carbon::parse($this->active_hours_end);

        return $now->format('H:i:s') >= $start->format('H:i:s')
            && $now->format('H:i:s') <= $end->format('H:i:s');
    }
}
