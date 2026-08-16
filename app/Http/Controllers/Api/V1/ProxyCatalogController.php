<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NOV-058-C - Proxy Catalog Controller
 *
 * Repassa requests de catalogo para a API central (api.hubai.io)
 * usando a rota interna /internal/catalog/* com X-Internal-Key.
 * O tenant e identificado via X-Tenant-Slug dinamico (env APP_TENANT).
 *
 * Rotas:
 *   GET  /api/v1/central/catalog/{path}  -> GET  api.hubai.io/internal/catalog/{path}
 *   POST /api/v1/central/catalog/{path}  -> POST api.hubai.io/internal/catalog/{path}
 */
class ProxyCatalogController extends Controller
{
    private string $centralApi;
    private string $internalKey;

    // Prefixos permitidos para evitar SSRF
    private const ALLOWED_PREFIXES = [
        'products',
        'suppliers',
        'catalog',
    ];

    public function __construct()
    {
        $this->centralApi  = rtrim(config('services.central_api.url', 'https://api.hubai.io'), '/');
        $this->internalKey = config('services.central_api.internal_key', '');
    }

    /**
     * Proxy generico para GET requests ao catalogo central.
     */
    public function get(Request $request, string $path): \Illuminate\Http\JsonResponse
    {
        return $this->proxy($request, $path, 'get');
    }

    /**
     * Proxy generico para POST requests ao catalogo central.
     */
    public function post(Request $request, string $path): \Illuminate\Http\JsonResponse
    {
        return $this->proxy($request, $path, 'post');
    }

    private function proxy(Request $request, string $path, string $method): \Illuminate\Http\JsonResponse
    {
        // Protecao SSRF: somente prefixos do catalogo permitidos
        $firstSegment = explode('/', ltrim($path, '/'))[0] ?? '';
        if (! in_array($firstSegment, self::ALLOWED_PREFIXES, true)) {
            return response()->json(['error' => 'Caminho nao permitido'], 403);
        }

        // Usa rota interna (/api/internal/catalog/) com X-Internal-Key
        $url = "{$this->centralApi}/api/internal/catalog/{$path}";

        Log::info('ProxyCatalogController', [
            'method' => $method,
            'url'    => $url,
            'query'  => $request->query(),
        ]);

        try {
            $headers = [
                'X-Internal-Key' => $this->internalKey,
                'X-Tenant-Slug'  => env('APP_TENANT', 'hubai'),
                'Accept'         => 'application/json',
                'X-Forwarded-By' => request()->getHost(),
            ];

            $response = Http::withHeaders($headers)
                ->timeout(15)
                ->{$method}($url, $request->method() === 'GET' ? $request->query() : $request->all());

            return response()->json($response->json(), $response->status());
        } catch (\Illuminate\Http\Client\RequestException $e) {
            Log::error('ProxyCatalogController error', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'Erro ao contatar API central', 'detail' => $e->getMessage()], 502);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('ProxyCatalogController connection error', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'Falha de conexao com API central', 'detail' => $e->getMessage()], 502);
        }
    }
}
