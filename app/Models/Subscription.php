<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = [
        'client_id',
        'plan_id',
        'status',
        'payment_method',
        'external_payment_id',
        'current_period_start',
        'current_period_end',
        'trial_ends_at',
        'cancelled_at',
        'pagarme_subscription_id',
        'pagarme_customer_id',
        'pagarme_status',
        // INF-030-MARCA (13/08): qual marca (seller.global | tokfy) essa
        // compra especifica veio de — gravado pelo CheckoutController a
        // partir do Origin/Referer. Ver App\Support\BrandKit.
        'marca',
    ];

    protected $casts = [
        'trial_ends_at'        => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end'   => 'datetime',
        'cancelled_at'         => 'datetime',
        'created_at'           => 'datetime',
        'updated_at'           => 'datetime',
    ];

    // SEL-182 pentest fix: ids do Pagar.me nao devem ir pro front (usados
    // internamente pra chamadas backend->gateway). GET /api/v1/profile expunha
    // esses campos.
    protected $hidden = [
        'pagarme_subscription_id',
        'pagarme_customer_id',
        'pagarme_status',
        'external_payment_id',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }
}
