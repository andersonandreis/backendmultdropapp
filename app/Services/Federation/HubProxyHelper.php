<?php

namespace App\Services\Federation;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * INF-054 caminho 2: helper que WLs usam pra encaminhar writes pro hub.
 * Se este backend rodar como HUB, o metodo isWl() retorna false — controllers
 * seguem o fluxo normal. Se rodar como WL, isWl() = true e forwardToHub
 * proxya a request pro hub via FEDERATION_HUB_TOKEN.
 */
class HubProxyHelper
{
    public static function isWl(): bool
    {
        return config('federation.tenant', 'hubai') !== 'hubai';
    }

    public static function forwardToHub(string $method, string $path, array $payload = []): JsonResponse
    {
        $hubUrl = rtrim((string) config('federation.hub_url'), '/');
        $token  = (string) config('federation.hub_token');

        if (!$hubUrl || !$token) {
            return response()->json(['error' => 'hub_not_configured'], 500);
        }

        $method = strtolower($method);
        try {
            $resp = Http::withToken($token)->timeout(15)->acceptJson()
                ->{$method}("$hubUrl/api/federation$path", $payload);
        } catch (\Throwable $e) {
            Log::warning('[HubProxy] forwardToHub falhou', [
                'method' => $method, 'path' => $path, 'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'hub_unreachable', 'detail' => $e->getMessage()], 502);
        }

        return response()->json($resp->json() ?? [], $resp->status());
    }
}
