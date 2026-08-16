<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * SEL-308 — Persistência de mídia TikTok no storage próprio.
 *
 * Problema: URLs assinadas TikTok CDN (x-expires) expiram em 24-48h -> 403
 * -> img-proxy devolve 502 -> browser bloqueia (ORB).
 *
 * Solucao: baixar a midia server-side no momento do enrich e servir URL
 * estavel `https://api.seller.global/storage/tt-media/<hash>.<ext>`.
 *
 * Estrategias de resolucao (em ordem):
 *   1. Se a URL original ainda e valida -> baixa direto.
 *   2. Se tem video_id/tiktok_url -> tikwm.com/api origin_cover/cover.
 *   3. Para avatares -> tikwm.com/api/user/info pelo @handle.
 *   4. Fallback: unavatar.io (nao baixa, retorna URL externa estavel).
 *
 * Idempotente: mesmo MD5(url_original) -> reaproveira arquivo existente.
 */
class TikTokMediaService
{
    private const TT_CDN_PATTERN = '/(?:tiktokcdn(?:-[a-z]{2})?\.com|ibyteimg\.com|ttwstatic\.com)/i';
    private const ALLOWED_MIMES  = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const EXT_MAP = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const STORAGE_DIR = 'tt-media';

    // -------------------------------------------------------------------------
    // Ponto de entrada principal
    // -------------------------------------------------------------------------

