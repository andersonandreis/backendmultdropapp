<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SEL-186: trilha de cupons aplicados em assinaturas.
 *
 * @property int    $id
 * @property int    $subscription_id
 * @property int    $coupon_id
 * @property float  $discount_amount_applied  valor descontado em R$
 * @property \Carbon\Carbon $applied_at
 */
class SubscriptionCoupon extends Model
{
    protected $fillable = [
        'subscription_id',
        'coupon_id',
        'discount_amount_applied',
        'applied_at',
    ];

    protected $casts = [
        'discount_amount_applied' => 'decimal:2',
        'applied_at'              => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
}
