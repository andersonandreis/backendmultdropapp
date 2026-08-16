<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SEL-UPGRADE (09/08): registro de uma cobranca de DIFERENCA gerada pelo
 * fluxo de upgrade de plano (paga so a diferenca entre o plano atual e o
 * plano alvo, sem pedir dados de novo).
 *
 * @property int    $id
 * @property int    $client_id
 * @property int    $subscription_id
 * @property int    $from_plan_id
 * @property int    $to_plan_id
 * @property int    $diff_amount_cents
 * @property string $payment_method
 * @property string $gateway
 * @property string|null $gateway_customer_id
 * @property string $gateway_order_id
 * @property string $status
 */
class PlanUpgradeCharge extends Model
{
    protected $fillable = [
        'client_id',
        'subscription_id',
        'from_plan_id',
        'to_plan_id',
        'diff_amount_cents',
        'payment_method',
        'gateway',
        'gateway_customer_id',
        'gateway_order_id',
        'status',
        'paid_at',
        'expires_at',
        'gateway_response',
    ];

    protected $casts = [
        'diff_amount_cents' => 'integer',
        'paid_at'           => 'datetime',
        'expires_at'        => 'datetime',
        'gateway_response'  => 'array',
    ];

    // Nunca expor ids/segredos de gateway pro front (mesmo padrao de Subscription::$hidden)
    protected $hidden = [
        'gateway_customer_id',
        'gateway_response',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function fromPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'from_plan_id');
    }

    public function toPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'to_plan_id');
    }
}