    /**
     * Garante que a URL de midia seja local estavel.
     * Retorna URL do storage proprio, ou URL estavel externa (unavatar),
     * ou null se nenhuma estrategia funcionou.
     *
     * @param  string|null  $url    URL atual salva no banco (pode ser expirada)
     * @param  array        $hints  ['video_id', 'tiktok_url', 'handle'] para re-resolucao
     * @return string|null
     */
    public function ensureLocal(?string $url, array $hints = []): ?string
    {
        // 1. Se ja e URL local do nosso storage - retorna direto
        if ($url && str_contains($url, 'api.seller.global/storage/')) {
            return $url;
        }

        // 2. Se e URL externa nao-TikTok estavel (unavatar, etc) - retorna direto
        if ($url && !$this->isTikTokCdnUrl($url)) {
            return $url;
        }

        // 3. Tenta baixar a URL atual se nao expirou
        if ($url && !$this->isExpiredSignedUrl($url)) {
            $local = $this->downloadAndStore($url);
            if ($local) return $local;
        }

        // 4. URL expirada ou falhou - re-resolve via tikwm
        if (!empty($hints['video_id']) || !empty($hints['tiktok_url'])) {
            $resolved = $this->resolveViaTikwmVideo($hints['video_id'] ?? null, $hints['tiktok_url'] ?? null);
            if ($resolved) {
                $local = $this->downloadAndStore($resolved);
                if ($local) return $local;
            }
        }

        // 5. Avatar por handle -> tikwm user/info
        if (!empty($hints['handle'])) {
            $resolved = $this->resolveViaTikwmUser($hints['handle']);
            if ($resolved) {
                $local = $this->downloadAndStore($resolved);
                if ($local) return $local;
            }
            // Fallback externo estavel: unavatar
            return 'https://unavatar.io/tiktok/' . ltrim($hints['handle'], '@');
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Download e armazenamento
    // -------------------------------------------------------------------------

    /**
     * Baixa URL de midia TikTok e salva em storage/app/public/tt-media/.
     * Retorna URL publica local ou null em caso de falha.
     */
    /**
     * Baixa a midia e guarda no storage proprio.
     *
     * SEL-467: $headersOverride existe porque nem todo CDN aceita o mesmo
     * Referer. O img.kalocdn.com (fotos oficiais do anuncio, via Kalodata)
     * devolve 403 justamente com 'Referer: https://shop.tiktok.com/' — que e o
     * default daqui — e devolve 200 sem Referer nenhum. Passando [] o chamador
     * NAO muda nada pra ninguem: sem override, o comportamento e identico ao
     * de antes.
     *
     * @param  array<string,string>  $headersOverride  headers que substituem os padrao
     */
    public function downloadAndStore(string $url, array $headersOverride = []): ?string
    {
        $hash   = md5($url);
        $disk   = Storage::disk('public');
        $prefix = self::STORAGE_DIR . '/';

        // Idempotencia: arquivo ja existe
        foreach (self::EXT_MAP as $ext) {
            $path = $prefix . $hash . '.' . $ext;
            if ($disk->exists($path)) {
                return $disk->url($path);
            }
        }

        try {
            $resp = Http::timeout(15)
                ->withOptions(['allow_redirects' => ['max' => 3, 'strict' => true, 'protocols' => ['http', 'https']]])
                ->withHeaders($headersOverride ?: [
                    'Referer'    => 'https://shop.tiktok.com/',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36',
                    'Accept'     => 'image/webp,image/apng,image/*,*/*;q=0.8',
                ])
                ->get($url);
        } catch (\Throwable $e) {
            Log::debug('[SEL-308 TikTokMedia] download threw', ['url' => substr($url, 0, 100), 'err' => $e->getMessage()]);
            return null;
        }

        if (!$resp->successful()) {
            Log::debug('[SEL-308 TikTokMedia] download HTTP ' . $resp->status(), ['url' => substr($url, 0, 100)]);
            return null;
        }

        $body = $resp->body();
        $size = strlen($body);

        if ($size < 100 || $size > self::MAX_BYTES) {
            Log::debug('[SEL-308 TikTokMedia] tamanho invalido', ['url' => substr($url, 0, 100), 'bytes' => $size]);
            return null;
        }

        $mime = $this->detectMime($body);
        $ext  = self::EXT_MAP[$mime] ?? null;
        if (!$ext) {
            Log::debug('[SEL-308 TikTokMedia] mime nao suportado', ['url' => substr($url, 0, 100), 'mime' => $mime]);
            return null;
        }

        $path = $prefix . $hash . '.' . $ext;
        $disk->put($path, $body);

        return $disk->url($path);
    }

    // -------------------------------------------------------------------------
    // Re-resolucao via tikwm (server-side)
    // -------------------------------------------------------------------------

    /**
     * Busca cover do video TikTok via tikwm.
     * Aceita video_id numerico OU tiktok_url completa.
     */
    public function resolveViaTikwmVideo(?string $videoId, ?string $videoUrl): ?string
    {
        $url = $videoUrl;
        if (!$url && $videoId) {
            $url = 'https://www.tiktok.com/@placeholder/video/' . $videoId;
        }
        if (!$url) return null;

        try {
            $resp = Http::timeout(12)->asForm()->post('https://tikwm.com/api/', [
                'url' => $url,
                'hd'  => 0,
            ]);
            if (!$resp->successful()) return null;
            $data  = $resp->json('data') ?? [];
            $cover = $data['origin_cover'] ?? ($data['cover'] ?? null);
            return (is_string($cover) && str_starts_with($cover, 'http')) ? $cover : null;
        } catch (\Throwable $e) {
            Log::debug('[SEL-308 TikTokMedia] tikwm video err', ['err' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Busca avatar do usuario TikTok via tikwm user/info.
     */
    public function resolveViaTikwmUser(string $handle): ?string
    {
        $handle = ltrim($handle, '@');
        try {
            $resp = Http::timeout(12)->acceptJson()->get('https://tikwm.com/api/user/info', [
                'unique_id' => '@' . $handle,
            ]);
            if (!$resp->successful()) return null;
            $code = $resp->json('code') ?? null;
            if ($code === -1) return null; // rate limit
            $user   = $resp->json('data.user') ?? [];
            $avatar = $user['avatarLarger'] ?? ($user['avatarMedium'] ?? ($user['avatarThumb'] ?? null));
            return (is_string($avatar) && str_starts_with($avatar, 'http')) ? $avatar : null;
        } catch (\Throwable $e) {
            Log::debug('[SEL-308 TikTokMedia] tikwm user err', ['handle' => $handle, 'err' => $e->getMessage()]);
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Helpers publicos (usados pelo ImageProxyController e pelo command)
    // -------------------------------------------------------------------------

    public function isTikTokCdnUrl(?string $url): bool
    {
        if (!$url) return false;
        $host = parse_url($url, PHP_URL_HOST) ?? '';
        return (bool) preg_match(self::TT_CDN_PATTERN, $host);
    }

    public function isExpiredSignedUrl(?string $url): bool
    {
        if (!$url) return true;
        if (preg_match('/[?&]x-expires=(\d+)/i', $url, $m)) {
            return ((int) $m[1]) < time();
        }
        return false;
    }

    private function detectMime(string $body): string
    {
        if (str_starts_with($body, "\xFF\xD8\xFF")) return 'image/jpeg';
        if (str_starts_with($body, "\x89PNG\r\n\x1A\n")) return 'image/png';
        if (str_starts_with($body, 'GIF87a') || str_starts_with($body, 'GIF89a')) return 'image/gif';
        if (str_starts_with($body, 'RIFF') && substr($body, 8, 4) === 'WEBP') return 'image/webp';
        return 'application/octet-stream';
    }
}
