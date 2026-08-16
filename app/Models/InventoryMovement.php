<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenantSupplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NOV-115 — Log imutável de movimentação de estoque.
 *
 * Cada movimentação gera 1 registro. Tipo identifica origem:
 *  - entrada/saida/ajuste/zerar: manual via painel
 *  - venda: dispatch automático pelo OrderObserver (NOV-117)
 *  - devolucao: OrderReturnService (NOV-120)
 *  - sync_marketplace: alteração externa não rastreada via observer
 */
class InventoryMovement extends Model
{
    use BelongsToTenantSupplier;

    protected $table = 'inventory_movements';

    public const UPDATED_AT = null;

    protected $fillable = [
        'supplier_id',
        'product_id',
        'variation_id',
        'inventory_id',
        'type',
        'qty_before',
        'qty_change',
        'qty_after',
        'reference_type',
        'reference_id',
        'marketplace',
        'notes',
        'user_id',
    ];

    protected $casts = [
        'qty_before' => 'integer',
        'qty_change' => 'integer',
        'qty_after'  => 'integer',
        'created_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(ProductVariation::class, 'variation_id');
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
