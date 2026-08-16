<?php

use App\Http\Controllers\Api\Federation\FederationCatalogController;
use App\Http\Controllers\Api\Federation\FederationCatalogReceiveController;
use App\Http\Controllers\Api\Federation\FederationOrderController;
use App\Http\Controllers\Api\Federation\FederationKitReceiveController;
use App\Http\Controllers\Api\Federation\FederationOrderReceiveController;
use App\Http\Controllers\Api\V1\KitController;
use App\Http\Controllers\Api\V1\OrderSwapProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Federation API Routes -- NOV-171-B + NOV-171-C
|--------------------------------------------------------------------------
|
| Rotas do HUB (api.hubai.io) -- auth.federation (Bearer token por WL):
|   POST   /api/federation/catalog/push          WL->Hub produto
|   GET    /api/federation/catalog/pull/{sup}    Hub->WL delta pull
|   GET    /api/federation/orders/delta          Hub->WL delta pedidos
|   GET    /api/federation/orders/delta-supplier  WL puxa pedidos do seu supplier
|   POST   /api/federation/orders/redispatch      WL pede reenvio (fanout) de pedidos
|   POST   /api/federation/orders/{id}/status    WL->Hub status pedido
|
| Rotas dos WLs (multdrop, fornecefy, mestoredrop) -- verify.federation.hmac:
|   POST   /api/federation/catalog/receive       Hub->WL produto push
|   POST   /api/federation/orders/receive        Hub->WL notificacao pedido
|
*/

