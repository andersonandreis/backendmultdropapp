<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Email\TrackEmailController;
use App\Http\Controllers\SlugController;
use App\Http\Controllers\OAuth\MercadoLivreController;

Route::get('/', function () {
    return view('welcome'); // Front page
});

Route::get('/install', [\App\Http\Controllers\InstallController::class, 'index'])->name('installer.index');
Route::post('/install/setup', [\App\Http\Controllers\InstallController::class, 'setup'])->name('installer.setup');

// Protected label printing route
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/orders/print-label/{order}', [\App\Http\Controllers\LabelPrinterController::class, 'print'])->name('orders.print-label');
});

// OAuth Mercado Livre — redirect antigo (Filament admin, exige auth)
Route::middleware('auth')->group(function () {
    Route::get('/oauth/mercadolivre/{account}/redirect', [MercadoLivreController::class, 'redirect'])
        ->name('oauth.mercadolivre.redirect');
});

// OAuth ML callback — SEM auth (usado pelo frontend hubai.io via OAuthController)
// A redirect_uri registrada no app ML e /oauth/mercadolivre/callback (sem /api/)
Route::get('/oauth/mercadolivre/callback', [\App\Http\Controllers\Api\OAuthController::class, 'callback'])
    ->defaults('platform', 'mercadolivre')
    ->name('oauth.mercadolivre.callback');


Route::get("/admin/products/template", [\App\Http\Controllers\Admin\ProductTemplateController::class, "download"])
    ->name("admin.products.template");

// HUB-QZ 2026-07-17: endpoints QZ Tray (impressao automatica etiqueta)
Route::middleware(['web', 'auth'])->prefix('admin/qz')->group(function () {
    Route::get('/certificate', [\App\Http\Controllers\Admin\QzTrayController::class, 'certificate'])->name('admin.qz.certificate');
    Route::get('/sign',        [\App\Http\Controllers\Admin\QzTrayController::class, 'sign'])->name('admin.qz.sign');
    Route::post('/mark-printed', [\App\Http\Controllers\Admin\QzTrayController::class, 'markPrinted'])->name('admin.qz.mark-printed');
});

// Webhooks de marketplace (sem prefixo /api, sem CSRF — o ML envia pra /webhooks/mercadolivre)
Route::withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->group(function () {
        // Shopee Push Platform — direto da Shopee (autenticado via HMAC, sem polling)
        Route::post('/webhooks/shopee', [\App\Http\Controllers\Webhooks\ShopeeWebhookController::class, 'handle'])->name('webhooks.shopee.push');
        Route::post('/webhooks/{platform}', [\App\Http\Controllers\Api\WebhookController::class, 'handle']);
    });


// OAuth Bling ERP — redirect antigo (Filament admin, exige auth)
Route::middleware("auth")->group(function () {
    Route::get('/bling/auth/{account}', [\App\Http\Controllers\OAuth\BlingController::class, 'redirect'])
        ->name('oauth.bling.redirect');
});

// OAuth Bling callback — SEM auth (usado pelo frontend hubai.io via OAuthController)
Route::get('/bling/callback', [\App\Http\Controllers\Api\OAuthController::class, 'callback'])
    ->defaults('platform', 'bling')
    ->name('oauth.bling.callback');

// OAuth Shopee Open Platform -- callback publico (Go-Live validation)
// A redirect_uri registrada no app Shopee e /shopee/oauth-callback (sem /api/)
Route::get('/shopee/oauth-callback', [\App\Http\Controllers\OAuth\ShopeeOAuthController::class, 'callback'])
    ->name('oauth.shopee.callback');

// GOL-032: Inicio do fluxo OAuth Shopee vindo do legado (goolhub.io/WL).
// A Shopee nao retorna o state no callback, entao usamos cookie-based pending:
// este endpoint valida o HMAC, armazena uid+ret no cache e redireciona para a Shopee
// com um cookie shopee_pending; o callback le o cookie para recuperar o contexto.
Route::get('/shopee/legado-start', [\App\Http\Controllers\OAuth\ShopeeOAuthController::class, 'legadoStart'])
    ->name('shopee.legado-start');

// Pagina de sucesso OAuth Shopee -- publica, sem sessao
// Resolve o problema de sessao WL: o legado redireciona para login quando recebe
// shopee_connected=1 porque a sessao PHP nao sobrevive ao redirect cross-domain.
// Esta pagina exibe confirmacao e redireciona automaticamente para o painel WL.
Route::get('/shopee/success', function (\Illuminate\Http\Request $request) {
    $shopId = $request->query('shop_id');
    $retEncoded = $request->query('ret', '');
    $returnUrl = null;
    if ($retEncoded) {
        $decoded = base64_decode($retEncoded, true);
        if ($decoded && filter_var($decoded, FILTER_VALIDATE_URL)) {
            $returnUrl = $decoded;
        }
    }
    return view('shopee-success', [
        'shopId'    => $shopId,
        'returnUrl' => $returnUrl,
    ]);
})->name('shopee.success');

