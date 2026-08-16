<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * MUL-227 item 31 Fase 4 — usuário secundário criado pelo dono da conta.
 * `permissions` é hash de menus permitidos. Null/[] = todos.
 */
class SubUser extends Model
{
    protected $fillable = [
        'parent_user_id',
        'name',
        'email',
        'password',
        'permissions',
        'is_active',
        'last_login_at',
        'created_by',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'permissions'   => 'array',
        'is_active'     => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function parent()
    {
        return $this->belongsTo(User::class, 'parent_user_id');
    }

    public function canAccess(string $menuKey): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $perms = $this->permissions;
        if (empty($perms)) {
            return true; // sem restrição = liberado (comportamento default)
        }

        return (bool) ($perms[$menuKey] ?? false);
    }
}
