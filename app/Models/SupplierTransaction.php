<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierTransaction extends Model
{
    protected $fillable = [
        'producer_id',
        'warehouse_id',
        'type', // sale, withdrawal, adjustment
        'amount',
        'description',
        'order_id',
        'withdrawal_request_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function producer()
    {
        return $this->belongsTo(Supplier::class, 'producer_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'producer_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Supplier::class, 'warehouse_id');
    }
}
