<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierBalance extends Model
{
    protected $fillable = [
        'producer_id',
        'warehouse_id',
        'balance',
        'total_earned',
        'total_withdrawn',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'total_earned' => 'decimal:2',
        'total_withdrawn' => 'decimal:2',
    ];

    public function producer()
    {
        return $this->belongsTo(Supplier::class, 'producer_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Supplier::class, 'warehouse_id');
    }
}
