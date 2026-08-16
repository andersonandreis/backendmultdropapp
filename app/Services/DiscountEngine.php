<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Product;
use App\Models\Coupon;
use App\Models\SupplierDiscount;
use App\Models\PlatformDiscount;

class DiscountEngine
{
    /**
     * Calcula o preço final que um Lojista (Client) pagará por um Produto
     * após aplicar todos os Descontos (Planos, Volume, Cupons)
     */
    public function calculateFinalPrice(Product $product, Client $client, int $quantity = 1, ?Coupon $coupon = null): float
    {
        $basePrice = $product->price * $quantity;
        $discountAmount = 0.0;

        // 1. Desconto por Plano (Subscription)
        $discountAmount += $this->calculatePlanDiscount($basePrice, $client);

        // 2. Desconto do Fornecedor (SupplierDiscount)
        $discountAmount += $this->calculateSupplierDiscount($basePrice, $product->supplier_id, $quantity);

        // 3. Descontos da Plataforma Fixos/Tiers (PlatformDiscount)
        $discountAmount += $this->calculatePlatformDiscount($basePrice, $quantity);

        // 4. Cupom Específico
        if ($coupon && $coupon->isValid()) {
            $discountAmount += $this->calculateCouponDiscount($basePrice, $coupon);
        }

        // Garante que o desconto não ultrapasse o valor original (ou um limite mínimo global)
        $finalPrice = max(0, $basePrice - $discountAmount);

        return round($finalPrice, 2);
    }

    protected function calculatePlanDiscount(float $basePrice, Client $client): float
    {
        $subscription = $client->subscriptions()->where('status', 'active')->first();
        if (!$subscription || !$subscription->plan) {
            return 0.0;
        }

        // Mock: $subscription->plan->discount_percentage
        $pct = 0; // Ex: PlanDiscount logic
        return $basePrice * ($pct / 100);
    }

    protected function calculateSupplierDiscount(float $basePrice, int $supplierId, int $quantity): float
    {
        // Pega regra do galpao p/ quant
        $rule = SupplierDiscount::where('supplier_id', $supplierId)
            ->where('is_active', true)
            ->where('min_quantity', '<=', $quantity)
            ->orderBy('min_quantity', 'desc')
            ->first();

        if ($rule) {
            if ($rule->discount_type === 'percentage') {
                return $basePrice * ($rule->discount_value / 100);
            }
            return $rule->discount_value * $quantity; // fixo por item
        }

        return 0.0;
    }

    protected function calculatePlatformDiscount(float $basePrice, int $quantity): float
    {
        // Mesma logica com PlatformDiscount
        return 0.0;
    }

    protected function calculateCouponDiscount(float $basePrice, Coupon $coupon): float
    {
        if ($coupon->type === 'percentage') {
            return $basePrice * ($coupon->value / 100);
        }
        return $coupon->value;
    }
}
