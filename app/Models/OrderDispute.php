<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDispute extends Model
{
    protected $fillable = [
        'order_id',
        'status',
        'reason',
        'description',
        'invoice_xml_path',
        'invoice_pdf_path',
        'invoice_xml_url',
        'invoice_pdf_url',
        'resolution_notes',
        'opened_by_user_id',
        'resolved_by_user_id',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    // ----------------------------------------------------------------
    // Relations
    // ----------------------------------------------------------------

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isResolved(): bool
    {
        return in_array($this->status, ['resolved', 'rejected']);
    }
}
