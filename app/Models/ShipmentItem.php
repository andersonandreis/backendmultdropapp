<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipmentItem extends Model
{
    protected $fillable = [
        'shipment_id',
        'product_id',
        'quantity',
        'quantity_received',
        'unit_cost',
        'label_code',
        'checked_at',
    ];

    protected $casts = [
        'unit_cost' => 'decimal:2',
        'checked_at' => 'datetime',
    ];

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
