<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WebhookConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Configuracoes', description: 'Webhook configs e event subscriptions')]
class WebhookConfigController extends Controller
{
    #[OA\Get(
        path: '/api/v1/webhook-configs',
        summary: 'Listar webhook configs do usuario autenticado',
        description: 'Retorna todas as configuracoes de webhook cadastradas. WebhookConfig define o schema de um webhook de entrada (plataforma de pagamento, ERP, etc.).',
        tags: ['Configuracoes'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista de webhook configs',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'name', type: 'string', example: 'Kiwify Vendas'),
                                new OA\Property(property: 'slug', type: 'string', example: 'kiwify-vendas'),
                                new OA\Property(property: 'is_active', type: 'boolean', example: true),
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
        $userId  = $request->user()->id;

        $configs = WebhookConfig::where('user_id', $userId)
            ->orderBy('name')
            ->get([
                'id', 'name', 'slug', 'security_header', 'event_field',
                'expected_event_value', 'is_active', 'created_at',
            ]);

        return response()->json(['data' => $configs]);
    }

    #[OA\Post(
        path: '/api/v1/webhook-configs',
        summary: 'Criar webhook config',
        description: 'Cria uma nova configuracao de webhook para recepcao de eventos de plataformas externas (ex: Kiwify, Eduzz, Hotmart). O slug e gerado automaticamente a partir do name.',
        tags: ['Configuracoes'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Kiwify Vendas'),
                    new OA\Property(property: 'security_header', type: 'string', nullable: true, example: 'X-Kiwify-Token'),
                    new OA\Property(property: 'event_field', type: 'string', nullable: true, example: 'event'),
                    new OA\Property(property: 'expected_event_value', type: 'string', nullable: true, example: 'order_approved'),
                    new OA\Property(property: 'customer_email_field', type: 'string', nullable: true, example: 'buyer.email'),
                    new OA\Property(property: 'amount_field', type: 'string', nullable: true, example: 'order.amount'),
                    new OA\Property(property: 'subscription_id_field', type: 'string', nullable: true, example: 'subscription.id'),
                    new OA\Property(property: 'is_active', type: 'boolean', example: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Webhook config criada',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Webhook config criada com sucesso.'),
                    new OA\Property(property: 'id', type: 'integer', example: 5),
                    new OA\Property(property: 'slug', type: 'string', example: 'kiwify-vendas'),
                ])
            ),
            new OA\Response(response: 401, description: 'Nao autenticado'),
            new OA\Response(response: 422, description: 'Dados invalidos'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'                   => 'required|string|max:100|unique:webhook_configs,name',
            'security_header'        => 'nullable|string|max:100',
            'event_field'            => 'nullable|string|max:100',
            'expected_event_value'   => 'nullable|string|max:100',
            'customer_email_field'   => 'nullable|string|max:100',
            'amount_field'           => 'nullable|string|max:100',
            'subscription_id_field'  => 'nullable|string|max:100',
            'is_active'              => 'sometimes|boolean',
        ]);

        $data['user_id'] = $request->user()->id;

        $config = WebhookConfig::create($data);

        return response()->json([
            'message' => 'Webhook config criada com sucesso.',
            'id'      => $config->id,
            'slug'    => $config->slug,
        ], 201);
    }

    #[OA\Put(
        path: '/api/v1/webhook-configs/{id}',
        summary: 'Atualizar webhook config',
        description: 'Atualiza os campos de uma configuracao de webhook existente.',
        tags: ['Configuracoes'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'), example: 5),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Kiwify Vendas v2'),
                    new OA\Property(property: 'is_active', type: 'boolean', example: false),
                    new OA\Property(property: 'security_header', type: 'string', nullable: true),
                    new OA\Property(property: 'event_field', type: 'string', nullable: true),
                    new OA\Property(property: 'expected_event_value', type: 'string', nullable: true),
                    new OA\Property(property: 'customer_email_field', type: 'string', nullable: true),
                    new OA\Property(property: 'amount_field', type: 'string', nullable: true),
                    new OA\Property(property: 'subscription_id_field', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Atualizado com sucesso',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Webhook config atualizada com sucesso.'),
                ])
            ),
            new OA\Response(response: 401, description: 'Nao autenticado'),
            new OA\Response(response: 404, description: 'Config nao encontrada'),
            new OA\Response(response: 422, description: 'Dados invalidos'),
        ]
    )]
    public function update(Request $request, int $id): JsonResponse
    {
        $config = WebhookConfig::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $data = $request->validate([
            'name'                   => "sometimes|required|string|max:100|unique:webhook_configs,name,{$id}",
            'security_header'        => 'nullable|string|max:100',
            'event_field'            => 'nullable|string|max:100',
            'expected_event_value'   => 'nullable|string|max:100',
            'customer_email_field'   => 'nullable|string|max:100',
            'amount_field'           => 'nullable|string|max:100',
            'subscription_id_field'  => 'nullable|string|max:100',
            'is_active'              => 'sometimes|boolean',
        ]);

        $config->update($data);

        return response()->json(['message' => 'Webhook config atualizada com sucesso.']);
    }

    #[OA\Delete(
        path: '/api/v1/webhook-configs/{id}',
        summary: 'Remover webhook config',
        description: 'Remove permanentemente uma configuracao de webhook. Nao pode ser desfeito.',
        tags: ['Configuracoes'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'), example: 5),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Removida com sucesso',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Webhook config removida com sucesso.'),
                ])
            ),
            new OA\Response(response: 401, description: 'Nao autenticado'),
            new OA\Response(response: 404, description: 'Config nao encontrada'),
        ]
    )]
    public function destroy(Request $request, int $id): JsonResponse
    {
        $config = WebhookConfig::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
        $config->delete();

        return response()->json(['message' => 'Webhook config removida com sucesso.']);
    }
}
