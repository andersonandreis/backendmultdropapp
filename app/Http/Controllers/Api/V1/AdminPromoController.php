<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AiWalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SEL-363 Ruan 27/07/2026 — Promoções de live.
 * Admin lista assinantes recentes e aplica promoção em lote:
 * troca de plano + extensão de período + créditos IA.
 */
class AdminPromoController extends Controller
{
    private function requireSuperAdmin(Request $request): void
    {
        if ($request->user()?->role !== 'super_admin') {
            abort(403, 'Acesso restrito a super_admin.');
        }
    }

    /**
     * GET /v1/admin/promo/recent-subscribers?hours=72&only_paid=0&search=
     */
    public function recentSubscribers(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $hours = min((int) $request->input('hours', 72), 24 * 30);
        $onlyPaid = $request->boolean('only_paid');
        $search = trim((string) $request->input('search', ''));

        $rows = DB::table('subscriptions as s')
            ->leftJoin('clients as c', 'c.id', '=', 's.client_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->leftJoin('plans as p', 'p.id', '=', 's.plan_id')
            ->where('s.created_at', '>=', now()->subHours($hours))
            ->when($onlyPaid, fn ($q) => $q->where('s.pagarme_status', 'paid'))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('u.name', 'like', "%{$search}%")
                       ->orWhere('u.email', 'like', "%{$search}%");
                });
            })
            ->select(
                's.id as subscription_id', 's.client_id', 's.status', 's.pagarme_status',
                's.payment_method', 's.created_at', 's.current_period_end',
                'p.slug as plan_slug', 'p.name as plan_name',
                'p.price_monthly', 'p.price_yearly',
                'u.name', 'u.email'
            )
            ->orderByDesc('s.created_at')
            ->limit(300)
            ->get();

        $clientIds = $rows->pluck('client_id')->filter()->unique()->values();
        $wallets = DB::table('client_ai_wallets')
            ->whereIn('client_id', $clientIds)
            ->pluck('balance', 'client_id');
        $promoApplied = DB::table('ai_wallet_transactions')
            ->whereIn('client_id', $clientIds)
            ->where('kind', 'promo_live')
            ->pluck('client_id')
            ->unique()
            ->flip();

        $data = $rows->map(function ($r) use ($wallets, $promoApplied) {
            $price = (float) $r->price_yearly > 0 ? (float) $r->price_yearly : (float) $r->price_monthly;
            return [
                'subscription_id'    => (int) $r->subscription_id,
                'client_id'          => (int) $r->client_id,
                'name'               => $r->name,
                'email'              => $r->email,
                'plan_slug'          => $r->plan_slug,
                'plan_name'          => $r->plan_name,
                'plan_price'         => $price,
                'status'             => $r->status,
                'pagarme_status'     => $r->pagarme_status,
                'payment_method'     => $r->payment_method,
                'created_at'         => $r->created_at,
                'current_period_end' => $r->current_period_end,
                'wallet_balance'     => (float) ($wallets[$r->client_id] ?? 0),
                'promo_applied'      => isset($promoApplied[$r->client_id]),
            ];
        });

        return response()->json(['data' => $data, 'hours' => $hours]);
    }

    /**
     * GET /v1/admin/promo/plans — planos disponíveis pra promoção (todos, incl. inativos)
     */
    public function promoPlans(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $plans = DB::table('plans')
            ->select('id', 'name', 'slug', 'category', 'price_monthly', 'price_yearly', 'is_active')
            ->orderBy('category')->orderBy('id')
            ->get();

        return response()->json(['data' => $plans]);
    }

    /**
     * POST /v1/admin/promo/apply
     * { subscription_ids: int[], plan_slug: string, months: int (default 12),
     *   credits: float (default 0), note: string }
     */
    public function apply(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $validated = $request->validate([
            'subscription_ids'   => 'required|array|min:1|max:100',
            'subscription_ids.*' => 'integer',
            'plan_slug'          => 'required|string|exists:plans,slug',
            'months'             => 'nullable|integer|min:1|max:36',
            'credits'            => 'nullable|numeric|min:0|max:1000',
            'note'               => 'nullable|string|max:255',
        ]);

        $plan = DB::table('plans')->where('slug', $validated['plan_slug'])->first();
        $months = (int) ($validated['months'] ?? 12);
        $credits = (float) ($validated['credits'] ?? 0);
        $note = $validated['note'] ?? ('Promoção aplicada pelo admin em ' . now()->format('d/m/Y'));

        $results = [];
        foreach ($validated['subscription_ids'] as $subId) {
            $sub = DB::table('subscriptions')->where('id', $subId)->first();
            if (!$sub) {
                $results[] = ['subscription_id' => $subId, 'ok' => false, 'error' => 'não encontrada'];
                continue;
            }

            $base = $sub->created_at ? \Carbon\Carbon::parse($sub->created_at) : now();
            DB::table('subscriptions')->where('id', $subId)->update([
                'plan_id'            => $plan->id,
                'status'             => 'active',
                'current_period_end' => $base->copy()->addMonths($months),
                'updated_at'         => now(),
            ]);

            $creditApplied = false;
            if ($credits > 0 && $sub->client_id) {
                $ref = 'promo:' . $plan->slug . ':' . $subId;
                $already = DB::table('ai_wallet_transactions')
                    ->where('client_id', $sub->client_id)->where('ref', $ref)->exists();
                if (!$already) {
                    AiWalletService::credit((int) $sub->client_id, $credits, 'promo_live', $ref, $note);
                    $creditApplied = true;
                }
            }

            $results[] = [
                'subscription_id' => $subId,
                'client_id'       => $sub->client_id,
                'ok'              => true,
                'plan'            => $plan->slug,
                'period_end'      => $base->copy()->addMonths($months)->toDateTimeString(),
                'credit_applied'  => $creditApplied,
            ];
        }

        return response()->json(['results' => $results]);
    }
}
