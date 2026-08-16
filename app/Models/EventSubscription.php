<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'supplier_id',
        'event_type',
        'channels',
        'webhook_url',
        'webhook_secret',
        'is_active',
    ];

    protected $casts = [
        'channels'  => 'array',
        'is_active' => 'boolean',
    ];

    // -------------------------------------------------------------------------
    // Relacionamentos
    // -------------------------------------------------------------------------

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function logs()
    {
        return $this->hasMany(EventLog::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * Subscriptions que correspondem a um tipo de evento especifico
     * ou ao wildcard '*'.
     */
    public function scopeForEvent($query, string $eventType)
    {
        return $query->where(function ($q) use ($eventType) {
            $q->where('event_type', $eventType)
              ->orWhere('event_type', '*');
        });
    }

    /**
     * Apenas subscriptions ativas.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
