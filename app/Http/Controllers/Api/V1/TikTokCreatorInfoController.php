<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TikTokMediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SEL-308 — Proxy server-side de informacoes de criador TikTok.
 *
 * Frontend chama tikwm.com/api/user/info DIRETO do browser (100 fetches/pageview,
 * 100% bloqueados por CORS). Este endpoint centraliza a consulta server-side,
 * cacheia 6h e devolve JSON limpo ao frontend.
 *
 * GET /api/v1/tt/creator-info?unique_id=@drpimenta
 *
 * Resposta:
 *   { handle, nickname, avatar_local, followers, videos, verified, cached_at }
 *
 * avatar_local: URL do nosso storage (api.seller.global/storage/tt-media/...).
 * Se download falhar -> URL do tikwm ou unavatar.io como fallback.
 */
class TikTokCreatorInfoController extends Controller
{
    private const CACHE_TTL   = 6 * 3600; // 6 horas em segundos
    private const TIKWM_URL   = 'https://tikwm.com/api/user/info';
    private const CORS_HEADERS = [
        'Access-Control-Allow-Origin'  => '*',
        'Cross-Origin-Resource-Policy' => 'cross-origin',
    ];

    public function __construct(private TikTokMediaService $ttMedia) {}

    /**
     * GET /api/v1/tt/creator-info
     *
     * @param  unique_id  string  Handle TikTok (@drpimenta ou drpimenta)
     */
    public function show(Request $request)
    {
        $uniqueId = trim($request->get('unique_id', ''));
        if (!$uniqueId) {
            return response()->json(['error' => 'unique_id obrigatorio'], 422, self::CORS_HEADERS);
        }

        $handle  = ltrim($uniqueId, '@');
        $cacheKey = 'tt_creator_info:' . md5($handle);

        // Cache HIT
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return response()->json(array_merge($cached, ['from_cache' => true]), 200, self::CORS_HEADERS);
        }

        // Busca tikwm
        $info = $this->fetchTikwmUser($handle);

        if (!$info) {
            return response()->json([
                'error'       => 'criador nao encontrado',
                'handle'      => $handle,
                'avatar_local' => 'https://unavatar.io/tiktok/' . $handle,
            ], 404, self::CORS_HEADERS);
        }

        // Persiste avatar local
        $avatarRemote = $info['avatar'];
        $avatarLocal  = $avatarRemote
            ? ($this->ttMedia->downloadAndStore($avatarRemote) ?? $avatarRemote)
            : ('https://unavatar.io/tiktok/' . $handle);

        $payload = [
            'handle'       => $info['handle'],
            'nickname'     => $info['nickname'],
            'avatar_local' => $avatarLocal,
            'avatar_remote' => $avatarRemote,
            'followers'    => $info['followers'],
            'videos'       => $info['videos'],
            'verified'     => $info['verified'],
            'cached_at'    => now()->toIso8601String(),
        ];

        Cache::put($cacheKey, $payload, self::CACHE_TTL);

        return response()->json(array_merge($payload, ['from_cache' => false]), 200, self::CORS_HEADERS);
    }

    // -------------------------------------------------------------------------

    private function fetchTikwmUser(string $handle): ?array
    {
        try {
            $resp = Http::timeout(12)->acceptJson()->get(self::TIKWM_URL, [
                'unique_id' => '@' . $handle,
            ]);
            if (!$resp->ok()) return null;
            $body = $resp->json();
            $code = $body['code'] ?? null;
            if ($code === -1) {
                Log::warning('[SEL-308 creator-info] tikwm rate limit', ['handle' => $handle]);
                return null;
            }
            $user  = $body['data']['user']  ?? null;
            $stats = $body['data']['stats'] ?? [];
            if (!is_array($user) || empty($user['uniqueId'])) return null;
            return [
                'handle'    => $user['uniqueId'],
                'nickname'  => $user['nickname'] ?? $handle,
                'avatar'    => $user['avatarLarger'] ?? ($user['avatarMedium'] ?? ($user['avatarThumb'] ?? null)),
                'followers' => (int) ($stats['followerCount'] ?? 0),
                'videos'    => (int) ($stats['videoCount'] ?? 0),
                'verified'  => (bool) ($user['verified'] ?? false),
            ];
        } catch (\Throwable $e) {
            Log::warning('[SEL-308 creator-info] tikwm exception', ['handle' => $handle, 'err' => $e->getMessage()]);
            return null;
        }
    }
}
