<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Barcode de produto (EAN13, QR, DUN14, etc.).
 *
 * Usado no fluxo de picking/packing via scanner — um produto pode ter
 * multiplos codigos de barras (ex: embalagem individual + caixa master).
 *
 * @property int    $id
 * @property int    $product_id
 * @property string $barcode
 * @property string $type
 * @property bool   $is_active
 */
class ProductBarcode extends Model
{
    protected $fillable = [
        'product_id',
        'barcode',
        'type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // =========================================================================
    // Relacionamentos
    // =========================================================================

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
