<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NOV-120 — Devolução de itens de pedido.
 * Cada item devolvido gera 1 linha + 1 InventoryMovement type=devolucao.
 */
class OrderReturn extends Model
{
    protected $table = 'order_returns';

    public const UPDATED_AT = null;

    protected $fillable = [
        'order_id',
        'order_item_id',
        'qty_returned',
        'reason',
        'user_id',
    ];

    protected $casts = [
        'qty_returned' => 'integer',
        'created_at'   => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
