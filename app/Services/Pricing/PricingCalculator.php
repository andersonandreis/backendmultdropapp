<?php

namespace App\Services\Pricing;

use App\Models\Product;
use App\Models\MarketplaceFee;
use App\Models\Client;
use App\Models\Supplier;
use App\Services\DiscountEngine;

class PricingCalculator
{
    /**
     * Calcula dinamicamente o Preço Final que será vendido no Mktplace 
     * Baseando-se no markup percentual desejado, custo base e Taxa do Listing
     */
    public function calculateFinalPrice(
        Client $client,
        Product $product,
        float $desiredMarginPercent,
        string $platform,
        ?string $listingTypeId = null
    ): array {

        // 1. Quanto Custa o Produto para o Cliente? (Usa DiscountEngine de Compra)
        $costToBuy = app(DiscountEngine::class)->calculateFinalPrice($product, $client);

        // 2. Definir Taxa da Plataforma Mktplace
        $feeQuery = MarketplaceFee::where('platform', $platform)->where('is_active', true);
        if ($listingTypeId) {
            $feeQuery->where('listing_type_id', $listingTypeId);
        }
        $marketplaceFeeRule = $feeQuery->first();

        $feePct = $marketplaceFeeRule ? ($marketplaceFeeRule->fee_percentage / 100) : 0.16; // 16% Mock fallback
        $fixedFee = $marketplaceFeeRule ? $marketplaceFeeRule->fixed_fee : 0; // Ex: + R$5 por venda

        // 3. Formula Inversa para Garantir Margem de Lucro Bruta sobre a Venda
        // Price = (Custo + Fixo) / (1 - TaxaMktplace - MargemLojista)
        $marginPct = $desiredMarginPercent / 100;

        // Proteção pra divisão por zero/negativo
        $divisor = (1 - $feePct - $marginPct);
        if ($divisor <= 0) {
            $divisor = 0.05; // 5% minimum rescue
        }

        $suggestedPrice = ($costToBuy + $fixedFee) / $divisor;
        $suggestedPrice = round($suggestedPrice, 2);

        // Resultados Extras pra View
        $feeAmount = ($suggestedPrice * $feePct) + $fixedFee;
        $profit = $suggestedPrice - $costToBuy - $feeAmount;

        return [
            'supplier_cost' => $costToBuy,
            'suggested_retail_price' => $suggestedPrice,
            'marketplace_fee_percent' => $feePct * 100,
            'marketplace_fee_absolute' => round($feeAmount, 2),
            'estimated_profit' => round($profit, 2)
        ];
    }

    /**
     * Calcula os 3 niveis de taxa para um preco dado.
     *
     * Nivel 1 — Taxa HubAI (config global HUBAI_PLATFORM_FEE_PERCENT, default 0%)
     * Nivel 2 — Taxa do fornecedor (pix_fee do SupplierPaymentSetting: fixed ou percentual)
     * Nivel 3 — Taxa do marketplace (MarketplaceFee via MarketplaceFeeService)
     *
     * Retorna breakdown completo com net_price pos-taxas.
     */
    public function calculateTotalFees(
        float $price,
        Supplier $supplier,
        ?string $platform = null,
        ?string $categoryId = null,
        ?string $listingType = null
    ): array {
        $feeService = app(MarketplaceFeeService::class);

        // --- Nivel 1: Taxa da plataforma HubAI (config global) ---
        $platformFeePercent = (float) config('hubai.platform_fee_percent', 0);
        $platformFee        = round($price * $platformFeePercent / 100, 2);

        // --- Nivel 2: Taxa do fornecedor (PIX fee configurada no supplier) ---
        $supplierFee = 0.0;
        $settings    = $supplier->paymentSetting;

        if ($settings && $settings->pix_fee_value > 0) {
            if ($settings->pix_fee_type === 'fixed') {
                $supplierFee = (float) $settings->pix_fee_value;
            } else {
                // Percentual
                $supplierFee = round($price * ((float) $settings->pix_fee_value / 100), 2);
            }
        }

        // --- Nivel 3: Taxa do marketplace ---
        $marketplaceFee = 0.0;
        if ($platform) {
            $marketplaceFee = $feeService->calculateFee($platform, $categoryId, $listingType, $price);
        }

        $totalFee  = round($platformFee + $supplierFee + $marketplaceFee, 2);
        $netPrice  = round(max(0.0, $price - $totalFee), 2);
        $feePct    = $price > 0 ? round(($totalFee / $price) * 100, 2) : 0.0;

        return [
            'price'                  => $price,
            'platform_fee'           => $platformFee,
            'platform_fee_percent'   => $platformFeePercent,
            'supplier_fee'           => $supplierFee,
            'supplier_fee_type'      => $settings?->pix_fee_type ?? null,
            'marketplace_fee'        => $marketplaceFee,
            'marketplace_platform'   => $platform,
            'total_fee'              => $totalFee,
            'net_price'              => $netPrice,
            'fee_percentage'         => $feePct,
        ];
    }
}
