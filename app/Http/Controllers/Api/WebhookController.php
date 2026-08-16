<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WebhookDispatcherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Controller generico de webhooks multi-plataforma.
 *
 * Rota: POST /api/webhooks/{platform}
 * Onde {platform} pode ser: mercadolivre, shopee, amazon, etc.
 *
 * O WebhookDispatcherService resolve o handler correto para cada plataforma.
 */
class WebhookController extends Controller
{
    #[OA\Post(
        path: '/api/webhooks/{platform}',
        operationId: 'genericWebhook',
        summary: 'Webhook generico multi-plataforma',
        description: 'Ponto de entrada unificado para webhooks de marketplaces. O WebhookDispatcherService resolve o handler correto por plataforma. Para adicionar novo marketplace, registrar handler em WebhookDispatcherService::$handlers. Cada plataforma valida assinatura no seu proprio handler.',
        tags: ['Webhooks'],
        parameters: [
            new OA\Parameter(
                name: 'platform',
                in: 'path',
                required: true,
                description: 'Identificador da plataforma de origem',
                schema: new OA\Schema(type: 'string'),
                example: 'mercadolivre'
            ),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            description: 'Payload enviado pela plataforma (estrutura varia por plataforma)',
            content: new OA\JsonContent(
                type: 'object',
                example: ['topic' => 'orders_v2', 'resource' => '/orders/123456789', 'user_id' => 987654321]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Evento recebido e processado ou enfileirado com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'ok'),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Plataforma desconhecida ou payload invalido'
            ),
            new OA\Response(
                response: 401,
                description: 'Assinatura HMAC invalida'
            ),
        ]
    )]
    public function handle(Request $request, string $platform): JsonResponse
    {
        return app(WebhookDispatcherService::class)->process($platform, $request);
    }
}
