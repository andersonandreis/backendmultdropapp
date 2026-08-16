<?php

/**
 * SEL-198: Rotas publicas de sellers (lojistas) do TikTok Shop BR.
 *
 * INCLUSAO em routes/api.php:
 *   require __DIR__ . '/tiktok-sellers.php';
 *
 * Ou via RouteServiceProvider:
 *   Route::middleware('api')->prefix('api')->group(base_path('routes/tiktok-sellers.php'));
 *
 * Protecoes:
 *  - auth:sanctum obrigatorio (nao queremos dados publicos sem login)
 *  - RestrictFreeAccess ja cobre 'api/v1/tiktok*': free ve ate 30 por pagina
 *
 * GET /api/v1/tiktok/sellers
 *   Filtros: ?category=Eletronicos&min_rating=4.5&order_by=gmv|rating|followers
 *   Response: paginated { data: [seller...], total, per_page, ... }
 *
 * GET /api/v1/tiktok/sellers/{handle}/catalog
 *   Response: { seller: {...}, products: [...] }
 */

use App\Http\Controllers\Api\V1\TiktokShopSellersController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])
    ->prefix('v1/tiktok')
    ->group(function () {
        // SEL-198: listagem paginada de sellers com filtros
        Route::get('sellers', [TiktokShopSellersController::class, 'index'])
             ->name('tiktok.sellers.index');

        // SEL-198: catalogo de produtos de um seller
        Route::get('sellers/{handle}/catalog', [TiktokShopSellersController::class, 'catalog'])
             ->where('handle', '[a-zA-Z0-9_@.-]+')
             ->name('tiktok.sellers.catalog');
    });
