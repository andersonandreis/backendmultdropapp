<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Services\AffiliateAccessService;
use App\Models\AffiliateCommission;
use App\Models\AffiliateReferral;
use App\Models\AffiliateWithdrawal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * SEL-345: Controlador completo de afiliados.
 * - Cadastro publico (apply) sem autenticacao
 * - Dashboard do afiliado logado (me, stats, commissions, withdrawals)
 * - Track de clique via /r/{code}
 * - Aprovacao/rejeicao (admin via rota autenticada)
 */
class AffiliateController extends Controller
{
    // =========================================================================
    // PUBLICO sem auth
    // =========================================================================

    /**
     * POST /api/v1/affiliates/apply
     * Cadastro publico de afiliado (form /seja-afiliado).
     */
    public function apply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'       => 'required|string|max:255',
            'email'      => 'required|email|max:255',
            'phone'      => 'nullable|string|max:50',
            'instagram'  => 'nullable|string|max:100',
            'tiktok'     => 'nullable|string|max:100',
            'motivation' => 'nullable|string|max:2000',
            'pix_key'    => 'nullable|string|max:255',
            'pix_type'   => ['nullable', Rule::in(['cpf', 'cnpj', 'email', 'phone', 'random'])],
            // SEL-GERENTE (09/08): cadastro publico via link de convite de gerente
            'invite_token' => 'nullable|string|max:64',
        ]);

        // SEL-GERENTE (09/08): resolve convite de gerente (se veio), sem quebrar o
        // fluxo normal de aplicacao caso o token seja invalido/ausente — so ignora.
        $inviteFields = [];
        $invite = null;
        if (! empty($validated['invite_token'])) {
            $invite = \App\Models\AffiliateManagerInvite::where('token', $validated['invite_token'])->first();
            if ($invite && $invite->isValid() && $invite->manager?->is_manager) {
                $inviteFields = [
                    'manager_id'           => $invite->manager_affiliate_id,
                    'video_gen_authorized' => false,
                ];
            } else {
                $invite = null;
            }
        }

        if (Affiliate::where('application_email', $validated['email'])->exists()) {
            return response()->json(['message' => 'Email ja cadastrado no programa de afiliados.'], 409);
        }

        $userId = null;
        $user = \App\Models\User::where('email', $validated['email'])->first();
        if ($user) {
            if (Affiliate::where('user_id', $user->id)->exists()) {
                return response()->json(['message' => 'Voce ja esta cadastrado como afiliado.'], 409);
            }
            $userId = $user->id;
        }

        $affiliate = Affiliate::create(array_merge([
            'user_id'                => $userId,
            'referral_code'          => $this->generateUniqueCode(),
            'commission_rate'        => 30.00,
            'status'                 => 'inactive',
            'approval_status'        => 'pending',
            'application_name'       => $validated['name'],
            'application_email'      => $validated['email'],
            'application_phone'      => $validated['phone'] ?? null,
            'application_instagram'  => $validated['instagram'] ?? null,
            'application_tiktok'     => $validated['tiktok'] ?? null,
            'application_motivation' => $validated['motivation'] ?? null,
            'pix_key'                => $validated['pix_key'] ?? null,
            'pix_type'               => $validated['pix_type'] ?? null,
        ], $inviteFields));

        // SEL-GERENTE (09/08): marca o convite como usado
        if ($invite) {
            $invite->update(['used_by_affiliate_id' => $affiliate->id, 'used_at' => now()]);
        }

        $this->notifyAdminNewAffiliate($affiliate);

        return response()->json([
            'message'   => 'Cadastro enviado! Voce sera notificado por email apos aprovacao.',
            'affiliate' => [
                'id'               => $affiliate->id,
                'application_name' => $affiliate->application_name,
                'approval_status'  => $affiliate->approval_status,
            ],
        ], 201);
    }

    // =========================================================================
    // AFILIADO LOGADO
    // =========================================================================

    /**
     * POST /api/v1/affiliate/register (legado — mantido, agora cria como pending)
     */
    public function register(Request $request): JsonResponse
    {
        $user = $request->user();
        $existing = Affiliate::where('user_id', $user->id)->first();
        if ($existing) {
            return response()->json([
                'message'         => 'Voce ja e um afiliado.',
                'affiliate'       => $existing,
                'referral_link'   => $this->buildReferralLink($existing->referral_code),
                'approval_status' => $existing->approval_status,
            ]);
        }
        // SEL-GERENTE (09/08): suporte a invite_token tambem no fluxo legado autenticado
        $inviteFields = [];
        $inviteToken = $request->input('invite_token');
        $invite = $inviteToken ? \App\Models\AffiliateManagerInvite::where('token', $inviteToken)->first() : null;
        if ($invite && $invite->isValid() && $invite->manager?->is_manager) {
            $inviteFields = ['manager_id' => $invite->manager_affiliate_id, 'video_gen_authorized' => false];
        } else {
            $invite = null;
        }

        $affiliate = Affiliate::create(array_merge([
            'user_id'          => $user->id,
            'referral_code'    => $this->generateUniqueCode(),
            'commission_rate'  => 30.00,
            'status'           => 'inactive',
            'approval_status'  => 'pending',
            'application_name' => $user->name,
            'application_email'=> $user->email,
        ], $inviteFields));
        if ($invite) {
            $invite->update(['used_by_affiliate_id' => $affiliate->id, 'used_at' => now()]);
        }
        $this->notifyAdminNewAffiliate($affiliate);
        return response()->json([
            'message'         => 'Cadastro de afiliado registrado. Aguardando aprovacao.',
            'affiliate'       => $affiliate,
            'approval_status' => 'pending',
        ], 201);
    }

    public function me(Request $request): JsonResponse
    {
        $affiliate = $this->resolveAffiliate($request);
        return response()->json([
            'affiliate'       => $affiliate,
            'referral_link'   => $this->buildReferralLink($affiliate->referral_code),
            'approval_status' => $affiliate->approval_status,
            'pix_key'         => $affiliate->pix_key,
            'pix_type'        => $affiliate->pix_type,
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $affiliate   = $this->resolveAffiliate($request);
        // SEL-493 (31/07): 'clicks' e 'referrals' devolviam O MESMO numero -- dois
        // rotulos diferentes pro mesmo calculo. O afiliado via "120 cliques / 120
        // indicacoes" e nao tinha como saber quantos viraram cadastro de fato.
        //
        // A tabela ja separa os dois: linha com referred_client_id NULO e clique
        // que nao virou nada; com client preenchido, virou cadastro.
        $clicks      = $affiliate->referrals()->count();
        $cadastros   = $affiliate->referrals()->whereNotNull('referred_client_id')->count();
        $conversions = $affiliate->referrals()->where('status', 'converted')->count();
        $pendingComm = (float) $affiliate->commissions()->where('status', 'pending')->sum('commission_amount');
        $paidComm    = (float) $affiliate->total_earned;
        $balance     = $paidComm - (float) $affiliate->total_withdrawn;
        $nextPayout  = now()->day(15)->gte(now()) ? now()->day(15) : now()->addMonth()->day(15);
        $last30      = $affiliate->referrals()
            ->where('attributed_at', '>=', now()->subDays(30))
            ->count();
        // SEL-387: receita gerada pelas indicacoes e ticket medio
        $receita = (float) $affiliate->commissions()->sum('gross_amount');
        $vendas  = (int) $affiliate->commissions()->count();
        $ticket  = $vendas > 0 ? $receita / $vendas : 0.0;

        return response()->json([
            'clicks'                   => $clicks,
            'referrals'                => $cadastros,
            'conversions'              => $conversions,
            'last_30d_signups'         => $last30,
            'commission_pending'       => round($pendingComm, 2),
            'commission_paid'          => round($paidComm, 2),
            'balance'                  => round($balance, 2),
            'next_payout_date'         => $nextPayout->format('d/m/Y'),
            'payout_min'               => 100.00,
            'can_withdraw'             => $balance >= 100.00,
            'referral_link'            => $this->buildReferralLink($affiliate->referral_code),
            'referral_code'            => $affiliate->referral_code,
            'max_ai_videos_per_month'  => $affiliate->max_ai_videos_per_month,
            'granted_plan_slug'        => $affiliate->granted_plan_slug,
            // SEL-387: metricas do painel (espelha Tokfy: Receita Total / Ticket Medio)
            'total_revenue'            => round($receita, 2),
            'average_ticket'           => round($ticket, 2),
            'sales_count'              => $vendas,
            'custom_referral_slug'     => $affiliate->custom_referral_slug,
            'pix_key'                  => $affiliate->pix_key,
            'pix_type'                 => $affiliate->pix_type,
        ]);
    }

    public function referrals(Request $request): JsonResponse
    {
        $affiliate = $this->resolveAffiliate($request);
        $referrals = $affiliate->referrals()
            ->select(['id', 'status', 'attributed_at', 'converted_at', 'utm_source'])
            ->latest('attributed_at')
            ->paginate(20);
        return response()->json($referrals);
    }

    public function commissions(Request $request): JsonResponse
    {
        $affiliate   = $this->resolveAffiliate($request);
        $commissions = $affiliate->commissions()
            ->select(['id', 'commission_amount', 'status', 'event_type', 'plan_slug', 'created_at', 'paid_at'])
            ->latest()
            ->paginate(20);
        return response()->json($commissions);
    }

    public function requestWithdrawal(Request $request): JsonResponse
    {
        $affiliate = $this->resolveAffiliate($request);

        if ($affiliate->approval_status !== 'approved') {
            return response()->json(['message' => 'Somente afiliados aprovados podem solicitar saques.'], 403);
        }

        $validated = $request->validate([
            'amount'   => ['required', 'numeric', 'min:100'],
            'pix_key'  => ['required', 'string', 'max:100'],
            'pix_type' => ['required', Rule::in(['cpf', 'cnpj', 'email', 'phone', 'random'])],
        ]);

        $balance = (float) $affiliate->total_earned - (float) $affiliate->total_withdrawn;
        if ($validated['amount'] > $balance) {
            return response()->json([
                'message' => 'Saldo insuficiente para o saque solicitado.',
                'balance' => round($balance, 2),
            ], 422);
        }

        $affiliate->update([
            'pix_key'  => $validated['pix_key'],
            'pix_type' => $validated['pix_type'],
        ]);

        $withdrawal = AffiliateWithdrawal::create([
            'affiliate_id' => $affiliate->id,
            'amount'       => $validated['amount'],
            'pix_key'      => $validated['pix_key'],
            'pix_type'     => $validated['pix_type'],
            'status'       => 'pending',
        ]);

        // SEL-GERENTE (09/08 tarde): avisa o Ruan na hora que um afiliado pede
        // saque — mesmo canal de push critico ja usado por outros comandos
        // (app/Console/Commands/AlertRuanCommand.php, alert:ruan). Nunca deixa
        // o saque falhar por causa do alerta (best-effort).
        try {
            $nome = $affiliate->display_name;
            \Illuminate\Support\Facades\Artisan::call('alert:ruan', [
                'title' => 'Saque solicitado',
                'body'  => "{$nome} pediu saque de " . number_format((float) $validated['amount'], 2, ',', '.') . " via PIX.",
                'url'   => '/afiliados',
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[SEL-GERENTE] alert:ruan falhou pra saque de afiliado', [
                'affiliate_id' => $affiliate->id,
                'error'        => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message'    => 'Solicitacao de saque registrada. Pagamento no dia 15, em horário comercial (seg. a sex.).',
            'withdrawal' => $withdrawal,
        ], 201);
    }

    public function withdrawals(Request $request): JsonResponse
    {
        $affiliate   = $this->resolveAffiliate($request);
        $withdrawals = $affiliate->withdrawals()->latest()->paginate(20);
        return response()->json($withdrawals);
    }

    public function updatePix(Request $request): JsonResponse
    {
        $affiliate = $this->resolveAffiliate($request);
        $validated = $request->validate([
            'pix_key'  => 'required|string|max:255',
            'pix_type' => ['required', Rule::in(['cpf', 'cnpj', 'email', 'phone', 'random'])],
        ]);
        $affiliate->update($validated);
        return response()->json(['message' => 'Chave PIX atualizada.']);
    }

    // =========================================================================
    // TRACKING PUBLICO
    // =========================================================================

    /**
     * GET /r/{code}
     */
    public function track(Request $request, string $code): RedirectResponse
    {
        $code      = strtoupper($code);
        $affiliate = Affiliate::where('approval_status', 'approved')
            ->where(function ($q) use ($code) {
                // SEL-387: aceita tanto o codigo gerado quanto o slug personalizado
                $q->where('referral_code', $code)
                  ->orWhereRaw('UPPER(custom_referral_slug) = ?', [$code]);
            })
            ->first();

        $frontendUrl = rtrim(config('app.frontend_url', config('app.url')), '/');

        if ($affiliate) {
            AffiliateReferral::create([
                'affiliate_id'       => $affiliate->id,
                'referred_client_id' => null,
                'referral_code'      => $code,
                'ip_address'         => $request->ip(),
                'user_agent'         => $request->userAgent(),
                'attributed_at'      => now(),
                'status'             => 'pending',
                'landing_url'        => $request->fullUrl(),
                'utm_source'         => $request->query('utm_source'),
            ]);

            return redirect()->away($frontendUrl . '/?ref=' . urlencode($code))
                ->withCookie(cookie('affiliate_ref', $code, 60 * 24 * 60));
        }

        return redirect()->away($frontendUrl);
    }

    // =========================================================================
    // ADMIN
    // =========================================================================

    public function adminList(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $affiliates = Affiliate::with('user')
            ->when($request->query('status'), fn ($q, $s) => $q->where('approval_status', $s))
            ->orderBy('created_at', 'desc')
            ->paginate(30);

        return response()->json($affiliates);
    }

    public function adminApprove(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        $affiliate = Affiliate::findOrFail($id);

        $validated = $request->validate([
            'commission_rate'         => 'nullable|numeric|min:0|max:100',
            'max_ai_videos_per_month' => 'nullable|integer|min:0',
            'granted_plan_slug'       => 'nullable|string|max:50',
        ]);

        $affiliate->update([
            'approval_status'         => 'approved',
            'status'                  => 'active',
            'approved_at'             => now(),
            'approved_by'             => $request->user()->id,
            'commission_rate'         => $validated['commission_rate'] ?? $affiliate->commission_rate,
            'max_ai_videos_per_month' => $validated['max_ai_videos_per_month'] ?? 0,
            'granted_plan_slug'       => $validated['granted_plan_slug'] ?? null,
        ]);

        // SEL-386: acesso equivalente ao plano anual (marcado como concessao, nunca receita)
        AffiliateAccessService::grant($affiliate, $validated['granted_plan_slug'] ?? null);

        $link = $this->buildReferralLink($affiliate->referral_code);
        $this->notifyAffiliateApproved($affiliate, $link);

        Log::info('[SEL-345] Afiliado aprovado', [
            'affiliate_id' => $affiliate->id,
            'approved_by'  => $request->user()->id,
        ]);

        return response()->json([
            'message'       => 'Afiliado aprovado.',
            'affiliate'     => $affiliate->fresh(),
            'referral_link' => $link,
        ]);
    }

    public function adminReject(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        $affiliate = Affiliate::findOrFail($id);
        $validated = $request->validate(['reason' => 'required|string|max:500']);
        $affiliate->update([
            'approval_status'  => 'rejected',
            'status'           => 'inactive',
            'rejection_reason' => $validated['reason'],
        ]);
        return response()->json(['message' => 'Afiliado rejeitado.']);
    }

    public function adminSuspend(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        $affiliate = Affiliate::findOrFail($id);
        $affiliate->update(['approval_status' => 'suspended', 'status' => 'suspended']);
        AffiliateAccessService::revoke($affiliate); // SEL-386
        return response()->json(['message' => 'Afiliado suspenso.']);
    }

    public function adminUpdateQuotas(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        $affiliate = Affiliate::findOrFail($id);
        $validated = $request->validate([
            'commission_rate'         => 'nullable|numeric|min:0|max:100',
            'max_ai_videos_per_month' => 'nullable|integer|min:0',
            'granted_plan_slug'       => 'nullable|string|max:50',
            'perks'                   => 'nullable|array',
        ]);
        $affiliate->update(array_filter($validated, fn ($v) => $v !== null));
        return response()->json(['message' => 'Quotas atualizadas.', 'affiliate' => $affiliate->fresh()]);
    }

    public function adminStats(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $month = now()->month;
        $year  = now()->year;

        $totalActive    = Affiliate::where('approval_status', 'approved')->count();
        $totalPending   = Affiliate::where('approval_status', 'pending')->count();
        $monthReferrals = AffiliateReferral::whereMonth('attributed_at', $month)
            ->whereYear('attributed_at', $year)->count();
        $monthConversions = AffiliateReferral::where('status', 'converted')
            ->whereMonth('converted_at', $month)->whereYear('converted_at', $year)->count();
        $totalCommPending = (float) AffiliateCommission::where('status', 'pending')->sum('commission_amount');
        $totalCommPaid    = (float) AffiliateCommission::where('status', 'paid')->sum('commission_amount');
        $withdrawPending  = (float) AffiliateWithdrawal::where('status', 'pending')->sum('amount');

        $top5 = Affiliate::with('user')
            ->where('total_earned', '>', 0)
            ->orderByDesc('total_earned')
            ->limit(5)
            ->get()
            ->map(fn ($a) => [
                'name'          => $a->user?->name ?? $a->application_name,
                'total_earned'  => (float) $a->total_earned,
                'referral_code' => $a->referral_code,
            ]);

        return response()->json([
            'total_active'       => $totalActive,
            'total_pending'      => $totalPending,
            'month_referrals'    => $monthReferrals,
            'month_conversions'  => $monthConversions,
            'commission_pending' => round($totalCommPending, 2),
            'commission_paid'    => round($totalCommPaid, 2),
            'withdraw_pending'   => round($withdrawPending, 2),
            'top_affiliates'     => $top5,
        ]);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * SEL-387: perfil editavel do afiliado (nome, contato e codigo personalizado).
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $affiliate = $this->resolveAffiliate($request);
        $user      = $request->user();

        $dados = $request->validate([
            'name'                  => 'sometimes|string|max:120',
            'email'                 => 'sometimes|email|max:150',
            'phone'                 => 'sometimes|nullable|string|max:30',
            'custom_referral_slug'  => 'sometimes|nullable|string|min:3|max:30|regex:/^[A-Za-z0-9_-]+$/',
        ]);

        if (array_key_exists('custom_referral_slug', $dados) && $dados['custom_referral_slug']) {
            $slug = strtoupper($dados['custom_referral_slug']);
            $emUso = Affiliate::where('id', '!=', $affiliate->id)
                ->where(function ($q) use ($slug) {
                    $q->whereRaw('UPPER(custom_referral_slug) = ?', [$slug])
                      ->orWhere('referral_code', $slug);
                })
                ->exists();
            if ($emUso) {
                return response()->json(['message' => 'Esse codigo ja esta em uso. Escolha outro.'], 422);
            }
            $affiliate->custom_referral_slug = $slug;
        }

        foreach (['name', 'email', 'phone'] as $campo) {
            if (array_key_exists($campo, $dados)) {
                $affiliate->{'application_' . $campo} = $dados[$campo];
                if ($user && in_array($campo, ['name', 'email'], true)) {
                    $user->{$campo} = $dados[$campo];
                }
            }
        }

        $affiliate->save();
        if ($user) { $user->save(); }

        $codigo = $affiliate->custom_referral_slug ?: $affiliate->referral_code;

        return response()->json([
            'message'              => 'Perfil salvo!',
            'name'                 => $affiliate->application_name,
            'email'                => $affiliate->application_email,
            'phone'                => $affiliate->application_phone,
            'custom_referral_slug' => $affiliate->custom_referral_slug,
            'referral_link'        => $this->buildReferralLink($codigo),
        ]);
    }

    /**
     * SEL-387: painel de vendas — pedidos pagos que geraram comissao pro afiliado.
     */
    public function sales(Request $request): JsonResponse
    {
        $affiliate = $this->resolveAffiliate($request);

        $vendas = $affiliate->commissions()
            ->with(['referral.referredClient.user:id,name,email'])
            ->latest()
            ->paginate(20);

        $vendas->getCollection()->transform(function ($c) {
            $u = $c->referral?->referredClient?->user;
            $nome = $u?->name ?: 'Cliente';
            $mail = (string) ($u?->email ?? '');
            // privacidade: afiliado ve o primeiro nome e o e-mail mascarado
            $mascarado = $mail !== '' && str_contains($mail, '@')
                ? substr($mail, 0, 2) . str_repeat('*', 4) . '@' . explode('@', $mail)[1]
                : null;

            return [
                'id'                => $c->id,
                'data'              => $c->created_at?->format('d/m/Y H:i'),
                'cliente'           => explode(' ', trim($nome))[0],
                'email'             => $mascarado,
                'plano'             => $c->plan_slug,
                'valor_pago'        => (float) $c->gross_amount,
                'comissao'          => (float) $c->commission_amount,
                'percentual'        => (float) $c->commission_rate,
                'tipo'              => $c->event_type,
                'status'            => $c->status,
                'pago_em'           => $c->paid_at?->format('d/m/Y'),
            ];
        });

        return response()->json($vendas);
    }

    private function resolveAffiliate(Request $request): Affiliate
    {
        $affiliate = Affiliate::where('user_id', $request->user()->id)->first();
        if (! $affiliate) {
            abort(404, 'Voce nao esta cadastrado como afiliado.');
        }
        return $affiliate;
    }

    private function requireAdmin(Request $request): void
    {
        if (! in_array($request->user()?->role, ['super_admin', 'admin'])) {
            abort(403, 'Acesso restrito a administradores.');
        }
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
        } while (Affiliate::where('referral_code', $code)->exists());
        return $code;
    }

    private function buildReferralLink(string $code): string
    {
        // SEL-477 (31/07): o link ia como api.seller.global/api/r/CODIGO — o
        // dominio da API, com /api no meio. Funciona (302 -> seller.global/?ref=),
        // mas quem recebe ve cara de link tecnico ou de golpe e nao clica, e o
        // afiliado acha que a "chave nao ficou pronta". Agora vai o dominio do
        // site: seller.global/r/CODIGO, que repassa pro rastreador e mantem o
        // registro da indicacao intacto.
        $site = rtrim(config('app.frontend_url') ?: 'https://seller.global', '/');

        return $site . '/r/' . $code;
    }

    private function notifyAdminNewAffiliate(Affiliate $affiliate): void
    {
        try {
            $token  = env('TELEGRAM_BOT_TOKEN');
            $chatId = env('TELEGRAM_ADMIN_CHAT_ID');
            if ($token && $chatId) {
                $msg = "Novo afiliado aguardando aprovacao:\n"
                    . "Nome: {$affiliate->application_name}\n"
                    . "Email: {$affiliate->application_email}\n"
                    . "Instagram: " . ($affiliate->application_instagram ?? '-') . "\n"
                    . "TikTok: " . ($affiliate->application_tiktok ?? '-') . "\n"
                    . "Aprovar em: https://admin.seller.global/admin/afiliados";
                \Illuminate\Support\Facades\Http::post(
                    "https://api.telegram.org/bot{$token}/sendMessage",
                    ['chat_id' => $chatId, 'text' => $msg]
                );
            }
        } catch (\Throwable $e) {
            Log::warning('[SEL-345] Telegram notify failed', ['error' => $e->getMessage()]);
        }
    }

    private function notifyAffiliateApproved(Affiliate $affiliate, string $link): void
    {
        try {
            $email = $affiliate->user?->email ?? $affiliate->application_email;
            $name  = $affiliate->user?->name  ?? $affiliate->application_name;
            if ($email) {
                Mail::raw(
                    "Ola {$name}!\n\nParabens! Sua candidatura ao programa de afiliados do Seller Global foi aprovada.\n\nSeu link exclusivo:\n{$link}\n\nDivulgue esse link e ganhe 30% de comissao na primeira fatura de cada cliente que se cadastrar por voce!\n\nEquipe Seller Global",
                    fn ($m) => $m->to($email)->subject('Voce foi aprovado no Programa de Afiliados Seller Global!')
                );
            }
        } catch (\Throwable $e) {
            Log::warning('[SEL-345] Email approved failed', ['error' => $e->getMessage()]);
        }
    }
}
