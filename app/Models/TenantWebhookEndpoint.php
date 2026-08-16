<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantWebhookEndpoint extends Model
{
    use HasUuids;

    protected $table = 'tenant_webhook_endpoints';

    protected $fillable = [
        'tenant_id',
        'url',
        'events',
        'secret',
        'active',
        'shadow',
    ];

    protected $casts = [
        'events' => 'array',
        'active' => 'boolean',
        'shadow' => 'boolean',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function deliveries()
    {
        return $this->hasMany(WebhookDelivery::class, 'endpoint_id');
    }

    public function subscribesTo(string $event): bool
    {
        return in_array($event, (array) $this->events, true)
            || in_array('*', (array) $this->events, true);
    }
}
