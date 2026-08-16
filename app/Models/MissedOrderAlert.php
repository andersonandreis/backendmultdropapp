<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Representa uma venda detectada no marketplace que não chegou via webhook.
 *
 * Ciclo de vida do alerta (status):
 *  pending    → criado, cliente ainda não agiu
 *  notified   → pelo menos uma notificação enviada
 *  dismissed  → cliente dispensou (ignored / not_mine / cancelled_at_marketplace)
 *  converted  → cliente registrou o pedido manualmente via wizard
 */
class MissedOrderAlert extends Model
{
    use HasFactory;

    /**
     * Nome real da tabela no banco — migration usa 'missed_orders_alerts' (plural composto).
     * Sem esta declaração o Laravel inferiria 'missed_order_alerts' e quebraria todas as queries.
     */
    protected $table = 'missed_orders_alerts';

    protected $fillable = [
        'client_id',
        'marketplace_account_id',
        'marketplace',
        'marketplace_order_id',
        'buyer_name',
        'buyer_doc',
        'amount_cents',
        'currency',
        'dismiss_reason',
        'detected_at',
        'notified_at',
        'dismissed_at',
        'converted_to_order_id',
        'notification_count',
        'payload',
    ];

    protected $casts = [
        'detected_at'  => 'datetime',
        'notified_at'  => 'datetime',
        'dismissed_at' => 'datetime',
        'payload'      => 'array',
        'notification_count' => 'integer',
        'amount_cents'       => 'integer',
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function marketplaceAccount(): BelongsTo
    {
        return $this->belongsTo(MarketplaceAccount::class);
    }

    public function convertedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'converted_to_order_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * Alertas que ainda aguardam ação (não dispensados e não convertidos).
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('dismissed_at')
                     ->whereNull('converted_to_order_id');
    }

    /**
     * Filtro por cliente — útil em contextos onde o auth já garantiu o client_id.
     */
    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    /**
     * Status computado a partir dos timestamps de ciclo de vida.
     *
     * @return 'pending'|'notified'|'dismissed'|'converted'
     */
    public function getStatusAttribute(): string
    {
        if ($this->converted_to_order_id !== null) {
            return 'converted';
        }

        if ($this->dismissed_at !== null) {
            return 'dismissed';
        }

        if ($this->notified_at !== null) {
            return 'notified';
        }

        return 'pending';
    }

    // -------------------------------------------------------------------------
    // Business logic
    // -------------------------------------------------------------------------

    /**
     * Verifica se é permitido enviar mais uma notificação.
     * Hard cap: 3 notificações por alerta (criação + digest D+1 + digest D+3).
     */
    public function canNotify(): bool
    {
        return $this->notification_count < 3 && $this->status !== 'dismissed' && $this->status !== 'converted';
    }
}
