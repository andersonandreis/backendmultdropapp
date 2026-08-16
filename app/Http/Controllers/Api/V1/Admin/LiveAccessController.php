<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\LiveOnlyAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SEL-408 — painel admin de acesso à LIVE (seller.global/admin).
 *
 * Pedido do Ruan (30/07): ele precisa ver quem tem acesso à live, liberar
 * pra quem quiser (independente do plano) e criar contas "só-live" pra
 * mandar pra alguém testar sem dar acesso ao resto do sistema.
 *
 * A rota é única pra "liberar" e "criar conta nova": se o e-mail já existe
 * (role client), só concede em cima do client dele; se não existe, cria
 * user+client novos com senha temporária (devolvida SÓ nessa resposta —
 * o admin precisa copiar/mandar na hora).
 */
class LiveAccessController extends Controller
{
    /**
     * GET /api/v1/admin/live-access
     * Lista quem tem acesso à live: e-mail, quando ganhou, último uso, origem
     * (assinatura paga ou concessão só-live).
     *
     * Universo listado = quem JÁ USOU a extensão (tem token 'extensao-live')
     * OU tem uma concessão só-live ativa agora (mesmo sem ter usado ainda —
     * o admin acabou de criar e quer ver/gerenciar). NÃO lista os milhares de
     * assinantes pagantes que nunca tocaram na live: isso aqui é o painel de
     * LIVE, não a lista geral de clientes.
     *
     * Filtros opcionais: ?origem=concessao|assinatura  ?q=busca por e-mail/nome
     */
    public function index(Request $request): JsonResponse
    {
        $userIdsComUso = DB::table('personal_access_tokens')
            ->where('tokenable_type', \App\Models\User::class)
            ->where('name', 'extensao-live')
            ->pluck('tokenable_id')
            ->unique();

        $clientIdsComConcessao = \App\Models\Subscription::where('payment_method', LiveOnlyAccessService::GRANT_METHOD)
            ->whereNull('cancelled_at')
            ->pluck('client_id')
            ->unique();

        $query = Client::query()
            ->where(function ($q) use ($userIdsComUso, $clientIdsComConcessao) {
                $q->whereIn('user_id', $userIdsComUso)->orWhereIn('id', $clientIdsComConcessao);
            })
            ->with([
                'user:id,name,email',
                'subscriptions' => function ($q) {
                    $q->whereIn('status', ['active', 'trialing'])
                        ->whereNull('cancelled_at')
                        ->with('plan:id,slug,name')
                        ->latest('id');
                },
            ]);

        if ($busca = $request->query('q')) {
            $query->whereHas('user', function ($q) use ($busca) {
                $q->where('email', 'like', "%{$busca}%")->orWhere('name', 'like', "%{$busca}%");
            });
        }

        if ($origem = $request->query('origem')) {
            if ($origem === 'concessao') {
                $query->whereIn('id', $clientIdsComConcessao);
            } elseif ($origem === 'assinatura') {
                $query->whereHas('subscriptions', function ($q) {
                    $q->whereIn('status', ['active', 'trialing'])
                        ->whereNull('cancelled_at')
                        ->where('payment_method', '!=', LiveOnlyAccessService::GRANT_METHOD)
                        ->orWhereNull('payment_method');
                });
            }
        }

        $clients = $query->orderByDesc('id')->paginate(50);

        $clients->getCollection()->transform(function (Client $client) {
            $sub = $client->subscriptions->first(); // mais recente ativa (latest('id') no with)
            $user = $client->user;
            $origem = $sub?->payment_method === LiveOnlyAccessService::GRANT_METHOD ? 'concessao' : 'assinatura';

            $ultimoUso = DB::table('personal_access_tokens')
                ->where('tokenable_type', \App\Models\User::class)
                ->where('tokenable_id', $user?->id)
                ->where('name', 'extensao-live')
                ->max('last_used_at');

            return [
                'client_id'    => $client->id,
                'user_id'      => $user?->id,
                'nome'         => $user?->name,
                'email'        => $user?->email,
                'plano'        => $sub?->plan?->slug,
                'status_acesso'=> $sub ? 'ativo' : 'sem_assinatura_ativa', // já usou mas perdeu acesso
                'origem'       => $sub ? $origem : null,
                'ganhou_em'    => $sub?->created_at,
                'expira_em'    => $sub?->current_period_end,
                'ultimo_uso'   => $ultimoUso,
                'ja_usou_live' => (bool) $ultimoUso,
            ];
        });

        return response()->json($clients);
    }

    /**
     * POST /api/v1/admin/live-access
     * Body: { email, name?, expira_em? (Y-m-d, opcional — sem prazo = revogar manual) }
     *
     * Cobre os dois casos do Ruan:
     *  - e-mail novo  → cria conta só-live (extensão + /lives, mais nada)
     *  - e-mail já é cliente → concede live independente do plano dele
     */
    public function grant(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'     => 'required|email|max:255',
            'name'      => 'nullable|string|max:255',
            'expira_em' => 'nullable|date|after:today',
        ]);

        try {
            $resultado = LiveOnlyAccessService::grant(
                $validated['email'],
                $validated['name'] ?? null,
                isset($validated['expira_em']) ? new \DateTime($validated['expira_em']) : null
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message'          => $resultado['criado']
                ? 'Conta só-live criada e acesso concedido.'
                : 'Acesso à live concedido pro cliente existente.',
            'criado'           => $resultado['criado'],
            'client_id'        => $resultado['client']->id,
            'user_id'          => $resultado['user']->id,
            'email'            => $resultado['user']->email,
            'nome'             => $resultado['user']->name,
            'senha_temporaria' => $resultado['senha_temporaria'], // null se a conta já existia
            'subscription'     => [
                'id'         => $resultado['subscription']->id,
                'status'     => $resultado['subscription']->status,
                'expira_em'  => $resultado['subscription']->current_period_end,
            ],
        ], 201);
    }

    /**
     * DELETE /api/v1/admin/live-access/{client}
     * Revoga a concessão só-live daquele client. NUNCA cancela assinatura paga —
     * se o client não tiver uma concessão ativa (acesso vem de plano pago), retorna 422.
     */
    public function revoke(Request $request, int $client): JsonResponse
    {
        $clientModel = Client::findOrFail($client);

        $n = LiveOnlyAccessService::revoke($clientModel);

        if ($n === 0) {
            return response()->json([
                'message' => 'Esse cliente não tem uma concessão só-live ativa pra revogar (o acesso dele, se houver, vem de assinatura paga).',
            ], 422);
        }

        return response()->json(['message' => 'Acesso só-live revogado.']);
    }
}
