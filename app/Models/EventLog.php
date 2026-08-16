<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventLog extends Model
{
    protected $fillable = [
        'event_subscription_id',
        'event_type',
        'channel',
        'payload',
        'status',
        'attempts',
        'sent_at',
        'error',
    ];

    protected $casts = [
        'payload' => 'array',
        'sent_at' => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relacionamentos
    // -------------------------------------------------------------------------

    public function eventSubscription()
    {
        return $this->belongsTo(EventSubscription::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }
}
