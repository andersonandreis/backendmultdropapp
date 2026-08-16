<?php

namespace App\Services\Repricing;

use App\Models\Product;
use App\Models\RepricingCostConfig;

/**
 * NOV-127 — Calculadora de repricing considerando 4 custos:
 *   custo do produto + frete + taxa marketplace + margem desejada + custo fixo extra.
 *
 * Fórmula:
 *   preço_venda = (custo_produto + extra_fixed) / (1 - (frete_pct + mp_fee_pct + margem_pct) / 100)
 *
 * Se a soma das porcentagens >= 95, força margem mínima para evitar divisão por ~zero.
 */
class RepricingCalculatorService
{
    public function calculateWithCosts(Product $product, string $marketplace, ?float $unitCostOverride = null, ?float $marginOverride = null): array
    {
        $unitCost = (float) ($unitCostOverride ?? $product->cost ?? $product->price ?? 0);
        $config = $this->resolveConfig($product->supplier_id, $marketplace, $product->category_id);

        $shippingPct = (float) ($config?->shipping_cost_pct ?? 0);
        $mpFeePct    = (float) ($config?->marketplace_fee_pct ?? 0);
        $marginPct   = (float) ($marginOverride ?? $config?->desired_margin_pct ?? 20);
        $extraFixed  = (float) ($config?->extra_cost_fixed ?? 0);

        $sumPct = $shippingPct + $mpFeePct + $marginPct;
        if ($sumPct >= 95) {
            $sumPct = 95;
        }

        $denominator = 1 - ($sumPct / 100);
        if ($denominator <= 0.01) {
            $denominator = 0.01;
        }

        $price = ($unitCost + $extraFixed) / $denominator;
        $price = round($price, 2);

        return [
            'unit_cost'       => $unitCost,
            'shipping_pct'    => $shippingPct,
            'marketplace_fee_pct' => $mpFeePct,
            'margin_pct'      => $marginPct,
            'extra_fixed'     => $extraFixed,
            'sum_pct'         => $sumPct,
            'final_price'     => $price,
            'breakdown'       => [
                'cost'               => $unitCost,
                'extra_fixed'        => $extraFixed,
                'shipping_amount'    => round($price * $shippingPct / 100, 2),
                'marketplace_fee'    => round($price * $mpFeePct / 100, 2),
                'margin_amount'      => round($price * $marginPct / 100, 2),
                'total_costs'        => $unitCost + $extraFixed + round($price * ($shippingPct + $mpFeePct) / 100, 2),
                'net_margin'         => round($price * $marginPct / 100, 2),
            ],
            'config_id' => $config?->id,
        ];
    }

    public function resolveConfig(?int $supplierId, string $marketplace, ?int $categoryId = null): ?RepricingCostConfig
    {
        if (!$supplierId) return null;

        // 1) tenta com categoria exata
        if ($categoryId) {
            $byCat = RepricingCostConfig::query()
                ->where('supplier_id', $supplierId)
                ->where('marketplace', $marketplace)
                ->where('product_category', (string) $categoryId)
                ->where('active', true)
                ->first();
            if ($byCat) return $byCat;
        }

        // 2) fallback para marketplace global do supplier
        return RepricingCostConfig::query()
            ->where('supplier_id', $supplierId)
            ->where('marketplace', $marketplace)
            ->whereNull('product_category')
            ->where('active', true)
            ->first();
    }
}
