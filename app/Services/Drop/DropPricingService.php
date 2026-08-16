<?php

namespace App\Services\Drop;

use App\Models\Drop\DropModuleConfig;
use App\Models\Drop\DropOrder;
use App\Models\Drop\DropPricingRule;
use App\Models\Drop\ImportedProduct;

/**
 * Drop Internacional — Serviço de precificação em USD.
 *
 * Responsável por:
 *  - Calcular margem de lucro dado custo + frete + preço de venda
 *  - Sugerir preço de venda dado custo + markup desejado (fórmula inversa)
 *  - Calcular lucro real de um pedido Drop já registrado
 *  - Buscar a regra de precificação ativa para um cliente (global ou por fornecedor)
 *  - Aplicar regra de precificação a um produto importado
 */
class DropPricingService
{
    /**
     * Calcular margem de lucro em USD.
     *
     * margin = sellPrice
     *        - costUsd
     *        - shippingUsd
     *        - (sellPrice * gatewayFeePct / 100)
     *        - (sellPrice * platformFeePct / 100)
     *
     * @param  float  $costUsd         Custo do produto no fornecedor (USD)
     * @param  float  $shippingUsd     Custo do frete pago ao fornecedor (USD)
     * @param  float  $sellPrice       Preço de venda ao consumidor (USD)
     * @param  float  $gatewayFeePct   Taxa do gateway de pagamento (%, default 3.5)
     * @param  float  $platformFeePct  Taxa da plataforma HubAI (%, default 5.0)
     * @return float  Margem líquida em USD (pode ser negativa)
     */
    public function calculateMargin(
        float $costUsd,
        float $shippingUsd,
        float $sellPrice,
        float $gatewayFeePct = 3.5,
        float $platformFeePct = 5.0
    ): float {
        $gatewayFee  = $sellPrice * ($gatewayFeePct / 100);
        $platformFee = $sellPrice * ($platformFeePct / 100);

        return round($sellPrice - $costUsd - $shippingUsd - $gatewayFee - $platformFee, 4);
    }

    /**
     * Sugerir preço de venda com base em custo + markup desejado (fórmula inversa).
     *
     * sellPrice = (costUsd + shippingUsd)
     *           / (1 - (markupPct + gatewayFeePct + platformFeePct) / 100)
     *
     * A fórmula garante que o markup seja calculado sobre o preço final,
     * não sobre o custo (comportamento correto para e-commerce).
     *
     * @param  float  $costUsd         Custo do produto no fornecedor (USD)
     * @param  float  $shippingUsd     Custo do frete pago ao fornecedor (USD)
     * @param  float  $markupPct       Margem de lucro desejada sobre venda (%, default 40)
     * @param  float  $gatewayFeePct   Taxa do gateway (%, default 3.5)
     * @param  float  $platformFeePct  Taxa da plataforma (%, default 5.0)
     * @return float  Preço sugerido em USD
     */
    public function suggestPrice(
        float $costUsd,
        float $shippingUsd,
        float $markupPct = 40.0,
        float $gatewayFeePct = 3.5,
        float $platformFeePct = 5.0
    ): float {
        $totalDeductionPct = $markupPct + $gatewayFeePct + $platformFeePct;
        $divisor = 1 - ($totalDeductionPct / 100);

        // Proteção contra divisão por zero ou divisor negativo
        if ($divisor <= 0) {
            $divisor = 0.05;
        }

        $price = ($costUsd + $shippingUsd) / $divisor;

        return round($price, 2);
    }

