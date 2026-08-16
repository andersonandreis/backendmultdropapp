<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientSupplierTransaction extends Model
{
    protected $fillable = [
        'client_id',
        'supplier_id',
        'type', // 'credit' ou 'debit'
        'amount',
        'description',
        'order_id',
        'reference',
        'running_balance',
        'transaction_type',
        'pix_transaction_id',
        // MUL-363 Fase 0 — campos do ledger canonico (WalletLedger)
        'idempotency_key',
        'actor',
        'origin',
        'reverses_transaction_id',
        'meta',
    ];

    protected $casts = [
        'running_balance' => 'decimal:2',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function pixTransaction()
    {
        return $this->belongsTo(PixTransaction::class);
    }
}
