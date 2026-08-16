<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiGeneration;
use App\Services\Ai\SeedanceCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SEL-073 admin: modulo Seedance — catalogo de modelos + custo real + cobranca.
 * Rotas: /api/v1/admin/video/* (role admin,super_admin).
 * Cobranca fica OCULTA pro cliente enquanto billing_enabled=0 (default).
 */
class AdminVideoController extends Controller
{
    private const GROUP = 'video_billing';

    public const DEFAULTS = [
        'billing_enabled'          => '0',
        'billing_mode'             => 'per_video', // per_video | per_generation | credits
        'price_per_video_brl'      => '3.90',
        'price_per_generation_brl' => '2.90',
        'credit_price_brl'         => '1.35',
        'credits_per_video'        => '1500',
        'markup_multiplier'        => '4',
        'usd_brl_rate'             => '5.50',
        'default_model'            => SeedanceCatalog::DEFAULT_MODEL,
        // SEL-329: modelo "dois mundos" — cliente ve creditos opacos, admin ve R$/video
        'videos_free_per_month'    => '3',
        'plan_required_slug'       => 'pro',
        'recharge_packages'        => '[{"amount_brl":50,"credits":1500},{"amount_brl":100,"credits":3200},{"amount_brl":200,"credits":7000},{"amount_brl":500,"credits":20000}]',
    ];

    private function settings(): array
    {
        $db = DB::table('settings')->where('group', self::GROUP)->pluck('value', 'key')->toArray();
        return array_merge(self::DEFAULTS, $db);
    }

    /** GET /api/v1/admin/video/models — comparativo dos 6 modelos com custo estimado. */
    public function models()
    {
        $s = $this->settings();
        $rate = (float) $s['usd_brl_rate'];
        $markup = (float) $s['markup_multiplier'];

        $models = array_map(function (array $m) use ($rate, $markup) {
            $costs = [];
            foreach ($m['resolutions'] as $res) {
                foreach ([5, 10] as $sec) {
                    $usd = SeedanceCatalog::estimateCostUsd($m['id'], $res, $sec);
                    $costs["{$res}_{$sec}s"] = [
                        'usd'                 => $usd,
                        'brl'                 => round($usd * $rate, 2),
                        'suggested_price_brl' => round($usd * $rate * $markup, 2),
                    ];
                }
            }
            $m['costs'] = $costs;
            $m['tokens_720p_5s'] = SeedanceCatalog::estimateTokens('720p', 5);
            return $m;
        }, SeedanceCatalog::models());

        return response()->json([
            'models'        => $models,
            'default_model' => $s['default_model'],
            'usd_brl_rate'  => $rate,
            'markup_multiplier' => $markup,
            'token_formula' => 'tokens = duracao(s) x largura x altura x 24fps / 1024 — cobra so sucesso (usage.completion_tokens)',
        ]);
    }

