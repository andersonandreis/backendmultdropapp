<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * HUB-080 Fase 1 - Auth V2
 *
 * Endpoints de autenticacao para o frontend hubai.io migrar do legado goolhub
 * para api.hubai.io. Usa Sanctum (Bearer token) + senha mestra de suporte.
 *
 * Mudancas Fase 1 (2026-06-24):
 * - Retorna `legacy_session_id` (era `legacy_session`) -- alinha com hubApiV2.ts
 * - Retorna `client.service` -- necessario para identificar whitelabel no frontend
 * - verifyPassword: suporte a plaintext com lazy migration para bcrypt
 * - GET /api/v2/auth/me: retorna plano ativo + legacy_session_id via metadata do token
 */
class AuthController extends Controller
{
    private const MASTER_PASSWORD = 'caravelas';

    /**
     * POST /api/v2/auth/login
     */
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $email    = $request->input('email');
        $password = $request->input('password');

        $user = User::where('email', $email)->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['Credenciais invalidas.'],
            ]);
        }

        if (! $this->verifyPassword($user, $password)) {
            throw ValidationException::withMessages([
                'email' => ['Credenciais invalidas.'],
            ]);
        }

        // MUL-312: regra de acesso unica em User::motivoSemAcesso().
        if ($motivo = $user->motivoSemAcesso()) {
            return response()->json(['message' => $motivo], 403);
        }

        $client = $user->client;

        // Obtem JWT do legado para interoperabilidade com gohub-proxy durante transicao
        $legacySessionId = $this->fetchLegacySession($email, $password);

        // Cria token Sanctum e injeta legacy_session_id como metadata no nome do token
        // para que GET /me possa devolver sem nova chamada ao legado
        $tokenName = $legacySessionId
            ? 'hubai-v2|ls=' . base64_encode(substr($legacySessionId, 0, 200))
            : 'hubai-v2';

        $token = $user->createToken($tokenName)->plainTextToken;

        // Plano ativo do cliente
        $plan = $this->getActivePlan($client);

        return response()->json([
            'token'             => $token,
            'token_type'        => 'Bearer',
            'legacy_session_id' => $legacySessionId,
            'user'              => [
                'id'    => $user->id,
                'email' => $user->email,
                'name'  => $user->name,
                'role'  => $user->role,
            ],
            'client' => $client ? [
                'id'              => $client->id,
                'service'         => $client->service ?? 'hubai',
                'company_name'    => $client->company_name,
                'legacy_id_login' => $client->legacy_id_login,
            ] : null,
            'plan' => $plan,
        ]);
    }

    /**
     * GET /api/v2/auth/me
     *
     * Retorna dados do usuario autenticado via token Sanctum.
     * legacy_session_id e recuperado do nome do token (injetado no login)
     * para evitar nova chamada HTTP ao legado a cada /me.
     */
    public function me(Request $request)
    {
        $user   = $request->user();
        $client = $user->client;

        // Recupera legacy_session_id do nome do token sem nova chamada ao legado
        $legacySessionId = $this->extractLegacySessionFromToken($request);

        // Plano ativo do cliente
        $plan = $this->getActivePlan($client);

        return response()->json([
            'legacy_session_id' => $legacySessionId,
            'user'              => [
                'id'    => $user->id,
                'email' => $user->email,
                'name'  => $user->name,
                'role'  => $user->role,
            ],
            'client' => $client ? [
                'id'              => $client->id,
                'service'         => $client->service ?? 'hubai',
                'company_name'    => $client->company_name,
                'legacy_id_login' => $client->legacy_id_login,
            ] : null,
            'plan' => $plan,
        ]);
    }

    /**
     * POST /api/v2/auth/logout
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Token revogado com sucesso.']);
    }

    /**
     * POST /api/v2/auth/master-login
     */
    public function masterLogin(Request $request)
    {
        $request->validate([
            'email'           => 'required|email',
            'master_password' => 'required|string',
        ]);

        if ($request->input('master_password') !== self::MASTER_PASSWORD) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $user = User::where('email', $request->input('email'))->first();

        if (! $user) {
            return response()->json(['message' => 'Usuario nao encontrado.'], 404);
        }

        $client = $user->client;
        $token  = $user->createToken('hubai-v2-master')->plainTextToken;
        $plan   = $this->getActivePlan($client);

        return response()->json([
            'token'             => $token,
            'token_type'        => 'Bearer',
            'legacy_session_id' => null,
            'user'              => [
                'id'    => $user->id,
                'email' => $user->email,
                'name'  => $user->name,
                'role'  => $user->role,
            ],
            'client' => $client ? [
                'id'              => $client->id,
                'service'         => $client->service ?? 'hubai',
                'company_name'    => $client->company_name,
                'legacy_id_login' => $client->legacy_id_login,
            ] : null,
            'plan' => $plan,
        ]);
    }

    // =========================================================================
    // Helpers privados
    // =========================================================================

    /**
     * Verifica a senha do usuario suportando multiplos formatos do legado:
     *
     * 1. Senha mestra de suporte ('caravelas') -- permite qualquer conta
     * 2. bcrypt (Laravel Hash) -- formato padrao para contas novas ou migradas
     * 3. SHA-256 hex (64 chars) -- migra automaticamente para bcrypt ao autenticar
     * 4. Plaintext -- migra automaticamente para bcrypt ao autenticar (lazy migration)
     *
     * Estrategia lazy migration: senhas antigas sao rehashadas para bcrypt na
     * primeira autenticacao bem-sucedida. Nunca quebra o login -- falhas de
     * rehash sao logadas e ignoradas.
     */
    private function verifyPassword(User $user, string $password): bool
    {
        // 1. Senha mestra -- acesso de suporte
        if ($password === self::MASTER_PASSWORD) {
            return true;
        }

        // 2. bcrypt -- formato padrao Laravel
        // INF-029: Hash::check lanca RuntimeException quando hash nao e bcrypt (ex: MD5 legado).
        // Capturamos silenciosamente e deixamos os fallbacks (SHA-256, plaintext) tentarem.
        try {
            if (!empty($user->password) && Hash::check($password, $user->password)) {
                return true;
            }
        } catch (RuntimeException) {
            Log::info("[AuthV2] Hash nao-bcrypt detectado, tentando fallbacks", ["user_id" => $user->id]);
        }

        $client = $user->client;

        // 3. SHA-256 hex -- formato legado de alguns usuarios migrados
        $sha256Hash = $client->legacy_sha256_hash ?? null;
        if ($sha256Hash && hash_equals($sha256Hash, hash('sha256', $password))) {
            $this->rehashPassword($user, $password, 'sha256');
            return true;
        }

        // 4. Plaintext -- a maioria dos usuarios do legado (~22.439 usuarios)
        // Verifica via legado apenas quando:
        //   - user.password esta vazio (conta sem bcrypt ainda) E
        //   - legacy_password_type nao e 'plaintext_migrated' (ja migrado anteriormente)
        //
        // Se o legacy_password_type ja e 'plaintext_migrated', a senha bcrypt deve
        // ter sido salva -- se Hash::check falhou no passo 2, a senha esta errada.
        $legacyPasswordType = $client->legacy_password_type ?? null;
        $hasLegacyId = !empty($client->legacy_id_login);

        if ($legacyPasswordType !== 'plaintext_migrated' && (empty($user->password) || $hasLegacyId)) {
            $legacyOk = $this->verifyViaLegacy($user->email, $password);
            if ($legacyOk) {
                $this->rehashPassword($user, $password, 'plaintext');
                return true;
            }
        }

        return false;
    }

    /**
     * Rehash de senha legada para bcrypt.
     * Falhas sao logadas mas nunca propagam excecao para nao quebrar o login.
     */
    private function rehashPassword(User $user, string $password, string $sourceType): void
    {
        try {
            $user->password = Hash::make($password);
            $user->saveQuietly();

            if ($user->client) {
                $user->client->legacy_password_type = 'plaintext_migrated';
                $user->client->saveQuietly();
            }

            Log::info('[AuthV2] Senha migrada para bcrypt', [
                'user_id'     => $user->id,
                'source_type' => $sourceType,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[AuthV2] Falha ao rehashear senha', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Verifica credenciais diretamente no legado.
     * Usado para contas sem bcrypt ainda (plaintext original do legado).
     * Retorna true se o legado autenticar com sucesso.
     */
    private function verifyViaLegacy(string $email, string $password): bool
    {
        try {
            $response = Http::timeout(8)
                ->withHeaders([
                    'Origin'  => 'https://hubai.io',
                    'Referer' => 'https://hubai.io/',
                ])
                ->withOptions(['allow_redirects' => false])
                ->asJson()
                ->post('https://goolhub.io/api/app2/auth/auth_login.php', [
                    'email' => $email,
                    'senha' => $password,
                ]);

            $body = $response->json();
            return ! empty($body['success']) && ! empty($body['token']);
        } catch (\Throwable $e) {
            Log::warning('[AuthV2] Falha ao verificar via legado', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Obtém JWT do legado para interoperabilidade com gohub-proxy durante transicao.
     *
     * O endpoint auth_login.php aceita { email, senha } e retorna
     * { success: true, token: "<JWT>", ... }.
     * Esse JWT e enviado como Authorization: Bearer nos endpoints app2/auth
     * pelo LegacyProxyController (POST /api/v2/proxy).
     *
     * Retorna null se: senha mestra, credenciais invalidas no legado, ou timeout.
     */
    private function fetchLegacySession(string $email, string $password): ?string
    {
        if ($password === self::MASTER_PASSWORD) {
            return null;
        }

        try {
            $response = Http::timeout(8)
                ->withHeaders([
                    'Origin'  => 'https://hubai.io',
                    'Referer' => 'https://hubai.io/',
                ])
                ->withOptions(['allow_redirects' => false])
                ->asJson()
                ->post('https://goolhub.io/api/app2/auth/auth_login.php', [
                    'email' => $email,
                    'senha' => $password,
                ]);

            $body = $response->json();

            if (! empty($body['success']) && ! empty($body['token'])) {
                return $body['token'];
            }

            Log::info('[AuthV2] Legado nao autenticou usuario', [
                'email'  => $email,
                'status' => $response->status(),
                'error'  => $body['error'] ?? 'sem campo error',
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::warning('[AuthV2] Falha ao obter sessao legada', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Extrai legacy_session_id do nome do token Sanctum.
     * No login, o token e criado com nome 'hubai-v2|ls=<base64>' para
     * evitar nova chamada HTTP ao legado em cada requisicao /me.
     */
    private function extractLegacySessionFromToken(Request $request): ?string
    {
        try {
            $tokenName = $request->user()->currentAccessToken()?->name ?? '';

            if (str_contains($tokenName, '|ls=')) {
                $encoded = substr($tokenName, strpos($tokenName, '|ls=') + 4);
                $decoded = base64_decode($encoded, true);
                return $decoded ?: null;
            }
        } catch (\Throwable) {
            // Ignora -- token sem legacy session e normal (master-login, por exemplo)
        }

        return null;
    }

    /**
     * Retorna dados do plano ativo do cliente para inclusao no response.
     * Retorna null se o cliente nao tem plano ativo.
     */
    private function getActivePlan(?\App\Models\Client $client): ?array
    {
        if (! $client) {
            return null;
        }

        try {
            $subscription = $client->subscriptions()
                ->whereIn('status', ['active', 'trialing'])
                ->with('plan')
                ->latest()
                ->first();

            if (! $subscription || ! $subscription->plan) {
                return null;
            }

            $plan = $subscription->plan;

            return [
                'id'                          => $plan->id,
                'name'                        => $plan->name,
                'slug'                        => $plan->slug,
                'max_skus'                    => $plan->max_skus,
                'max_marketplace_connections' => $plan->max_marketplace_connections,
                'subscription_status'         => $subscription->status,
                'current_period_end'          => $subscription->current_period_end?->toIso8601String(),
            ];
        } catch (\Throwable $e) {
            Log::warning('[AuthV2] Falha ao buscar plano do cliente', [
                'client_id' => $client->id,
                'error'     => $e->getMessage(),
            ]);
            return null;
        }
    }
}
