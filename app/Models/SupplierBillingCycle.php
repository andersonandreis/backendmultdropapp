<?php

namespace App\Models;

use App\Models\Scopes\TenantSupplierScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierBillingCycle extends Model
{
    protected $fillable = [
        'supplier_id', 'period_start', 'period_end', 'due_date',
        'clients_active', 'orders_count',
        'amount_users', 'amount_orders', 'amount_extra', 'amount_total',
        'status', 'payment_method', 'payment_url', 'pix_qr_code',
        'paid_at', 'external_invoice_id', 'notes',
    ];

    protected $casts = [
        'period_start'   => 'date',
        'period_end'     => 'date',
        'due_date'       => 'date',
        'paid_at'        => 'datetime',
        'amount_users'   => 'decimal:2',
        'amount_orders'  => 'decimal:2',
        'amount_extra'   => 'decimal:2',
        'amount_total'   => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantSupplierScope());
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isOverdue(): bool
    {
        return !$this->isPaid() && $this->due_date && $this->due_date->isPast();
    }
}
