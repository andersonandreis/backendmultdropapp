<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutoListingLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'queue_item_id',
        'client_id',
        'action',
        'details',
        'duration_ms',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function queueItem()
    {
        return $this->belongsTo(AutoListingQueueItem::class, 'queue_item_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
