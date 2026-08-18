<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class PlanController extends Controller
{
    private function clientOrFail(Request $request)
    {
        $client = $request->user()->client;

        if (! $client) {
            abort(403, 'Usuario nao possui perfil de lojista.');
        }

        return $client;
    }

    #[OA\Get(
        path: '/api/v1/plans',
        summary: 'Listar planos disponiveis para contratacao',
        description: 'Retorna todos os planos ativos da plataforma com seus limites e precos. Use para montar a tela de upgrade/downgrade de plano no frontend.',
        tags: ['Planos'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista de planos ativos',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 1),
                                    new OA\Property(property: 'name', type: 'string', example: 'Start'),
                                    new OA\Property(property: 'slug', type: 'string', example: 'start'),
                                    new OA\Property(property: 'max_skus', type: 'integer', nullable: true, description: 'Limite de SKUs ativos. null = ilimitado', example: 500),
                                    new OA\Property(property: 'max_marketplace_connections', type: 'integer', nullable: true, description: 'Limite de contas de marketplace conectadas. null = ilimitado', example: 2),
                                    new OA\Property(property: 'max_erp_connections', type: 'integer', nullable: true, description: 'Limite de conexoes ERP. null = ilimitado', example: 1),
                                    new OA\Property(property: 'price_monthly', type: 'number', description: 'Preco mensal em R$', example: 29.90),
                                    new OA\Property(property: 'price_yearly', type: 'number', nullable: true, description: 'Preco anual em R$ (12 meses com desconto)', example: 287.90),
                                    new OA\Property(property: 'is_active', type: 'boolean', example: true),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente ou invalido',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.')]
                )
            ),
        ]
    )]
    public function index()
    {
        $plans = Plan::where('is_active', true)
            ->orderBy('price_monthly')
            ->get([
                'id',
                'name',
                'slug',
                'max_skus',
                'max_marketplace_connections',
                'max_erp_connections',
                'price_monthly',
                'price_yearly',
                'is_active',
            ]);

        return response()->json(['data' => $plans]);
    }

    #[OA\Get(
        path: '/api/v1/subscription',
        summary: 'Assinatura ativa do lojista autenticado',
        description: 'Retorna os dados da assinatura mais recente do lojista, incluindo plano, status de pagamento, periodo vigente e data de cancelamento (se houver). Retorna null se o lojista nao possui nenhuma assinatura.',
        tags: ['Planos'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Assinatura atual do lojista (null se inexistente)',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            nullable: true,
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 3),
                                new OA\Property(property: 'status', type: 'string', description: 'Status da assinatura: active, trial, past_due, cancelled, expired', example: 'active'),
                                new OA\Property(property: 'payment_method', type: 'string', nullable: true, description: 'Metodo de pagamento', example: 'credit_card'),
                                new OA\Property(property: 'current_period_start', type: 'string', format: 'date-time', nullable: true, example: '2026-05-01T00:00:00Z'),
                                new OA\Property(property: 'current_period_end', type: 'string', format: 'date-time', nullable: true, example: '2026-06-01T00:00:00Z'),
                                new OA\Property(property: 'cancelled_at', type: 'string', format: 'date-time', nullable: true, example: null),
                                new OA\Property(
                                    property: 'plan',
                                    type: 'object',
                                    nullable: true,
                                    properties: [
                                        new OA\Property(property: 'id', type: 'integer', example: 2),
                                        new OA\Property(property: 'name', type: 'string', example: 'Pro'),
                                        new OA\Property(property: 'slug', type: 'string', example: 'pro'),
                                        new OA\Property(property: 'max_skus', type: 'integer', nullable: true, example: 2000),
                                        new OA\Property(property: 'max_marketplace_connections', type: 'integer', nullable: true, example: 5),
                                        new OA\Property(property: 'price_monthly', type: 'number', example: 99.90),
                                        new OA\Property(property: 'price_yearly', type: 'number', nullable: true, example: 959.90),
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
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.')]
                )
            ),
            new OA\Response(
                response: 403,
                description: 'Usuario nao possui perfil de lojista',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Usuario nao possui perfil de lojista.')]
                )
            ),
        ]
    )]
    public function subscription(Request $request)
    {
        $client = $this->clientOrFail($request);

        $subscription = $client->subscriptions()
            ->with([
                // MUL-420: max_connections_per_platform e max_erp_connections faltavam nesta lista,
                // entao o painel do lojista recebia o plano sem limite por plataforma e caia no
                // default de 999 — mostrava infinito em todo card enquanto o backend barrava a
                // conexao no retorno do OAuth. O lojista so descobria o teto depois de autorizar
                // no marketplace.
                'plan:id,name,slug,max_skus,max_marketplace_connections,max_erp_connections,max_connections_per_platform,price_monthly,price_yearly',
            ])
            ->latest()
            ->first();

        if (! $subscription) {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => [
                'id'                   => $subscription->id,
                'status'               => $subscription->status,
                'payment_method'       => $subscription->payment_method,
                'current_period_start' => $subscription->current_period_start,
                'current_period_end'   => $subscription->current_period_end,
                'cancelled_at'         => $subscription->cancelled_at,
                'plan'                 => $subscription->plan,
            ],
        ]);
    }
}
