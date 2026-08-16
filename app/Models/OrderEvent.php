<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderEvent extends Model
{
    protected $fillable = [
        'order_id',
        'event_type',
        'description',
        'metadata',
        'user_id',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
