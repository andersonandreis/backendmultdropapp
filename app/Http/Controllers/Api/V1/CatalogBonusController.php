<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CatalogBonusSubsidy;
use App\Models\Supplier;
use App\Services\CatalogBonusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SEL-171 — Endpoints do bonus 50% (3 primeiras vendas silencioso).
 *  - /api/v1/me/bonus-status  (cliente)
 *  - /api/v1/admin/catalog-subsidies  (super_admin)
 */
class CatalogBonusController extends Controller
{
    public function __construct(private CatalogBonusService $svc)
    {
    }

    // ===================
    // Cliente
    // ===================

    /**
     * GET /api/v1/me/bonus-status
     * Retorna { used, remaining, bonus_pct, eligible } pro cliente autenticado.
     */
    public function meStatus(Request $request): JsonResponse
    {
        $user = $request->user();
        $client = $user?->client;
        // SEL-273 Ruan 15:57: super_admin sempre vê catálogo com 50% off
        // (evita "cadê o desconto?" quando Ruan audita como super_admin).
        if (($user?->role ?? '') === 'super_admin') {
            return response()->json([
                'used' => 0,
                'remaining' => 3,
                'bonus_pct' => CatalogBonusService::DEFAULT_BONUS_PCT,
                'eligible' => true,
            ]);
        }
        if (!$client) {
            return response()->json([
                'used' => 0,
                'remaining' => 0,
                'bonus_pct' => CatalogBonusService::DEFAULT_BONUS_PCT,
                'eligible' => false,
            ]);
        }
        return response()->json($this->svc->statusFor($client));
    }

    // ===================
    // Admin
    // ===================

    private function requireSuperAdmin(Request $request): void
    {
        if ($request->user()?->role !== 'super_admin') {
            abort(403, 'Acesso restrito a super_admin.');
        }
    }

