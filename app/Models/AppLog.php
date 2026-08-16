<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class AppLog extends Model
{
    /**
     * Log e append-only — sem updated_at.
     */
    const UPDATED_AT = null;

    protected $table = 'app_logs';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'level',
        'channel',
        'event',
        'message',
        'context',
        'ip',
        'request_id',
        'duration_ms',
    ];

    protected $casts = [
        'context'    => 'array',
        'created_at' => 'datetime',
    ];

    // =========================================================================
    // Scopes
    // =========================================================================

    public function scopeLevel(Builder $query, string $level): Builder
    {
        return $query->where('level', $level);
    }

    public function scopeChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }

    public function scopeRecent(Builder $query, int $days = 1): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function scopeByTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }
}
