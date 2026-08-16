<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WebhookDelivery extends Model
{
    use HasUuids;

    protected $table = 'webhook_deliveries';

    protected $fillable = [
        'endpoint_id',
        'event',
        'payload',
        'idempotency_key',
        'attempt',
        'status',
        'response_code',
        'response_body',
        'next_retry_at',
    ];

    protected $casts = [
        'payload'        => 'array',
        'attempt'        => 'integer',
        'response_code'  => 'integer',
        'next_retry_at'  => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_DEAD    = 'dead';

    public function endpoint()
    {
        return $this->belongsTo(TenantWebhookEndpoint::class, 'endpoint_id');
    }
}