// Email tracking — sem autenticacao, sem CSRF (clientes de e-mail nao enviam cookies)
Route::withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->group(function () {
        Route::get('/email/track/open/{token}', [TrackEmailController::class, 'open'])
            ->name('email.track.open');
        Route::get('/email/track/click/{token}', [TrackEmailController::class, 'click'])
            ->name('email.track.click');
    });

// OAuth Shopify callback — SEM auth
Route::get('/oauth/shopify/callback', [\App\Http\Controllers\Api\OAuthController::class, 'callback'])
    ->defaults('platform', 'shopify')
    ->name('oauth.shopify.callback');


// Live Board Publico — painel de vendas ao vivo
Route::get('/demo/{slug}', [\App\Http\Controllers\DemoController::class, 'show']);

// OAuth Shopify callback — SEM auth
Route::get('/oauth/shopify/callback', [\App\Http\Controllers\Api\OAuthController::class, 'callback'])
    ->defaults('platform', 'shopify')
    ->name('oauth.shopify.callback');

// Fallback Route for Dynamic Slugs (Must be at the very bottom)
Route::fallback([SlugController::class, 'resolve']);


// Logout universal — aceita GET, sem CSRF, faz logout de todos os guards
\Illuminate\Support\Facades\Route::any('/logout-all', function (\Illuminate\Http\Request $request) {
    foreach (['web', 'admin', 'app'] as $guard) {
        try { \Illuminate\Support\Facades\Auth::guard($guard)->logout(); } catch (\Throwable $e) {}
    }
    $request->session()->invalidate();
    $request->session()->regenerateToken();
    // Redireciona pra base do panel de origem (whitelist por segurança)
    $to = $request->query('to', '/');
    $allowed = ['/', '/app/login', '/admin/login'];
    if (! in_array($to, $allowed)) $to = '/';
    return redirect($to);
})->name('logout-all');


// Drop Internacional — Shopify Webhooks (sem auth, validados por HMAC)
// Seguindo o mesmo padrao dos webhooks de marketplace (sem CSRF)
\Illuminate\Support\Facades\Route::withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->prefix('webhooks/drop/shopify')
    ->group(function () {
        \Illuminate\Support\Facades\Route::post('orders-create', [\App\Http\Controllers\Drop\DropWebhookController::class, 'ordersCreate'])->name('drop.webhook.orders-create');
        \Illuminate\Support\Facades\Route::post('orders-updated', [\App\Http\Controllers\Drop\DropWebhookController::class, 'ordersUpdated'])->name('drop.webhook.orders-updated');
        \Illuminate\Support\Facades\Route::post('fulfillments-create', [\App\Http\Controllers\Drop\DropWebhookController::class, 'fulfillmentsCreate'])->name('drop.webhook.fulfillments-create');
        \Illuminate\Support\Facades\Route::post('app-uninstalled', [\App\Http\Controllers\Drop\DropWebhookController::class, 'appUninstalled'])->name('drop.webhook.app-uninstalled');
    });

// Drop Internacional -- Stripe Webhooks (sem auth, validados por assinatura HMAC)
\Illuminate\Support\Facades\Route::post(
    'webhooks/drop/stripe',
    [\App\Http\Controllers\Drop\DropStripeController::class, 'handle']
)->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
 ->name('drop.webhook.stripe');

// ===== Loja Nativa — vitrine pública =====
Route::prefix('loja/{slug}')->group(function () {
    Route::get('/',                     [\App\Http\Controllers\Drop\NativeStorefrontController::class, 'catalog'])->name('native.catalog');
    Route::get('/produto/{id}',         [\App\Http\Controllers\Drop\NativeStorefrontController::class, 'product'])->name('native.product');
    Route::post('/checkout',            [\App\Http\Controllers\Drop\NativeStorefrontController::class, 'checkout'])->name('native.checkout');
    Route::get('/pedido/{orderKey}',    [\App\Http\Controllers\Drop\NativeStorefrontController::class, 'orderStatus'])->name('native.order');
})->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

// MercadoPago webhook Drop Internacional
Route::post('/api/webhooks/drop/mercadopago',
    [\App\Http\Controllers\Drop\MercadoPagoDropWebhookController::class, 'handle']
)->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
 ->name('drop.webhook.mercadopago');

// Pagar.me webhook Drop Internacional - Fase 3
Route::post('/api/webhooks/drop/pagarme',
    [\App\Http\Controllers\Drop\PagarmeDropWebhookController::class, 'handle']
)->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
 ->name('drop.webhook.pagarme');


// NOV-046-I: redirect 301 /admin/whitelabels/* -> /admin/tenants/*
// WhitelabelResource foi consolidado em TenantResource (nomenclatura oficial)
Route::get('/admin/whitelabels', function () {
    return redirect()->to('/admin/tenants', 301);
});
Route::get('/admin/whitelabels/{rest}', function (string $rest) {
    return redirect()->to('/admin/tenants/' . $rest, 301);
})->where('rest', '.*');

