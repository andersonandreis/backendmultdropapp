<?php

namespace App\Models\Drop;

use Illuminate\Database\Eloquent\Model;

class DropSupplierOrder extends Model
{
    protected $table = 'drop_supplier_orders';

    protected $fillable = [
        'drop_order_id',
        'supplier_slug',
        'external_order_id',
        'product_url',
        'variant_title',
        'cost_paid_usd',
        'status',
        'tracking_code',
        'tracking_carrier',
        'purchase_evidence',
        'notes',
        'ordered_at',
    ];

    protected $casts = [
        'cost_paid_usd'     => 'decimal:4',
        'purchase_evidence' => 'array',
        'ordered_at'        => 'datetime',
    ];

    public function dropOrder()
    {
        return $this->belongsTo(DropOrder::class, 'drop_order_id');
    }

    public function trackingUpdates()
    {
        return $this->hasMany(DropTrackingUpdate::class, 'drop_supplier_order_id');
    }
}
