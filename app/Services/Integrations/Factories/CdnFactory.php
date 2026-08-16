<?php

namespace App\Services\Integrations\Factories;

use App\Services\Integrations\Contracts\CdnInterface;
use App\Services\Integrations\Cdn\BunnyCdnService;
use App\Services\Integrations\Cdn\CloudflareService;
use App\Services\Integrations\Cdn\LocalStorageService;
use InvalidArgumentException;

class CdnFactory
{
    /**
     * Retorna a implementacao do driver de CDN desejado.
     *
     * @param string|null $driver Se null, le de config/cdn.driver (default: 'local')
     */
    public static function make(?string $driver = null): CdnInterface
    {
        $driver = $driver ?: config('cdn.driver', 'local');

        return match ($driver) {
            'bunny'      => app(BunnyCdnService::class),
            'cloudflare' => app(CloudflareService::class),
            'local'      => app(LocalStorageService::class),
            default      => throw new InvalidArgumentException("CDN driver nao suportado: {$driver}"),
        };
    }
}
