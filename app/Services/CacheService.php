<?php

namespace App\Services;

use App\Services\Integrations\Cdn\BunnyCdnService;
use App\Services\Integrations\Cdn\CloudflareService;
use Illuminate\Support\Facades\Cache;

class CacheService
{
    /**
     * Limpa o cache local (Redis/File) por tag e também invalida na CDN configurada
     */
    public function purge(array|string $tags, string $urlToPurgeCdn = null): void
    {
        // 1. Purge Local Laravel Cache Tags
        Cache::tags($tags)->flush();

        // 2. Purge OpenLiteSpeed cache (via command line ou HTTP purge folder)
        // Isso normalmente é feito enviando um HTTP PURGE ou deletando a pasta lscache
        // @exec("rm -rf /usr/local/lsws/cachedata/..." ) -> opcional

        // 3. Purge CDNs se URL for fornecida
        if ($urlToPurgeCdn) {
            if (config('services.cloudflare.api_token')) {
                app(CloudflareService::class)->purge($urlToPurgeCdn);
            }

            if (config('services.bunnycdn.access_key')) {
                app(BunnyCdnService::class)->purge($urlToPurgeCdn);
            }
        }
    }

    /**
     * Lembra valor em cache tagged
     */
    public function rememberTag(array $tags, string $key, \Closure $callback, int $ttl = 3600)
    {
        return Cache::tags($tags)->remember($key, $ttl, $callback);
    }
}
