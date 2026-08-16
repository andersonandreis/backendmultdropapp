<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * SEL-171: Rastreio de subsidio pago pelo Ruan (por fora) pro fornecedor
 * quando um cliente elegivel usa o bonus 50% off das 3 primeiras vendas
 * do catalogo. Um pedido = uma row (unique em order_id).
 */
class CatalogBonusSubsidy extends Model
{
    use HasFactory;

    protected $table = 'catalog_bonus_subsidies';

    protected $fillable = [
        'client_id',
        'supplier_id',
        'order_id',
        'product_name',
        'product_id',
        'original_price',
        'discounted_price',
        'subsidy_amount',
        'status',
        'paid_at',
        'paid_by_admin_id',
        'admin_note',
    ];

    protected $casts = [
        'client_id' => 'integer',
        'supplier_id' => 'integer',
        'order_id' => 'integer',
        'product_id' => 'integer',
        'original_price' => 'decimal:2',
        'discounted_price' => 'decimal:2',
        'subsidy_amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'paid_by_admin_id' => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function paidByAdmin()
    {
        return $this->belongsTo(User::class, 'paid_by_admin_id');
    }
}
