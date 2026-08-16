<?php

namespace App\Models;

use App\Models\Scopes\TenantSupplierScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SupplierApiToken extends Model
{
    protected $fillable = [
        'supplier_id', 'name', 'token_hash', 'prefix',
        'abilities', 'last_used_at', 'expires_at', 'active',
        'created_by_user_id',
    ];

    protected $casts = [
        'abilities'    => 'array',
        'active'       => 'boolean',
        'last_used_at' => 'datetime',
        'expires_at'   => 'datetime',
    ];

    protected $hidden = ['token_hash'];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantSupplierScope());
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** Gera um token plain + retorna ['plain' => ..., 'model' => ...]. */
    public static function generate(int $supplierId, string $name, array $abilities = ['*'], ?int $userId = null): array
    {
        $plain = 'hub_sk_'.Str::random(40);
        $prefix = substr($plain, 0, 12);
        $hash = hash('sha256', $plain);

        $model = static::query()->create([
            'supplier_id'        => $supplierId,
            'name'               => $name,
            'token_hash'         => $hash,
            'prefix'             => $prefix,
            'abilities'          => $abilities,
            'active'             => true,
            'created_by_user_id' => $userId,
        ]);

        return ['plain' => $plain, 'model' => $model];
    }

    /** Localiza um token pelo valor cru. Atualiza last_used_at se válido. */
    public static function findByPlain(string $plain): ?self
    {
        $hash = hash('sha256', $plain);
        $model = static::query()
            ->withoutGlobalScopes()
            ->where('token_hash', $hash)
            ->where('active', true)
            ->first();
        if ($model) {
            if ($model->expires_at && $model->expires_at->isPast()) {
                return null;
            }
            $model->last_used_at = now();
            $model->saveQuietly();
        }
        return $model;
    }
}
