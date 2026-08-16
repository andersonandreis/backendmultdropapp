<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Operador de picking/packing.
 *
 * Identificado pelo badge_code (cracha bipado via scanner) nas estacoes
 * de picking/packing. Cada operador pertence a um supplier (isolamento tenant).
 *
 * @property int    $id
 * @property int    $supplier_id
 * @property string $name
 * @property string $badge_code
 * @property bool   $is_active
 */
class Operator extends Model
{
    protected $fillable = [
        'supplier_id',
        'name',
        'badge_code',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // =========================================================================
    // Relationships
    // =========================================================================

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Filtra pelo supplier do usuario logado.
     * Usa LOCAL_SUPPLIER_ID como fallback para instalacoes fixas (multdrop.app).
     */
    public function scopeForCurrentSupplier($query)
    {
        $supplierId = auth()->user()?->supplier?->id
            ?? config('multdrop.supplier_id');

        return $query->where('supplier_id', $supplierId);
    }

    // =========================================================================
    // Static helpers
    // =========================================================================

    /**
     * Localiza operador pelo badge_code dentro do supplier atual.
     */
    public static function findByBadge(string $badgeCode): ?static
    {
        $supplierId = auth()->user()?->supplier?->id
            ?? config('multdrop.supplier_id');

        return static::where('badge_code', $badgeCode)
            ->where('supplier_id', $supplierId)
            ->where('is_active', true)
            ->first();
    }
}
