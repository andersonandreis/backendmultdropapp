<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V2\AuthController;
use App\Http\Controllers\Api\V2\LegacyProxyController;

/*
|--------------------------------------------------------------------------
| API V2 Routes — HUB-080
|--------------------------------------------------------------------------
|
| Autenticacao nova (Sanctum) + proxy para o legado goolhub.io.
| Prefixo /api/v2 (definido pelo bootstrap/app.php que ja monta /api).
|
| Rotas publicas:  POST /api/v2/auth/login
|                  POST /api/v2/auth/master-login
|
| Rotas protegidas (Bearer token Sanctum):
|                  GET  /api/v2/auth/me
|                  POST /api/v2/auth/logout
|                  POST /api/v2/proxy
|
*/

Route::prefix('v2')->group(function () {

    // =========================================================================
    // Rotas publicas de autenticacao (sem middleware)
    // =========================================================================
    Route::post('/auth/login',        [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/auth/master-login', [AuthController::class, 'masterLogin'])->middleware('throttle:5,1');

    // =========================================================================
    // Rotas protegidas por Sanctum
    // =========================================================================
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me',     [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/proxy',       [LegacyProxyController::class, 'proxy']);
    });
});
