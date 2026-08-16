<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** NOV-138 — Relatório de PIX recebidos por dia. */
class PixReportController extends Controller
{
    /** GET /api/v1/supplier/reports/pix-by-day?start=YYYY-MM-DD&end=YYYY-MM-DD */
    public function pixByDay(Request $request): JsonResponse
    {
        $start = $request->input('start', now()->subDays(30)->toDateString());
        $end   = $request->input('end', now()->toDateString());

        $supplierFilter = '';
        $params = ['start' => $start, 'end' => $end];

        if (auth()->user()?->role === 'supplier') {
            $supplierId = auth()->user()->supplier?->id;
            if ($supplierId) {
                $supplierFilter = ' AND p.supplier_id = :supplier_id';
                $params['supplier_id'] = $supplierId;
            }
        }

        $rows = DB::select("
            SELECT
                DATE(p.paid_at) AS day,
                COUNT(*) AS pix_count,
                SUM(p.amount) AS total_amount
            FROM pix_transactions p
            WHERE p.status = 'paid'
              AND DATE(p.paid_at) BETWEEN :start AND :end
              {$supplierFilter}
            GROUP BY DATE(p.paid_at)
            ORDER BY day
        ", $params);

        $total = 0.0;
        $count = 0;
        $days = [];
        foreach ($rows as $r) {
            $days[] = [
                'day'    => $r->day,
                'pix_count' => (int) $r->pix_count,
                'amount' => (float) $r->total_amount,
            ];
            $total += (float) $r->total_amount;
            $count += (int) $r->pix_count;
        }

        return response()->json([
            'data' => [
                'days'        => $days,
                'total_count' => $count,
                'total_amount'=> $total,
                'avg_per_day' => count($days) > 0 ? round($total / count($days), 2) : 0,
                'period'      => ['start' => $start, 'end' => $end],
            ],
        ]);
    }
}
