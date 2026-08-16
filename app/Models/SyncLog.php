<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncLog extends Model
{
    protected $fillable = [
        'syncable_type',
        'syncable_id',
        'platform',
        'marketplace_account_id',
        'action',
        'direction',
        'status',
        'request_payload',
        'response_payload',
        'error_message',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
    ];

    public function syncable()
    {
        return $this->morphTo();
    }
}
