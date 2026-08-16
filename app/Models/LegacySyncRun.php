<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LegacySyncRun extends Model
{
    protected $fillable = [
        'job', 'status', 'processed', 'errors', 'message',
        'started_at', 'finished_at', 'duration_ms',
    ];

    protected $casts = [
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];
}
