<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SEL-GERENTE (09/08): token de convite gerado por um afiliado GERENTE.
 * Quem aceita entra com manager_id = manager_affiliate_id + video_gen_authorized = false.
 */
class AffiliateManagerInvite extends Model
{
    protected $fillable = [
        'manager_affiliate_id',
        'token',
        'created_by_user_id',
        'used_by_affiliate_id',
        'used_at',
        'expires_at',
    ];

    protected $casts = [
        'used_at'    => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function manager()
    {
        return $this->belongsTo(Affiliate::class, 'manager_affiliate_id');
    }

    public function usedBy()
    {
        return $this->belongsTo(Affiliate::class, 'used_by_affiliate_id');
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isValid(): bool
    {
        return ! $this->isUsed() && ! $this->isExpired();
    }
}
