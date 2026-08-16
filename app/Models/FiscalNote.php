<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'supplier_id',
        'client_id',
        'source',
        'nf_key',
        'nf_number',
        'nf_series',
        'status',
        'issued_at',
        'value',
        'xml_url',
        'pdf_url',
        'external_id',
        'raw_data',
    ];

    protected $casts = [
        'issued_at'             => 'datetime',
        'value'                 => 'decimal:2',
        'raw_data'              => 'array',
        'auto_invoice_enabled'  => 'boolean',
    ];

    // ─── Relações ────────────────────────────────────────────────────────────

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────

    public function scopeIssued($query)
    {
        return $query->where('status', 'issued');
    }

    public function scopeBySource($query, string $source)
    {
        return $query->where('source', $source);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    public function isIssued(): bool
    {
        return $this->status === 'issued';
    }

    public function hasPdf(): bool
    {
        return !empty($this->pdf_url);
    }

    public function hasXml(): bool
    {
        return !empty($this->xml_url);
    }
}