    /**
     * GET /api/v1/admin/catalog-subsidies?status=pending&supplier_id=X&from=Y&to=Z
     *
     * SEL-175BE Ruan 16/07: cada row agora traz `plan_slug` do plano ativo do
     * cliente (via subscriptions.plan). Assim o painel consegue mostrar
     * breakdown por plano (Start/Scaling/Pro/TikTok Free) sem batch-call
     * secundario. Se o cliente nao tem subscription ativa, plan_slug = null.
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $q = CatalogBonusSubsidy::query()
            ->with([
                // MUL-269 fase 2: nome do seller vem do user (accessor client->company_name).
                'client:id,user_id',
                'client.user:id,name,full_name,email',
                // SEL-175BE: subscription ativa + plan p/ plan_slug em cada row.
                'client.subscriptions' => function ($sub) {
                    $sub->whereIn('status', ['active', 'trialing'])
                        ->with('plan:id,name,slug')
                        ->latest();
                },
                'supplier:id,name',
                'order:id,order_number,supplier_total,total,paid_at',
                'paidByAdmin:id,name,email',
            ]);

        if ($status = $request->query('status')) {
            if (in_array($status, ['pending', 'paid', 'waived'], true)) {
                $q->where('status', $status);
            }
        }
        if ($supplierId = $request->query('supplier_id')) {
            $q->where('supplier_id', (int) $supplierId);
        }
        if ($clientId = $request->query('client_id')) {
            $q->where('client_id', (int) $clientId);
        }
        if ($from = $request->query('from')) {
            $q->where('created_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->where('created_at', '<=', $to);
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 30)));
        $paginator = $q->orderByDesc('id')->paginate($perPage);

        // SEL-175BE: injetar plan_slug em cada row (achata subscription ativa).
        // Uso through() (paginator preserva meta) e cai pra null se sem sub.
        $paginator->through(function (CatalogBonusSubsidy $row) {
            $plan = $row->client?->subscriptions?->first()?->plan;
            $arr = $row->toArray();
            $arr['plan_slug'] = $plan?->slug;
            $arr['plan_name'] = $plan?->name;
            // Aliases pro frontend (backwards compat com nomes que a UI usa).
            $arr['supplier_name'] = $row->supplier?->name ?? null;
            $arr['client_name']   = $row->client?->user?->name ?? $row->client?->company_name ?? null;
            $arr['client_email']  = $row->client?->user?->email ?? null;
            $arr['order_number']  = $row->order?->order_number ?? null;
            $arr['catalog_price'] = $row->discounted_price !== null
                ? (float) $row->discounted_price
                : null;
            return $arr;
        });

        return response()->json($paginator);
    }

    /**
     * GET /api/v1/admin/catalog-subsidies/summary
     *   ?supplier_id=X&from=YYYY-MM-DD&to=YYYY-MM-DD
     *
     * SEL-175BE Ruan 16/07: bloco novo de agregados pro painel enriquecido —
     *   distinct_products  (COUNT DISTINCT product_id) do filtro corrente
     *   filter_total       (SUM subsidy_amount) do filtro corrente
     *   by_plan            [{plan_slug,total,count}] por plano do cliente
     *   pending_count      (contagem, alem do total)
     *   paid_today_total/count, paid_count (rename de paid_today)
     * O frontend ja tem fallback client-side se algum vier ausente. Aqui a
     * fonte de verdade cobre tambem meta.total > per_page (30).
     *
     * Defensivo: quando o filtro nao devolve nenhuma row, todos os agregados
     * retornam zeros — nunca 500. Um try/catch envolve o bloco pra qualquer
     * SQL error nao derrubar a tela do admin (retorna zeros + log).
     */
    public function adminSummary(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $supplierId = $request->query('supplier_id');
        $from       = $request->query('from');
        $to         = $request->query('to');

        // Helper: aplica filtros comuns em qualquer query builder.
        // Qualifica os campos com o nome da tabela pra evitar ambiguidade
        // quando o query builder faz JOIN com subscriptions (que tambem
        // tem created_at). Sem qualificacao, JOIN dispara SQLSTATE[23000]
        // "Column 'created_at' is ambiguous".
        $applyFilters = function ($q) use ($supplierId, $from, $to) {
            if ($supplierId !== null && $supplierId !== '' && $supplierId !== 'all') {
                $q->where('catalog_bonus_subsidies.supplier_id', (int) $supplierId);
            }
            if ($from) {
                $q->where('catalog_bonus_subsidies.created_at', '>=', $from);
            }
            if ($to) {
                // fim do dia pra `to` YYYY-MM-DD virar range inclusivo.
                $q->where('catalog_bonus_subsidies.created_at', '<=', strlen($to) === 10 ? ($to . ' 23:59:59') : $to);
            }
            return $q;
        };

        // Envelope defensivo: qualquer explosao (JOIN quebrado, coluna que nao
        // existe apos migration parcial, tenant divergente) volta zeros pro
        // painel ao inves de 500. O erro fica no log Laravel pra investigacao.
        try {
            // ── Blocos "globais" (nao usam supplier_id/from/to). Compat legada. ──
            $pendingTotal = (float) CatalogBonusSubsidy::where('status', 'pending')->sum('subsidy_amount');
            $pendingCount = (int)   CatalogBonusSubsidy::where('status', 'pending')->count();

            $paidTodayTotal = (float) CatalogBonusSubsidy::where('status', 'paid')
                ->whereDate('paid_at', now()->toDateString())
                ->sum('subsidy_amount');
            $paidTodayCount = (int) CatalogBonusSubsidy::where('status', 'paid')
                ->whereDate('paid_at', now()->toDateString())
                ->count();

            $paidTotal = (float) CatalogBonusSubsidy::where('status', 'paid')->sum('subsidy_amount');
            $paidCount = (int)   CatalogBonusSubsidy::where('status', 'paid')->count();

            // ── by_supplier (nome legado — sempre em cima do PENDING global). ──
            $bySupplierRaw = CatalogBonusSubsidy::where('status', 'pending')
                ->select(
                    'supplier_id',
                    DB::raw('COUNT(*) as pending_count'),
                    DB::raw('SUM(subsidy_amount) as pending_amount')
                )
                ->groupBy('supplier_id')
                ->orderByDesc('pending_amount')
                ->get();

            $supplierIds = $bySupplierRaw->pluck('supplier_id')->all();
            $names = empty($supplierIds)
                ? collect()
                : Supplier::whereIn('id', $supplierIds)->pluck('name', 'id');

            $bySupplier = $bySupplierRaw->map(function ($row) use ($names) {
                return [
                    'supplier_id'    => (int) $row->supplier_id,
                    'supplier_name'  => $names[$row->supplier_id] ?? null,
                    'name'           => $names[$row->supplier_id] ?? null, // compat legada
                    'pending_count'  => (int)   $row->pending_count,
                    'pending_total'  => (float) $row->pending_amount,     // nome novo
                    'pending_amount' => (float) $row->pending_amount,     // compat legada
                ];
            })->values();

            // ── SEL-175BE: blocos NOVOS respeitam supplier_id/from/to. ──
            // distinct_products + filter_total (independem de status — mostram o
            // que aparece na LISTAGEM atual, cobrindo total > per_page da UI).
            // Qualificamos campos com o nome da tabela pra ficar consistente
            // com o applyFilters (que ja qualifica pra evitar ambiguidade).
            $baseFiltered = $applyFilters(CatalogBonusSubsidy::query());
            $distinctProducts = (int) (clone $baseFiltered)
                ->whereNotNull('catalog_bonus_subsidies.product_id')
                ->distinct('catalog_bonus_subsidies.product_id')
                ->count('catalog_bonus_subsidies.product_id');
            $filterTotal = (float) (clone $baseFiltered)->sum('catalog_bonus_subsidies.subsidy_amount');

            // by_plan: JOIN em clients + subscriptions (active/trialing) + plans.
            // Um cliente pode ter varias subs — pegamos a MAIS RECENTE ativa (por
            // MAX(id)). GROUP BY plans.slug agrupa fielmente. Compatibilidade:
            // se o cliente nao tem sub ativa, entra em `other` (COALESCE null).
            $byPlanRaw = $applyFilters(CatalogBonusSubsidy::query())
                ->leftJoinSub(
                    // subquery: para cada client_id, a subscription ativa mais recente
                    DB::table('subscriptions')
                        ->select('client_id', DB::raw('MAX(id) as sub_id'))
                        ->whereIn('status', ['active', 'trialing'])
                        ->groupBy('client_id'),
                    'active_sub',
                    'active_sub.client_id',
                    '=',
                    'catalog_bonus_subsidies.client_id'
                )
                ->leftJoin('subscriptions', 'subscriptions.id', '=', 'active_sub.sub_id')
                ->leftJoin('plans', 'plans.id', '=', 'subscriptions.plan_id')
                ->select(
                    DB::raw("COALESCE(plans.slug, 'other') as plan_slug"),
                    DB::raw('COUNT(*) as cnt'),
                    DB::raw('SUM(catalog_bonus_subsidies.subsidy_amount) as total')
                )
                ->groupBy('plan_slug')
                ->orderByDesc('total')
                ->get();

            $byPlan = $byPlanRaw->map(function ($row) {
                return [
                    'plan_slug' => (string) ($row->plan_slug ?: 'other'),
                    'total'     => (float) $row->total,
                    'count'     => (int) $row->cnt,
                ];
            })->values();

            return response()->json([
                // Compat legada (v1 do endpoint).
                'pending_total'     => round($pendingTotal, 2),
                'paid_today'        => round($paidTodayTotal, 2),
                'paid_total'        => round($paidTotal, 2),
                'by_supplier'       => $bySupplier,

                // SEL-175BE — contagens + blocos filtrados.
                'pending_count'     => $pendingCount,
                'paid_today_total'  => round($paidTodayTotal, 2),
                'paid_today_count'  => $paidTodayCount,
                'paid_count'        => $paidCount,
                'distinct_products' => $distinctProducts,
                'filter_total'      => round($filterTotal, 2),
                'by_plan'           => $byPlan,
            ]);
        } catch (\Throwable $e) {
            \Log::warning('[SEL-175BE adminSummary] falhou; devolvendo zeros', [
                'error'       => $e->getMessage(),
                'file'        => $e->getFile(),
                'line'        => $e->getLine(),
                'supplier_id' => $supplierId,
                'from'        => $from,
                'to'          => $to,
            ]);
            return response()->json([
                'pending_total'     => 0.0,
                'paid_today'        => 0.0,
                'paid_total'        => 0.0,
                'by_supplier'       => [],
                'pending_count'     => 0,
                'paid_today_total'  => 0.0,
                'paid_today_count'  => 0,
                'paid_count'        => 0,
                'distinct_products' => 0,
                'filter_total'      => 0.0,
                'by_plan'           => [],
                'error'             => 'summary_partial_failure',
                'message'           => 'Nao foi possivel calcular todos os agregados. Verifique logs.',
            ]);
        }
    }