    /** GET /api/v1/admin/video/usage — custo REAL medio por geracao (ai_generations service=video). */
    public function usage(Request $r)
    {
        $range = $r->query('range', '30d');
        $from = match ($range) {
            'today' => now()->startOfDay(),
            '7d'    => now()->subDays(7),
            default => now()->subDays(30),
        };
        $s = $this->settings();
        $rate = (float) $s['usd_brl_rate'];

        $base = AiGeneration::query()->where('service', 'video')->where('created_at', '>=', $from);

        $total = (clone $base)->count();
        $succeeded = (clone $base)->where('status', 'succeeded')->count();
        $failed = (clone $base)->whereIn('status', ['failed', 'expired', 'cancelled'])->count();
        $costUsd = (float) (clone $base)->sum('cost_usd');
        $tokens = (int) (clone $base)->sum('usage_tokens');
        $withCost = (clone $base)->where('cost_usd', '>', 0)->count();
        $avgCostUsd = $withCost > 0 ? $costUsd / $withCost : 0;

        $byModel = (clone $base)
            ->selectRaw('provider_model, count(*) as total, sum(case when status = "succeeded" then 1 else 0 end) as succeeded, sum(cost_usd) as cost_usd, sum(usage_tokens) as tokens')
            ->groupBy('provider_model')
            ->get();

        // SEL-322: consumo por usuário (quem usou, quanto custou, créditos)
        $byUser = \App\Models\AiGeneration::query()
            ->where('ai_generations.service', 'video')
            ->where('ai_generations.created_at', '>=', $from)
            ->leftJoin('users', 'users.id', '=', 'ai_generations.user_id')
            ->selectRaw('ai_generations.user_id, users.email as user_email, users.name as user_name, count(*) as total, sum(case when ai_generations.status = "succeeded" then 1 else 0 end) as succeeded, sum(ai_generations.cost_usd) as cost_usd, sum(ai_generations.credits_debited) as credits_debited, max(ai_generations.created_at) as last_at')
            ->groupBy('ai_generations.user_id', 'users.email', 'users.name')
            ->orderByDesc('total')
            ->limit(50)
            ->get()
            ->map(function ($u) use ($rate) {
                $u->cost_brl = round((float) $u->cost_usd * $rate, 2);
                return $u;
            });

        // Pipelines novos (wizard/clone SEL-321) por usuário
        $pipelinesByUser = DB::table('ai_video_pipelines')
            ->leftJoin('users', 'users.id', '=', 'ai_video_pipelines.user_id')
            ->where('ai_video_pipelines.created_at', '>=', $from)
            ->selectRaw('ai_video_pipelines.user_id, users.email as user_email, users.name as user_name, count(*) as total, sum(case when step = "done" then 1 else 0 end) as done, sum(case when step = "failed" then 1 else 0 end) as failed, max(ai_video_pipelines.created_at) as last_at')
            ->groupBy('ai_video_pipelines.user_id', 'users.email', 'users.name')
            ->orderByDesc('total')
            ->limit(50)
            ->get();

        return response()->json([
            'range' => $range,
            'summary' => [
                'total_generations'   => $total,
                'succeeded'           => $succeeded,
                'failed'              => $failed,
                'cost_usd'            => round($costUsd, 4),
                'cost_brl'            => round($costUsd * $rate, 2),
                'avg_cost_usd'        => round($avgCostUsd, 4),
                'avg_cost_brl'        => round($avgCostUsd * $rate, 2),
                'usage_tokens'        => $tokens,
                'credits_debited'     => (int) (clone $base)->sum('credits_debited'),
            ],
            'by_model' => $byModel,
            'by_user' => $byUser,
            'pipelines_by_user' => $pipelinesByUser,
            'usd_brl_rate' => $rate,
        ]);
    }

    /** GET /api/v1/admin/video/billing — config de cobranca (grupo video_billing). */
    public function billingGet()
    {
        return response()->json(['settings' => $this->settings(), 'modes' => ['per_video', 'per_generation', 'credits']]);
    }

