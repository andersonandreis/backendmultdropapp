<?php

namespace App\Services\Ai;

use App\Models\VideoEngine;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SEL-425 — Adapter DICloak → Playwright CDP.
 *
 * Fluxo:
 *   1. Chama a API DICloak na VM (via túnel Cloudflare INF-072) pedindo
 *      para abrir o perfil Chrome identificado por $profileId.
 *   2. DICloak devolve { wsEndpoint: "ws://127.0.0.1:9222" } — endpoint CDP.
 *   3. O Worker Node (veo_generate_cdp.js) usa chromium.connectOverCDP(wsEndpoint)
 *      no lugar de chromium.launch(), aproveitando a sessão Google já logada
 *      no perfil DICloak.
 *   4. Ao terminar (sucesso ou falha), chama closeProfile() para liberar o perfil.
 *
 * SEGURANÇA:
 *   - Lock Redis por perfil (TTL=15min) para garantir que 2 jobs nunca colidam
 *     no mesmo perfil simultâneo (a amarração client↔job é garantida pelo lock).
 *   - URL do túnel vem de DICLOAK_TUNNEL_URL no .env (nunca hardcoded).
 *   - Timeout de 30s no HTTP para não travar o job principal.
 *
 * STUB (INF-072 pendente):
 *   - Se DICLOAK_TUNNEL_URL não está configurado, este adapter lança
 *     DicloakNotConfiguredException → VideoEnginePool cai no próximo motor.
 *   - Quando INF-072 fechar: setar DICLOAK_TUNNEL_URL=https://dicloak-vm.hubai.club
 *     e ativar o engine DICloak-VEO3-01 na tabela video_engines (is_active=true).
 */
class DicloakVeoAdapter
{
    private string $tunnelUrl;

    public function __construct()
    {
        $url = config('services.dicloak.tunnel_url')
            ?? env('DICLOAK_TUNNEL_URL')
            ?? '';

        if (empty(trim($url))) {
            throw new DicloakNotConfiguredException(
                'DICLOAK_TUNNEL_URL não configurado — INF-072 pendente. Motor pulado.'
            );
        }

        $this->tunnelUrl = rtrim($url, '/');
    }

    /**
     * Abre um perfil no DICloak e retorna o wsEndpoint CDP.
     *
     * @param  string $profileId  ID do perfil DICloak (campo profile_id em config_json)
     * @return string             ex: "ws://127.0.0.1:9222"
     * @throws \RuntimeException  se DICloak não devolver wsEndpoint
     */
    public function openProfile(string $profileId): string
    {
        Log::info('[SEL-425][DICloak] abrindo perfil', ['profile_id' => $profileId]);

        $resp = Http::timeout(30)
            ->post("{$this->tunnelUrl}/dicloak-api/profile/{$profileId}/open");

        if (!$resp->successful()) {
            throw new \RuntimeException(
                "DICloak openProfile HTTP {$resp->status()}: " . mb_substr($resp->body(), 0, 200)
            );
        }

        $data = $resp->json();
        $ws   = $data['wsEndpoint'] ?? $data['ws_endpoint'] ?? $data['cdp'] ?? null;

        if (empty($ws)) {
            throw new \RuntimeException(
                'DICloak não retornou wsEndpoint. Resposta: ' . mb_substr($resp->body(), 0, 300)
            );
        }

        Log::info('[SEL-425][DICloak] perfil aberto', ['profile_id' => $profileId, 'ws' => $ws]);
        return $ws;
    }

    /**
     * Fecha/libera o perfil após a geração (sucesso ou falha).
     * Fail-silent: erro no close não deve derrubar o job já concluído.
     */
    public function closeProfile(string $profileId): void
    {
        try {
            Http::timeout(15)
                ->post("{$this->tunnelUrl}/dicloak-api/profile/{$profileId}/close");
            Log::info('[SEL-425][DICloak] perfil fechado', ['profile_id' => $profileId]);
        } catch (\Throwable $e) {
            Log::warning('[SEL-425][DICloak] falha ao fechar perfil (ignorada)', [
                'profile_id' => $profileId,
                'err'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Lista perfis disponíveis com prefixo "VEO3" no DICloak.
     * Usado pelo admin para sincronizar a tabela video_engines.
     *
     * @return array<array{id: string, name: string, status: string}>
     */
    public function listVeo3Profiles(): array
    {
        $resp = Http::timeout(30)
            ->get("{$this->tunnelUrl}/dicloak-api/profiles");

        if (!$resp->successful()) {
            throw new \RuntimeException(
                "DICloak listProfiles HTTP {$resp->status()}: " . mb_substr($resp->body(), 0, 200)
            );
        }

        $all = $resp->json('profiles') ?? $resp->json() ?? [];
        return array_values(array_filter($all, fn($p) => str_starts_with(
            strtoupper($p['name'] ?? ''), 'VEO3'
        )));
    }
}
