<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PixTransaction extends Model
{
    protected $fillable = [
        'supplier_id',
        'client_id',
        'order_id',
        'type',
        'gateway',
        'external_id',
        'amount',
        'fee_amount',
        'net_amount',
        'status',
        'qr_code',
        'qr_code_text',
        'expires_at',
        'paid_at',
        'gateway_response',
        'idempotency_key',
        'order_ids',
        'balance_used',
    ];

    protected $casts = [
        'gateway_response' => 'array',
        'expires_at'       => 'datetime',
        'paid_at'          => 'datetime',
        'amount'           => 'decimal:2',
        'fee_amount'       => 'decimal:2',
        'net_amount'       => 'decimal:2',
    ];

    // -------------------------------------------------------------------------
    // Relacionamentos
    // -------------------------------------------------------------------------

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    /**
     * Transacoes expiradas: status pending e expires_at no passado.
     */
    public function scopeExpired($query)
    {
        return $query->where('status', 'pending')
            ->where('expires_at', '<', now());
    }

    // -------------------------------------------------------------------------
    // Metodos de estado
    // -------------------------------------------------------------------------

    /**
     * Verifica se esta transacao ja expirou (sem alterar o banco).
     */
    public function isExpired(): bool
    {
        return $this->expires_at
            && $this->expires_at->isPast()
            && $this->status === 'pending';
    }

    /**
     * Marca a transacao como paga e registra o timestamp.
     */
    public function markAsPaid(): void
    {
        $this->update([
            'status'  => 'paid',
            'paid_at' => now(),
        ]);
    }

    /**
     * Marca a transacao como expirada.
     */
    public function markAsExpired(): void
    {
        $this->update(['status' => 'expired']);
    }
}