    /**
     * POST /api/v1/admin/catalog-subsidies/{id}/mark-paid  body {note?}
     */
    public function adminMarkPaid(Request $request, int $id): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $row = CatalogBonusSubsidy::findOrFail($id);
        if ($row->status !== 'pending') {
            return response()->json([
                'error' => 'invalid_status',
                'message' => "Subsidio ja esta com status={$row->status}. So 'pending' pode virar 'paid'.",
            ], 422);
        }
        $row->update([
            'status' => 'paid',
            'paid_at' => now(),
            'paid_by_admin_id' => $request->user()->id,
            'admin_note' => $request->input('note'),
        ]);
        return response()->json(['ok' => true, 'subsidy' => $row->fresh()]);
    }

    /**
     * POST /api/v1/admin/catalog-subsidies/mark-paid-bulk  body {ids:[], note?}
     */
    public function adminMarkPaidBulk(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $ids = $request->input('ids', []);
        if (!is_array($ids) || empty($ids)) {
            return response()->json(['error' => 'ids_required'], 422);
        }
        $note = $request->input('note');
        $adminId = $request->user()->id;

        $updated = CatalogBonusSubsidy::whereIn('id', $ids)
            ->where('status', 'pending')
            ->update([
                'status' => 'paid',
                'paid_at' => now(),
                'paid_by_admin_id' => $adminId,
                'admin_note' => $note,
            ]);

        return response()->json([
            'ok' => true,
            'updated' => $updated,
            'requested' => count($ids),
        ]);
    }

    /**
     * POST /api/v1/admin/catalog-subsidies/{id}/waive  body {note?}
     * Marca como waived (Ruan decidiu nao pagar — ex: subsidio duplicado ou fraudulento).
     */
    public function adminWaive(Request $request, int $id): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $row = CatalogBonusSubsidy::findOrFail($id);
        if ($row->status === 'paid') {
            return response()->json([
                'error' => 'already_paid',
                'message' => 'Subsidio ja foi pago — nao pode ser dispensado.',
            ], 422);
        }
        $row->update([
            'status' => 'waived',
            'paid_by_admin_id' => $request->user()->id,
            'admin_note' => $request->input('note'),
        ]);
        return response()->json(['ok' => true, 'subsidy' => $row->fresh()]);
    }
}
