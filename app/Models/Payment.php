<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'order_id',
        'client_id',
        'supplier_id',
        'gateway',
        'method',
        'amount',
        'wallet_amount',
        'pix_amount',
        'fee_amount',
        'status',
        'external_id',
        'gateway_response',
        'paid_at',
        'pix_transaction_id',
        'refunded_at',
        'refund_amount',
        'refund_reason',
    ];

    protected $casts = [
        'amount'           => 'decimal:2',
        'wallet_amount'    => 'decimal:2',
        'pix_amount'       => 'decimal:2',
        'fee_amount'       => 'decimal:2',
        'refund_amount'    => 'decimal:2',
        'gateway_response' => 'array',
        'paid_at'          => 'datetime',
        'refunded_at'      => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function pixTransaction()
    {
        return $this->belongsTo(PixTransaction::class);
    }
}
