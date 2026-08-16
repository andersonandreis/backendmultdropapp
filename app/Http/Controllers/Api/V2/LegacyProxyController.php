<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HUB-080 - Legacy Proxy V2
 *
 * Proxy autenticado que encaminha chamadas do frontend hubai.io
 * para o legado goolhub.io usando a sessao PHP obtida no login.
 *
 * O frontend manda { endpoint, method, body, legacy_session }.
 * O proxy constroi a URL https://goolhub.io/api/app2/auth{endpoint},
 * injeta o JWT legado (Authorization: Bearer) e repassa a resposta integralmente.
 */
class LegacyProxyController extends Controller
{
    private const LEGACY_BASE = 'https://goolhub.io/api/app2/auth';

    /** Endpoints permitidos (whitelist de prefixos) para evitar SSRF */
    private const ALLOWED_PREFIXES = [
        '/get_',
        '/set_',
        '/save_',
        '/list_',
        '/update_',
        '/delete_',
        '/check_',
        '/fetch_',
        '/create_',
        '/cancel_',
        '/orders',
        '/products',
        '/dashboard',
        '/stats',
        '/label',
        '/invoice',
        '/financial',
        '/buscar_',
        '/autobot/',
        '/didit/',
        '/ia/',
        '/insert_',
        '/integration/',
        '/marketplace/',
        '/pedidos/',
        '/product/',
        '/produto_cadastro/',
        '/produtos/',
        '/sku/',
        '/upload_',
        '/users/',
        '/wallet/',
    ];

    /**
     * POST /api/v2/proxy
     *
     * Encaminha requisicao autenticada para o legado goolhub.io.
     *
     * Body:
     *   endpoint       string   Ex: "/get_orders_stats.php"
     *   method         string   "GET" | "POST" (default: POST)
     *   body           object   Payload a encaminhar (opcional)
     *   legacy_session string   JWT obtido no login via auth_login.php
     */
    public function proxy(Request $request)
    {
        $request->validate([
            'endpoint'       => 'required|string|max:256',
            'method'         => 'nullable|string|in:GET,POST,PUT,PATCH,DELETE',
            'body'           => 'nullable|array',
            'legacy_session' => 'nullable|string|max:512',
        ]);

        $endpoint      = $request->input('endpoint');
        $method        = strtoupper($request->input('method', 'POST'));
        $body          = $request->input('body', []);
        $legacySession = $request->input('legacy_session');

        // Frontend hubai.io v2 (extractEndpoint) envia endpoint com prefixo /app2/auth.
        // LEGACY_BASE ja termina em /app2/auth, entao precisamos strip pra:
        //   (a) nao duplicar path na URL final (goolhub.io/api/app2/auth/app2/auth/...)
        //   (b) permitir que a whitelist bata (prefixos sao /get_, /products, /wallet/, etc)
        if (str_starts_with($endpoint, '/app2/auth')) {
            $endpoint = substr($endpoint, strlen('/app2/auth')) ?: '/';
        }

        // Validacao de seguranca: endpoint deve comecar com prefixo permitido
        if (! $this->isEndpointAllowed($endpoint)) {
            return response()->json([
                'error'    => 'endpoint_not_allowed',
                'message'  => 'Endpoint nao permitido pelo proxy.',
                'endpoint' => $endpoint,
            ], 403);
        }

        $url = self::LEGACY_BASE . $endpoint;

        // Log da requisicao para auditoria
        Log::info('[LegacyProxy] Encaminhando requisicao', [
            'user_id'   => $request->user()->id,
            'url'       => $url,
            'method'    => $method,
            'has_session' => ! empty($legacySession),
        ]);

        try {
            $http = Http::timeout(30)
                ->withOptions(['allow_redirects' => true]);

            // Injeta JWT do legado via Authorization: Bearer se disponivel
            if ($legacySession) {
                $http = $http->withHeaders([
                    'Authorization' => 'Bearer ' . $legacySession,
                    'Origin'        => 'https://hubai.io',
                    'Referer'       => 'https://hubai.io/',
                ]);
            }

            $response = match ($method) {
                'GET'    => $http->get($url, $body),
                'POST'   => $http->asForm()->post($url, $body),
                'PUT'    => $http->asForm()->put($url, $body),
                'PATCH'  => $http->asForm()->patch($url, $body),
                'DELETE' => $http->delete($url, $body),
                default  => $http->asForm()->post($url, $body),
            };

            $statusCode  = $response->status();
            $contentType = $response->header('Content-Type', 'application/json');

            // Tenta retornar como JSON, fallback para texto
            if (str_contains($contentType, 'application/json')) {
                return response()->json($response->json(), $statusCode);
            }

            return response($response->body(), $statusCode)
                ->header('Content-Type', $contentType);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('[LegacyProxy] Falha de conexao com legado', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error'   => 'legacy_unavailable',
                'message' => 'Servico legado indisponivel. Tente novamente.',
            ], 503);

        } catch (\Throwable $e) {
            Log::error('[LegacyProxy] Erro inesperado', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error'   => 'proxy_error',
                'message' => 'Erro interno no proxy.',
            ], 500);
        }
    }

    /**
     * Verifica se o endpoint comeca com algum prefixo permitido.
     * Protecao basica contra SSRF.
     */
    private function isEndpointAllowed(string $endpoint): bool
    {
        // Deve comecar com '/'
        if (! str_starts_with($endpoint, '/')) {
            return false;
        }

        // Nao pode conter traversal
        if (str_contains($endpoint, '..') || str_contains($endpoint, '//')) {
            return false;
        }

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($endpoint, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
