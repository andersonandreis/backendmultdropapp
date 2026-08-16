<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * NOV-071 — Centro de Comando: endpoint de resumo financeiro.
 *
 * Autenticado via X-Internal-Key (InternalKeyMiddleware).
 *
 * GET /api/internal/financial-summary
 *
 * Retorna saques pendentes, saldos de fornecedores e receita (hoje / 7d).
 * Usa a tabela `payments` (status=paid, paid_at) para receita.
 * Usa `supplier_balances` + `suppliers` para saldos por fornecedor.
 * Usa `withdrawal_requests` para saques pendentes.
 */
class FinancialSummaryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'withdrawals_pending'       => $this->withdrawalsPendingCount(),
            'withdrawals_pending_value' => $this->withdrawalsPendingValue(),
            'supplier_balances'         => $this->supplierBalances(),
            'revenue_today'             => $this->revenueToday(),
            'revenue_7d'                => $this->revenue7d(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Withdrawals
    // -------------------------------------------------------------------------

    private function withdrawalsPendingCount(): int
    {
        try {
            return (int) DB::table('withdrawal_requests')
                ->where('status', 'pending')
                ->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function withdrawalsPendingValue(): float
    {
        try {
            $sum = DB::table('withdrawal_requests')
                ->where('status', 'pending')
                ->sum('amount');
            return round((float) $sum, 2);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    // -------------------------------------------------------------------------
    // Supplier balances
    // -------------------------------------------------------------------------

    private function supplierBalances(): array
    {
        try {
            $rows = DB::table('supplier_balances as sb')
                ->join('suppliers as s', 's.id', '=', 'sb.warehouse_id')
                ->select([
                    's.display_name',
                    's.company_name',
                    'sb.balance',
                ])
                ->where('sb.balance', '>', 0)
                ->orderByDesc('sb.balance')
                ->get();

            return $rows->map(function ($row) {
                return [
                    'supplier' => $row->display_name ?? $row->company_name,
                    'balance'  => round((float) $row->balance, 2),
                ];
            })->values()->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    // -------------------------------------------------------------------------
    // Revenue (payments com status=paid)
    // -------------------------------------------------------------------------

    private function revenueToday(): float
    {
        try {
            $sum = DB::table('payments')
                ->where('status', 'paid')
                ->whereDate('paid_at', today())
                ->sum('amount');
            return round((float) $sum, 2);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    private function revenue7d(): float
    {
        try {
            $sum = DB::table('payments')
                ->where('status', 'paid')
                ->where('paid_at', '>=', now()->subDays(7)->startOfDay())
                ->sum('amount');
            return round((float) $sum, 2);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }
}
