<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliateManagerInvite;
use App\Services\AffiliateManagerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * SEL-GERENTE (09/08) — Gerente de Afiliados.
 *
 * Painel do GERENTE: gerar/listar convites, ver o time (afiliados sob ele),
 * indicados+checkout+comissao de cada um, extrato do override recorrente.
 *
 * Painel ADMIN: classificar afiliado (direto vs gerente), autorizar/revogar
 * geracao de video por afiliado, atribuir/remover gerente manualmente,
 * listar gerentes + aprovar payout (marca overrides pendentes como aprovados
 * — o saque em si continua pelo fluxo ja existente de AffiliateWithdrawal).
 */
class AffiliateManagerController extends Controller
{
    // =========================================================================
    // GERENTE (usuario logado precisa ser afiliado com is_manager=true)
    // =========================================================================

    /** POST /api/v1/affiliate/manager/invite */
    public function invite(Request $request): JsonResponse
    {
        $manager = $this->resolveManager($request);

        $validated = $request->validate([
            'expires_in_days' => 'nullable|integer|min:1|max:180',
        ]);

        $invite = AffiliateManagerService::generateInviteToken(
            $manager,
            $request->user()->id,
            (int) ($validated['expires_in_days'] ?? 30)
        );

        $site = rtrim(config('app.frontend_url') ?: 'https://seller.global', '/');

        return response()->json([
            'message'      => 'Convite gerado.',
            'token'        => $invite->token,
            'invite_link'  => $site . '/afiliado/convite/' . $invite->token,
            'expires_at'   => $invite->expires_at,
        ], 201);
    }

    /** GET /api/v1/affiliate/manager/invites */
    public function invites(Request $request): JsonResponse
    {
        $manager = $this->resolveManager($request);

        $invites = $manager->managerInvites()
            ->with('usedBy:id,application_name,user_id')
            ->latest()
            ->paginate(30);

        $invites->getCollection()->transform(function (AffiliateManagerInvite $i) {
            return [
                'id'           => $i->id,
                'token'        => $i->token,
                'status'       => $i->isUsed() ? 'used' : ($i->isExpired() ? 'expired' : 'pending'),
                'used_by'      => $i->usedBy?->display_name,
                'used_at'      => $i->used_at,
                'expires_at'   => $i->expires_at,
                'created_at'   => $i->created_at,
            ];
        });

        return response()->json($invites);
    }

    /** GET /api/v1/affiliate/manager/team — resumo dos afiliados sob o gerente */
    public function team(Request $request): JsonResponse
    {
        $manager = $this->resolveManager($request);

        $time = $manager->subAffiliates()
            ->with('user:id,name,email')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (Affiliate $a) {
                $overrideGerado = (float) AffiliateCommission::where('affiliate_id', $a->manager_id)
                    ->where('event_type', 'manager_override')
                    ->whereHas('referral', fn ($q) => $q->where('affiliate_id', $a->id))
                    ->sum('commission_amount');

                return [
                    'affiliate_id'          => $a->id,
                    'nome'                  => $a->display_name,
                    'email'                 => $a->user?->email ?? $a->application_email,
                    'approval_status'       => $a->approval_status,
                    'status'                => $a->status,
                    'video_gen_authorized'  => (bool) $a->video_gen_authorized,
                    'total_vendas'          => (int) $a->commissions()->count(),
                    'receita_gerada'        => (float) $a->commissions()->sum('gross_amount'),
                    'override_gerado_gerente' => round($overrideGerado, 2),
                    // SEL-GERENTE (09/08 tarde): comissao que o gerente deu a este
                    // afiliado dentro do teto do pool (null = gerente ainda nao definiu)
                    'manager_commission_rate' => $a->manager_commission_rate !== null ? (float) $a->manager_commission_rate : null,
                    'desde'                 => $a->created_at,
                ];
            });

