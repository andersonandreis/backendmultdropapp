<?php

namespace App\Services\Drop;

use App\Models\Drop\DropOrder;
use App\Models\Drop\DropProfitReport;

class ProfitReportService
{
    public function __construct(
        private readonly DropPricingService $pricingService
    ) {}

    /**
     * Gerar relatorio financeiro de um periodo.
     *
     * @param  int     $clientId
     * @param  string  $startDate  YYYY-MM-DD
     * @param  string  $endDate    YYYY-MM-DD
     * @return DropProfitReport
     */
    public function generateReport(int $clientId, string $startDate, string $endDate): DropProfitReport
    {
        $orders = DropOrder::where("client_id", $clientId)
            ->whereBetween("created_at", [$startDate . " 00:00:00", $endDate . " 23:59:59"])
            ->whereNotIn("status", ["cancelled"])
            ->get();

        $totals = [
            "total_revenue"       => 0.0,
            "total_cost_product"  => 0.0,
            "total_cost_shipping" => 0.0,
            "total_gateway_fees"  => 0.0,
            "total_platform_fees" => 0.0,
            "total_chargebacks"   => 0.0,
            "total_refunds"       => 0.0,
            "orders_count"        => 0,
            "profitable_orders"   => 0,
            "loss_orders"         => 0,
        ];

        foreach ($orders as $order) {
            $profit = $this->pricingService->calculateOrderProfit($order);

            $totals["total_revenue"]       += $profit["revenue"];
            $totals["total_cost_product"]  += $profit["cost_product"];
            $totals["total_cost_shipping"] += $profit["cost_shipping"];
            $totals["total_gateway_fees"]  += $profit["gateway_fee"];
            $totals["total_platform_fees"] += $profit["platform_fee"];
            $totals["orders_count"]++;

            if ($order->status === "refunded") {
                $totals["total_refunds"] += $profit["revenue"];
            }

            if ($profit["net_profit"] >= 0) {
                $totals["profitable_orders"]++;
            } else {
                $totals["loss_orders"]++;
            }
        }

        $grossProfit = round(
            $totals["total_revenue"]
            - $totals["total_cost_product"]
            - $totals["total_cost_shipping"],
            4
        );

        $netProfit = round(
            $grossProfit
            - $totals["total_gateway_fees"]
            - $totals["total_platform_fees"]
            - $totals["total_chargebacks"]
            - $totals["total_refunds"],
            4
        );

        $report = DropProfitReport::updateOrCreate(
            [
                "client_id"    => $clientId,
                "period_start" => $startDate,
                "period_end"   => $endDate,
            ],
            array_merge($totals, [
                "gross_profit" => $grossProfit,
                "net_profit"   => $netProfit,
            ])
        );

        return $report;
    }
}
