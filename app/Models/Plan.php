<?php

namespace App\Models;

use App\Models\Subscription;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Plan extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'max_skus',
        'max_marketplace_connections',
        'has_drop_internacional',
        'max_erp_connections',
        'max_integrations',
        'price_monthly',
        'price_yearly',
        'trial_days',
        'is_active',
    ];

    protected $casts = [
        'has_drop_internacional' => 'boolean',
        'is_active'              => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Plan $plan) {
            if (empty($plan->slug)) {
                $plan->slug = Str::slug($plan->name);
            }
        });

        static::updating(function (Plan $plan) {
            if (empty($plan->slug)) {
                $plan->slug = Str::slug($plan->name);
            }
        });
    }

    public function suppliers()
    {
        return $this->belongsToMany(Supplier::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }
}
