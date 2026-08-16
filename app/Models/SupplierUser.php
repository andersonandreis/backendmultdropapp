<?php

namespace App\Models;

use App\Models\Scopes\TenantSupplierScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierUser extends Model
{
    protected $fillable = [
        'supplier_id', 'user_id', 'role', 'permissions',
        'active', 'invite_token', 'invited_at', 'accepted_at', 'invited_by_user_id',
    ];

    protected $casts = [
        'permissions' => 'array',
        'active'      => 'boolean',
        'invited_at'  => 'datetime',
        'accepted_at' => 'datetime',
    ];

    protected $hidden = ['invite_token'];

    /** Permissões padrão por role. */
    public const DEFAULT_PERMISSIONS = [
        'admin'     => ['*'],
        'operador'  => ['orders.view', 'orders.update'],
        'estoque'   => ['products.view', 'inventory.manage', 'shipments.manage'],
        'financeiro'=> ['orders.view', 'billing.view', 'reports.view'],
        'sac'       => ['support.manage', 'orders.view'],
        'logistica' => ['orders.update', 'shipments.manage', 'tracking.update'],
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantSupplierScope());
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function hasPermission(string $perm): bool
    {
        $perms = $this->permissions ?? self::DEFAULT_PERMISSIONS[$this->role] ?? [];
        return in_array('*', $perms, true) || in_array($perm, $perms, true);
    }
}
