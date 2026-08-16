<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NOV-019 - Legacy Forward Controller
 *
 * Proxy transparente para o legado goolhub.io.
 * Recebe o jwt_token legado do usuário diretamente no body (sem Sanctum),
 * pois quem chama é a edge function gohub-proxy do Supabase, que já carrega
 * o gohub_api_token da tabela painel_gohub_sessions.
 *
 * POST /api/v1/legacy-forward
 *   endpoint     string   Caminho completo ex: "/app2/auth/get_plano.php"
 *   jwt_token    string   JWT legado do goolhub (gohub_api_token)
 *   body         object   Payload a encaminhar (opcional)
 *   method       string   "GET" | "POST" (default: POST)
 *   content_type string   "json" | "form" (default: "json")
 */
class LegacyForwardController extends Controller
{
    private const GOOLHUB_BASE = 'https://goolhub.io/api';

    // Prefixos permitidos (protecao SSRF)
    private const ALLOWED_PREFIXES = [
        '/app2/',
    ];

    public function forward(Request $request): \Illuminate\Http\JsonResponse
    {
        $endpoint    = $request->input('endpoint');
        $jwtToken    = $request->input('jwt_token');
        $body        = $request->input('body', []);
        $method      = strtoupper($request->input('method', 'POST'));
        $contentType = $request->input('content_type', 'json');

        // Validacoes basicas
        if (empty($endpoint)) {
            return response()->json(['success' => false, 'error' => 'endpoint obrigatorio'], 400);
        }

        if (empty($jwtToken)) {
            return response()->json(['success' => false, 'error' => 'jwt_token obrigatorio'], 401);
        }

        // Protecao SSRF: endpoint deve comecar com prefixo permitido
        $allowed = false;
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($endpoint, $prefix)) {
                $allowed = true;
                break;
            }
        }

        if (! $allowed) {
            Log::warning('[LegacyForward] Endpoint nao permitido', ['endpoint' => $endpoint]);
            return response()->json(['success' => false, 'error' => 'endpoint nao permitido'], 403);
        }

        // Traversal guard
        if (str_contains($endpoint, '..') || str_contains($endpoint, '//')) {
            return response()->json(['success' => false, 'error' => 'endpoint invalido'], 400);
        }

        $url = self::GOOLHUB_BASE . $endpoint;

        try {
            $http = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $jwtToken,
                    'Origin'        => 'https://hubai.io',
                    'Referer'       => 'https://hubai.io/',
                ]);

            if ($method === 'GET') {
                $response = $http->get($url, $body);
            } elseif ($contentType === 'form') {
                $response = $http->asForm()->post($url, $body);
            } else {
                $response = $http->post($url, $body);
            }

            $status      = $response->status();
            $contentTypeH = $response->header('Content-Type', 'application/json');

            Log::info('[LegacyForward] ' . $method . ' ' . $endpoint . ' -> ' . $status);

            // Retorna JSON se o legado respondeu JSON; senao texto puro
            if (str_contains($contentTypeH, 'application/json')) {
                return response()->json($response->json(), $response->successful() ? 200 : $status);
            }

            // Tenta decodificar JSON mesmo sem header correto (legado inconsistente)
            $decoded = json_decode($response->body(), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return response()->json($decoded, $response->successful() ? 200 : $status);
            }

            return response()->json([
                'success' => false,
                'raw'     => $response->body(),
            ], $status);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('[LegacyForward] Falha de conexao', ['endpoint' => $endpoint, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error'   => 'Servico legado indisponivel. Tente novamente.',
            ], 503);

        } catch (\Throwable $e) {
            Log::error('[LegacyForward] Erro inesperado', ['endpoint' => $endpoint, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error'   => 'Erro ao conectar com servico legado',
            ], 502);
        }
    }
}
