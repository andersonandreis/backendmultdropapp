<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * SEL-345: Model Affiliate com suporte a aprovacao manual e quotas.
 */
class Affiliate extends Model
{
    protected $fillable = [
        'user_id',
        'referral_code',
        'commission_rate',
        'status',
        'total_earned',
        'total_withdrawn',
        'pix_key',
        'pix_type',
        // SEL-345: campos de aplicacao
        'application_name',
        'application_email',
        'application_phone',
        'application_instagram',
        'application_tiktok',
        'application_motivation',
        // SEL-345: aprovacao
        'approval_status',
        'approved_at',
        'approved_by',
        'rejection_reason',
        // SEL-345: quotas/perks
        'max_ai_videos_per_month',
        'granted_plan_slug',
        'perks',
        'custom_referral_slug',
        // SEL-GERENTE (09/08): hierarquia de gerente de afiliados
        'is_manager',
        'manager_id',
        'video_gen_authorized',
        'manager_override_rate',
        // SEL-GERENTE (09/08 tarde): comissao que o gerente da a este afiliado
        // dentro do teto do proprio pool (manager_override_rate)
        'manager_commission_rate',
    ];

    protected $casts = [
        'commission_rate'          => 'decimal:2',
        'total_earned'             => 'decimal:2',
        'total_withdrawn'          => 'decimal:2',
        'perks'                    => 'array',
        'approved_at'              => 'datetime',
        'is_manager'               => 'boolean',
        'video_gen_authorized'     => 'boolean',
        'manager_override_rate'    => 'decimal:2',
        'manager_commission_rate'  => 'decimal:2',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function referrals()
    {
        return $this->hasMany(AffiliateReferral::class);
    }

    public function commissions()
    {
        return $this->hasMany(AffiliateCommission::class);
    }

    public function withdrawals()
    {
        return $this->hasMany(AffiliateWithdrawal::class);
    }

    // SEL-GERENTE (09/08): hierarquia gerente <-> afiliados sob ele
    public function manager()
    {
        return $this->belongsTo(Affiliate::class, 'manager_id');
    }

    public function subAffiliates()
    {
        return $this->hasMany(Affiliate::class, 'manager_id');
    }

    public function managerInvites()
    {
        return $this->hasMany(AffiliateManagerInvite::class, 'manager_affiliate_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('approval_status', 'approved');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('approval_status', 'pending');
    }

    public function scopeManagers(Builder $query): Builder
    {
        return $query->where('is_manager', true);
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    public function getBalanceAttribute(): float
    {
        return round((float) $this->total_earned - (float) $this->total_withdrawn, 2);
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->user?->name ?? $this->application_name ?? 'Sem nome';
    }

    /**
     * SEL-GERENTE (09/08): afiliado sob gerente sem liberacao explicita nao gera video.
     */
    public function isBlockedFromVideoGen(): bool
    {
        return $this->manager_id !== null && $this->video_gen_authorized === false;
    }
}
