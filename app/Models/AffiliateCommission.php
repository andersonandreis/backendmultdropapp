<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AffiliateCommission extends Model
{
    protected $fillable = [
        'affiliate_id',
        'referral_id',
        'subscription_id',
        'gross_amount',
        'commission_rate',
        'commission_amount',
        'status',
        'paid_at',
        'notes',
        // SEL-387: faltavam no fillable — plan_slug era descartado silenciosamente
        'event_type',
        'plan_slug',
    ];

    protected $casts = [
        'gross_amount'      => 'decimal:2',
        'commission_rate'   => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'paid_at'           => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function affiliate()
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function referral()
    {
        return $this->belongsTo(AffiliateReferral::class, 'referral_id');
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }
}
