<?php

namespace App\Services\Marketplace\Reconciliation;

use InvalidArgumentException;

/**
 * Factory que resolve o adapter correto pelo slug do marketplace.
 *
 * Uso:
 *   $adapter = MarketplaceReconciliationFactory::forMarketplace('ml');
 *   $orders  = $adapter->fetchRecentOrders($account, now()->subHours(25));
 *
 * Slugs suportados: 'shopee', 'ml', 'mercadolivre' (alias), 'bling'
 */
class MarketplaceReconciliationFactory
{
    private const MAP = [
        'shopee'        => ShopeeReconciliationAdapter::class,
        'ml'            => MercadoLivreReconciliationAdapter::class,
        'mercadolivre'  => MercadoLivreReconciliationAdapter::class,
        'mercado_livre' => MercadoLivreReconciliationAdapter::class,
        'bling'         => BlingReconciliationAdapter::class,
    ];

    /**
     * @throws InvalidArgumentException Se o slug nao for suportado
     */
    public static function forMarketplace(string $slug): ReconciliationAdapter
    {
        $slug = strtolower(trim($slug));

        if (! isset(self::MAP[$slug])) {
            throw new InvalidArgumentException(
                "Marketplace nao suportado na reconciliacao: '{$slug}'. "
                . "Suportados: " . implode(', ', self::supportedMarketplaces())
            );
        }

        return app(self::MAP[$slug]);
    }

    /** Slugs canonicos (sem aliases) */
    public static function supportedMarketplaces(): array
    {
        return ['shopee', 'ml', 'bling'];
    }
}
