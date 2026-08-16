<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro de cada webhook recebido pelos marketplaces.
 *
 * @property int         $id
 * @property string      $platform
 * @property string|null $topic
 * @property string|null $resource
 * @property string|null $user_id
 * @property string      $status        received|processed|failed
 * @property array|null  $payload
 * @property string|null $error_message
 * @property \Carbon\Carbon|null $processed_at
 * @property \Carbon\Carbon $created_at
 */
class WebhookLog extends Model
{
    public $timestamps = false;

    // Somente created_at é gerenciado pelo banco via useCurrent()
    protected $guarded = [];

    protected $casts = [
        'payload'      => 'array',
        'processed_at' => 'datetime',
        'created_at'   => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Scopes úteis para auditoria
    // -------------------------------------------------------------------------

    public function scopePlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeUnprocessed($query)
    {
        return $query->where('status', 'received');
    }
}
