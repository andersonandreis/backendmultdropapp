<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant = sistema externo consumidor da Tenant API.
 *
 * Exemplos: multdrop.app (ve so supplier MultDrop), fornecefy (ve todos os suppliers),
 * outros parceiros futuros.
 *
 * NÃO representa whitelabel do legado (essas rodam no monolito tudoonline,
 * isolamento por id_empresa na sessao PHP; ver Obsidian/Recursos/Arquitetura Legado Goolhub.md).
 */
class Tenant extends Model
{
    use HasUuids;

    protected $table = 'tenants';

    protected $fillable = [
        'slug',
        'name',
        'description',
        'logo_url',
        'legacy_empresa_id',
        'status',
        'default_supplier_visibility',
        'write_enabled',
        'rate_limit_per_min',
    ];

    protected $casts = [
        'legacy_empresa_id'  => 'integer',
        'write_enabled'      => 'boolean',
        'rate_limit_per_min' => 'integer',
    ];

    public const STATUS_ACTIVE    = 'active';
    public const STATUS_ARCHIVED  = 'archived';
    public const STATUS_SUSPENDED = 'suspended';

    public function scopeActive($q)
    {
        return $q->where('status', self::STATUS_ACTIVE);
    }

    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class, 'tenant_supplier');
    }

    public function webhookEndpoints(): HasMany
    {
        return $this->hasMany(TenantWebhookEndpoint::class);
    }

    public function apiCredentials(): HasMany
    {
        return $this->hasMany(TenantApiCredential::class);
    }
}
