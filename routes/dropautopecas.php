<?php
/**
 * Rotas da API Drop Autopeças — compatível com contrato Goolhub (api.hubai.io/dropautopecas/v1)
 *
 * Autenticação: Bearer ht_live_XXXXX.YYYYY (mesmo token do tenant API genérico)
 *
 * Endpoints:
 *   GET    /api/v1/orders                       — listar pedidos (page)
 *   GET    /api/v1/orders/{pedido}              — detalhe do pedido
 *   PUT    /api/v1/orders/{pedido}              — atualizar status (ABERTO/ENVIADO/...)
 *   GET    /api/v1/label/{pedido}               — etiqueta (marca como impressa)
 *   GET    /api/v1/invoice/entrada/{pedido}     — NF entrada
 *   PUT    /api/v1/invoice/entrada/{pedido}     — atualizar NF
 *   GET    /api/v1/invoice/saida/{pedido}       — NF saída
 *   GET    /api/v1/sku                          — listar produtos (page)
 *   GET    /api/v1/sku/{skuName}               — detalhe produto
 *   POST   /api/v1/sku                          — criar produto
 *   PUT    /api/v1/sku/{skuName}               — editar produto
 *   DELETE /api/v1/sku/{skuName}               — remover produto
 *   GET    /api/v1/stock/{skuName}             — consultar estoque
 *   PUT    /api/v1/stock/{skuName}             — atualizar estoque
 *   GET    /api/v1/price/{skuName}             — consultar preço
 *   PUT    /api/v1/price/{skuName}             — atualizar preço
 */

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DropAuto\V1\OrderController;
use App\Http\Controllers\DropAuto\V1\LabelController;
use App\Http\Controllers\DropAuto\V1\InvoiceController;
use App\Http\Controllers\DropAuto\V1\SkuController;
use App\Http\Controllers\DropAuto\V1\StockController;
use App\Http\Controllers\DropAuto\V1\PriceController;

Route::prefix('dropautopecas/v1')->middleware('dropautopecas.api')->group(function () {

    // Pedidos
    Route::get('/orders',           [OrderController::class, 'index']);
    Route::get('/orders/{pedido}',  [OrderController::class, 'show']);
    Route::put('/orders/{pedido}',  [OrderController::class, 'update']);

    // Etiqueta
    Route::get('/label/{pedido}',   [LabelController::class, 'show']);

    // Nota Fiscal
    Route::get('/invoice/entrada/{pedido}', [InvoiceController::class, 'entrada']);
    Route::put('/invoice/entrada/{pedido}', [InvoiceController::class, 'updateEntrada']);
    Route::get('/invoice/saida/{pedido}',   [InvoiceController::class, 'saida']);

    // Produtos (SKU)
    Route::get('/sku',              [SkuController::class, 'index']);
    Route::post('/sku',             [SkuController::class, 'store']);
    Route::get('/sku/{skuName}',    [SkuController::class, 'show']);
    Route::put('/sku/{skuName}',    [SkuController::class, 'update']);
    Route::delete('/sku/{skuName}', [SkuController::class, 'destroy']);

    // Estoque
    Route::get('/stock/{skuName}',  [StockController::class, 'show']);
    Route::put('/stock/{skuName}',  [StockController::class, 'update']);

    // Preço
    Route::get('/price/{skuName}',  [PriceController::class, 'show']);
    Route::put('/price/{skuName}',  [PriceController::class, 'update']);
});