    /** PUT /api/v1/admin/video/billing — persiste settings do grupo video_billing. */
    public function billingPut(Request $r)
    {
        $v = $r->validate([
            'billing_enabled'          => 'nullable|boolean',
            'billing_mode'             => 'nullable|in:per_video,per_generation,credits',
            'price_per_video_brl'      => 'nullable|numeric|min:0|max:1000',
            'price_per_generation_brl' => 'nullable|numeric|min:0|max:1000',
            'credit_price_brl'         => 'nullable|numeric|min:0|max:1000',
            'credits_per_video'        => 'nullable|integer|min:1|max:1000000',
            'markup_multiplier'        => 'nullable|numeric|min:1|max:100',
            'usd_brl_rate'             => 'nullable|numeric|min:1|max:20',
            'default_model'            => 'nullable|string|in:' . implode(',', SeedanceCatalog::ids()),
            // SEL-329: novos campos modelo "dois mundos"
            'videos_free_per_month'    => 'nullable|integer|min:0|max:100',
            'plan_required_slug'       => 'nullable|string|max:32',
            'recharge_packages'        => 'nullable|array|min:1|max:8',
            'recharge_packages.*.amount_brl' => 'required_with:recharge_packages|numeric|min:1|max:10000',
            'recharge_packages.*.credits'    => 'required_with:recharge_packages|integer|min:1|max:10000000',
            // SEL-329: qualidade + cap R$50
            'quality_default'                     => 'nullable|in:fast,balanced,master',
            'internal_cost_cap_brl_month'         => 'nullable|numeric|min:0|max:10000',
            'internal_cost_by_quality_brl_per_s'  => 'nullable|array',
            'internal_cost_by_quality_brl_per_s.fast'     => 'nullable|numeric|min:0|max:10',
            'internal_cost_by_quality_brl_per_s.balanced' => 'nullable|numeric|min:0|max:10',
            'internal_cost_by_quality_brl_per_s.master'   => 'nullable|numeric|min:0|max:10',
            'credits_by_quality_per_s'            => 'nullable|array',
            'credits_by_quality_per_s.fast'     => 'nullable|integer|min:1|max:10000',
            'credits_by_quality_per_s.balanced' => 'nullable|integer|min:1|max:10000',
            'credits_by_quality_per_s.master'   => 'nullable|integer|min:1|max:10000',
        ]);

        foreach ($v as $key => $value) {
            if ($value === null) continue;
            if ($key === 'billing_enabled') $value = $value ? '1' : '0';
            if (in_array($key, ['recharge_packages', 'internal_cost_by_quality_brl_per_s', 'credits_by_quality_per_s'], true)) {
                $value = json_encode(is_array($value) ? $value : []);
            }
            DB::table('settings')->updateOrInsert(
                ['group' => self::GROUP, 'key' => $key],
                ['value' => (string) $value, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        return response()->json(['ok' => true, 'settings' => $this->settings()]);
    }

    /** GET /api/v1/admin/video/credit-transactions — extrato de creditos. */
    public function creditTransactions(Request $r)
    {
        $q = DB::table('ai_credit_transactions')
            ->leftJoin('users', 'users.id', '=', 'ai_credit_transactions.user_id')
            ->select('ai_credit_transactions.*', 'users.email as user_email')
            ->orderByDesc('ai_credit_transactions.id');
        if ($r->query('user_id')) $q->where('ai_credit_transactions.user_id', $r->query('user_id'));
        return response()->json($q->paginate((int) $r->query('per_page', 20)));
    }

    /**
     * SEL-329: dashboard executivo — números pra Ruan ver na abertura.
     * GET /api/v1/admin/video/dashboard
     */
    public function dashboard()
    {
        $s = $this->settings();
        $cap = (float) ($s['internal_cost_cap_brl_month'] ?? 50.00);
        $rate = (float) ($s['usd_brl_rate'] ?? 5.50);
        $reqSlug = $s['plan_required_slug'] ?? 'pro';

        // 1. Pagantes por plano
        $payingByPlan = DB::table('subscriptions as s')
            ->join('plans as p', 'p.id', '=', 's.plan_id')
            ->join('clients as c', 'c.id', '=', 's.client_id')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->whereIn('s.status', ['active', 'trialing'])
            ->whereNotNull('s.pagarme_subscription_id')
            ->where('u.is_active', 1)
            ->selectRaw('p.id, p.name, p.slug, p.price_monthly, COUNT(*) as pagantes, COUNT(*)*p.price_monthly as mrr')
            ->groupBy('p.id', 'p.name', 'p.slug', 'p.price_monthly')
            ->orderByDesc('p.price_monthly')
            ->get();

        // 2. Consumo do mês por user Pro pagante
        $now = now()->startOfMonth();
        $proUsers = DB::table('subscriptions as s')
            ->join('plans as p', 'p.id', '=', 's.plan_id')
            ->join('clients as c', 'c.id', '=', 's.client_id')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->leftJoin('client_ai_wallets as w', 'w.client_id', '=', 'c.id')
            ->where('p.slug', $reqSlug)
            ->whereIn('s.status', ['active', 'trialing'])
            ->whereNotNull('s.pagarme_subscription_id')
            ->where('u.is_active', 1)
            ->select('u.id as user_id', 'u.name', 'u.email', 'c.id as client_id',
                'w.balance as wallet_credits', 's.current_period_end')
            ->get()
            ->map(function ($row) use ($now, $rate) {
                $usedUsd = (float) DB::table('ai_generations')
                    ->where('user_id', $row->user_id)
                    ->where('service', 'video')
                    ->whereIn('status', ['succeeded', 'processing'])
                    ->where('created_at', '>=', $now)
                    ->sum('cost_usd');
                $videos = (int) DB::table('ai_generations')
                    ->where('user_id', $row->user_id)
                    ->where('service', 'video')
                    ->whereIn('status', ['succeeded', 'processing'])
                    ->where('created_at', '>=', $now)
                    ->count();
                return [
                    'user_id' => $row->user_id,
                    'name'    => $row->name,
                    'email'   => $row->email,
                    'used_this_month_brl' => round($usedUsd * $rate, 2),
                    'videos_count'  => $videos,
                    'wallet_credits' => (float) ($row->wallet_credits ?? 0),
                    'renews_at'     => $row->current_period_end,
                ];
            })
            ->sortByDesc('used_this_month_brl')
            ->values();

        // 3. Kling saldo — reusa AiWalletController::adminKlingBalance via chamada interna
        $klingBalance = null;
        try {
            $ctrl = app(\App\Http\Controllers\Api\V1\AiWalletController::class);
            $klingBalance = $ctrl->adminKlingBalance(app(\App\Services\Ai\KlingService::class))->getData(true);
        } catch (\Throwable $e) {
            $klingBalance = ['error' => $e->getMessage()];
        }

        // 4. Totais mensais
        $mrrTotal = $payingByPlan->sum('mrr');
        $usedThisMonthTotal = $proUsers->sum('used_this_month_brl');
        $capTotalPossivel = $proUsers->count() * $cap;

        return response()->json([
            'cap_brl_per_user' => $cap,
            'plan_required_slug' => $reqSlug,
            'summary' => [
                'paying_total'           => $payingByPlan->sum('pagantes'),
                'mrr_total_brl'          => (float) $mrrTotal,
                'pro_paying_count'       => (int) $proUsers->count(),
                'pro_used_this_month'    => (float) $usedThisMonthTotal,
                'pro_cap_total_possible' => (float) $capTotalPossivel,
                'pro_gross_margin_min'   => (float) ($payingByPlan->firstWhere('slug', $reqSlug)->mrr ?? 0) - $capTotalPossivel,
            ],
            'kling_balance' => $klingBalance,
            'kling_browser' => $this->klingBrowserSnapshot(), // SEL-381
            'paying_by_plan' => $payingByPlan,
            'pro_users' => $proUsers,
        ]);
    }

    /**
     * SEL-381 — Snapshot completo do Kling Browser (Consumer Pro via Playwright)
     * pra card do dashboard admin.
     * GET /v1/admin/video/kling-browser
     */
    public function klingBrowser()
    {
        return response()->json($this->klingBrowserSnapshot(true));
    }

    protected function klingBrowserSnapshot(bool $includeVideos = false): array
    {
        $mode = config('services.kling.mode');
        $enabled = (bool) config('services.kling.browser_enabled');
        $snap = [
            'active_mode'    => $mode,
            'browser_enabled'=> $enabled,
            'account_email'  => config('services.kling.browser_account_email'),
            'plan'           => config('services.kling.browser_plan'),
            'monthly_credits'=> 3000, // Pro Monthly = 3000/mês
            'topup_url'      => 'https://kling.ai/app/membership/spirit-unit', // Créditos avulsos $5+
            'plan_url'       => 'https://kling.ai/app/membership/membership-plan?r=26',
            'session'        => [
                'file'      => config('services.kling.browser_session_path'),
                'exists'    => is_file((string) config('services.kling.browser_session_path')),
                'age_hours' => is_file((string) config('services.kling.browser_session_path'))
                    ? round((time() - filemtime(config('services.kling.browser_session_path'))) / 3600, 1)
                    : null,
            ],
            'worker'         => [
                'js_path'   => config('services.kling.browser_worker_js'),
                'last_run_ago_s' => is_file((string) config('services.kling.browser_rate_lock'))
                    ? (time() - filemtime(config('services.kling.browser_rate_lock')))
                    : null,
            ],
        ];

        // Stats via ai_generations
        try {
            $today = \DB::table('ai_generations')
                ->where('provider', 'kling_browser')
                ->whereDate('created_at', now()->toDateString())
                ->selectRaw('
                    count(*) as total,
                    sum(case when status = "succeeded" then 1 else 0 end) as done,
                    sum(case when status = "failed" then 1 else 0 end) as failed,
                    avg(TIMESTAMPDIFF(SECOND, created_at, updated_at)) as avg_seconds
                ')
                ->first();
            $week = \DB::table('ai_generations')
                ->where('provider', 'kling_browser')
                ->where('created_at', '>=', now()->subDays(7))
                ->count();
            $snap['stats'] = [
                'today_total'  => (int) ($today->total ?? 0),
                'today_done'   => (int) ($today->done ?? 0),
                'today_failed' => (int) ($today->failed ?? 0),
                'avg_seconds'  => $today->avg_seconds ? round((float) $today->avg_seconds, 1) : null,
                'week_total'   => (int) $week,
                'monthly_estimate' => (int) ($week * 4.3), // extrapola semana → mês
            ];
        } catch (\Throwable $e) {
            $snap['stats'] = ['error' => $e->getMessage()];
        }

        // Vídeos armazenados no disco
        $videosDir = config('services.kling.browser_videos_dir');
        if ($videosDir && is_dir($videosDir)) {
            $files = glob("{$videosDir}/*.mp4");
            $snap['storage'] = [
                'count' => count($files),
                'total_mb' => round(array_sum(array_map(fn($f) => filesize($f), $files)) / 1024 / 1024, 1),
            ];
            if ($includeVideos) {
                usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
                $snap['recent_videos'] = array_slice(array_map(fn($f) => [
                    'file' => basename($f),
                    'size_kb' => round(filesize($f) / 1024),
                    'created_at' => date('Y-m-d H:i:s', filemtime($f)),
                    'age_min' => round((time() - filemtime($f)) / 60, 1),
                ], $files), 0, 20);
            }
        }

        // Últimos jobs failed (cache dos taskIds)
        if ($includeVideos) {
            try {
                $recentGen = \DB::table('ai_generations')
                    ->where('provider', 'kling_browser')
                    ->orderByDesc('id')
                    ->limit(10)
                    ->get(['id', 'user_id', 'provider_model', 'status', 'output_url', 'error_message', 'created_at', 'updated_at']);
                $snap['recent_generations'] = $recentGen;
            } catch (\Throwable $e) {
                $snap['recent_generations'] = ['error' => $e->getMessage()];
            }
        }

        return $snap;
    }

    /**
     * SEL-381 — pausar/retomar fila kling-browser (emergência)
     * POST /v1/admin/video/kling-browser/pause
     */
    public function klingBrowserPause(Request $r)
    {
        $flagFile = '/home/api.seller.global/storage/kling-browser/PAUSED';
        $action = $r->input('action', 'toggle');
        if ($action === 'pause') {
            file_put_contents($flagFile, now()->toIso8601String());
            return response()->json(['paused' => true, 'since' => now()->toIso8601String()]);
        }
        if ($action === 'resume') {
            @unlink($flagFile);
            return response()->json(['paused' => false]);
        }
        if (is_file($flagFile)) {
            @unlink($flagFile);
            return response()->json(['paused' => false, 'action' => 'resumed']);
        }
        file_put_contents($flagFile, now()->toIso8601String());
        return response()->json(['paused' => true, 'since' => now()->toIso8601String()]);
    }
}
