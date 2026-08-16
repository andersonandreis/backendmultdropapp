<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SEL-345: Modelo de referral com campos de contexto adicionados.
 */
class AffiliateReferral extends Model
{
    protected $fillable = [
        'affiliate_id',
        'referred_client_id',
        'referral_code',
        'ip_address',
        'user_agent',
        'attributed_at',
        'converted_at',
        'status',
        'landing_url',
        'utm_source',
    ];

    protected $casts = [
        'attributed_at' => 'datetime',
        'converted_at'  => 'datetime',
    ];

    public function affiliate()
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function referredClient()
    {
        return $this->belongsTo(Client::class, 'referred_client_id');
    }
}
