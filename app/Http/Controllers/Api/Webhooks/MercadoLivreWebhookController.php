<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\WebhookDispatcherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Controller de webhook do Mercado Livre.
 *
 * Toda a logica de validacao de assinatura, extracao de topico e despacho
 * de jobs foi movida para MercadoLivreWebhookHandler + WebhookDispatcherService.
 * Este controller e mantido como alias da rota legada /api/webhooks/mercadolivre.
 */
class MercadoLivreWebhookController extends Controller
{
    #[OA\Post(
        path: '/api/webhooks/mercadolivre',
        operationId: 'mercadolivreWebhookLegacy',
        summary: 'Webhook legado do Mercado Livre',
        description: 'Alias mantido para compatibilidade com configuracoes de webhook ML anteriores. Delega ao WebhookDispatcherService identico ao /api/webhooks/mercadolivre generio. Novos cadastros devem apontar para /api/webhooks/mercadolivre via rota generica. Valida assinatura HMAC-SHA256 via header x-signature.',
        tags: ['Webhooks'],
        requestBody: new OA\RequestBody(
            required: false,
            description: 'Payload de notificacao do MercadoLivre',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'topic', type: 'string', example: 'orders_v2', description: 'Topico do evento ML'),
                    new OA\Property(property: 'resource', type: 'string', example: '/orders/123456789', description: 'Recurso afetado'),
                    new OA\Property(property: 'user_id', type: 'integer', example: 987654321, description: 'ML user_id do vendedor'),
                    new OA\Property(property: 'application_id', type: 'integer', example: 111222333),
                    new OA\Property(property: 'sent', type: 'string', format: 'date-time', example: '2026-05-12T14:30:00Z'),
                    new OA\Property(property: 'attempts', type: 'integer', example: 1),
                    new OA\Property(property: 'received', type: 'string', format: 'date-time', example: '2026-05-12T14:30:01Z'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Evento recebido com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'ok'),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Assinatura HMAC invalida'
            ),
        ]
    )]
    public function handle(Request $request): JsonResponse
    {
        return app(WebhookDispatcherService::class)->process('mercadolivre', $request);
    }
}
