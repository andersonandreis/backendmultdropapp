<?php

namespace App\Services\Integrations\Factories;

use App\Models\MarketplaceAccount;
use App\Services\Integrations\Contracts\MarketplaceInterface;
use App\Services\Integrations\Marketplaces\MercadoLivreService;
use App\Services\Integrations\Marketplaces\ShopeeService;
use App\Services\Integrations\Marketplaces\MagaluService;
use App\Services\Integrations\Marketplaces\TikTokShopService;
use InvalidArgumentException;

class MarketplaceFactory
{
    /**
     * Retorna a implementacao correta da interface baseada na plataforma da conta.
     * Para adicionar novo marketplace: criar Service, registrar aqui.
     */
    public static function make(MarketplaceAccount $account): MarketplaceInterface
    {
        return match ($account->platform) {
            'mercadolivre',
            'mercado_livre',
            'ml' => app(MercadoLivreService::class), // NOV-061: alias ml aceito pelo factory
            'shopee'       => app(ShopeeService::class),
            'magalu'       => app(MagaluService::class),
            'tiktok'       => app(TikTokShopService::class),
            // 'amazon'    => app(AmazonService::class),
            default => throw new InvalidArgumentException("Plataforma nao suportada: {$account->platform}")
        };
    }
}
