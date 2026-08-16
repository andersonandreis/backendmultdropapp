<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Webhooks\BlingWebhookHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * NOV-077-P3: Processa webhooks do Bling via BlingWebhookHandler.
 *
 * Bling envia HMAC-SHA256 no header X-Bling-Signature-256 (formato sha256=<hash>).
 * O handler valida a assinatura, extrai topic/resource/userId e despacha
 * ProcessBlingWebhookJob para processamento assicrono.
 */
class BlingWebhookController extends Controller
{
    public function __construct(
        protected BlingWebhookHandler $handler
    ) {}

    public function handle(Request $request): Response
    {
        // 1. Validar assinatura HMAC-SHA256 do Bling
        if (! $this->handler->validateSignature($request)) {
            Log::warning("[BlingWebhook] Assinatura HMAC invalida rejeitada", [
                "ip"  => $request->ip(),
                "sig" => substr((string) $request->header("X-Bling-Signature-256", ""), 0, 20),
            ]);

            return response("Unauthorized", 401);
        }

        // 2. Extrair topic, resource e userId do payload
        $topic    = $this->handler->extractTopic($request);
        $resource = $this->handler->extractResource($request);
        $userId   = $this->handler->extractUserId($request);

        Log::info("[BlingWebhook] Evento recebido", [
            "topic"    => $topic,
            "resource" => $resource,
            "userId"   => $userId,
        ]);

        // 3. Despachar job de processamento assicrono
        $this->handler->dispatchJob($topic, $resource, $userId);

        return response("", 200);
    }
}