        return response()->json([
            'manager'          => [
                'affiliate_id'           => $manager->id,
                'nome'                   => $manager->display_name,
                'manager_override_rate'  => (float) ($manager->manager_override_rate ?? 0),
            ],
            'time'             => $time,
            'total_afiliados'  => $time->count(),
        ]);
    }

    /**
     * PATCH /api/v1/affiliate/manager/team/{affiliateId}/commission
     *
     * O gerente define, PRA CADA afiliado do seu time, quanto desse afiliado
     * ganha — sempre entre 0% e o teto do pool do proprio gerente
     * (manager_override_rate). Guarda em Affiliate.manager_commission_rate.
     *
     * NOTA (corrigida em 16/08): o comentario antigo aqui dizia que este endpoint
     * "ainda NAO altera o calculo real". Isso ficou FALSO desde 09/08 a tarde — o
     * valor guardado aqui e consumido por AffiliateManagerService::resolveSubAffiliateRate
     * e ja divide o pool de verdade em AffiliateCommissionService::registerPayment e no
     * PagarmeWebhookController. Quem lesse o texto antigo e fosse "implementar o split"
     * acabaria descontando o pool duas vezes.
     */
    public function updateMemberCommission(Request $request, int $affiliateId): JsonResponse
    {
        $manager = $this->resolveManager($request);

        $sub = Affiliate::where('id', $affiliateId)->where('manager_id', $manager->id)->first();
        if (! $sub) {
            return response()->json(['message' => 'Afiliado não encontrado no seu time.'], 404);
        }

        $pool = (float) ($manager->manager_override_rate ?? 0);

        $validated = $request->validate([
            'rate' => ['required', 'numeric', 'min:0', "max:{$pool}"],
        ], [
            'rate.max' => "A comissão não pode passar do seu pool ({$pool}%).",
        ]);

        $sub->update(['manager_commission_rate' => $validated['rate']]);

        return response()->json([
            'message'                  => 'Comissão do afiliado atualizada.',
            'affiliate_id'             => $sub->id,
            'manager_commission_rate'  => (float) $sub->manager_commission_rate,
            'pool_do_gerente'          => $pool,
        ]);
    }

    /**
     * GET /api/v1/affiliate/manager/sales-timeline
     *
     * Serie diaria (30 dias) das vendas do TIME do gerente + override que ele
     * ganhou em cada dia — pro grafico "Minha venda ao vivo" do painel.
     * Mesma forma de SupplierAdminDashboard.vendas_30d (total_brl + por_dia)
     * pra manter o padrao ja usado no restante do app.
     */
    public function salesTimeline(Request $request): JsonResponse
    {
        $manager = $this->resolveManager($request);
        $subIds  = $manager->subAffiliates()->pluck('id');

        $desde = now()->subDays(29)->startOfDay();

        $vendasPorDia = AffiliateCommission::whereIn('affiliate_id', $subIds)
            ->where('created_at', '>=', $desde)
            ->selectRaw('DATE(created_at) as dia, COUNT(*) as qtd, SUM(gross_amount) as total')
            ->groupBy('dia')
            ->orderBy('dia')
            ->get()
            ->keyBy('dia');

        $overridePorDia = AffiliateCommission::where('affiliate_id', $manager->id)
            ->where('event_type', 'manager_override')
            ->where('created_at', '>=', $desde)
            ->selectRaw('DATE(created_at) as dia, SUM(commission_amount) as total')
            ->groupBy('dia')
            ->orderBy('dia')
            ->get()
            ->keyBy('dia');

        $porDia = [];
        $totalBrl = 0.0;
        for ($i = 0; $i < 30; $i++) {
            $dia = now()->subDays(29 - $i)->format('Y-m-d');
            $v = $vendasPorDia->get($dia);
            $o = $overridePorDia->get($dia);
            $totalDia = (float) ($v->total ?? 0);
            $totalBrl += $totalDia;
            $porDia[] = [
                'date'     => $dia,
                'qtd'      => (int) ($v->qtd ?? 0),
                'total'    => round($totalDia, 2),
                'override' => round((float) ($o->total ?? 0), 2),
            ];
        }

        return response()->json([
            'vendas_30d' => [
                'total_brl' => round($totalBrl, 2),
                'por_dia'   => $porDia,
            ],
        ]);
    }

    /** GET /api/v1/affiliate/manager/team/{affiliateId} — indicados + checkout + comissao de 1 afiliado do time */
    /**
     * SEL-GERENTE-CRIA-CONTA (16/08) — o gerente cria a conta do afiliado dele.
     *
     * Antes so existia link de convite, e o aceite exigia que a pessoa JA tivesse login.
     * Para quem nao tem conta, o convite nao levava a lugar nenhum (a tabela de convites
     * esta vazia com AUTO_INCREMENT=3: duas tentativas nao viraram nada).
     *
     * Nasce aprovado e ativo — quem criou foi o gerente, que responde pelo time. A senha
     * provisoria e devolvida UMA vez, para o gerente repassar; o afiliado troca depois em
     * Configuracoes > Senha.
     */
    public function createMember(Request $request): JsonResponse
    {
        $manager = $this->resolveManager($request);

        // Dois niveis, nao mais. Sub que cria sub vira piramide e o pool deixa de fechar.
        if ($manager->manager_id) {
            return response()->json([
                'message' => 'Voce faz parte do time de outro afiliado e por isso nao pode criar contas.',
            ], 403);
        }

        $pool = (float) ($manager->manager_override_rate ?? 0);
        if ($pool <= 0) {
            return response()->json([
                'message' => 'Sua comissao de gerente ainda nao foi definida. Fale com o suporte antes de montar seu time.',
            ], 422);
        }

        // Piso 5: com 0 o afiliado trabalha de graca. Teto pool-5: com o pool inteiro o
        // GERENTE some do proprio extrato (o override zera e nao gera linha nenhuma).
        $piso = 5.0;
        $teto = max($piso, round($pool - 5, 2));

        $dados = $request->validate([
            'name'            => ['required', 'string', 'min:2', 'max:120'],
            'email'           => ['required', 'email', 'max:190'],
            'phone'           => ['nullable', 'string', 'max:30'],
            'commission_rate' => ['nullable', 'numeric', 'min:' . $piso, 'max:' . $teto],
        ]);

        $email = mb_strtolower(trim($dados['email']));

        if ($email === mb_strtolower((string) $request->user()->email)) {
            return response()->json(['message' => 'Voce nao pode criar uma conta com o seu proprio e-mail.'], 422);
        }

        if (\App\Models\User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return response()->json([
                'message' => 'Ja existe uma conta com esse e-mail. Peca para essa pessoa entrar e use o link de convite.',
            ], 409);
        }

        // Metade do pool e a leitura literal da regra ("desse 50% dele, quanto ele da").
        $taxa = isset($dados['commission_rate'])
            ? round((float) $dados['commission_rate'], 2)
            : round($pool / 2, 2);

        $senha = \Illuminate\Support\Str::password(12, true, true, false);

        $resultado = \Illuminate\Support\Facades\DB::transaction(function () use ($dados, $email, $manager, $taxa, $senha, $request) {
            $user = \App\Models\User::create([
                'name'              => trim($dados['name']),
                'email'             => $email,
                'password'          => \Illuminate\Support\Facades\Hash::make($senha),
                'role'              => 'affiliate',
                'email_verified_at' => now(),
            ]);

            $afiliado = Affiliate::create([
                'user_id'                 => $user->id,
                'referral_code'           => $this->codigoUnico(),
                'commission_rate'         => 30.00,
                'status'                  => 'active',
                'approval_status'         => 'approved',
                'approved_at'             => now(),
                'application_name'        => trim($dados['name']),
                'application_email'       => $email,
                'application_phone'       => $dados['phone'] ?? null,
                'manager_id'              => $manager->id,
                'manager_commission_rate' => $taxa,
                'video_gen_authorized'    => false,
            ]);

            \Illuminate\Support\Facades\Log::info('[SEL-GERENTE-CRIA-CONTA] gerente criou afiliado', [
                'gerente_afiliado_id' => $manager->id,
                'gerente_user_id'     => $request->user()->id,
                'novo_afiliado_id'    => $afiliado->id,
                'novo_user_id'        => $user->id,
                'pool'                => (float) $manager->manager_override_rate,
                'taxa_do_membro'      => $taxa,
            ]);

            return ['user' => $user, 'afiliado' => $afiliado];
        });

        return response()->json([
            'message' => 'Conta criada. Passe o e-mail e a senha provisoria para o seu afiliado — ele troca a senha em Configuracoes.',
            'membro'  => [
                'affiliate_id'            => $resultado['afiliado']->id,
                'nome'                    => $resultado['user']->name,
                'email'                   => $resultado['user']->email,
                'referral_code'           => $resultado['afiliado']->referral_code,
                'manager_commission_rate' => $taxa,
                'pool_do_gerente'         => $pool,
                'minha_sobra'             => round($pool - $taxa, 2),
            ],
            // Aparece UMA vez. Nao guardamos senha em texto em lugar nenhum.
            'senha_provisoria' => $senha,
        ], 201);
    }

    /** Codigo de indicacao unico (mesmo formato do fluxo normal: 8 caracteres). */
    private function codigoUnico(): string
    {
        do {
            $codigo = strtoupper(bin2hex(random_bytes(4)));
        } while (Affiliate::where('referral_code', $codigo)->exists());

        return $codigo;
    }

    public function teamMemberDetail(Request $request, int $affiliateId): JsonResponse
    {
        $manager = $this->resolveManager($request);

        $sub = Affiliate::where('id', $affiliateId)->where('manager_id', $manager->id)->first();
        if (! $sub) {
            return response()->json(['message' => 'Afiliado não encontrado no seu time.'], 404);
        }

        $indicados = $sub->referrals()
            ->with('referredClient.user:id,name,email')
            ->latest('attributed_at')
            ->paginate(30);

        $indicados->getCollection()->transform(function ($r) use ($sub) {
            $comissao = AffiliateCommission::where('affiliate_id', $sub->id)
                ->where('referral_id', $r->id)
                ->orderByDesc('created_at')
                ->first();

            $statusCheckout = 'nao_pagou';
            if ($r->status === 'converted') {
                $statusCheckout = 'pago';
            } elseif ($r->referred_client_id) {
                $statusCheckout = 'pendente';
            }

            return [
                'referral_id'      => $r->id,
                'cliente'          => $r->referredClient?->user?->name ?? '-',
                'status_checkout'  => $statusCheckout,
                'atribuido_em'     => $r->attributed_at,
                'convertido_em'    => $r->converted_at,
                'ultima_comissao'  => $comissao ? [
                    'valor'       => (float) $comissao->commission_amount,
                    'evento'      => $comissao->event_type,
                    'status'      => $comissao->status,
                    'criada_em'   => $comissao->created_at,
                ] : null,
            ];
        });

        $overrideTotal = (float) AffiliateCommission::where('affiliate_id', $manager->id)
            ->where('event_type', 'manager_override')
            ->whereHas('referral', fn ($q) => $q->where('affiliate_id', $sub->id))
            ->sum('commission_amount');

        return response()->json([
            'afiliado' => [
                'affiliate_id'         => $sub->id,
                'nome'                 => $sub->display_name,
                'video_gen_authorized' => (bool) $sub->video_gen_authorized,
            ],
            'indicados'          => $indicados,
            'override_total_gerado' => round($overrideTotal, 2),
        ]);
    }

    /** GET /api/v1/affiliate/manager/overrides — extrato das comissoes override recebidas pelo gerente */
    public function overrides(Request $request): JsonResponse
    {
        $manager = $this->resolveManager($request);

        $overrides = $manager->commissions()
            ->where('event_type', 'manager_override')
            ->latest()
            ->paginate(30);

        return response()->json($overrides);
    }

    private function resolveManager(Request $request): Affiliate
    {
        $affiliate = Affiliate::where('user_id', $request->user()->id)->first();
        if (! $affiliate) {
            abort(404, 'Você não está cadastrado como afiliado.');
        }
        if (! $affiliate->is_manager) {
            abort(403, 'Acesso restrito a gerentes de afiliados.');
        }
        return $affiliate;
    }

    // =========================================================================
    // ADMIN
    // =========================================================================

    /** PATCH /api/v1/affiliate/admin/{id}/manager-classify — direto vs gerente */
    public function adminClassify(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        $affiliate = Affiliate::findOrFail($id);

        $validated = $request->validate([
            'is_manager'             => 'required|boolean',
            'manager_override_rate'  => 'nullable|numeric|min:0|max:100',
        ]);

        $data = ['is_manager' => $validated['is_manager']];
        if ($validated['is_manager']) {
            $data['manager_override_rate'] = $validated['manager_override_rate'] ?? $affiliate->manager_override_rate ?? 10.00;
        }
        $affiliate->update($data);

        return response()->json(['message' => 'Classificação atualizada.', 'affiliate' => $affiliate->fresh()]);
    }

    /** PATCH /api/v1/affiliate/admin/{id}/video-gen — autoriza/revoga geracao de video */
    public function adminVideoGen(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        $affiliate = Affiliate::findOrFail($id);

        $validated = $request->validate(['authorized' => 'required|boolean']);
        $affiliate->update(['video_gen_authorized' => $validated['authorized']]);

        return response()->json([
            'message'    => $validated['authorized'] ? 'Geração de vídeo liberada.' : 'Geração de vídeo revogada.',
            'affiliate'  => $affiliate->fresh(),
        ]);
    }

    /** PATCH /api/v1/affiliate/admin/{id}/assign-manager — atribui/remove o gerente manualmente (sem convite) */
    public function adminAssignManager(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        $affiliate = Affiliate::findOrFail($id);

        $validated = $request->validate(['manager_id' => 'nullable|integer|exists:affiliates,id']);

        if (($validated['manager_id'] ?? null) === $affiliate->id) {
            return response()->json(['message' => 'Um afiliado não pode ser gerente de si mesmo.'], 422);
        }

        $data = ['manager_id' => $validated['manager_id'] ?? null];
        if ($data['manager_id']) {
            $data['video_gen_authorized'] = false; // entra sob gerente = precisa liberacao
        }
        $affiliate->update($data);

        return response()->json(['message' => 'Gerente atualizado.', 'affiliate' => $affiliate->fresh()]);
    }

    /** GET /api/v1/affiliate/admin/managers — lista gerentes + tamanho do time + override pendente */
    public function adminManagersList(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $managers = Affiliate::managers()
            ->with('user:id,name,email')
            ->get()
            ->map(function (Affiliate $m) {
                $overridePendente = (float) AffiliateCommission::where('affiliate_id', $m->id)
                    ->where('event_type', 'manager_override')
                    ->where('status', 'pending')
                    ->sum('commission_amount');
                $overrideAprovado = (float) AffiliateCommission::where('affiliate_id', $m->id)
                    ->where('event_type', 'manager_override')
                    ->where('status', 'approved')
                    ->sum('commission_amount');

                return [
                    'affiliate_id'           => $m->id,
                    'nome'                   => $m->display_name,
                    'email'                  => $m->user?->email ?? $m->application_email,
                    'manager_override_rate'  => (float) ($m->manager_override_rate ?? 0),
                    'tamanho_time'           => $m->subAffiliates()->count(),
                    'override_pendente'      => round($overridePendente, 2),
                    'override_aprovado'      => round($overrideAprovado, 2),
                ];
            });

        return response()->json(['managers' => $managers, 'total' => $managers->count()]);
    }

    /** POST /api/v1/affiliate/admin/managers/{id}/approve-payout — aprova overrides pendentes do gerente */
    public function adminApprovePayout(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        $manager = Affiliate::findOrFail($id);
        if (! $manager->is_manager) {
            return response()->json(['message' => 'Este afiliado não é gerente.'], 422);
        }

        $atualizados = AffiliateCommission::where('affiliate_id', $manager->id)
            ->where('event_type', 'manager_override')
            ->where('status', 'pending')
            ->update(['status' => 'approved']);

        return response()->json([
            'message'             => "{$atualizados} comissão(ões) de override aprovada(s). O saque segue o fluxo normal de saque do afiliado.",
            'comissoes_aprovadas' => $atualizados,
        ]);
    }

    private function requireAdmin(Request $request): void
    {
        if (! in_array($request->user()?->role, ['super_admin', 'admin'])) {
            abort(403, 'Acesso restrito a administradores.');
        }
    }

    // =========================================================================
    // CONVITE — publico (preview) + autenticado (aceitar)
    // =========================================================================

    /** GET /api/v1/affiliate/invite/{token} — preview publico do convite */
    public function inviteInfo(string $token): JsonResponse
    {
        $invite = AffiliateManagerInvite::where('token', $token)->with('manager')->first();
        if (! $invite || ! $invite->manager) {
            return response()->json(['message' => 'Convite não encontrado.'], 404);
        }

        return response()->json([
            'valid'        => $invite->isValid(),
            'manager_nome' => $invite->manager->display_name,
            'expires_at'   => $invite->expires_at,
        ]);
    }

    /** POST /api/v1/affiliate/invite/{token}/accept — aceitar convite (usuario logado) */
    public function acceptInvite(Request $request, string $token): JsonResponse
    {
        $result = AffiliateManagerService::acceptInvite($token, $request->user());
        return response()->json(
            array_filter([
                'message'   => $result['message'],
                'affiliate' => $result['affiliate'] ?? null,
            ], fn ($v) => $v !== null),
            $result['status']
        );
    }
}
