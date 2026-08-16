<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantApiCredential extends Model
{
    use HasUuids;

    protected $table = 'tenant_api_credentials';

    protected $fillable = [
        'tenant_id',
        'key_id',
        'key_hash',
        'scopes',
        'last_used_at',
        'revoked_at',
    ];

    protected $casts = [
        'scopes'       => 'array',
        'last_used_at' => 'datetime',
        'revoked_at'   => 'datetime',
    ];

    public const SCOPES = [
        'orders:read',
        'orders:write',
        'suppliers:read',
        'products:read',
        'events:read',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, (array) $this->scopes, true);
    }
}
