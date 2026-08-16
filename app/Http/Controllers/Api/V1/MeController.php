<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class MeController extends Controller
{
    #[OA\Get(
        path: '/api/v1/me',
        summary: 'Retorna os dados do usuario autenticado e seu perfil de lojista',
        description: 'Endpoint de identidade. Retorna os dados basicos do usuario logado (id, nome, email, role) e o perfil completo de lojista (client) vinculado, incluindo plano ativo, saldo e configuracoes. Use este endpoint apos o login para montar o contexto da sessao no front-end.',
        tags: ['Auth'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Dados do usuario e perfil de lojista retornados com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(
                                    property: 'id',
                                    type: 'integer',
                                    description: 'ID unico do usuario',
                                    example: 42
                                ),
                                new OA\Property(
                                    property: 'name',
                                    type: 'string',
                                    description: 'Nome completo do usuario',
                                    example: 'Joao Silva'
                                ),
                                new OA\Property(
                                    property: 'email',
                                    type: 'string',
                                    format: 'email',
                                    description: 'E-mail do usuario',
                                    example: 'joao@loja.com'
                                ),
                                new OA\Property(
                                    property: 'role',
                                    type: 'string',
                                    description: 'Papel do usuario no sistema: admin, client ou supplier',
                                    enum: ['admin', 'client', 'supplier'],
                                    example: 'client'
                                ),
                                new OA\Property(
                                    property: 'client',
                                    type: 'object',
                                    nullable: true,
                                    description: 'Perfil de lojista vinculado ao usuario. Null se o usuario nao for lojista.',
                                    properties: [
                                        new OA\Property(property: 'id', type: 'integer', example: 7),
                                        new OA\Property(property: 'user_id', type: 'integer', example: 42),
                                        new OA\Property(
                                            property: 'company_name',
                                            type: 'string',
                                            description: 'Razao social ou nome fantasia do lojista',
                                            example: 'Loja do Joao LTDA'
                                        ),
                                        new OA\Property(
                                            property: 'document',
                                            type: 'string',
                                            description: 'CNPJ ou CPF do lojista',
                                            example: '12.345.678/0001-90'
                                        ),
                                        new OA\Property(
                                            property: 'phone',
                                            type: 'string',
                                            nullable: true,
                                            example: '(11) 99999-1234'
                                        ),
                                        new OA\Property(
                                            property: 'created_at',
                                            type: 'string',
                                            format: 'date-time',
                                            example: '2024-01-15T10:30:00Z'
                                        ),
                                    ]
                                ),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente ou invalido',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.'),
                    ]
                )
            ),
        ]
    )]
    public function __invoke(Request $request)
    {
        $user = $request->user()->load('client');

        // SEL-092 fix Ruan 19:26: cliente pagante caiu como demo porque
        // subscription.plan (com slug) nao vinha. Fixado: carrega plan e expoe
        // plan_slug tanto na subscription quanto top-level pra TierContext ler.
        $subscription = $user->client?->subscriptions()->with('plan:id,slug,name,price_monthly')->latest()->first();
        $planSlug = $subscription?->plan?->slug;
        $planId = $subscription?->plan_id;

        // SEL-CONVITE: estado do trial do /convite pro front montar cronometro +
        // oferta. Se JA passou das 24h, trialInfoFor marca a linha como expirada e
        // devolve expired=true — o front (ConviteGuard) cobre a tela com a parede
        // de upgrade. NAO revoga o token AQUI de proposito: revogar no /me fazia os
        // fetches da propria pagina caírem em 401 e jogava o usuario no /login
        // generico em vez da parede bonita. O corte de acesso real fica no
        // EnsureInviteTrialActive (rotas de geracao) + no re-login (start = wall).
        $trialInfo = \App\Services\InviteTrialService::trialInfoFor($user->id);

        return response()->json([
            'data' => [
                'id'                => $user->id,
                'name'              => $user->name,
                'email'             => $user->email,
                'role'              => $user->role,
                'is_affiliate' => (bool) \DB::table('affiliates')->where('user_id', $user->id)->exists(),
                'trial'             => $trialInfo,
                'client'            => $user->client,
                'plan_id'           => $planId,
                'plan_slug'         => $planSlug,
                'subscription'      => $subscription ? array_merge(
                    $subscription->only(['status', 'plan_id', 'trial_ends_at', 'current_period_end']),
                    ['plan' => $subscription->plan ? [
                        'id'    => $subscription->plan->id,
                        'slug'  => $subscription->plan->slug,
                        'name'  => $subscription->plan->name ?? null,
                        'price' => $subscription->plan->price_monthly ?? null,
                    ] : null]
                ) : null,
                'has_active_access' => in_array(
                    $subscription?->status,
                    ['active', 'trialing']
                ),
            ],
        ]);
    }
}
