<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderLabelQueue extends Model
{
    protected $fillable = [
        'order_id',
        'status',
        'next_check_at',
        'attempts',
        'error_log',
    ];

    protected $casts = [
        'next_check_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
