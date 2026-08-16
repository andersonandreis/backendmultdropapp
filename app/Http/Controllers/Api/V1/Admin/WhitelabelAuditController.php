<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * NOV-214 / Antifraude WL MVP
 *
 * Endpoint: GET /api/v1/admin/whitelabels/{empresaId}/audit
 *
 * Retorna suspeitas de fraude de cobranca: clientes excluidos ou desativados
 * nos 3 dias antes do fechamento do ciclo e recriados ou reativados nos 3 dias
 * apos o fechamento.
 *
 * Parametros query:
 *   cycle_end (YYYY-MM-DD, obrigatorio) — data de fechamento do ciclo
 *   days_window (int, opcional, default=3) — janela de dias antes/depois
 *   page, per_page — paginacao
 */
class WhitelabelAuditController extends Controller
{
    public function fraudSuspects(Request $request, int $empresaId): JsonResponse
    {
        $request->validate([
            "cycle_end"   => "required|date",
            "days_window" => "integer|min:1|max:14",
        ]);

        $cycleEnd   = $request->date("cycle_end");
        $window     = (int) $request->input("days_window", 3);
        $perPage    = min((int) $request->input("per_page", 50), 200);

        // Emails de clientes que tiveram DELETE ou deactivate nos [window] dias antes do fechamento
        $suspectsBefore = DB::table("wl_client_audit_log")
            ->where("empresa_id", $empresaId)
            ->whereIn("action", ["delete", "deactivate"])
            ->whereBetween("changed_at", [
                $cycleEnd->copy()->subDays($window)->startOfDay(),
                $cycleEnd->copy()->endOfDay(),
            ])
            ->whereNotNull("email")
            ->pluck("email")
            ->unique()
            ->toArray();

        if (empty($suspectsBefore)) {
            return response()->json([
                "data"    => [],
                "total"   => 0,
                "message" => "Nenhuma acao suspeita nos {$window} dias antes do fechamento.",
            ]);
        }

        // Desses emails, quais voltaram (reactivate ou snapshot ativo) nos [window] dias apos o fechamento
        $reactivatedAfter = DB::table("wl_client_audit_log")
            ->where("empresa_id", $empresaId)
            ->where("action", "reactivate")
            ->whereBetween("changed_at", [
                $cycleEnd->copy()->startOfDay(),
                $cycleEnd->copy()->addDays($window)->endOfDay(),
            ])
            ->whereIn("email", $suspectsBefore)
            ->select(["email", "client_id", "changed_at", "action", "before", "after", "changed_by_user_id"])
            ->get()
            ->keyBy("email");

        // Complementa com snapshot semanal apos o ciclo (caso nao haja reactivate explicito)
        $snapshotAfter = DB::table("wl_client_snapshots")
            ->where("empresa_id", $empresaId)
            ->where("is_active", 1)
            ->whereNull("blocked_at")
            ->whereBetween("snapshot_date", [
                $cycleEnd->copy()->toDateString(),
                $cycleEnd->copy()->addDays($window + 7)->toDateString(),
            ])
            ->whereIn("email", $suspectsBefore)
            ->select(["email", "client_id", "snapshot_date"])
            ->get()
            ->keyBy("email");

        // Acao original (antes do fechamento)
        $originalActions = DB::table("wl_client_audit_log")
            ->where("empresa_id", $empresaId)
            ->whereIn("action", ["delete", "deactivate"])
            ->whereBetween("changed_at", [
                $cycleEnd->copy()->subDays($window)->startOfDay(),
                $cycleEnd->copy()->endOfDay(),
            ])
            ->whereIn("email", $suspectsBefore)
            ->orderBy("changed_at")
            ->get()
            ->groupBy("email");

        $suspects = [];
        foreach ($suspectsBefore as $email) {
            $reactivated = $reactivatedAfter->get($email) ?? $snapshotAfter->get($email);
            if (! $reactivated) {
                continue; // Nao voltou — nao e suspeita de fraude, pode ter sido exclusao real
            }

            $origActions = $originalActions->get($email, collect());

            $suspects[] = [
                "email"               => $email,
                "empresa_id"          => $empresaId,
                "cycle_end"           => $cycleEnd->toDateString(),
                "removed_before_close"=> $origActions->map(fn($a) => [
                    "action"     => $a->action,
                    "changed_at" => $a->changed_at,
                    "client_id"  => $a->client_id,
                ])->values(),
                "returned_after_close"=> [
                    "type"       => isset($reactivatedAfter[$email]) ? "reactivate_log" : "snapshot",
                    "date"       => $reactivatedAfter[$email]->changed_at ?? $snapshotAfter[$email]?->snapshot_date,
                    "client_id"  => $reactivatedAfter[$email]->client_id ?? $snapshotAfter[$email]?->client_id,
                ],
                "fraud_score"         => $origActions->count() >= 2 ? "high" : "medium",
            ];
        }

        // Paginacao manual
        $total   = count($suspects);
        $page    = max(1, (int) $request->input("page", 1));
        $sliced  = array_slice($suspects, ($page - 1) * $perPage, $perPage);

        return response()->json([
            "data"         => $sliced,
            "total"        => $total,
            "page"         => $page,
            "per_page"     => $perPage,
            "window_days"  => $window,
            "cycle_end"    => $cycleEnd->toDateString(),
            "message"      => $total > 0
                ? "Encontrados {$total} cliente(s) suspeito(s) de manipulacao pre-fechamento."
                : "Nenhuma suspeita de fraude no periodo.",
        ]);
    }

    /**
     * Endpoint auxiliar: lista todos os eventos de auditoria de uma empresa.
     * GET /api/v1/admin/whitelabels/{empresaId}/audit/events
     */
    public function events(Request $request, int $empresaId): JsonResponse
    {
        $request->validate([
            "from"     => "date",
            "to"       => "date",
            "action"   => "in:deactivate,delete,reactivate",
            "per_page" => "integer|min:1|max:200",
        ]);

        $query = DB::table("wl_client_audit_log")
            ->where("empresa_id", $empresaId)
            ->orderByDesc("changed_at");

        if ($request->filled("from")) {
            $query->where("changed_at", ">=", $request->input("from"));
        }
        if ($request->filled("to")) {
            $query->where("changed_at", "<=", $request->input("to") . " 23:59:59");
        }
        if ($request->filled("action")) {
            $query->where("action", $request->input("action"));
        }

        $perPage = min((int) $request->input("per_page", 50), 200);
        $results = $query->paginate($perPage);

        return response()->json($results);
    }
}