// SEL-046: OAuth TikTok Shop Partner API — callback publico (redireciona pro frontend)
Route::get('/oauth/tiktok/callback', [\App\Http\Controllers\Api\V1\TiktokOAuthController::class, 'callback']);

// MUL-355: fallback do arquivo de etiqueta espelhada.
//
// O label_url do pedido espelhado chega com o nome do arquivo do HUB
// (shopee-<sn>-<id_do_hub>.png) e o disco do WL nao tem esse arquivo. Medido em
// 08/08/2026 no painel MultDrop: 96 de 96 pedidos do dia respondiam 404 no botao
// "Baixar Etiqueta" e no "Visualizar etiqueta" — a etiqueta existia, so nao abria.
//
// Arquivo que EXISTE e servido pelo LiteSpeed antes de chegar no Laravel, entao esta
// rota so roda no que hoje ja e 404: nao muda nada do caminho que funciona.
//
// Mesma logica do proxyStorageLabel (JT-008 / MUL-244): local primeiro, hub como
// fallback. Sem auth de proposito — <a href> e <img src> nao mandam Bearer, e o mesmo
// arquivo ja responde 200 publico em api.hubai.io/storage/labels/, entao isto nao
// amplia exposicao. (A exposicao publica em si e questao separada, ver MUL-355.)
Route::get("/storage/labels/{filename}", function (string $filename) {
    $mime = match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
        "pdf"         => "application/pdf",
        "jpg", "jpeg" => "image/jpeg",
        "png"         => "image/png",
        "gif"         => "image/gif",
        "webp"        => "image/webp",
        default       => "application/octet-stream",
    };

    $local = storage_path("app/public/labels/" . $filename);
    if (is_file($local)) {
        return response()->file($local, ["Content-Type" => $mime]);
    }

    // MUL-359: arquivo antigo movido pro privado. Publico NAO serve — so a
    // busca interna WL->hub, autenticada pelo segredo de federacao (o mesmo
    // FEDERATION_HMAC_SECRET que o hub guarda por tenant, em claro no banco).
    $priv = storage_path("app/private/labels/" . $filename);
    if (is_file($priv)) {
        // FOR-101: o painel Filament busca a etiqueta por ESTA URL com cookie de
        // sessao -- QZ Tray (tray-client.blade: fetch same-origin), a view
        // labels/print e a acao "Imprimir Etiqueta" do ProcessOrders. Sem isto,
        // mover o arquivo pro privado quebra a expedicao do fornecedor.
        // So papel de operacao; lojista (role client) NAO entra -- ele tem o
        // endpoint /orders/{id}/label-file, que confere o dono do pedido.
        $u = auth()->user();
        if ($u && in_array((string) ($u->role ?? ""), ["super_admin", "admin", "supplier"], true)) {
            return response()->file($priv, ["Content-Type" => $mime]);
        }

        $tenant = (string) request()->header("X-Federation-Tenant", "");
        $secret = (string) request()->header("X-Federation-Secret", "");
        if ($tenant !== "" && $secret !== "") {
            $esperado = \Illuminate\Support\Facades\DB::table("tenant_webhook_endpoints")
                ->join("tenants", "tenants.id", "=", "tenant_webhook_endpoints.tenant_id")
                ->where("tenants.slug", $tenant)
                ->value("tenant_webhook_endpoints.secret");
            if ($esperado && hash_equals((string) $esperado, $secret)) {
                return response()->file($priv, ["Content-Type" => $mime]);
            }
        }
        abort(404);
    }

    // No proprio hub nao ha a quem apelar — impede a rota de chamar a si mesma.
    if (config("app.tenant") === "hubai") {
        abort(404);
    }

    $hubUrl = rtrim(config("services.hubai_federation.storage_url", "https://api.hubai.io"), "/");
    try {
        $res = \Illuminate\Support\Facades\Http::timeout(30)->connectTimeout(10)
            ->get($hubUrl . "/storage/labels/" . $filename);
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::warning("[Label/Fallback] hub inacessivel", [
            "filename" => $filename,
            "error"    => $e->getMessage(),
        ]);
        abort(502);
    }

    if (! $res->successful()) {
        abort(404);
    }

    // Cacheia local: a proxima leitura sai pelo LiteSpeed, sem tocar no hub.
    try {
        if (! is_dir(dirname($local))) {
            mkdir(dirname($local), 0755, true);
        }
        file_put_contents($local, $res->body());
    } catch (\Throwable) {
        // cache e otimizacao, nao requisito — segue servindo da resposta
    }

    return response($res->body(), 200)->header("Content-Type", $mime);
})->where("filename", "[a-zA-Z0-9._\-]+\.(?:pdf|png|jpe?g|gif|webp)");
