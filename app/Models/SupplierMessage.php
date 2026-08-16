<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenantSupplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NOV-148 — Mensagem broadcast enviada por supplier para clientes.
 *
 * Channels: email, sms, push, whatsapp.
 * recipient_type: all (toda base), client (especifico), segment (futuro).
 */
class SupplierMessage extends Model
{
    use BelongsToTenantSupplier;

    protected $fillable = [
        'supplier_id', 'recipient_type', 'recipient_id', 'channel',
        'subject', 'body', 'sent_at', 'status',
        'recipients_count', 'delivered_count', 'failed_count',
        'error_message', 'created_by_user_id',
        'payload', 'response',
    ];

    protected $casts = [
        'sent_at'          => 'datetime',
        'recipients_count' => 'integer',
        'delivered_count'  => 'integer',
        'failed_count'     => 'integer',
        'payload'          => 'array',
        'response'         => 'array',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function recipientClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'recipient_id');
    }
}
