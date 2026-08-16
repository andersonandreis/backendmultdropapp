<?php

namespace App\Models\Drop;

use Illuminate\Database\Eloquent\Model;

class DropTrackingUpdate extends Model
{
    protected $table = 'drop_tracking_updates';

    protected $fillable = [
        'drop_supplier_order_id',
        'status',
        'location',
        'description',
        'occurred_at',
        'source',
        'raw_payload',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function supplierOrder()
    {
        return $this->belongsTo(DropSupplierOrder::class, 'drop_supplier_order_id');
    }
}
