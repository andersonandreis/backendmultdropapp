<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    protected $fillable = [
        'code',
        'description',
        'owner_type',
        'owner_id',
        'discount_type',
        'discount_value',
        'min_order_value',
        'max_uses',
        'max_uses_per_client',
        'uses_count',
        'starts_at',
        'ends_at',
        'is_active',
        'applies_to_plan_slug',
    ];

    protected $casts = [
        'discount_value'  => 'decimal:2',
        'min_order_value' => 'decimal:2',
        'starts_at'       => 'datetime',
        'ends_at'         => 'datetime',
        'is_active'       => 'boolean',
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function subscriptionCoupons(): HasMany
    {
        return $this->hasMany(SubscriptionCoupon::class);
    }

    // -------------------------------------------------------------------------
    // Business methods
    // -------------------------------------------------------------------------

    /**
     * Verifica se o cupom e valido pra ser aplicado.
     * Retorna null se OK, ou string com motivo de invalidade.
     *
     * @param  string|null $planSlug  slug do plano que o cliente esta tentando assinar
     * @param  int|null    $clientId  para checar max_uses_per_client
     */
    public function validateForCheckout(?string $planSlug = null, ?int $clientId = null): ?string
    {
        if (!$this->is_active) {
            return 'Cupom inativo.';
        }

        $now = now();

        if ($this->starts_at && $now->lt($this->starts_at)) {
            return 'Cupom ainda nao esta disponivel.';
        }

        if ($this->ends_at && $now->gt($this->ends_at)) {
            return 'Cupom expirado.';
        }

        if ($this->max_uses !== null && $this->uses_count >= $this->max_uses) {
            return 'Cupom esgotado.';
        }

        if ($this->applies_to_plan_slug && $planSlug && $this->applies_to_plan_slug !== $planSlug) {
            return "Cupom valido apenas para o plano {$this->applies_to_plan_slug}.";
        }

        if ($clientId && $this->max_uses_per_client !== null) {
            $usedByClient = SubscriptionCoupon::whereHas(
                'subscription',
                fn ($q) => $q->where('client_id', $clientId)
            )->where('coupon_id', $this->id)->count();

            if ($usedByClient >= $this->max_uses_per_client) {
                return 'Voce ja utilizou este cupom o numero maximo de vezes.';
            }
        }

        return null;
    }

    /**
     * Calcula o valor do desconto dado o preco base em R$.
     * Retorna o valor descontado (nao o preco final).
     */
    public function calculateDiscount(float $basePrice): float
    {
        if ($this->discount_type === 'percentage') {
            return round($basePrice * ($this->discount_value / 100), 2);
        }

        if ($this->discount_type === 'fixed') {
            return min(round((float) $this->discount_value, 2), $basePrice);
        }

        return 0.0;
    }
}