// -------------------------------------------------------------------------
// Rotas do HUB (WL -> Hub): auth.federation Bearer
// -------------------------------------------------------------------------
Route::prefix('api/federation')->middleware(['api', 'auth.federation'])->group(function () {

    Route::post('catalog/push', [FederationCatalogController::class, 'pushFromWl'])
        ->name('federation.catalog.push');

    Route::get('catalog/pull/{supplier_id}', [FederationCatalogController::class, 'pullDelta'])
        ->name('federation.catalog.pull')
        ->whereNumber('supplier_id');

    Route::get('orders/delta', [FederationOrderController::class, 'delta'])
        ->name('federation.orders.delta');

    Route::get('orders/delta-supplier', [FederationOrderController::class, 'deltaSupplier'])
        ->name('federation.orders.delta_supplier');

    Route::post('orders/redispatch', [FederationOrderController::class, 'redispatchToTenant'])
        ->name('federation.orders.redispatch');

    // INF-054 R1: WL encaminha writes de pedido pro hub (Rodada 1)
    Route::patch('orders/{id}/notes', [\App\Http\Controllers\Api\V1\OrderController::class, 'updateNotesFromFederation'])
        ->whereNumber('id');
    Route::patch('orders/{id}/expedition-note', [\App\Http\Controllers\Api\V1\OrderController::class, 'updateExpeditionNoteFromFederation'])
        ->whereNumber('id');
    Route::post('orders/{id}/expedition-note/read', [\App\Http\Controllers\Api\V1\OrderController::class, 'markExpeditionNoteReadFromFederation'])
        ->whereNumber('id');
    Route::post('orders/mark-labels-printed', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'markLabelsPrintedFromFederation']);
    // INF-054 R4 Fase 1: Categoria Z endpoints seguros (sem risco credencial)
    Route::post('orders/{id}/pay', [\App\Http\Controllers\Api\V1\OrderController::class, 'payFromFederation'])->whereNumber('id');
    Route::post('orders/{id}/label', [\App\Http\Controllers\Api\V1\OrderController::class, 'generateLabelFromFederation'])->whereNumber('id');
    Route::post('orders/{id}/invoice', [\App\Http\Controllers\Api\V1\OrderController::class, 'addInvoiceFromFederation'])->whereNumber('id');
    Route::post('orders/{id}/dispute', [\App\Http\Controllers\Api\V1\OrderDisputeController::class, 'disputeFromFederation'])->whereNumber('id');
    Route::post('orders/{id}/dispute/import-note', [\App\Http\Controllers\Api\V1\OrderDisputeController::class, 'importNoteFromFederation'])->whereNumber('id');
    Route::post('orders/{id}/label-fetch', [\App\Http\Controllers\Api\V1\OrderLabelInvoiceController::class, 'requestLabelFromFederation'])->whereNumber('id');
    Route::post('orders/{id}/manual-payment', [\App\Http\Controllers\Api\V1\ManualOrderController::class, 'payManualFromFederation'])->whereNumber('id');
    Route::post('orders/manual/preview', [\App\Http\Controllers\Api\V1\ManualOrderController::class, 'previewFromFederation']);
    // INF-054 R5: Categoria W (criar pedido)
    Route::post('orders/manual', [\App\Http\Controllers\Api\V1\ManualOrderController::class, 'storeFromFederation']);
    Route::post('orders/search-marketplace', [\App\Http\Controllers\Api\V1\OrderSearchController::class, 'searchFromFederation']);
    Route::post('orders/import-by-number', [\App\Http\Controllers\Api\V1\OrderImportController::class, 'importFromFederation']);
    Route::post('orders/fetch-by-id', [\App\Http\Controllers\Api\V1\OrderImportController::class, 'fetchByIdFromFederation']);

    // INF-054 R3: Categoria Y (delega pro legado via bridge)
    Route::post('orders/{id}/cancel', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'orderCancelFromFederation'])->whereNumber('id');
    Route::get('orders/{id}/payment-details', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'paymentDetailsFromFederation'])->whereNumber('id');
    Route::post('orders/{id}/cancel-label', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'orderCancelLabelFromFederation'])->whereNumber('id');
    Route::post('orders/{id}/refund', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'orderRefundFromFederation'])->whereNumber('id');
    Route::post('orders/{id}/block', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'orderBlockFromFederation'])->whereNumber('id');
    Route::delete('orders/{id}/block', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'orderBlockFromFederation'])->whereNumber('id');
    Route::post('orders/{id}/swap-sku', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'orderSwapSkuFromFederation'])->whereNumber('id');
    // MUL-264/265: sync Bling + emitir NF-e via federation
    Route::post('orders/{id}/sync-bling', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'syncBlingFromFederation'])->whereNumber('id');
    Route::post('orders/{id}/emit-nfe',   [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'emitNfeFromFederation'])->whereNumber('id');

    Route::post('orders/{id}/ship', [\App\Http\Controllers\Api\V1\OrderController::class, 'markShippedFromFederation'])
        ->whereNumber('id');

    // INF-054 caminho 2: WL encaminha swap-product pro hub
    Route::post('orders/{id}/swap-product', [OrderSwapProductController::class, 'swapFromFederation'])
        ->name('federation.orders.swap_product')
        ->whereNumber('id');

    // MUL-298: edicao de item via federation. Item identificado por SKU, nunca por id local (MUL-272).
    Route::post('orders/{id}/items', [\App\Http\Controllers\Api\V1\OrderItemsController::class, 'storeFromFederation'])->whereNumber('id');
    Route::patch('orders/{id}/items/{sku}', [\App\Http\Controllers\Api\V1\OrderItemsController::class, 'updateFromFederation'])->whereNumber('id');
    Route::delete('orders/{id}/items/{sku}', [\App\Http\Controllers\Api\V1\OrderItemsController::class, 'destroyFromFederation'])->whereNumber('id');

    Route::post('orders/{hub_order_id}/status', [FederationOrderController::class, 'updateStatusFromWl'])
        ->name('federation.orders.status')
        ->whereNumber('hub_order_id');

    // MUL-236 F2: WL encaminha writes de kit pro hub (fonte de verdade)
    Route::post('kits/upsert', [KitController::class, 'upsertFromFederation'])
        ->name('federation.kits.upsert');

    Route::post('kits/deactivate', [KitController::class, 'deactivateFromFederation'])
        ->name('federation.kits.deactivate');
});

// -------------------------------------------------------------------------
// Rotas dos WLs (Hub -> WL): verify.federation.hmac HMAC
// -------------------------------------------------------------------------
Route::prefix('api/federation')->middleware(['api', 'verify.federation.hmac'])->group(function () {

    Route::post('catalog/receive', [FederationCatalogReceiveController::class, 'receive'])
        ->name('federation.catalog.receive');

    Route::post('orders/receive', [FederationOrderReceiveController::class, 'receiveWebhook'])
        ->name('federation.orders.receive');

    Route::post('kits/receive', [FederationKitReceiveController::class, 'receive'])
        ->name('federation.kits.receive');
});
