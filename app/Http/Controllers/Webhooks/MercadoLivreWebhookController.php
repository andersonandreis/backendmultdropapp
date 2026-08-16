<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\WebhookDispatcherService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Controller legado de webhook do Mercado Livre.
 * Mantido para compatibilidade. Delega ao WebhookDispatcherService.
 *
 * A rota ativa usa App\Http\Controllers\Api\Webhooks\MercadoLivreWebhookController.
 */
class MercadoLivreWebhookController extends Controller
{
    public function handle(Request $request)
    {
        Log::info('[ML Webhook Legacy] Evento recebido, delegando ao dispatcher', [
            'topic'    => $request->input('topic'),
            'resource' => $request->input('resource'),
        ]);

        return app(WebhookDispatcherService::class)->process('mercadolivre', $request);
    }
}
