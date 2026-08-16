<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TikTokMediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * SEL-269 — Proxy de imagens TT Shop / Kalodata / ibyteimg.
 * SEL-308 — Resiliente: cache em disco, re-resolve 403, placeholder, CORS sempre.
 *
 * Muitas imagens do TikTok Shop retornam 400/403 quando acessadas direto
 * do browser sem Referer=https://shop.tiktok.com. URLs Kalodata tem
 * x-signature que expira em 24h — precisa cache local.
 *
 * Fluxo:
 *   1. Cache em disco (storage/app/tt-cache/) -> retorna imagem imediatamente.
 *   2. Fetch upstream com Referer TT.
 *   3. Se 403 e URL TT CDN -> tenta re-resolver via TikTokMediaService (tikwm).
 *   4. Se tudo falhar -> retorna placeholder PNG 1x1 com HTTP 200 (nunca 502).
 *
 * CORS: Access-Control-Allow-Origin: * em TODAS as respostas (HIT/MISS/FAIL).
 *
 * Uso: GET /api/v1/tt/img-proxy?url={encoded}
 */
class ImageProxyController extends Controller
{
    /**
     * SEL-274: allowlist dinamica — qualquer subdominio dos dominios TT conhecidos.
     * Anchor `$` com boundary de ponto mantem o anti-SSRF.
     */
    private const ALLOWED_HOST_PATTERN = '/(?:^|\.)(?:ibyteimg\.com|tiktokcdn(?:-[a-z]{2})?\.com|ttwstatic\.com)$/i';

    private const CACHE_DIR = 'tt-cache';
    private const CACHE_TTL = 86400 * 30; // 30 dias em segundos
    private const EXT_MAP   = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    /** Placeholder PNG 1x1 transparente (base64). Retornado em falha total. */
    private const PLACEHOLDER_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private const CORS_HEADERS = [
        'Access-Control-Allow-Origin'   => '*',
        'Cross-Origin-Resource-Policy'  => 'cross-origin',
        'X-Content-Type-Options'        => 'nosniff',
    ];

    public function __construct(private TikTokMediaService $ttMedia) {}

    public function proxy(Request $request)
    {
        $url = $request->get('url');
        if (!$url) {
            return $this->placeholderResponse('missing url');
        }

        $parsed = parse_url($url);
        $scheme = strtolower($parsed['scheme'] ?? '');
        $host   = $parsed['host'] ?? '';

        if (!in_array($scheme, ['http', 'https'], true) || !preg_match(self::ALLOWED_HOST_PATTERN, $host)) {
            return $this->placeholderResponse('host not allowed');
        }

        // --- 1. Cache em disco ---
        $cacheKey = md5($url);
        $cached   = $this->readDiskCache($cacheKey);
        if ($cached) {
            return response($cached['body'], 200, array_merge(self::CORS_HEADERS, [
                'Content-Type'  => $cached['ct'],
                'Cache-Control' => 'public, max-age=86400',
                'X-Cache'       => 'HIT',
            ]));
        }

        // --- 2. Fetch upstream ---
        [$body, $ct, $status] = $this->fetchUpstream($url);

        // --- 3. Re-resolve se 403 e URL TT CDN ---
        if ($status === 403 && $this->ttMedia->isTikTokCdnUrl($url)) {
            $resolvedUrl = $this->ttMedia->resolveViaTikwmVideo(null, $url);
            if ($resolvedUrl) {
                [$body, $ct, $status] = $this->fetchUpstream($resolvedUrl);
            }
        }

        // --- 4. Placeholder em falha total ---
        if ($body === null || $status >= 400) {
            Log::debug('[SEL-308 img-proxy] fallback placeholder', ['url' => substr($url, 0, 100), 'status' => $status]);
            return $this->placeholderResponse();
        }

        // Grava cache em disco
        $this->writeDiskCache($cacheKey, $body, $ct);

        return response($body, 200, array_merge(self::CORS_HEADERS, [
            'Content-Type'  => $ct,
            'Cache-Control' => 'public, max-age=86400',
            'X-Cache'       => 'MISS',
        ]));
    }

    // -------------------------------------------------------------------------
    // Helpers internos
    // -------------------------------------------------------------------------

    /** Retorna [body, content-type, status] ou [null, null, status] em falha. */
    private function fetchUpstream(string $url): array
    {
        try {
            $r = Http::timeout(8)
                ->withHeaders([
                    'Referer'    => 'https://shop.tiktok.com/',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36',
                    'Accept'     => 'image/webp,image/apng,image/*,*/*;q=0.8',
                ])
                ->get($url);
        } catch (\Throwable $e) {
            return [null, null, 0];
        }

        if (!$r->successful()) {
            return [null, null, $r->status()];
        }

        $ct   = $r->header('Content-Type', 'image/webp');
        $body = $r->body();

        return [$body, $ct, $r->status()];
    }

    private function placeholderResponse(string $reason = 'upstream fail'): \Illuminate\Http\Response
    {
        return response(base64_decode(self::PLACEHOLDER_PNG), 200, array_merge(self::CORS_HEADERS, [
            'Content-Type'  => 'image/png',
            'Cache-Control' => 'no-store',
            'X-Proxy-Fail'  => $reason,
        ]));
    }

    private function readDiskCache(string $key): ?array
    {
        $base = storage_path('app/' . self::CACHE_DIR . '/' . $key);
        foreach (self::EXT_MAP as $mime => $ext) {
            $path = $base . '.' . $ext;
            if (file_exists($path) && (time() - filemtime($path)) < self::CACHE_TTL) {
                return ['body' => file_get_contents($path), 'ct' => $mime];
            }
        }
        return null;
    }

    private function writeDiskCache(string $key, string $body, string $ct): void
    {
        $dir = storage_path('app/' . self::CACHE_DIR);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $ext  = self::EXT_MAP[$ct] ?? 'bin';
        $path = $dir . '/' . $key . '.' . $ext;
        @file_put_contents($path, $body);
    }
}
