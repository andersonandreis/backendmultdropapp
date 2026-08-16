<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenantSupplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NOV-145 — Deposito / fornecedor parceiro do supplier.
 *
 * Equivale a 'depositos' do legado. Cada supplier mantem N depositos
 * (matriz, filial, dropshipping parceiro).
 */
class SupplierWarehouse extends Model
{
    use BelongsToTenantSupplier;

    protected $fillable = [
        'supplier_id', 'legacy_deposito_id', 'name',
        'address', 'number', 'complement', 'district',
        'city', 'state', 'zip_code',
        'contact_name', 'contact_phone', 'contact_email',
        'active', 'is_default',
    ];

    protected $casts = [
        'active'     => 'boolean',
        'is_default' => 'boolean',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
