<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WithdrawalRequest extends Model
{
    protected $fillable = [
        'producer_id',
        'warehouse_id',
        'amount',
        'status',
        'pix_key',
        'rejection_reason',
        'requested_at',
        'approved_at',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
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
