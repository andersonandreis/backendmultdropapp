<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * NOV-171-C -- Middleware de verificacao de assinatura HMAC nos endpoints de recepcao WL.
 *
 * O hub (api.hubai.io) assina o payload com o secret configurado no TenantWebhookEndpoint.
 * Header esperado: X-HubAI-Signature: sha256=<hex>
 * Secret: config('federation.hmac_secret') = env('FEDERATION_HMAC_SECRET')
 *
 * Retorna 401 se assinatura ausente, formato invalido, ou nao confere.
 *
 * NOV-171-D fix: prefixo corrigido de hmac-sha256= para sha256= (alinhado com DispatchWebhookJob).
 */
class VerifyFederationHmac
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('federation.hmac_secret');

        if (! $secret) {
            \Illuminate\Support\Facades\Log::warning('[VerifyFederationHmac] FEDERATION_HMAC_SECRET nao configurado');
            return response()->json(['message' => 'Federacao nao configurada neste backend.'], 401);
        }

        $signature = $request->header('X-HubAI-Signature');

        if (! $signature) {
            return response()->json(['message' => 'Assinatura de federacao ausente (X-HubAI-Signature).'], 401);
        }

        if (! str_starts_with($signature, 'sha256=')) {
            return response()->json(['message' => 'Formato de assinatura invalido. Esperado: sha256=<hex>.'], 401);
        }

        $receivedHex = substr($signature, strlen('sha256='));
        $body        = $request->getContent();
        $expectedHex = hash_hmac('sha256', $body, $secret);

        if (! hash_equals($expectedHex, $receivedHex)) {
            \Illuminate\Support\Facades\Log::warning('[VerifyFederationHmac] assinatura invalida', [
                'path'     => $request->path(),
                'expected' => substr($expectedHex, 0, 8) . '...',
                'received' => substr($receivedHex, 0, 8) . '...',
            ]);
            return response()->json(['message' => 'Assinatura de federacao invalida.'], 401);
        }

        return $next($request);
    }
}
