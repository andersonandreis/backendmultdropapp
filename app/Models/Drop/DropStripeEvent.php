<?php

namespace App\Models\Drop;

use Illuminate\Database\Eloquent\Model;
use App\Models\Client;

/**
 * Modelo para eventos Stripe recebidos via webhook no modulo Drop Internacional.
 *
 * @property int         $id
 * @property int         $client_id
 * @property string      $stripe_event_id   ID unico do evento Stripe (idempotencia)
 * @property string      $type              Tipo do evento (ex: payment_intent.succeeded)
 * @property int|null    $drop_order_id     Pedido relacionado, quando aplicavel
 * @property float|null  $amount            Valor em USD, quando aplicavel
 * @property string|null $currency          Moeda (ex: usd)
 * @property string      $status            Estado do processamento: pending|processed|dispute|refunded|error
 * @property string      $payload           JSON raw do evento Stripe
 * @property \Carbon\Carbon|null $processed_at
 */
class DropStripeEvent extends Model
{
    protected $table = 'drop_stripe_events';

    protected $fillable = [
        'client_id',
        'stripe_event_id',
        'type',
        'drop_order_id',
        'amount',
        'currency',
        'status',
        'payload',
        'processed_at',
    ];

    protected $casts = [
        'amount'       => 'decimal:4',
        'processed_at' => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Constantes de status
    // -------------------------------------------------------------------------

    const STATUS_PENDING   = 'pending';
    const STATUS_PROCESSED = 'processed';
    const STATUS_DISPUTE   = 'dispute';
    const STATUS_REFUNDED  = 'refunded';
    const STATUS_ERROR     = 'error';

    // -------------------------------------------------------------------------
    // Relacionamentos
    // -------------------------------------------------------------------------

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function dropOrder()
    {
        return $this->belongsTo(DropOrder::class, 'drop_order_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}
