<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    protected $table = 'inventory';

    protected $fillable = [
        'warehouse_id',
        'product_id',
        'producer_id',
        'quantity',
        'reserved',
        'warehouse_price',
        'stock_alert_threshold',
    ];

    protected $casts = [
        'warehouse_price' => 'decimal:2',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Supplier::class, 'warehouse_id');
    }

    public function producer()
    {
        return $this->belongsTo(Supplier::class, 'producer_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
