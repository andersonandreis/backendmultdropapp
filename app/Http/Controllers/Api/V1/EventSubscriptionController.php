<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EventSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class EventSubscriptionController extends Controller
{
    #[OA\Get(
        path: '/api/v1/event-subscriptions',
        summary: 'Listar inscricoes em eventos do usuario autenticado',
        description: 'Retorna as inscricoes em eventos configuradas para o usuario autenticado. Cada inscricao define um tipo de evento (ex: order.paid, stock.low) e os canais de notificacao (webhook, email, push).',
        tags: ['Configuracoes'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista de inscricoes em eventos',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'event_type', type: 'string', example: 'order.paid'),
                                new OA\Property(property: 'channels', type: 'array', items: new OA\Items(type: 'string'), example: ['webhook', 'email']),
                                new OA\Property(property: 'webhook_url', type: 'string', nullable: true, example: 'https://meu-sistema.com/hooks/orders'),
                                new OA\Property(property: 'is_active', type: 'boolean', example: true),
                                new OA\Property(property: 'supplier_id', type: 'integer', nullable: true, example: 3),
                                new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                            ]
                        )),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Nao autenticado'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $subscriptions = EventSubscription::where('user_id', $request->user()->id)
            ->orderBy('event_type')
            ->get([
                'id', 'event_type', 'channels', 'webhook_url',
                'supplier_id', 'is_active', 'created_at',
            ]);

        return response()->json(['data' => $subscriptions]);
    }

    #[OA\Post(
        path: '/api/v1/event-subscriptions',
        summary: 'Inscrever-se em um tipo de evento',
        description: 'Cria uma inscricao para receber notificacoes quando um determinado evento ocorrer. Use event_type="*" para receber todos os eventos. Canais disponiveis: webhook, email, push.',
        tags: ['Configuracoes'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['event_type', 'channels'],
                properties: [
                    new OA\Property(property: 'event_type', type: 'string', example: 'order.paid',
                        description: 'Tipo do evento. Use "*" para todos os eventos.'),
                    new OA\Property(property: 'channels', type: 'array',
                        items: new OA\Items(type: 'string', enum: ['webhook', 'email', 'push']),
                        example: ['webhook']),
                    new OA\Property(property: 'webhook_url', type: 'string', nullable: true,
                        example: 'https://meu-sistema.com/hooks/orders'),
                    new OA\Property(property: 'webhook_secret', type: 'string', nullable: true,
                        example: 'meu-segredo-hmac'),
                    new OA\Property(property: 'supplier_id', type: 'integer', nullable: true, example: 3,
                        description: 'Limitar inscricao a eventos de um fornecedor especifico'),
                    new OA\Property(property: 'is_active', type: 'boolean', example: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Inscricao criada',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Inscricao criada com sucesso.'),
                    new OA\Property(property: 'id', type: 'integer', example: 8),
                ])
            ),
            new OA\Response(response: 401, description: 'Nao autenticado'),
            new OA\Response(response: 422, description: 'Dados invalidos'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_type'     => 'required|string|max:100',
            'channels'       => 'required|array|min:1',
            'channels.*'     => 'string|in:webhook,email,push',
            'webhook_url'    => 'nullable|url|max:500',
            'webhook_secret' => 'nullable|string|max:255',
            'supplier_id'    => 'nullable|integer|exists:suppliers,id',
            'is_active'      => 'sometimes|boolean',
        ]);

        $data['user_id'] = $request->user()->id;

        $subscription = EventSubscription::create($data);

        return response()->json([
            'message' => 'Inscricao criada com sucesso.',
            'id'      => $subscription->id,
        ], 201);
    }
}
