<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceHealthCheck extends Model
{
    protected $fillable = [
        'marketplace_account_id',
        'metric',
        'value',
        'status',
        'details',
    ];

    protected $casts = [
        'value' => 'decimal:4',
        'details' => 'array',
    ];

    public function marketplaceAccount()
    {
        return $this->belongsTo(MarketplaceAccount::class);
    }

    /**
     * Scopes
     */
    public function scopeHealthy($query)
    {
        return $query->where('status', 'healthy');
    }

    public function scopeWarning($query)
    {
        return $query->where('status', 'warning');
    }

    public function scopeCritical($query)
    {
        return $query->where('status', 'critical');
    }

    public function scopeForAccount($query, int $accountId)
    {
        return $query->where('marketplace_account_id', $accountId);
    }

    public function scopeLatest($query)
    {
        return $query->orderByDesc('created_at');
    }
}
