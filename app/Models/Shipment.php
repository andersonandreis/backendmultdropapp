<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shipment extends Model
{
    protected $fillable = [
        'producer_id',
        'warehouse_id',
        'shipment_number',
        'status',
        'notes',
        'total_items',
        'total_checked',
        'sent_at',
        'received_at',
        'checked_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'received_at' => 'datetime',
        'checked_at' => 'datetime',
    ];

    public function producer()
    {
        return $this->belongsTo(Supplier::class, 'producer_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Supplier::class, 'warehouse_id');
    }

    public function items()
    {
        return $this->hasMany(ShipmentItem::class);
    }
}
