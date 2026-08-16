<?php

namespace App\Services\Integrations\Factories;

use App\Services\Integrations\Contracts\ErpInterface;
use App\Services\Integrations\Erps\BlingService;
use InvalidArgumentException;

class ErpFactory
{
    /**
     * Retorna a implementacao correta do ERP baseada na plataforma.
     */
    public static function make(string $platform): ErpInterface
    {
        return match ($platform) {
            'bling' => app(BlingService::class),
            default => throw new InvalidArgumentException("ERP nao suportado: {$platform}"),
        };
    }
}