    /**
     * Calcular o lucro real detalhado de um pedido Drop.
     *
     * Retorna breakdown completo com todas as componentes de custo e receita.
     *
     * @param  DropOrder  $order
     * @return array{
     *     revenue: float,
     *     cost_product: float,
     *     cost_shipping: float,
     *     gateway_fee: float,
     *     platform_fee: float,
     *     gross_profit: float,
     *     net_profit: float,
     *     margin_pct: float
     * }
     */
    public function calculateOrderProfit(DropOrder $order): array
    {
        // Receita bruta = total cobrado do cliente
        $revenue = (float) $order->total_amount;

        // Custo do fornecedor: soma de todos os DropSupplierOrders do pedido
        $costProduct  = 0.0;
        $costShipping = 0.0;
        if ($order->relationLoaded('supplierOrders')) {
            foreach ($order->supplierOrders as $so) {
                $costProduct += (float) $so->cost_paid_usd;
            }
        } else {
            $costProduct = (float) $order->supplierOrders()->sum('cost_paid_usd');
        }

        // Taxas: buscar config do modulo ou usar defaults
        $gatewayFeePct  = (float) ($order->gateway_fee ?? 3.5);
        $platformFeePct = 5.0;

        // Usar split_platform_pct da loja se disponivel
        if ($order->drop_store_id) {
            $store = \App\Models\Drop\DropStore::find($order->drop_store_id);
            if ($store && $store->split_platform_pct > 0) {
                $platformFeePct = (float) $store->split_platform_pct;
            }
        }

        $gatewayFee  = round($revenue * ($gatewayFeePct / 100), 4);
        $platformFee = round($revenue * ($platformFeePct / 100), 4);

        $grossProfit = round($revenue - $costProduct - $costShipping, 4);
        $netProfit   = round($grossProfit - $gatewayFee - $platformFee, 4);
        $marginPct   = $revenue > 0
            ? round(($netProfit / $revenue) * 100, 2)
            : 0.0;

        return [
            'revenue'        => $revenue,
            'cost_product'   => $costProduct,
            'cost_shipping'  => $costShipping,
            'gateway_fee'    => $gatewayFee,
            'platform_fee'   => $platformFee,
            'gross_profit'   => $grossProfit,
            'net_profit'     => $netProfit,
            'margin_pct'     => $marginPct,
        ];
    }

    /**
     * Buscar a regra de precificação ativa para o cliente.
     *
     * Prioridade: regra específica para o fornecedor > regra global do cliente.
     * Se não houver nenhuma, retorna null (o chamador deve usar defaults).
     *
     * @param  int          $clientId      ID do cliente (lojista)
     * @param  string|null  $supplierSlug  Slug do fornecedor (ex: 'cj', 'aliexpress')
     * @return DropPricingRule|null
     */
    public function getActiveRule(int $clientId, ?string $supplierSlug = null): ?DropPricingRule
    {
        // 1. Tenta regra específica para o fornecedor
        if ($supplierSlug) {
            $specific = DropPricingRule::where('client_id', $clientId)
                ->where('supplier_slug', $supplierSlug)
                ->where('is_active', true)
                ->latest('updated_at')
                ->first();

            if ($specific) {
                return $specific;
            }
        }

        // 2. Fallback: regra global do cliente (sem supplier_slug)
        return DropPricingRule::where('client_id', $clientId)
            ->whereNull('supplier_slug')
            ->where('is_active', true)
            ->latest('updated_at')
            ->first();
    }

    /**
     * Aplicar uma regra de precificação a um produto importado.
     *
     * Preenche os campos sell_price, markup_pct e margin_usd do produto
     * com base na regra fornecida (ou nos defaults do config se $rule = null).
     *
     * O produto é retornado com os campos preenchidos mas NÃO é salvo —
     * o chamador controla a persistência.
     *
     * @param  ImportedProduct       $product  Produto importado a ser precificado
     * @param  DropPricingRule|null  $rule     Regra a aplicar (null usa defaults do config)
     * @return ImportedProduct  Produto com sell_price, markup_pct e margin_usd preenchidos
     */
    public function applyRuleToProduct(ImportedProduct $product, ?DropPricingRule $rule = null): ImportedProduct
    {
        $markupPct      = $rule?->markup_pct      ?? (float) config('drop.default_markup_pct', 40.0);
        $gatewayFeePct  = $rule?->gateway_fee_pct ?? 3.5;
        $platformFeePct = $rule?->platform_fee_pct ?? 5.0;

        $costUsd     = (float) $product->cost_usd;
        $shippingUsd = (float) ($product->shipping_cost_usd ?? 0.0);

        $sellPrice = $this->suggestPrice($costUsd, $shippingUsd, $markupPct, $gatewayFeePct, $platformFeePct);
        $margin    = $this->calculateMargin($costUsd, $shippingUsd, $sellPrice, $gatewayFeePct, $platformFeePct);

        $product->sell_price     = $sellPrice;
        $product->markup_pct     = $markupPct;
        $product->margin_usd     = $margin;

        return $product;
    }
}
