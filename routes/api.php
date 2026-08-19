<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Models\Product;
use App\Models\Inventory;
use App\Models\Order;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PublicApiController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\FinancialController;
use App\Http\Controllers\Api\V1\WalletController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\AdminPromoController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\SupplierPanelController;
use App\Http\Controllers\Api\V1\WebhookConfigController;
use App\Http\Controllers\Api\V1\EventSubscriptionController;
use App\Http\Controllers\Api\V1\MarketplaceController;
use App\Http\Controllers\Api\V1\MarketplaceOrdersController;
use App\Http\Controllers\Api\V1\ClientProductCountController;
use App\Http\Controllers\Api\V1\ClientProductSyncController;
use App\Http\Controllers\Api\V1\ClientDiscountController;
use App\Http\Controllers\Api\V1\ManualOrderController;
use App\Http\Controllers\Api\V1\OrderSwapProductController;
use App\Http\Controllers\Api\V1\MissedOrderController;
use App\Http\Controllers\Api\V1\OrderSearchController;
use App\Http\Controllers\Api\V1\AffiliateController;
use App\Http\Controllers\Api\V1\ImpersonationController;
use App\Http\Controllers\Api\V1\IntegrationBlingController;
use App\Http\Controllers\Api\V1\OrderDisputeController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Health check publico
Route::get('/health', [PublicApiController::class, 'health']);

// =========================================================================
// NOV-061: Bridge interna goolhub.io -> api.hubai.io para obter token
// valido de marketplace sem o legado tentar refresh local.
// Auth: header X-Internal-Key (INTERNAL_BRIDGE_KEY).
// =========================================================================
Route::middleware('internal.key')->prefix('internal')->group(function () {
    Route::get('marketplace-token', [\App\Http\Controllers\Internal\InternalTokenController::class, 'getToken']);
    // MUL-159: Gerenciar marketplace_accounts via paineis WL
    Route::get('marketplace-accounts/{id}', [\App\Http\Controllers\Internal\InternalMarketplaceAccountController::class, 'show'])->whereNumber('id');
    Route::post('marketplace-accounts/{id}/refresh', [\App\Http\Controllers\Internal\InternalMarketplaceAccountController::class, 'refresh'])->whereNumber('id');
    Route::post('marketplace-accounts/{id}/mark-reauth', [\App\Http\Controllers\Internal\InternalMarketplaceAccountController::class, 'markReauth'])->whereNumber('id');
    // NOV-058-C: Catalog proxy para WLs (MEStoreDrop e futuras)
    Route::get('catalog/products', [\App\Http\Controllers\Internal\InternalCatalogController::class, 'products']);
    Route::get('catalog/products/{id}', [\App\Http\Controllers\Internal\InternalCatalogController::class, 'product'])->whereNumber('id');
    // NOV-071: Centro de Comando — Health + Financeiro
    Route::get('system-health', [\App\Http\Controllers\Internal\SystemHealthController::class, 'index']);
    Route::get('financial-summary', [\App\Http\Controllers\Internal\FinancialSummaryController::class, 'index']);

    // NOV-072: Robo de Cadastro v2 - controle da fila product_listing_jobs
    Route::get('listing-queue-stats', [\App\Http\Controllers\Internal\ListingQueueController::class, 'stats']);
    Route::post('listing-queue/enqueue', [\App\Http\Controllers\Internal\ListingQueueController::class, 'enqueue']);
    Route::post('listing-queue/pause/{client_id}', [\App\Http\Controllers\Internal\ListingQueueController::class, 'pause'])->whereNumber('client_id');
    Route::post('listing-queue/resume/{client_id}', [\App\Http\Controllers\Internal\ListingQueueController::class, 'resume'])->whereNumber('client_id');
    Route::post('listing-queue/clear-failed/{client_id}', [\App\Http\Controllers\Internal\ListingQueueController::class, 'clearFailed'])->whereNumber('client_id');

    // NOV-099: Provisionar/consultar subscription MySQL apos webhook Pagar.me
    Route::post('subscriptions', [\App\Http\Controllers\Internal\InternalSubscriptionController::class, 'provision']);
    Route::get('subscriptions/by-email/{email}', [\App\Http\Controllers\Internal\InternalSubscriptionController::class, 'show']);

    // SEL-430: Flush instantaneo do cache Redis do billing gate WL (< 1s).
    // Chamado pelo api.seller.global apos gravar is_blocked=false no Supabase.
    Route::post('wl-billing/flush-cache', [\App\Http\Controllers\Internal\WlBillingFlushController::class, 'flush']);
});

// =========================================================================
// CHECKOUT PUBLICO — Planos e Assinatura via Pagar.me
// =========================================================================
Route::get('/checkout/plans', [\App\Http\Controllers\Api\CheckoutController::class, 'getPlans']);
Route::post('/checkout/subscribe', [\App\Http\Controllers\Api\CheckoutController::class, 'createSubscription']);

// SEL-186: Cupons de assinatura — pre-validacao e calculo de desconto
// GET  /api/checkout/coupons/{code}  — pre-check (publica, sem auth)
// POST /api/checkout/apply-coupon    — calculo final (auth opcional)
Route::get('/checkout/coupons/{code}', [\App\Http\Controllers\Api\CouponsController::class, 'validate']);
Route::post('/checkout/apply-coupon', [\App\Http\Controllers\Api\CouponsController::class, 'apply']);

// =========================================================================
// SEL-010 - CAPI Meta (relay server-side para Graph API v21)
// Rota publica, rate-limit 60/min. Purchase bloqueado (vem so webhook Pagar.me).
// Env-gated: no-op silencioso se META_CAPI_TOKEN ausente.
// =========================================================================
Route::post('/tracking/capi', [\App\Http\Controllers\Api\TrackingController::class, 'capi'])
    ->middleware(['throttle:60,1'])
    ->name('tracking.capi');

// =========================================================================
// INF-030 (Ruan 12/08) — coleta propria leve (click/video_progress) pro
// admin /admin/analytics. Publica, sem auth, rate-limit 120/min. Nunca
// derruba a pagina do visitante (controller engole excecao, sempre 204).
// =========================================================================
Route::post('/track/event', [\App\Http\Controllers\Api\V1\SiteAnalyticsController::class, 'track'])
    ->middleware(['throttle:120,1'])
    ->name('track.event');

// INF-030 (Ruan 12/08, ampliacao) — campanha (utm/fbclid/gclid/ttclid) no
// primeiro toque + chunks de gravacao de sessao (rrweb). Mesmo padrao:
// publica, sem auth, rate-limitada, nunca derruba a pagina do visitante.
Route::post('/track/campaign', [\App\Http\Controllers\Api\V1\SiteAnalyticsController::class, 'campaign'])
    ->middleware(['throttle:30,1'])
    ->name('track.campaign');
Route::post('/track/recording', [\App\Http\Controllers\Api\V1\SiteAnalyticsController::class, 'recording'])
    ->middleware(['throttle:120,1'])
    ->name('track.recording');

// =========================================================================
// PUBLICACAO DE PRODUTOS (integrado com hubai.io via bridge interna)
// SEL-182 pentest fix: exige X-Internal-Key porque qualquer anonimo podia
// publicar produtos arbitrarios em qualquer conta ML/Shopee conectada
// enumerando client_id (idor + abuso de publicacao).
// =========================================================================
Route::post('/v1/products/publish', [\App\Http\Controllers\Api\V1\ProductPublishController::class, 'publish'])
    ->middleware(['internal.key', 'throttle:60,1']);

// =========================================================================
// NOV-020: ML SELLER REPUTATION (publico, sem Sanctum)
// SEL-182 pentest fix: cap throttle pra evitar scraping automatizado de ML.
// =========================================================================
Route::get('/v1/ml/seller-reputation/{seller_id}', [\App\Http\Controllers\Api\V1\MlReputationController::class, 'show'])
    ->middleware('throttle:30,1');

// =========================================================================
// NOV-020: AI PRODUCT GENERATOR (integrado com hubai.io via bridge interna)
// SEL-182 pentest fix: sem auth qualquer atacante queimava nossas API keys
// OpenAI/Gemini/Claude (custo direto em dolar). Agora exige X-Internal-Key
// + throttle 30/min como defesa em profundidade.
// =========================================================================
Route::post('/v1/ai/generate-product', [\App\Http\Controllers\Api\V1\ProductAiController::class, 'generate'])
    ->middleware(['internal.key', 'throttle:30,1']);

// =========================================================================
// NOV-020: PRODUCT LISTINGS (integrado com hubai.io via bridge interna)
// SEL-182 pentest fix: retornava lista completa de anuncios (SKU/URL/marketplace)
// pra qualquer client_id enumerado — vazamento de catalogo de qualquer lojista.
// =========================================================================
Route::get('/v1/products/listings', [\App\Http\Controllers\Api\V1\ProductListingsController::class, 'index'])
    ->middleware(['internal.key', 'throttle:120,1']);

// =========================================================================
// SEL-036 F8: TENDENCIAS ML (publico, cache 6h; sem conta ML local faz fallback pro hub)
// =========================================================================
Route::get('/v1/trends', [\App\Http\Controllers\Api\V1\TrendsController::class, 'index'])->middleware('throttle:30,1');

// SEL-256: Insights TikTok Shop BR — dados enriquecidos (públicos, snapshot atualizado).
// Endpoint neutro (sem citar fonte externa). Body/response iguais.
Route::prefix('/v1/insights/tiktok')->middleware('throttle:240,1')->group(function () {
    Route::get('/snapshot', [\App\Http\Controllers\Api\V1\KalodataController::class, 'snapshot']);
    Route::get('/products', [\App\Http\Controllers\Api\V1\KalodataController::class, 'products']);
    Route::get('/creators', [\App\Http\Controllers\Api\V1\KalodataController::class, 'creators']);
    Route::get('/videos',   [\App\Http\Controllers\Api\V1\KalodataController::class, 'videos']);
    Route::get('/lives',    [\App\Http\Controllers\Api\V1\KalodataController::class, 'lives']);
    Route::get('/shops',    [\App\Http\Controllers\Api\V1\KalodataController::class, 'shops']);
    // SEL-319: detalhe de produto + video refresh on-demand
    Route::get('/products/{id}',      [\App\Http\Controllers\Api\V1\KalodataController::class, 'productDetail']);
    Route::get('/video-refresh/{id}', [\App\Http\Controllers\Api\V1\KalodataController::class, 'videoRefresh']);
    // SEL-336: analise estruturada de video viral (transcricao + insight)
    Route::get('/videos/{id}/analysis', [\App\Http\Controllers\Api\V1\KalodataController::class, 'videoAnalysis']);
    // SEL-341: marcas (brands) e ADS Kalodata
    Route::get('/brands', [\App\Http\Controllers\Api\V1\KalodataController::class, 'brands']);
    Route::get('/ads',    [\App\Http\Controllers\Api\V1\KalodataController::class, 'ads']);
    Route::get('/lives-ranking', [\App\Http\Controllers\Api\V1\KalodataController::class, 'livesRanking']);
    // SEL-356: drill-down marcas, lojas e ranking categorias
    Route::get('/brands/{external_id}/detail', [\App\Http\Controllers\Api\V1\KalodataController::class, 'brandDetail']);
    Route::get('/shops/{external_id}/detail',  [\App\Http\Controllers\Api\V1\KalodataController::class, 'shopDetail']);
    Route::get('/categories', [\App\Http\Controllers\Api\V1\KalodataController::class, 'categories']);
});

// =========================================================================
// SEL-129: Detalhe publico de fornecedor operacional + catalogo paginado.
// Sem Sanctum. Regex no {slug} exige que comece com letra pra nao colidir
// com /v1/suppliers/{id}/catalog (numerico) do grupo autenticado.
// =========================================================================
Route::get('/v1/suppliers/{slug}', [\App\Http\Controllers\Api\V1\SupplierDetailController::class, 'show'])
    ->where('slug', '[a-z][a-z0-9\-]*')
    ->middleware('throttle:60,1');



// SEL-046/047: TikTok OAuth relay central (matriz hubai)
Route::get('/tiktok/oauth/init', [\App\Http\Controllers\Api\V1\TiktokOAuthController::class, 'init']);

// SEL-046: OAuth TikTok Shop Partner API
Route::middleware(['auth:sanctum'])->prefix('v1/tiktok/oauth')->group(function () {
    Route::get('/start',      [\App\Http\Controllers\Api\V1\TiktokOAuthController::class, 'start']);
    Route::get('/status',     [\App\Http\Controllers\Api\V1\TiktokOAuthController::class, 'status']);
    Route::post('/disconnect',[\App\Http\Controllers\Api\V1\TiktokOAuthController::class, 'disconnect']);
});

// SEL-044: TikTok Shop trends (ingest via Playwright + leitura publica)
Route::middleware(['auth:sanctum'])->prefix('v1/trends')->group(function () {
    Route::get('/tiktok-shop', [\App\Http\Controllers\Api\V1\TiktokShopTrendsController::class, 'index']);
});
// SEL (08/08 Ruan): selar aparelho no admin (link secreto, protegido pelo code)
Route::get('v1/admin/seal-device', [\App\Http\Controllers\Api\V1\AdminDeviceController::class, 'seal']);

Route::middleware(['auth:sanctum', 'role:admin,super_admin'])->prefix('v1/admin/trends')->group(function () {
    Route::post('/tiktok-shop/ingest', [\App\Http\Controllers\Api\V1\TiktokShopTrendsController::class, 'ingest']);
});

// SEL-122 backend Ruan 16/07: import automatico de perfis TikTok via tikwm.
// Complementa SEL-161 (preview no front) — grava o criador em tiktok_shop_trends
// pra listar sem precisar do coletor Playwright semanal.
Route::middleware(['auth:sanctum', 'role:admin,super_admin'])->prefix('v1/admin/tiktok-shop-trends')->group(function () {
    Route::post('/import-creator-profile', [\App\Http\Controllers\Api\V1\Admin\TiktokTrendImportController::class, 'importCreatorProfile']);

    // SEL-123-BACKEND (16/08): a tela /admin/creators-review chamava esta rota desde
    // sempre e ela NAO EXISTIA (curl: 405). O toggle de aprovar/ocultar criador piscava,
    // revertia e mostrava o erro cru do Laravel pro admin. As colunas ja existiam na
    // tabela; faltava so a porta. Escopo estreito: SO is_visible e is_approved.
    Route::match(['patch', 'post'], '/{id}', function (\Illuminate\Http\Request $request, int $id) {
        $dados = $request->validate([
            'is_visible'  => 'sometimes|boolean',
            'is_approved' => 'sometimes|boolean',
        ]);

        if (! $dados) {
            return response()->json(['error' => 'nada_para_atualizar'], 422);
        }

        $linha = \Illuminate\Support\Facades\DB::table('tiktok_shop_trends')->where('id', $id)->first();
        if (! $linha) {
            return response()->json(['error' => 'criador_nao_encontrado'], 404);
        }

        \Illuminate\Support\Facades\DB::table('tiktok_shop_trends')
            ->where('id', $id)
            ->update($dados + ['updated_at' => now()]);

        \Illuminate\Support\Facades\Log::error('[SEL-123-BACKEND] criador atualizado pelo admin', [
            'id' => $id, 'campos' => $dados, 'admin' => $request->user()?->id,
        ]);

        return response()->json([
            'ok' => true,
            'id' => $id,
        ] + $dados);
    })->whereNumber('id');
});

// SEL-199 — Criadores IA: endpoint público + CRUD admin
// Público: GET /api/v1/ai-creators (paginated, free cap 30 na pag default)
Route::middleware(['auth:sanctum', 'check.user.active'])->prefix('v1')->group(function () {
    Route::get('/ai-creators', [\App\Http\Controllers\Api\V1\AiCreatorController::class, 'index']);
    // SEL-237: produtos TT Shop que o criador vende (via anchors dos vídeos)
    Route::get('/ai-creators/{aiCreator}/products', [\App\Http\Controllers\Api\V1\AiCreatorController::class, 'products']);

    // SEL-245: wallet créditos IA cliente
    Route::get('/ai-wallet/summary', [\App\Http\Controllers\Api\V1\AiWalletController::class, 'summary']);
    Route::post('/ai-wallet/deposit', [\App\Http\Controllers\Api\V1\AiWalletController::class, 'deposit']);
});

// SEL-245: admin — saldo Kling + consumo por cliente
Route::middleware(['auth:sanctum', 'check.user.active', 'role:admin,super_admin'])->prefix('v1/admin')->group(function () {
    Route::get('/kling/balance', [\App\Http\Controllers\Api\V1\AiWalletController::class, 'adminKlingBalance']);
    Route::get('/ai-wallet/consumption', [\App\Http\Controllers\Api\V1\AiWalletController::class, 'adminConsumption']);
    Route::post('/ai-wallet/credit', [\App\Http\Controllers\Api\V1\AiWalletController::class, 'adminCredit']);
});

// SEL-200E — Dashboard TT Shop pagante (produtos alta/criadores/fornecedores/risco/viral/horarios/live)
Route::middleware(['auth:sanctum', 'check.user.active', \App\Http\Middleware\EnsureTrialActive::class])->prefix('v1/tiktok')->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\Api\V1\TiktokShopDashboardController::class, 'index']);
});


// Admin: GET/POST/PATCH/DELETE /api/v1/admin/ai-creators + regen-avatar + recompute-ranks
Route::middleware(['auth:sanctum', 'role:admin,super_admin'])->prefix('v1/admin/ai-creators')->group(function () {
    Route::get('/',                          [\App\Http\Controllers\Api\V1\AiCreatorController::class, 'adminIndex']);
    Route::post('/',                         [\App\Http\Controllers\Api\V1\AiCreatorController::class, 'store']);
    Route::post('/recompute-ranks',          [\App\Http\Controllers\Api\V1\AiCreatorController::class, 'recomputeRanks']); // SEL-217
    Route::patch('/{aiCreator}',             [\App\Http\Controllers\Api\V1\AiCreatorController::class, 'update']);
    Route::delete('/{aiCreator}',            [\App\Http\Controllers\Api\V1\AiCreatorController::class, 'destroy']);
    Route::post('/{aiCreator}/regen-avatar', [\App\Http\Controllers\Api\V1\AiCreatorController::class, 'regenAvatar']);
});

// SEL-408 admin: controle de acesso a LIVE — listar quem tem acesso, liberar
// independente do plano, revogar, e criar conta so-live (extensao + /lives,
// nada mais do sistema). Pedido do Ruan 30/07.
Route::middleware(['auth:sanctum', 'check.user.active', 'role:admin,super_admin'])->prefix('v1/admin/live-access')->group(function () {
    Route::get('/',        [\App\Http\Controllers\Api\V1\Admin\LiveAccessController::class, 'index']);
    Route::post('/',       [\App\Http\Controllers\Api\V1\Admin\LiveAccessController::class, 'grant']);
    Route::delete('/{client}', [\App\Http\Controllers\Api\V1\Admin\LiveAccessController::class, 'revoke'])->whereNumber('client');
});

// SEL-040 admin: gestao de features AI + relatorios + permissoes por plano/usuario
Route::middleware(['auth:sanctum', 'role:admin,super_admin'])->prefix('v1/admin/ai')->group(function () {
    Route::get('/catalog',           [\App\Http\Controllers\Api\V1\AdminAIController::class, 'catalogList']);
    Route::get('/keys',              [\App\Http\Controllers\Api\V1\AdminAIController::class, 'keys']);
    Route::get('/plans',             [\App\Http\Controllers\Api\V1\AdminAIController::class, 'plansList']);
    Route::put('/plans/{id}',        [\App\Http\Controllers\Api\V1\AdminAIController::class, 'planUpdate']);
    Route::get('/users',             [\App\Http\Controllers\Api\V1\AdminAIController::class, 'usersList']);
    Route::put('/users/{id}',        [\App\Http\Controllers\Api\V1\AdminAIController::class, 'userUpdate']);
    Route::get('/usage',             [\App\Http\Controllers\Api\V1\AdminAIController::class, 'usage']);
    Route::get('/generations',       [\App\Http\Controllers\Api\V1\AdminAIController::class, 'generations']);
});

// SEL-040: hub central de geracao IA — 14 endpoints /api/v1/ai/* mascarados
Route::middleware(['auth:sanctum', 'check.user.active'])->prefix('v1/ai')->group(function () {
    Route::get('/status',            [\App\Http\Controllers\Api\V1\AIController::class, 'status']);
    Route::get('/voices',            [\App\Http\Controllers\Api\V1\AIController::class, 'voices']);
    Route::post('/prompt/preview',   [\App\Http\Controllers\Api\V1\AIController::class, 'promptPreview']);
    Route::post('/script',           [\App\Http\Controllers\Api\V1\AIController::class, 'script']);
    Route::post('/analyze-image',    [\App\Http\Controllers\Api\V1\AIController::class, 'analyzeImage']);
    Route::post('/tts',              [\App\Http\Controllers\Api\V1\AIController::class, 'tts']);
    Route::post('/sound-effects',    [\App\Http\Controllers\Api\V1\AIController::class, 'soundEffects']);
    Route::post('/dubbing',          [\App\Http\Controllers\Api\V1\AIController::class, 'dubbing']);
    Route::post('/image/generate',   [\App\Http\Controllers\Api\V1\AIController::class, 'image']);   // SEL-303: async (despacha job, retorna 202)
    Route::get('/image/tasks/{id}',  [\App\Http\Controllers\Api\V1\AIController::class, 'imageTask']);    // SEL-303: polling de status
    Route::post('/video/generate',   [\App\Http\Controllers\Api\V1\AIController::class, 'videoGenerate']);
    Route::get('/video/tasks/{id}',  [\App\Http\Controllers\Api\V1\AIController::class, 'videoTask']);
    Route::post('/virtual-try-on',   [\App\Http\Controllers\Api\V1\AIController::class, 'virtualTryOn']);
    Route::post('/lip-sync',         [\App\Http\Controllers\Api\V1\AIController::class, 'lipSync']);
    // SEL-485: copiar movimento (vídeo de referência + foto da pessoa)
    Route::post('/motion-control',   [\App\Http\Controllers\Api\V1\AIController::class, 'motionControl']);
    Route::post('/upload',           [\App\Http\Controllers\Api\V1\AIController::class, 'upload']);
    Route::get('/generations',       [\App\Http\Controllers\Api\V1\AIController::class, 'history']);
});

// SEL-033: geracao de video de produto (Seedance 2.0 / BytePlus ModelArk) — proxy autenticado
Route::middleware(['auth:sanctum', 'check.user.active'])->prefix('v1/video')->group(function () {
    Route::get('/status', [\App\Http\Controllers\Api\V1\VideoGenerationController::class, 'status']);
    Route::post('/generate', [\App\Http\Controllers\Api\V1\VideoGenerationController::class, 'generate']);
    Route::get('/tasks/{id}', [\App\Http\Controllers\Api\V1\VideoGenerationController::class, 'task']);
});

// =========================================================================
// NOV-019: LEGACY FORWARD -- Proxy transparente para goolhub.io (Fase 1 migracao frontend)
// POST /api/v1/legacy-forward
// Sem Sanctum: jwt_token legado vem no body (enviado pela edge function gohub-proxy)
// SEL-182 pentest fix: cap throttle porque endpoint proxy pode virar tunel de
// scraping se descontrolado. Auth real acontece no legado quando ele valida
// o jwt_token, mas rate-limit evita amplificacao.
// =========================================================================
Route::post('/v1/legacy-forward', [\App\Http\Controllers\Api\V1\LegacyForwardController::class, 'forward'])
    ->middleware('throttle:120,1');

// =========================================================================
// AUTENTICAÇÃO (Sanctum Stateless)
// =========================================================================
Route::get('/v1/live/extensao/updates.xml', [\App\Http\Controllers\Api\V1\LiveController::class, 'updatesXml']);
Route::post('/v1/live/login', [\App\Http\Controllers\Api\V1\LiveController::class, 'login'])
    ->middleware('throttle:10,1'); // SEL-393: login da extensao (publico, como o /api/login)
// SEL-LOGINLIMITE (13/08, Ruan): era throttle:5,1 e barrava CLIENTE PAGANTE.
// Medido na API depois de zerar os contadores: a 3a requisicao ja voltava 429.
// 5/min por IP pune quem erra a senha duas vezes, quem esta em IP compartilhado
// (escritorio, 4G) e quem tem o front mandando requisicao duplicada. O cliente
// wandernunes2022 (plano Ultra ATIVO) ficou fora do proprio painel por causa disto,
// e havia 4.733 contadores de bloqueio ativos no cache.
// 20/min continua matando forca bruta (um ataque real faz centenas por minuto)
// sem transformar erro de digitacao em cliente barrado.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login'); // SEL-LOGINLIMITE: 20/min por IP
Route::post('/verify-2fa', [AuthController::class, 'verify2fa'])->middleware('throttle:5,1'); // MUL-222 2FA login challenge
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:3,1'); // SEL-184 esqueci senha
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1'); // SEL-184 consome token
Route::post('/google-login', [AuthController::class, 'googleLogin'])->middleware('throttle:10,1'); // MUL-214 item 36
// SEL-CONVITE Fase A — trial fechado por link /convite (login Google-only + trial/waitlist/wall)
Route::post('/convite/start', [\App\Http\Controllers\Api\V1\ConviteController::class, 'start'])->middleware('throttle:10,1');
// SEL-CONVITE Fase B — painel admin do convite (toggle mode, teto, waitlist, liberar lote SMTP)
Route::middleware(['auth:sanctum', 'check.user.active', 'role:admin,super_admin'])->prefix('v1/admin/convite')->group(function () {
    Route::get('/stats', [\App\Http\Controllers\Api\V1\Admin\AdminConviteController::class, 'stats']);
    Route::put('/settings', [\App\Http\Controllers\Api\V1\Admin\AdminConviteController::class, 'updateSettings']);
    Route::post('/release-batch', [\App\Http\Controllers\Api\V1\Admin\AdminConviteController::class, 'releaseBatch']);
});
Route::get('/public-config', [AuthController::class, 'publicConfig'])->middleware('throttle:60,1'); // MUL-214 pos
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:3,1'); // max 3 cadastros/min por IP
Route::post('/register-tiktok-free', [AuthController::class, 'registerTikTokFree'])->middleware('throttle:5,1'); // SEL-082 F1

// SEL-227 Ruan 18/07 — telemetry device fingerprint anti-fraude
Route::post('/v1/telemetry/device', [\App\Http\Controllers\Api\V1\TelemetryDeviceController::class, 'record'])
    ->middleware('throttle:30,1');
Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);

// =========================================================================
// API V1 — Protegida por Sanctum
// =========================================================================
Route::middleware(['auth:sanctum', 'check.user.active', 'supplier.panel'])->prefix('v1')->group(function () { // MUL-151

    // Perfil do usuário autenticado
    Route::get('/me', MeController::class);
    // SEL-082 F7: questionario onboarding
    Route::get('/me/survey', [\App\Http\Controllers\Api\V1\SurveyController::class, 'show']);
    Route::post('/me/survey', [\App\Http\Controllers\Api\V1\SurveyController::class, 'store']);
    Route::post('/me/survey/skip', [\App\Http\Controllers\Api\V1\SurveyController::class, 'skip']);
    // SEL-171: bonus 50% silencioso nas 3 primeiras vendas do catalogo
    Route::get('/me/bonus-status', [\App\Http\Controllers\Api\V1\CatalogBonusController::class, 'meStatus']);
    Route::get('/me/integrations/status', [\App\Http\Controllers\Api\V1\IntegrationStatusController::class, 'index']); // FOR-081
    // MUL-142-H: IA por WL — endpoints autenticados com chave OpenAI do supplier
    Route::post('/ai/generate-listing', [\App\Http\Controllers\Api\V1\AiListingController::class, 'generateListing']);
    Route::post('/ai/generate-image',   [\App\Http\Controllers\Api\V1\AiListingController::class, 'generateImage']);
    Route::post('/ai/generate-carousel', [\App\Http\Controllers\Api\V1\AiListingController::class, 'generateCarousel']); // MUL-161-BE1 #11a
    Route::post('/ai/suggest-kits',      [\App\Http\Controllers\Api\V1\AiListingController::class, 'suggestKits']);      // MUL-161-BE1 #11b


    // Lojas (MarketplaceAccounts)
    Route::get('/stores', [StoreController::class, 'index']);
    Route::post('/stores', [StoreController::class, 'store']);
    Route::get('/stores/{id}', [StoreController::class, 'show']);
    Route::put('/stores/{id}', [StoreController::class, 'update']);
    Route::delete('/stores/{id}', [StoreController::class, 'destroy']);
    Route::post('/stores/{id}/reconnect', [StoreController::class, 'reconnect']);
    Route::get('/stores/{id}/bling-channels', [StoreController::class, 'blingChannels']);

    // Fornecedores
    Route::get('/suppliers', [SupplierController::class, 'index']);
    Route::get('/suppliers/{id}/catalog', [SupplierController::class, 'catalog']);
    Route::get('/suppliers/{id}/catalog/categories', [SupplierController::class, 'catalogCategories']);
    Route::post('/catalog/products/{id}/ai-generate', [SupplierController::class, 'catalogAiGenerate'])->whereNumber('id');
    // MUL-103: gerar titulo IA para produto do catalogo (sem ClientProduct)
    Route::post('/catalog/products/{id}/generate-title', [SupplierController::class, 'catalogGenerateTitle'])->whereNumber('id');
    // MUL-104: gerar descricao IA para produto do catalogo (sem ClientProduct)
    Route::post('/catalog/products/{id}/generate-description', [SupplierController::class, 'catalogGenerateDescription'])->whereNumber('id');

    // Produtos do lojista (ClientProducts)
    Route::post('/products/batch-publish', [ProductController::class, 'batchPublish']);
    Route::get('/products', [ProductController::class, 'index']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::get('/products/{id}', [ProductController::class, 'show']);
    Route::put('/products/{id}', [ProductController::class, 'update']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);

    // Imagens do produto (product_media type=image) — ML: até 12 | Shopee: até 9
    Route::post('/products/{id}/images', [ProductController::class, 'uploadImage']);
    Route::delete('/products/{id}/images/{imageId}', [ProductController::class, 'deleteImage']);
    Route::put('/products/{id}/images/reorder', [ProductController::class, 'reorderImages']);

    // Vídeo do produto — ML: YouTube URL | Shopee: upload via API própria (pendência)
    Route::post('/products/{id}/video', [ProductController::class, 'setVideo']);
    Route::delete('/products/{id}/video', [ProductController::class, 'removeVideo']);
    // MUL-106: upload de video MP4 para ClientProduct (ate 50MB)
    Route::post('/products/{id}/upload-video', [ProductController::class, 'uploadVideo'])->whereNumber('id');
    // MUL-106: gerar video com IA (stub -- Kling API key pendente)
    Route::post('/products/{id}/generate-video', [ProductController::class, 'generateVideo'])->whereNumber('id');

    // NOV-117 -- Historico de movimentacoes de estoque por produto
    Route::get('/products/{product}/inventory-movements', [\App\Http\Controllers\Api\V1\InventoryMovementController::class, 'index'])->whereNumber('product');

    // Variações do produto
    Route::get('/products/{id}/variations', [ProductController::class, 'listVariations']);
    Route::patch('/client-products/{cpId}/price', [ProductController::class, 'updateClientProductPrice'])->whereNumber('cpId'); // MUL-115
    Route::post('/products/{id}/variations', [ProductController::class, 'storeVariation']);
    Route::put('/products/{id}/variations/{vid}', [ProductController::class, 'updateVariation']);
    Route::delete('/products/{id}/variations/{vid}', [ProductController::class, 'deleteVariation']);

    // NOV-136: branding self-service
    Route::get("/supplier/branding", [\App\Http\Controllers\Api\V1\SupplierBrandingController::class, "show"]);

    
    // NOV-138: relatorio PIX por dia
    Route::get("/supplier/reports/pix-by-day", [\App\Http\Controllers\Api\V1\PixReportController::class, "pixByDay"]);
// NOV-126: scanner de remessas para recebimento
    Route::get("/supplier/shipments", [\App\Http\Controllers\Api\V1\SupplierShipmentController::class, "index"]);
    Route::post("/supplier/shipments/{id}/scan-item", [\App\Http\Controllers\Api\V1\SupplierShipmentController::class, "scanItem"])->whereNumber("id");

    // NOV-121: dashboard operacional do fornecedor
    Route::get('/supplier/dashboard-stats', [\App\Http\Controllers\Api\V1\SupplierDashboardController::class, 'stats']);

    // IA - Geracao de Conteudo
    Route::post('/products/{id}/generate-title',       [ProductController::class, 'generateTitle']);
    Route::post('/products/{id}/generate-description', [ProductController::class, 'generateDescription']);
    Route::post('/products/{id}/generate-bullets',     [ProductController::class, 'generateBullets']);
    Route::post('/products/{id}/suggest-category',     [ProductController::class, 'suggestCategory']);
    // MUL-100: Categorias do catálogo do supplier local (todas, com contagem de produtos)
    Route::get('/categories', [ProductController::class, 'categories']);

    // MUL-119: Produtos do lojista agrupados por SKU (multi-marketplace view)
    Route::get('/client/products-grouped-by-sku', [ProductController::class, 'groupedBySku']);

    Route::get('/client/my-products',                   [ProductController::class, 'myProducts']);
    Route::get('/client/product-count', ClientProductCountController::class);
    // MUL-118
    Route::post('/client-products/{id}/sync-stock', [ClientProductSyncController::class, 'syncStock'])->whereNumber('id');
    Route::post('/client-products/sync-stock-all', [ClientProductSyncController::class, 'syncStockAll']);
    Route::get('/client/discount-info',              [ClientDiscountController::class, 'show']);
    Route::post('/products/{id}/auto-fill-attributes',  [ProductController::class, 'autoFillAttributes'])->whereNumber('id');

    // Pedidos — client.required garante Client record antes de qualquer acesso (MUL-FIX-2)
    Route::middleware('client.required')->group(function () {
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/summary', [OrderController::class, 'summary']);
        Route::get('/orders/revenue', [OrderController::class, 'revenue']);
        Route::get('/orders/top-products', [OrderController::class, 'topProducts']);
        Route::get('/orders/filters', [OrderController::class, 'filters']); // MUL-161-BE1 #29
        Route::get('/orders/importable-accounts', [\App\Http\Controllers\Api\V1\OrderImportController::class, 'accounts']);
        Route::post('/orders/import-by-number',   [\App\Http\Controllers\Api\V1\OrderImportController::class, 'import']);
        Route::get('/orders/check-import',         [\App\Http\Controllers\Api\V1\OrderImportController::class, 'checkImport']);
        Route::get('/orders/pay-status', [OrderController::class, 'payStatus']); // MUL-252
        Route::post('/orders/fetch-by-id',           [\App\Http\Controllers\Api\V1\OrderImportController::class, 'fetchById']);
        Route::get('/orders/{id}', [OrderController::class, 'show']);
    });

    // Financeiro
    Route::get('/financial/balance', [FinancialController::class, 'balance']);
    Route::get('/financial/transactions', [FinancialController::class, 'transactions']);
    Route::get('/financial/summary', [FinancialController::class, 'summary']);
    Route::get('/financial/balance-history', [FinancialController::class, 'balanceHistory']);

    // Carteira (wallet topup via PIX)
    Route::post('/financial/deposit', [WalletController::class, 'deposit']);
    Route::get('/financial/deposit/{id}/status', [WalletController::class, 'depositStatus']);
    Route::get('/financial/deposits', [WalletController::class, 'deposits']);
    Route::post('/financial/pay-with-balance', [WalletController::class, 'payWithBalance']);
    Route::post('/financial/pay-partial', [WalletController::class, 'payPartial']);

    // Configuracoes de auto-pay via wallet
    Route::get('/settings/auto-pay', [SettingsController::class, 'getAutoPay']);
    Route::put('/settings/auto-pay', [SettingsController::class, 'updateAutoPay']);

    // Perfil do lojista
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::put('/password', [ProfileController::class, 'changePassword']);

    // Planos e assinatura
    Route::get('/plans', [PlanController::class, 'index']);
    Route::get('/subscription', [PlanController::class, 'subscription']);

    // Acoes em pedidos — client.required garante Client record (MUL-FIX-2)
    Route::middleware('client.required')->group(function () {
        // MUL-227 item 29: seller bloqueia proprio pedido (com reembolso auto se pago pela carteira)
        Route::post('/orders/{id}/block',   [OrderController::class, 'blockOrder']);
        Route::delete('/orders/{id}/block', [OrderController::class, 'unblockOrder']);
        Route::post('/orders/{id}/label', [OrderController::class, 'generateLabel']);
        Route::post('/orders/{id}/invoice', [OrderController::class, 'addInvoice']);
        Route::post('/orders/{id}/ship', [OrderController::class, 'markShipped']);
        Route::post('/orders/{id}/pay',  [OrderController::class, 'pay']);
        Route::patch('/orders/{id}/notes', [OrderController::class, 'updateNotes']); // MUL-112
        Route::patch('/orders/{id}/expedition-note', [OrderController::class, 'updateExpeditionNote']); // MUL-142-E #15
        Route::post('/orders/{id}/expedition-note/read', [OrderController::class, 'markExpeditionNoteRead']); // MUL-142-E #15
        Route::post('/orders/{id}/swap-product', [OrderSwapProductController::class, 'swap'])->whereNumber('id'); // MUL-108
        // MUL-298: edicao de pedido por item (liberada enquanto wallet_paid_at IS NULL)
        Route::post('/orders/{id}/items', [\App\Http\Controllers\Api\V1\OrderItemsController::class, 'store'])->whereNumber('id');
        Route::patch('/orders/{id}/items/{itemId}', [\App\Http\Controllers\Api\V1\OrderItemsController::class, 'update'])->whereNumber(['id','itemId']);
        Route::delete('/orders/{id}/items/{itemId}', [\App\Http\Controllers\Api\V1\OrderItemsController::class, 'destroy'])->whereNumber(['id','itemId']);
        Route::get('/orders/{id}/swap-catalog', [OrderSwapProductController::class, 'catalog'])->whereNumber('id'); // MUL-267

        // Etiqueta/NF via bridge Goolhub (Fase 3 — legado e dono da API
        // do marketplace; novo le do banco + enfileira on-demand quando vazio).
        Route::get('/orders/{id}/label-info',    [\App\Http\Controllers\Api\V1\OrderLabelInvoiceController::class, 'show']);
        Route::get('/orders/{id}/label-file',    [\App\Http\Controllers\Api\V1\OrderLabelInvoiceController::class, 'file'])->whereNumber('id'); // MUL-359
        Route::post('/orders/{id}/label-fetch',  [\App\Http\Controllers\Api\V1\OrderLabelInvoiceController::class, 'requestLabel']);
        Route::get('/orders/{id}/invoice-info',  [\App\Http\Controllers\Api\V1\OrderLabelInvoiceController::class, 'invoice']);

        // Packing Slip — etiqueta de conferencia interna com foto + SKU (NOV-091)
        Route::get('/orders/{id}/packing-slip',    [\App\Http\Controllers\Api\V1\PackingSlipController::class, 'show'])->name('api.v1.orders.packing-slip');
        Route::post('/orders/{id}/packing-slip',   [\App\Http\Controllers\Api\V1\PackingSlipController::class, 'generate']);

        // Etiqueta manual - lojista sobe PDF proprio (canal manual, NOV-fix)
        Route::post('/orders/{id}/manual-label', [ManualOrderController::class, 'uploadLabel'])->whereNumber('id');
        
        // Abrir disputa para pedido entregue (MUL-109)
        Route::post("/orders/{id}/dispute", [OrderDisputeController::class, "dispute"])->whereNumber("id");
        Route::get("/orders/{id}/dispute/available-notes", [OrderDisputeController::class, "availableNotes"])->whereNumber("id"); // MUL-161-BE1 #13a
        Route::post("/orders/{id}/dispute/import-note",    [OrderDisputeController::class, "importNote"])->whereNumber("id");    // MUL-161-BE1 #13b


        // Pagamento de pedido manual (canal manual) via wallet/PIX (NOV-099)
        Route::post('/orders/{id}/manual-payment', [ManualOrderController::class, 'payManual'])->whereNumber('id');
        Route::post('/orders/{id}/confirm-external-payment', [ManualOrderController::class, 'confirmExternalPayment'])->whereNumber('id'); // NOV-207 E3
        Route::post('/orders/{id}/revert-external-payment', [ManualOrderController::class, 'revertExternalPayment'])->whereNumber('id'); // NOV-207 E3
        Route::post('/orders/{id}/force-charge', [ManualOrderController::class, 'forceCharge'])->whereNumber('id'); // MUL-254
        Route::post('/orders/force-charge-batch', [ManualOrderController::class, 'forceChargeBatch']); // MUL-277
        Route::get('/orders/bling-sync-status',   [ManualOrderController::class, 'blingSyncStatus']); // MUL-277
        Route::post('/orders/bling-sync-status',  [ManualOrderController::class, 'blingSyncStatus']); // MUL-278: listas grandes via body
        Route::post('/orders/{id}/revert-forced-charge', [ManualOrderController::class, 'revertForcedCharge'])->whereNumber('id'); // MUL-254B
    });

    // Importacao manual de pedido por numero (Feature B — Fase 4).

    // Chamados/Tickets (Fase 5 — substitui localStorage do /chamados)
    Route::get('/tickets',                  [\App\Http\Controllers\Api\V1\TicketController::class, 'index']);
    Route::post('/tickets',                 [\App\Http\Controllers\Api\V1\TicketController::class, 'store']);
    Route::get('/tickets/{id}',             [\App\Http\Controllers\Api\V1\TicketController::class, 'show']);
    Route::put('/tickets/{id}',             [\App\Http\Controllers\Api\V1\TicketController::class, 'update']);
    Route::post('/tickets/{id}/messages',   [\App\Http\Controllers\Api\V1\TicketController::class, 'storeMessage']);
    Route::post('/tickets/{id}/upload',     [\App\Http\Controllers\Api\V1\TicketController::class, 'uploadImage']);
    Route::post('/tickets/{id}/rate',       [\App\Http\Controllers\Api\V1\TicketController::class, 'rate']);

    // Kits (MUL-142-G — SKU auto, catalog picker, enriched items)
    Route::get('/kits',          [\App\Http\Controllers\Api\V1\KitController::class, 'index']);
    Route::get('/kits/catalog',   [\App\Http\Controllers\Api\V1\KitController::class, 'catalog']);
    Route::post('/kits',         [\App\Http\Controllers\Api\V1\KitController::class, 'store']);
    Route::post('/kits/{id}/ensure-product', [\App\Http\Controllers\Api\V1\KitController::class, 'ensureProduct']); // MUL-214 item 28
    Route::get('/kits/{id}',     [\App\Http\Controllers\Api\V1\KitController::class, 'show']);
    Route::put('/kits/{id}',     [\App\Http\Controllers\Api\V1\KitController::class, 'update']);
    Route::delete('/kits/{id}',  [\App\Http\Controllers\Api\V1\KitController::class, 'destroy']);

    // Tools - Ferramentas utilitarias
    Route::get('/tools/trends', [\App\Http\Controllers\Api\V1\ToolsController::class, 'trends']);
    // Notas Fiscais (Fase 5 — lista orders.invoice_* preenchidos)
    // MUL-227 item 31 Fase 4 — usuarios secundarios do dono da conta (RBAC)
    Route::get('/sub-users',       [\App\Http\Controllers\Api\V1\SubUserController::class, 'index']);
    Route::post('/sub-users',      [\App\Http\Controllers\Api\V1\SubUserController::class, 'store']);
    Route::put('/sub-users/{id}',  [\App\Http\Controllers\Api\V1\SubUserController::class, 'update']);
    Route::delete('/sub-users/{id}', [\App\Http\Controllers\Api\V1\SubUserController::class, 'destroy']);
    // MUL-227 item 30 — Menu Fulfillment (contratos de armazenamento/preparo)
    Route::get('/fulfillment',        [\App\Http\Controllers\Api\V1\FulfillmentController::class, 'index']);
    Route::post('/fulfillment',       [\App\Http\Controllers\Api\V1\FulfillmentController::class, 'store']);
    Route::put('/fulfillment/{id}',   [\App\Http\Controllers\Api\V1\FulfillmentController::class, 'update']);
    // MUL-227 item 28 — sininho seller: preferencias personalizadas por categoria/canal/janela
    Route::get('/me/notification-prefs',  [\App\Http\Controllers\Api\V1\NotificationPrefsController::class, 'show']);
    Route::put('/me/notification-prefs',  [\App\Http\Controllers\Api\V1\NotificationPrefsController::class, 'update']);

    Route::get('/invoices',      [\App\Http\Controllers\Api\V1\InvoiceController::class, 'index']);

    // Painel admin do FORNECEDOR (MultDrop) — substitui o /admin "super_admin sistema" antigo
    Route::get('/supplier-admin/dashboard', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'dashboard']);
    Route::get('/supplier-admin/orders',    [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'orders']);
    // MUL-213 #1 — opções dinâmicas dos filtros (canais de envio / marketplaces)
    Route::get('/supplier-admin/orders/filters',      [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'orderFilters']);
    // NOV-112 B4: dashboard endpoints para supplier_admin
    Route::get('/supplier-admin/orders/summary',      [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'ordersSummary']);
    Route::get('/supplier-admin/orders/revenue',      [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'ordersRevenue']);
    // MUL-222 item 3: 2FA TOTP endpoints
    Route::get('/supplier-admin/2fa/status', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'twoFactorStatus']);
    Route::post('/supplier-admin/2fa/setup', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'twoFactorSetup']);
    Route::post('/supplier-admin/2fa/confirm', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'twoFactorConfirm']);
    Route::post('/supplier-admin/2fa/disable', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'twoFactorDisable']);
    // MUL-222 item 5: Central de Notificações
    Route::get('/supplier-admin/notifications-admin', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'notificationsList']);
    Route::post('/supplier-admin/notifications-admin', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'notificationsStore']);
    Route::patch('/supplier-admin/notifications-admin/{id}', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'notificationsUpdate'])->whereNumber('id');
    Route::delete('/supplier-admin/notifications-admin/{id}', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'notificationsDelete'])->whereNumber('id');
    Route::get('/notifications-feed', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'notificationsFeed']);
    Route::get('/supplier-admin/report/top-sellers', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'reportTopSellers']); // MUL-222 item 6
    Route::get('/supplier-admin/report/top-products', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'reportTopProducts']); // MUL-222 item 7
    Route::get('/supplier-admin/disputes', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'adminListDisputes']); // MUL-222 item 12
    Route::patch('/supplier-admin/disputes/{id}', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'adminUpdateDispute'])->whereNumber('id'); // MUL-222 item 12
    Route::get('/supplier-admin/bling-export-report', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'blingExportReport']); // MUL-222 item 4
    Route::post('/supplier-admin/bling-export-report/{logId}/resend', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'resendBlingExport'])->whereNumber('logId'); // MUL-222 item 4
    Route::post('/supplier-admin/products/{id}/inflate-stock', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'inflateStock'])->whereNumber('id'); // MUL-222 item 16
    Route::post('/supplier-admin/products/{id}/reserve-stock', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'reserveStock'])->whereNumber('id'); // MUL-222 item 16
    Route::get('/supplier-admin/api-keys', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'apiKeysStatus']); // MUL-214 pos
    Route::post('/supplier-admin/api-keys', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'apiKeysSave']); // MUL-214 pos
    Route::get('/supplier-admin/bling-queue/status', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'blingQueueStatus']); // MUL-222 item 1
    Route::post('/supplier-admin/bling-queue/toggle', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'setBlingQueueStatus']); // MUL-222 item 1
    Route::get('/supplier-admin/orders/top-products', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'ordersTopProducts']);
    // MUL-197 — Rascunhos: pull manual em massa (rota estatica ANTES de /orders/{id})
    Route::post('/supplier-admin/orders/pull-integration', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'pullOrdersFromIntegrationBulk']);
    Route::get('/supplier-admin/orders/{id}', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'showOrder'])->whereNumber('id');
    // MUL-197 — Rascunhos: edicao admin (whitelist) + pull manual por pedido
    Route::patch('/supplier-admin/orders/{id}', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'updateOrderAdmin'])->whereNumber('id');
    Route::post('/supplier-admin/orders/{id}/pull-integration', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'pullOrderFromIntegration'])->whereNumber('id');
    Route::get('/supplier-admin/products',  [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'products']);
    Route::post('/supplier-admin/products',                  [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'createProduct']);
    Route::delete('/supplier-admin/products/{id}',           [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'deleteProduct'])->whereNumber('id');
    Route::put('/supplier-admin/products/{id}',              [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'updateProduct'])->whereNumber('id');
    Route::post('/supplier-admin/products/import',           [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'importProducts']);
    Route::put('/supplier-admin/products/{id}/stock',         [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'updateStock'])->whereNumber('id');
    // MUL-152 -- Ajuste manual de estoque com auditoria
    Route::post('/supplier-admin/products/{id}/stock-adjust', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'stockAdjust'])->whereNumber('id'); // MUL-152
    Route::get('/supplier-admin/products/{id}/stock-info',    [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'stockInfo'])->whereNumber('id');    // MUL-152
    // NOV-117 -- Historico de movimentacoes de estoque de um produto
    Route::get('/supplier-admin/products/{id}/stock-movements',  [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'productStockMovements'])->whereNumber('id');
    // NOV-110 -- Localizacao produto no armazem
    Route::get('/supplier-admin/products/{id}/location',    [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'getProductLocation'])->whereNumber('id');
    Route::patch('/supplier-admin/products/{id}/location',  [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'updateProductLocation'])->whereNumber('id');
    Route::patch('/supplier-admin/products/{id}/toggle-active',[\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'toggleActive'])->whereNumber('id');
    Route::get('/supplier-admin/ai-settings',                  [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'aiSettings']);
    Route::put('/supplier-admin/ai-settings',                  [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'updateAiSettings']);
    Route::post('/supplier-admin/products/{id}/ai-generate',   [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'aiGenerate'])->whereNumber('id');

    // NOV-127 — Smart Repricing (config de custos por marketplace + calculadora)
    Route::get('/supplier/repricing-configs',          [\App\Http\Controllers\Api\V1\RepricingCostConfigController::class, 'index']);
    Route::post('/supplier/repricing-configs',         [\App\Http\Controllers\Api\V1\RepricingCostConfigController::class, 'store']);
    Route::get('/supplier/repricing-configs/{id}',    [\App\Http\Controllers\Api\V1\RepricingCostConfigController::class, 'show'])->whereNumber('id');
    Route::put('/supplier/repricing-configs/{id}',    [\App\Http\Controllers\Api\V1\RepricingCostConfigController::class, 'update'])->whereNumber('id');
    Route::delete('/supplier/repricing-configs/{id}', [\App\Http\Controllers\Api\V1\RepricingCostConfigController::class, 'destroy'])->whereNumber('id');
    Route::post('/supplier/repricing-configs/calculate', [\App\Http\Controllers\Api\V1\RepricingCostConfigController::class, 'calculate']);

    // Sprint G — Clientes do fornecedor (com marketplaces + extrato)
    Route::get('/supplier-admin/clients',                       [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'listClients']);
    Route::get('/supplier-admin/clients/{id}',                  [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'showClient'])->whereNumber('id');
    Route::get('/supplier-admin/clients/{id}/transactions',     [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'clientTransactions'])->whereNumber('id');
    Route::post('/supplier-admin/clients/{id}/wallet-adjust', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'walletAdjust'])->whereNumber('id'); // MUL-215
    Route::patch('/supplier-admin/clients/{id}',              [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'updateClientFull'])->whereNumber('id');
    Route::patch('/supplier-admin/clients/{id}/blocked',       [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'updateClientBlocked'])->whereNumber('id');
    // MUL: admin troca plano do seller + lista planos disponiveis
    Route::post('/supplier-admin/clients/{id}/change-plan',    [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'changePlan'])->whereNumber('id');
    Route::get('/supplier-admin/plans/available',              [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'plansAvailable']);
    Route::patch('/supplier-admin/clients/{id}/phone',         [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'updateClientPhone'])->whereNumber('id');
    Route::get('/supplier-admin/sectors',                       [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'listSectors']);
    Route::post('/supplier-admin/sectors',                      [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'storeSector']);
    Route::put('/supplier-admin/sectors/{id}',                  [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'updateSector'])->whereNumber('id');
    Route::delete('/supplier-admin/sectors/{id}',               [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'destroySector'])->whereNumber('id');
    Route::get('/supplier-admin/operators',                     [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'listOperatorsCad']);
    Route::post('/supplier-admin/operators',                    [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'storeOperator']);
    Route::put('/supplier-admin/operators/{id}',                [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'updateOperator'])->whereNumber('id');
    Route::delete('/supplier-admin/operators/{id}',             [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'destroyOperator'])->whereNumber('id');
    Route::get('/supplier-admin/finance/transactions',          [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'financeTransactions']);
    Route::get('/supplier-admin/finance/summary',               [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'financeSummary']);

    // Picking / Packing (scanner por rastreio)
    Route::get('/supplier-admin/picking/queue',    [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'pickingQueue']);
    Route::get('/supplier-admin/picking/separacao', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'pickingSeparacao']);
    // NOV-110 -- Relatorio de separacao com filtros
    Route::get('/supplier-admin/picking/separation-report', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'pickingSeparationReport']);
    Route::get('/supplier-admin/picking/lookup',     [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'pickingLookup']);
    Route::get('/supplier-admin/picking/lookup_cep', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'pickingLookupCep']);
    Route::post('/supplier-admin/picking/ship',    [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'pickingShip']);
    Route::post('/supplier-admin/picking/skip',    [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'pickingSkip']);
    Route::post('/supplier-admin/picking/problema',[\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'pickingProblema']);
    // MUL-043 / NOV-075 -- Impressao em lote de etiquetas + historico
    Route::post('/supplier-admin/picking/print-batch',  [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'printBatch']);
        Route::get('/proxy/storage/labels/{filename}', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'proxyStorageLabel'])->where('filename', '[a-zA-Z0-9._-]+\\.(pdf|jpg|jpeg|png|gif|webp)'); // JT-008 + MUL-244
    Route::post('/supplier-admin/orders/mark-labels-printed', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'markLabelsPrinted']); // JT-008
    Route::get('/supplier-admin/picking/print-history', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'printHistory']);
    // NOV-096 -- Etiqueta combinada (cabecalho HubAI + etiqueta marketplace)
    Route::get('/supplier-admin/orders/{orderId}/combined-label',  [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'combinedLabel'])->whereNumber('orderId');
    // MUL-445: versao em imagem da etiqueta combinada (o QZ Tray nao imprime HTML)
    Route::get('/supplier-admin/orders/{orderId}/combined-label-image', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'combinedLabelImage'])->whereNumber('orderId');
    Route::post('/supplier-admin/picking/print-batch-combined',    [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'printBatchCombined']);
    Route::get('/supplier-admin/print-settings',                   [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'printSettings']);
    Route::put('/supplier-admin/print-settings',                   [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'updatePrintSettings']);
    // MUL-244 — QZ Tray via token (frontend Lovable): cert + sign + mark-printed
    Route::get('/supplier-admin/qz/certificate',   [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'qzCertificate']);
    Route::get('/supplier-admin/qz/sign',          [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'qzSign']);
    Route::post('/supplier-admin/qz/mark-printed', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'qzMarkPrinted']);
    Route::get('/supplier-admin/packing-stats',        [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'packingStats']);
    Route::post('/supplier-admin/verify-supervisor',   [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'verifySupervisor']);
    Route::post('/supplier-admin/packing-complete',    [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'packingComplete']);
    // Devolucoes (estorno conta_corrente_white via bridge legado)
    Route::get('/supplier-admin/returns/queue',          [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'returnsQueue']);
    Route::post('/supplier-admin/returns/{id}/approve',  [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'approveReturn'])->whereNumber('id');
    Route::post('/supplier-admin/returns/{id}/reject',   [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'rejectReturn'])->whereNumber('id');

    // Q&A de Produto
    Route::get('/supplier-admin/questions',         [\App\Http\Controllers\Api\V1\ProductQuestionController::class, 'supplierIndex']);
    Route::get('/supplier-admin/questions/count',   [\App\Http\Controllers\Api\V1\ProductQuestionController::class, 'pendingCount']);
    Route::patch('/supplier-admin/questions/{id}',  [\App\Http\Controllers\Api\V1\ProductQuestionController::class, 'answer'])->whereNumber('id');
    Route::patch('/supplier-admin/questions/{id}/visibility', [\App\Http\Controllers\Api\V1\ProductQuestionController::class, 'setVisibility'])->whereNumber('id'); // MUL-142-E #7
    Route::get('/products/{product_id}/questions',  [\App\Http\Controllers\Api\V1\ProductQuestionController::class, 'index'])->whereNumber('product_id');
    Route::get('/products/{product_id}/questions/public',  [\App\Http\Controllers\Api\V1\ProductQuestionController::class, 'index'])->whereNumber('product_id');
    Route::post('/products/{product_id}/questions', [\App\Http\Controllers\Api\V1\ProductQuestionController::class, 'store'])->whereNumber('product_id');

    // =========================================================================
    // NOV-140..148 — Features novas painel admin MEStoreDrop/WL
    // =========================================================================

    // NOV-140: Banners por supplier
    Route::get('/supplier/banners',                  [\App\Http\Controllers\Api\V1\SupplierBannerController::class, 'publicIndex']);
    Route::get('/supplier-admin/banners',            [\App\Http\Controllers\Api\V1\SupplierBannerController::class, 'adminIndex']);
    Route::post('/supplier-admin/banners',           [\App\Http\Controllers\Api\V1\SupplierBannerController::class, 'store']);
    Route::put('/supplier-admin/banners/{id}',       [\App\Http\Controllers\Api\V1\SupplierBannerController::class, 'update'])->whereNumber('id');
    Route::delete('/supplier-admin/banners/{id}',    [\App\Http\Controllers\Api\V1\SupplierBannerController::class, 'destroy'])->whereNumber('id');

    // NOV-141: SMTP per-WL
    Route::get('/supplier-admin/smtp-config',                 [\App\Http\Controllers\Api\V1\SupplierFeaturesController::class, 'smtpShow']);
    Route::put('/supplier-admin/smtp-config',                 [\App\Http\Controllers\Api\V1\SupplierFeaturesController::class, 'smtpUpdate']);
    Route::post('/supplier-admin/smtp-config/test',           [\App\Http\Controllers\Api\V1\SupplierFeaturesController::class, 'smtpTest']);
    Route::post('/supplier-admin/smtp-config/send-test',      [\App\Http\Controllers\Api\V1\SupplierFeaturesController::class, 'smtpSendTest']);

    // NOV-142: Planos (listar com contagem de usuarios)
    Route::get('/supplier-admin/plans',                       [\App\Http\Controllers\Api\V1\SupplierFeaturesController::class, 'plansIndex']);

    // NOV-143: Top clientes (relatorio)
    Route::get('/supplier-admin/reports/top-clients',         [\App\Http\Controllers\Api\V1\SupplierFeaturesController::class, 'topClients']);

    // NOV-144: Descontos por catalogo / faixa quantidade
    Route::get('/supplier-admin/catalog-discounts',           [\App\Http\Controllers\Api\V1\SupplierFeaturesController::class, 'discountsIndex']);
    Route::post('/supplier-admin/catalog-discounts',          [\App\Http\Controllers\Api\V1\SupplierFeaturesController::class, 'discountsStore']);
    Route::put('/supplier-admin/catalog-discounts/{id}',      [\App\Http\Controllers\Api\V1\SupplierFeaturesController::class, 'discountsUpdate'])->whereNumber('id');
    Route::delete('/supplier-admin/catalog-discounts/{id}',   [\App\Http\Controllers\Api\V1\SupplierFeaturesController::class, 'discountsDestroy'])->whereNumber('id');

    // NOV-145: Depositos / fornecedores parceiros do supplier
    Route::get('/supplier-admin/warehouses',                  [\App\Http\Controllers\Api\V1\SupplierFeaturesController::class, 'warehousesIndex']);
    Route::post('/supplier-admin/warehouses',                 [\App\Http\Controllers\Api\V1\SupplierFeaturesController::class, 'warehousesStore']);
    Route::put('/supplier-admin/warehouses/{id}',             [\App\Http\Controllers\Api\V1\SupplierFeaturesController::class, 'warehousesUpdate'])->whereNumber('id');
    Route::delete('/supplier-admin/warehouses/{id}',          [\App\Http\Controllers\Api\V1\SupplierFeaturesController::class, 'warehousesDestroy'])->whereNumber('id');

    // NOV-146: Validar PIX manualmente
    Route::post('/supplier-admin/pix/{id}/confirm-manual',    [\App\Http\Controllers\Api\V1\SupplierFeaturesController::class, 'pixConfirmManual'])->whereNumber('id');

    // NOV-147: 2FA obrigatorio por WL
    Route::put('/supplier-admin/security/two-factor-required',[\App\Http\Controllers\Api\V1\SupplierFeaturesController::class, 'twoFactorRequired']);

    // NOV-148: Log de mensagens (broadcast supplier->clientes)
    Route::get('/supplier-admin/messages',                    [\App\Http\Controllers\Api\V1\SupplierFeaturesController::class, 'messagesIndex']);
    Route::post('/supplier-admin/messages',                   [\App\Http\Controllers\Api\V1\SupplierFeaturesController::class, 'messagesStore']);
    Route::get('/supplier-admin/messages/{id}',               [\App\Http\Controllers\Api\V1\SupplierFeaturesController::class, 'messagesShow'])->whereNumber('id');


    // Gateway de pagamento do fornecedor (NOV-066)
    Route::get('/supplier-admin/payment-gateway',       [\App\Http\Controllers\Api\V1\SupplierGatewayController::class, 'show']);
    Route::post('/supplier-admin/payment-gateway',      [\App\Http\Controllers\Api\V1\SupplierGatewayController::class, 'upsert']);
    Route::delete('/supplier-admin/payment-gateway',    [\App\Http\Controllers\Api\V1\SupplierGatewayController::class, 'destroy']);
    Route::post('/supplier-admin/payment-gateway/test', [\App\Http\Controllers\Api\V1\SupplierGatewayController::class, 'test']);

    // Integracoes (lista + disconnect)
    Route::get('/supplier-admin/integrations',                    [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'integracoes']);
    Route::post('/supplier-admin/integrations/{id}/disconnect',   [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'disconnectIntegracao'])->whereNumber('id');

    // Acoes de pedido (cancel / cancel-label / refund / block / swap-sku)
    Route::post('/supplier-admin/orders/{id}/cancel',       [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'orderCancel'])->whereNumber('id');
    Route::post('/supplier-admin/orders/{id}/cancel-label', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'orderCancelLabel'])->whereNumber('id');
    Route::post('/supplier-admin/orders/{id}/refund',       [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'orderRefund'])->whereNumber('id');
    Route::post('/supplier-admin/orders/{id}/block',        [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'orderBlock'])->whereNumber('id');
    Route::delete('/supplier-admin/orders/{id}/block',      [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'orderBlock'])->whereNumber('id');
    Route::post('/supplier-admin/orders/{id}/swap-sku',     [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'orderSwapSku'])->whereNumber('id');
    // MUL-297: rota por item que o front do admin ja chama (devolvia 405)
    Route::post('/supplier-admin/orders/{id}/items/{itemId}/swap-sku', [\App\Http\Controllers\Api\V1\OrderItemsController::class, 'swapSkuAlias'])->whereNumber(['id','itemId']);
    // MUL-264/265: sync Bling do fornecedor + emissao manual de NF-e (saida/entrada)
    Route::post('/supplier-admin/orders/{id}/sync-bling',       [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'syncBling'])->whereNumber('id');
    Route::post('/supplier-admin/orders/{id}/emit-nfe',         [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'emitNfe'])->whereNumber('id');
    // MUL-274: config auto-sync Bling (pedido pago ao fornecedor -> Bling automatico)
    Route::get('/supplier-admin/erp/bling/config',              [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'blingConfig']);
    Route::post('/supplier-admin/erp/bling/config',             [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'setBlingConfig']);
    // Pagamento ao fornecedor (PIX ShiPay)
    Route::post('/supplier-admin/orders/{id}/pay-supplier',   [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'paySupplier'])->whereNumber('id');
    Route::get('/supplier-admin/orders/{id}/payment-status',  [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'paymentStatus'])->whereNumber('id');
    // MUL-251: confirmar/estornar recebimento externo pelo painel do fornecedor (NOV-207 E3)
    Route::post('/supplier-admin/orders/{id}/confirm-external-payment', [ManualOrderController::class, 'confirmExternalPayment'])->whereNumber('id');
    Route::post('/supplier-admin/orders/{id}/revert-external-payment',  [ManualOrderController::class, 'revertExternalPayment'])->whereNumber('id');
    Route::post('/supplier-admin/orders/{id}/force-charge',  [ManualOrderController::class, 'forceCharge'])->whereNumber('id'); // MUL-254
    Route::post('/supplier-admin/orders/force-charge-batch', [ManualOrderController::class, 'forceChargeBatch']); // MUL-277
    Route::get('/supplier-admin/orders/bling-sync-status',   [ManualOrderController::class, 'blingSyncStatus']); // MUL-277
    Route::post('/supplier-admin/orders/bling-sync-status',  [ManualOrderController::class, 'blingSyncStatus']); // MUL-278: listas grandes via body
    Route::post('/supplier-admin/orders/{id}/revert-forced-charge',  [ManualOrderController::class, 'revertForcedCharge'])->whereNumber('id'); // MUL-254B
    // NF-e e documento do comprador
    Route::patch('/supplier-admin/orders/{id}/invoice',         [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'updateInvoice'])->whereNumber('id');
    Route::get('/supplier-admin/orders/{id}/buyer-document',    [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'buyerDocument'])->whereNumber('id');

    // Variacoes de produto
    Route::get('/supplier-admin/products/{id}/variations',              [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'productVariations'])->whereNumber('id');
    Route::post('/supplier-admin/products/{id}/variations',             [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'createProductVariation'])->whereNumber('id');
    Route::put('/supplier-admin/products/{id}/variations/{varId}',      [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'updateProductVariation'])->whereNumber(['id','varId']);
    Route::delete('/supplier-admin/products/{id}/variations/{varId}',   [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'deleteProductVariation'])->whereNumber(['id','varId']);

    // Categorias dos marketplaces (proxy bridge legado)
    Route::get('/supplier-admin/categories',          [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'categoriesInternal']);
    Route::get('/supplier-admin/categories/shopee',          [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'categoriesShopee']);
    Route::get('/supplier-admin/categories/meli',            [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'categoriesMeli']);
    Route::get('/supplier-admin/categories/meli/attributes', [\App\Http\Controllers\Api\V1\SupplierAdminPanelController::class, 'categoryMeliAttributes']);

    // =========================================================================
    // Painel do Fornecedor (role=supplier)
    // =========================================================================
    Route::prefix('supplier')->group(function () {
        Route::get('/products', [SupplierPanelController::class, 'products']);
        Route::post('/products', [SupplierPanelController::class, 'storeProduct']);
        Route::put('/products/{id}', [SupplierPanelController::class, 'updateProduct']);
        Route::put('/products/{id}/stock', [SupplierPanelController::class, 'updateStock']);
        Route::get('/orders', [SupplierPanelController::class, 'orders']);
        Route::get('/dashboard', [SupplierPanelController::class, 'dashboard']);
        Route::get('/dashboard/chart', [SupplierPanelController::class, 'dashboardChart']);
        Route::get('/dashboard/top-products', [SupplierPanelController::class, 'dashboardTopProducts']);
        Route::get('/dashboard/orders-summary', [SupplierPanelController::class, 'dashboardOrdersSummary']);
        Route::get('/clients', [SupplierPanelController::class, 'clients']);
        Route::get('/client-products', [SupplierPanelController::class, 'clientProducts']);
        Route::get('/auto-listing', [SupplierPanelController::class, 'autoListingConfig']);
        Route::put('/auto-listing', [SupplierPanelController::class, 'updateAutoListingConfig']);
        Route::get('/settings', [SupplierPanelController::class, 'settings']);
        Route::put('/settings', [SupplierPanelController::class, 'updateSettings']);
        Route::get('/plans', [SupplierPanelController::class, 'plans']);
        Route::get('/legacy-clients', [SupplierPanelController::class, 'legacyClients']);
        // Financeiro do fornecedor
        Route::get('/financial/balance', [SupplierPanelController::class, 'financialBalance']);
        Route::get('/financial/transactions', [SupplierPanelController::class, 'financialTransactions']);
        // PIX do fornecedor (FOR-027) — hotfix IDOR + throttle no saque
        Route::get('/financial/pix/{supplier}', [SupplierPanelController::class, 'getSupplierPix']);
        Route::post('/financial/withdraw', [SupplierPanelController::class, 'requestWithdrawal'])
            ->middleware('throttle:5,1');
        // Chamados/Tickets — alias do /tickets para o painel do fornecedor (Fase 5)
        Route::get('/tickets',                [\App\Http\Controllers\Api\V1\TicketController::class, 'index']);
        Route::post('/tickets',               [\App\Http\Controllers\Api\V1\TicketController::class, 'store']);
        Route::get('/tickets/{id}',           [\App\Http\Controllers\Api\V1\TicketController::class, 'show']);
        Route::put('/tickets/{id}',           [\App\Http\Controllers\Api\V1\TicketController::class, 'update']);
        Route::post('/tickets/{id}/messages', [\App\Http\Controllers\Api\V1\TicketController::class, 'storeMessage']);
        Route::post('/tickets/{id}/upload',    [\App\Http\Controllers\Api\V1\TicketController::class, 'uploadImage']);
    });

    // =========================================================================
    // Configuracoes — Webhook Configs e Event Subscriptions
    // =========================================================================
    Route::apiResource('webhook-configs', WebhookConfigController::class)
        ->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('event-subscriptions', EventSubscriptionController::class)
        ->only(['index', 'store']);

    // =========================================================================
    // Marketplace — Conexao e Publicacao (direto ou via ponte goolhub)
    // =========================================================================
    Route::prefix('marketplace')->group(function () {
        Route::get('/connect/{platform}', [MarketplaceController::class, 'connect']);
        Route::post('/confirm', [MarketplaceController::class, 'confirmConnection']); // SEL-375: confirma conta OAuth pending_confirm
        Route::get('/status', [MarketplaceController::class, 'status']);
        Route::post('/{platform}/publish', [MarketplaceController::class, 'publish']);
        Route::get('/listing', [MarketplaceController::class, 'fetchListing']);
        Route::put('/listing', [MarketplaceController::class, 'updateListing']);
        Route::get('/accounts/{accountId}/stats', [MarketplaceController::class, 'accountStats']);
        Route::get('/accounts/{accountId}/health', [MarketplaceController::class, 'accountHealth']);
        Route::post('/accounts/{accountId}/sync', [MarketplaceController::class, 'accountSync']);
        // MUL-096: plano Bling do lojista
        Route::get('/orders', [MarketplaceOrdersController::class, 'index']);
    });

    // =========================================================================
    // MUL-096 — Bling: informacoes da conta do lojista
    // GET /api/v1/integrations/bling/{id}/plan
    // =========================================================================
    Route::prefix('integrations')->group(function () {
        Route::get('/bling/{id}/plan', [IntegrationBlingController::class, 'plan'])->whereNumber('id');
    });

    // =========================================================================
    // Pedidos Manuais — INSERT direto no legado (canal=13)
    // POST /api/v1/orders/manual
    // =========================================================================
    // POST /api/v1/orders/manual/preview (dry-run — antes da rota store para nao conflitar)
    Route::post('/orders/manual/preview', [ManualOrderController::class, 'preview']);

    Route::post('/orders/manual', [ManualOrderController::class, 'store']);

    // POST /api/v1/orders/search-marketplace
    Route::post('/orders/search-marketplace', [OrderSearchController::class, 'search']);


    // =========================================================================
    // Pedidos Perdidos (Reconciliacao) — deteccao de webhooks nao recebidos
    // GET    /api/v1/missed-orders
    // POST   /api/v1/missed-orders/refresh
    // POST   /api/v1/missed-orders/{id}/dismiss
    // =========================================================================
    Route::prefix('missed-orders')->group(function () {
        Route::post('refresh', [MissedOrderController::class, 'refresh']);
        Route::get('/', [MissedOrderController::class, 'index']);
        Route::post('{id}/dismiss', [MissedOrderController::class, 'dismiss']);
    });



    // SEL-391: ponte da extensao de LIVE (roda dentro do console do TikTok)
    Route::prefix('live')->group(function () {
        Route::get('/licenca', [\App\Http\Controllers\Api\V1\LiveController::class, 'licenca']);
        Route::post('/eventos', [\App\Http\Controllers\Api\V1\LiveController::class, 'eventos'])
            ->middleware('throttle:120,1');
        Route::get('/extensao', [\App\Http\Controllers\Api\V1\LiveController::class, 'baixarExtensao']);
    });


    // ShopeeStats — produtos recentes
    Route::get("/shopee/recent-products", [\App\Http\Controllers\Api\V1\ShopeeStatsController::class, "recentProducts"]);

    // Live Board — Simulation Config
    Route::get('/simulation/config',  [\App\Http\Controllers\Api\V1\SimulationController::class, 'show']);
    Route::put('/simulation/config',  [\App\Http\Controllers\Api\V1\SimulationController::class, 'update']);

    // SEL-048: Diretorio de Fornecedores (Lista de Fornecedores)
    // SEL-082 F9: throttle 60/min por cliente pra dificultar scraping massa.
    Route::get('/directory-suppliers', [\App\Http\Controllers\Api\V1\DirectorySupplierController::class, 'index'])->middleware('throttle:60,1');
    Route::get('/directory-suppliers/{slug}', [\App\Http\Controllers\Api\V1\DirectorySupplierController::class, 'show'])->middleware('throttle:30,1');

}); // End v1 middleware

// =========================================================================
// Afiliados — sem supplier.panel (SEL-345 + SEL-387 + SEL-420 fix)
// =========================================================================
Route::middleware(['auth:sanctum', 'check.user.active'])->prefix('v1')->group(function () {
    Route::prefix('affiliate')->group(function () {
        Route::post('/register', [AffiliateController::class, 'register']);
        Route::get('/me', [AffiliateController::class, 'me']);
        Route::get('/stats', [AffiliateController::class, 'stats']);
        Route::get('/referrals', [AffiliateController::class, 'referrals']);
        Route::get('/commissions', [AffiliateController::class, 'commissions']);
        Route::post('/withdrawals', [AffiliateController::class, 'requestWithdrawal']);
        Route::get('/withdrawals', [AffiliateController::class, 'withdrawals']);
        Route::put('/pix', [AffiliateController::class, 'updatePix']);
        Route::put('/profile', [AffiliateController::class, 'updateProfile']); // SEL-387
        Route::get('/sales', [AffiliateController::class, 'sales']);           // SEL-387
        // SEL-486c: galeria de videos gerados no painel do afiliado (+ excluir da visao dele)
        Route::get('/videos', [\App\Http\Controllers\Api\V1\AffiliateVideoController::class, 'index']);
        Route::delete('/videos/{id}', [\App\Http\Controllers\Api\V1\AffiliateVideoController::class, 'hide']);
        Route::post('/videos/{id}/restore', [\App\Http\Controllers\Api\V1\AffiliateVideoController::class, 'restore']);

        // SEL-CARTEIRA-AFILIADO (14/08) — carteira com QR code: o afiliado poe saldo
        // por PIX e usa esse saldo pra criar usuarios pagando METADE do plano.
        Route::get('/wallet', [\App\Http\Controllers\Api\V1\AffiliateWalletController::class, 'index']);
        Route::post('/wallet/deposit', [\App\Http\Controllers\Api\V1\AffiliateWalletController::class, 'depositar']);
        Route::get('/wallet/deposit/{id}', [\App\Http\Controllers\Api\V1\AffiliateWalletController::class, 'statusDoDeposito'])->whereNumber('id');
        Route::post('/wallet/create-user', [\App\Http\Controllers\Api\V1\AffiliateWalletController::class, 'criarUsuario']);

        // Controle do Ruan: quem pagou quanto, quanto tem de saldo, quanto saiu.
        Route::prefix('wallet-admin')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\AffiliateWalletController::class, 'adminLista']);
            Route::get('/{affiliateId}', [\App\Http\Controllers\Api\V1\AffiliateWalletController::class, 'adminExtrato'])->whereNumber('affiliateId');
            Route::post('/{affiliateId}/adjust', [\App\Http\Controllers\Api\V1\AffiliateWalletController::class, 'adminAjustar'])->whereNumber('affiliateId');
            Route::post('/deposit/{id}/confirm', [\App\Http\Controllers\Api\V1\AffiliateWalletController::class, 'adminConfirmarDeposito'])->whereNumber('id');
        });

        // Admin routes (auth + role check dentro do controller)
        Route::prefix('admin')->group(function () {
            Route::get('/', [AffiliateController::class, 'adminList']);
            Route::get('/stats', [AffiliateController::class, 'adminStats']);
            Route::post('/{id}/approve', [AffiliateController::class, 'adminApprove']);
            Route::post('/{id}/reject', [AffiliateController::class, 'adminReject']);
            Route::post('/{id}/suspend', [AffiliateController::class, 'adminSuspend']);
            Route::patch('/{id}/quotas', [AffiliateController::class, 'adminUpdateQuotas']);

            // SEL-GERENTE (09/08): classificar direto vs gerente, autorizar/revogar
            // video, atribuir gerente manualmente, listar gerentes, aprovar payout.
            Route::patch('/{id}/manager-classify', [\App\Http\Controllers\Api\V1\AffiliateManagerController::class, 'adminClassify']);
            Route::patch('/{id}/video-gen', [\App\Http\Controllers\Api\V1\AffiliateManagerController::class, 'adminVideoGen']);
            Route::patch('/{id}/assign-manager', [\App\Http\Controllers\Api\V1\AffiliateManagerController::class, 'adminAssignManager']);
            Route::get('/managers', [\App\Http\Controllers\Api\V1\AffiliateManagerController::class, 'adminManagersList']);
            Route::post('/managers/{id}/approve-payout', [\App\Http\Controllers\Api\V1\AffiliateManagerController::class, 'adminApprovePayout']);
        });

        // SEL-GERENTE (09/08): painel do GERENTE de afiliados (guarda-chuva).
        Route::prefix('manager')->group(function () {
            Route::post('/invite', [\App\Http\Controllers\Api\V1\AffiliateManagerController::class, 'invite']);
            Route::get('/invites', [\App\Http\Controllers\Api\V1\AffiliateManagerController::class, 'invites']);
            Route::get('/team', [\App\Http\Controllers\Api\V1\AffiliateManagerController::class, 'team']);
            // SEL-GERENTE-CRIA-CONTA (16/08): o gerente cria a conta do afiliado dele.
            // O convite antigo (POST /invite) so servia para quem JA tinha login.
            Route::post('/team', [\App\Http\Controllers\Api\V1\AffiliateManagerController::class, 'createMember']);
            Route::get('/team/{affiliateId}', [\App\Http\Controllers\Api\V1\AffiliateManagerController::class, 'teamMemberDetail'])->whereNumber('affiliateId');
            // SEL-GERENTE (09/08 tarde): gerente define a comissao de cada afiliado do time (0..pool)
            Route::patch('/team/{affiliateId}/commission', [\App\Http\Controllers\Api\V1\AffiliateManagerController::class, 'updateMemberCommission'])->whereNumber('affiliateId');
            Route::get('/overrides', [\App\Http\Controllers\Api\V1\AffiliateManagerController::class, 'overrides']);
            // SEL-GERENTE (09/08 tarde): serie diaria (30d) pro grafico "Minha venda ao vivo"
            Route::get('/sales-timeline', [\App\Http\Controllers\Api\V1\AffiliateManagerController::class, 'salesTimeline']);
        });

        // SEL-GERENTE (09/08): aceitar convite de gerente (usuario logado).
        Route::post('/invite/{token}/accept', [\App\Http\Controllers\Api\V1\AffiliateManagerController::class, 'acceptInvite']);
    });
});

// =========================================================================
// ROTAS LEGADAS / INTEGRAÇÕES (mantidas)
// =========================================================================
Route::prefix('v1')->group(function () {
    // Rota que o Mercado Livre bate via Webhook de Perguntas
    Route::post('/webhooks/mercadolivre/questions', [\App\Http\Controllers\Api\OpenAICopilotController::class, 'handleMercadoLivreQuestion']);

    // Rota Dinâmica para Gateways de Pagamento (Kiwify, Eduzz, etc)
    Route::post('/webhooks/pagamentos/{slug}', [\App\Http\Controllers\Api\DynamicWebhookController::class, 'handle']);
}); // End v1 legacy

// SEL-345: Cadastro publico de afiliado (sem auth)
Route::post('/v1/affiliates/apply', [\App\Http\Controllers\Api\V1\AffiliateController::class, 'apply']);

// Redirect rastreado de afiliado (rota publica)
Route::get('/r/{code}', [AffiliateController::class, 'track']);

// SEL-GERENTE (09/08): preview publico do convite de gerente (antes do login/cadastro)
Route::get('/v1/affiliate/invite/{token}', [\App\Http\Controllers\Api\V1\AffiliateManagerController::class, 'inviteInfo']);

// OAUTH2 Centralizado - Múltiplas Conexões independentes p/ o painel novo
// Middleware web para sessao Filament. Bearer token e resolvido manualmente no controller.
// client_id pode vir de: sessao web autenticada, Bearer token, ou query param.
Route::withoutMiddleware([\Illuminate\Auth\Middleware\Authenticate::class, \Filament\Http\Middleware\Authenticate::class])->middleware(["web"])->group(function () {
    Route::get('/oauth/{platform}/redirect', [\App\Http\Controllers\Api\OAuthController::class, 'redirect']);
    Route::get('/oauth/{platform}/callback', [\App\Http\Controllers\Api\OAuthController::class, 'callback']);
});


// SEL-182 pentest fix: endpoints /public/marketplace-* aceitavam client_id/
// account_id via query e expunham dados sensiveis do lojista (account_name,
// seller_id, ML profile completo com CNPJ/email/reputacao), permitiam LEITURA
// de anuncios em conta alheia e ate ESCRITA (updateMarketplaceListing) sem
// nenhuma autenticacao. Agora exige X-Internal-Key (mesma chave usada pelas
// bridges hubai.io/goolhub.io) + throttle.
Route::middleware(['internal.key', 'throttle:120,1'])->group(function () {
    Route::get('/public/marketplace-accounts', [PublicApiController::class, 'marketplaceAccounts']);
    Route::get('/public/marketplace-items', [PublicApiController::class, 'marketplaceItems']);
    Route::get('/public/marketplace-listings/{accountId}/{itemId}', [PublicApiController::class, 'getMarketplaceListing']);
    Route::put('/public/marketplace-listings/{accountId}/{itemId}', [PublicApiController::class, 'updateMarketplaceListing']);
    Route::get('/public/marketplace-listings/{accountId}/{itemId}/score', [PublicApiController::class, 'getMarketplaceScore']);
});



// =========================================================================
// ENDPOINTS PUBLICOS — Catalogo de Fornecedores e Produtos
// Usados pelo frontend hubai.io e admin
// =========================================================================

// Lista fornecedores com contagem de produtos
Route::get('/public/suppliers', [PublicApiController::class, 'suppliers']);

// Catalogo paginado de um fornecedor (com imagens)
Route::get('/public/catalog/{supplier_id}', [PublicApiController::class, 'catalog']);

// NOV-CLIENT-INTEGRATIONS: integracoes do cliente para o painel Lovable
// Aceita client_id real OU legacy_id_login. Retorna so campos safe (sem tokens).
// Fix bug 401: Lovable passava legacy_id_login no lugar de clients.id.
Route::middleware('throttle:60,1')->get('/public/client-integrations', [PublicApiController::class, 'clientIntegrations']);


// Webhook do legado — sync produto/estoque em tempo real
// Tipos: stock (estoque), product (criar/atualizar), delete (remover)
Route::post('/webhooks/legacy-sync', [PublicApiController::class, 'legacySync']);

// Alias pra retrocompatibilidade (endpoint antigo de estoque)
Route::post('/webhooks/legacy-stock', [PublicApiController::class, 'legacyStock']);

// Webhook Shipay Wallet — credita saldo na carteira do lojista
Route::post('/webhooks/shipay/wallet', [WalletController::class, 'webhookShipay']);

// Webhook Asaas (Pagamentos PIX / Boleto / Assinaturas)
// INF-068 rollback Asaas: rota removida (conta bloqueada 01/08)
// Route::post('/webhooks/asaas', [\App\Http\Controllers\Webhooks\AsaasWebhookController::class, 'handle']);

// Webhook Pagar.me dedicado (substitui edge function Supabase)
// POST /api/webhooks/pagarme
Route::post('/webhooks/pagarme', [\App\Http\Controllers\Api\PagarmeWebhookController::class, 'handle'])
    ->name('webhooks.pagarme');

// Webhook de pagamento por fornecedor (Fase 4)
// POST /api/webhooks/payment/{supplier_slug}/{gateway}
// Ex: /api/webhooks/payment/minha-loja/asaas
Route::post('/webhooks/payment/{supplier_slug}/{gateway}', [\App\Http\Controllers\Webhooks\SupplierPaymentWebhookController::class, 'handle'])
    ->name('webhooks.supplier-payment')
    ->where('gateway', 'asaas|shipay|pagarme|mercadopago');

// Bridge Shopee: callback do goolhub após OAuth concluído (redirect do browser, sem auth)
Route::get('/oauth/shopee/bridge-callback', [\App\Http\Controllers\Api\OAuthController::class, 'shopeeBridgeCallback']);
// Bridge Shopee: receiver de tokens relayed pelo api.hubai.io (POST HMAC X-HubAI-Bridge-Sig)
Route::post('/oauth/shopee/hubai-relay', [\App\Http\Controllers\Api\OAuthController::class, 'shopeeHubAiRelay']);
// Relay token-refresh: hub recebe tokens renovados e atualiza marketplace_accounts por shop_id
Route::post('/oauth/shopee/relay-token-refresh', [\App\Http\Controllers\Api\OAuthController::class, 'shopeeRelayTokenRefresh']);
// MUL-029-2: receiver Bling OAuth relay (api.hubai.io -> WL). HMAC X-HubAI-Bridge-Sig.
Route::post('/oauth/bling/wl-relay', [\App\Http\Controllers\Api\BlingRelayController::class, 'receive']);
Route::post('/oauth/bling/config-sync', [\App\Http\Controllers\Api\BlingConfigSyncController::class, 'receive']); // MUL-190 sync config importacao
// NOV-181: autenticacao central Shopee — hub renova, WLs espelham (HMAC X-HubAI-Bridge-Sig)
Route::post('/oauth/shopee/bridge-refresh', [\App\Http\Controllers\Api\ShopeeBridgeController::class, 'refresh']);           // roda no hub
Route::post('/oauth/shopee/bridge-export', [\App\Http\Controllers\Api\ShopeeBridgeController::class, 'export']);             // roda na WL (handoff)
Route::post('/oauth/shopee/bridge-mark-managed', [\App\Http\Controllers\Api\ShopeeBridgeController::class, 'markManaged']);  // roda na WL
Route::post('/marketplace/bridge-sync', [\App\Http\Controllers\Api\V1\MarketplaceController::class, 'accountSyncBridge']); // roda no hub (MUL-214 item 35 — WL pede sync, hub puxa e entrega via fanout)
// =========================================================================
// SHOPEE OPEN PLATFORM — OAuth Callback público (Go-Live validation)
// Recebe: code + shop_id. Salva em shopee_oauth_callbacks e redireciona.
// =========================================================================
Route::get('/shopee/oauth-callback', [\App\Http\Controllers\OAuth\ShopeeOAuthController::class, 'callback']);

// Init OAuth Shopee -- hub central para todos os servicos
Route::get('/shopee/oauth/init', [\App\Http\Controllers\OAuth\ShopeeOAuthInitController::class, 'init'])->name('shopee.oauth.init');

// SEL-326 Fase C: variante POST autenticada por JWT — retorna JSON pra front dar window.location.href
Route::post('/shopee/oauth/init', [\App\Http\Controllers\OAuth\ShopeeOAuthInitController::class, 'initPost'])->name('shopee.oauth.init.post');

// SEL-326: emite JWT curto (300s) pra front autenticado passar como Authorization Bearer no init acima.
Route::middleware('auth:sanctum')->post('/auth/oauth-init-token', [\App\Http\Controllers\Api\OAuthInitTokenController::class, 'issue'])->name('oauth.init-token');


// Webhook relay Shopee — recebe copia do payload do legado (autenticado via X-Bridge-Key)
Route::post('/webhooks/shopee', [\App\Http\Controllers\Api\Webhooks\ShopeeWebhookController::class, 'handle']);

// Webhook genérico multi-plataforma: POST /api/webhooks/{platform}
// Suporta: mercadolivre, shopee, amazon, etc.
// Para adicionar novo marketplace: registrar handler em WebhookDispatcherService::$handlers
Route::post('/webhooks/{platform}', [\App\Http\Controllers\Api\WebhookController::class, 'handle'])
    ->where('platform', 'mercadolivre|shopee|amazon|[a-z0-9_-]+');

// Rota legada mantida como alias para compatibilidade
// (o ML já pode estar configurado apontando para esta URL)
Route::post('/webhooks/mercadolivre', [\App\Http\Controllers\Api\Webhooks\MercadoLivreWebhookController::class, 'handle']);
Route::post('/webhooks/bling', [\App\Http\Controllers\Api\Webhooks\BlingWebhookController::class, 'handle']);

// Webhooks de Roteamento de Pedidos Multi-Conexão (Por Sub-SKU)
// SEL-182 pentest fix: rota criava pedidos falsos no banco sem autenticacao
// nenhuma (bastava saber um custom_sku publicado no ML/Shopee). Marketplaces
// reais usam handlers dedicados (/api/webhooks/{mercadolivre,shopee,bling})
// que validam assinatura. Deixamos publica pra retrocompat (simulador HubAI)
// mas cap 30/min por IP pra bloquear abuso de flood.
Route::post('/webhooks/orders/{platform}', [\App\Http\Controllers\Api\OrderWebhookController::class, 'handle'])
    ->middleware('throttle:30,1');

// =========================================================================
// Impersonation — exchange é público (uso único)
// SEL-182 pentest fix: throttle pra dificultar bruteforce de token uuid.
Route::post('/v1/impersonate/exchange', [ImpersonationController::class, 'exchange'])->middleware('throttle:20,1');

// SEL-032: KPIs do operador (MRR, afiliados) - restrito a super_admin
Route::middleware(['auth:sanctum', 'check.user.active', 'supplier.panel'])->prefix('v1/operator')->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\Api\V1\AdminController::class, 'operatorDashboard']);
});

// API V1 ADMIN — Restrito a super_admin
// Bloco separado para nao conflitar com rotas publicas e webhooks
// =========================================================================
Route::middleware(['auth:sanctum', 'check.user.active', 'supplier.panel'])->prefix('v1/admin')->group(function () { // MUL-151

    // Clientes
    Route::get('/clients', [AdminController::class, 'clients']);
    Route::get('/clients/enrich', [\App\Http\Controllers\Api\V1\AdminBillingController::class, 'clientsEnrich']); // SEL-054
    Route::get('/clients/{id}/billing', [\App\Http\Controllers\Api\V1\AdminBillingController::class, 'clientBilling'])->whereNumber('id'); // SEL-054
    Route::post('/clients/bulk-delete', [\App\Http\Controllers\Api\V1\AdminBillingController::class, 'bulkDeleteClients']); // SEL-054
    Route::post('/clients/{id}/reset-password', [\App\Http\Controllers\Api\V1\AdminBillingController::class, 'resetClientPassword'])->whereNumber('id'); // SEL-054
    // SEL-113-routes Ruan 14:23: config grupo WhatsApp + toggle convite
    Route::get('/whatsapp-group', [\App\Http\Controllers\Api\V1\AdminController::class, 'whatsappGroupGet']);
    Route::put('/whatsapp-group', [\App\Http\Controllers\Api\V1\AdminController::class, 'whatsappGroupUpdate']);
    Route::patch('/clients/{id}/whatsapp-invite', [\App\Http\Controllers\Api\V1\AdminController::class, 'whatsappInviteToggle'])->whereNumber('id');
    // SEL-113 fase 2: alias com naming solicitado ({whatsapp_group_url, remaining})
    Route::get('/whatsapp-config', [\App\Http\Controllers\Api\V1\AdminController::class, 'whatsappConfigGet']);
    Route::put('/whatsapp-config', [\App\Http\Controllers\Api\V1\AdminController::class, 'whatsappConfigUpdate']);
    Route::get('/recovery-leads', [\App\Http\Controllers\Api\V1\AdminBillingController::class, 'recoveryLeads']); // SEL-060
    Route::get('/clients/{id}', [AdminController::class, 'clientShow'])->whereNumber('id');
    Route::put('/clients/{id}', [AdminController::class, 'clientUpdate'])->whereNumber('id');
    Route::delete('/clients/{id}', [\App\Http\Controllers\Api\V1\AdminBillingController::class, 'deleteSingleClient'])->whereNumber('id'); // SEL-095

    // Dashboard (KPIs agregados)
    Route::get('/dashboard', [AdminController::class, 'dashboard']);

    // SEL-032: funil PIX do checkout (subscriptions) - visao admin
    Route::get('/deposits', [AdminController::class, 'deposits']);
    Route::get('/video-health', [AdminController::class, 'videoHealth']); // INF-030 06/08: saude da geracao de video (Studio)

    // Pedidos (gestao admin)
    Route::get('/orders', [AdminController::class, 'orders']);
    Route::get('/orders/{id}', [AdminController::class, 'orderShow']);
    Route::put('/orders/{id}/status', [AdminController::class, 'orderUpdateStatus']);

    // Promoções de live (SEL-363)
    Route::get('/promo/recent-subscribers', [AdminPromoController::class, 'recentSubscribers']);
    Route::get('/promo/plans', [AdminPromoController::class, 'promoPlans']);
    Route::post('/promo/apply', [AdminPromoController::class, 'apply']);

    // Planos (CRUD)
    Route::get('/plans', [AdminController::class, 'plans']);
    Route::post('/plans', [AdminController::class, 'planStore']);
    Route::put('/plans/{id}', [AdminController::class, 'planUpdate']);
    Route::delete('/plans/{id}', [AdminController::class, 'planDestroy']);

    // Diretorio de fornecedores (Lista de Fornecedores — SEL-066)
    Route::get('/directory-suppliers', [AdminController::class, 'directorySuppliers']);
    Route::put('/directory-suppliers/{id}', [AdminController::class, 'directorySupplierUpdate']);
    Route::delete('/directory-suppliers/{id}', [AdminController::class, 'directorySupplierDestroy']);

    // Taxas de marketplace (CRUD)
    Route::get('/marketplace-fees', [AdminController::class, 'marketplaceFees']);
    Route::post('/marketplace-fees', [AdminController::class, 'marketplaceFeeStore']);
    Route::put('/marketplace-fees/{id}', [AdminController::class, 'marketplaceFeeUpdate']);
    Route::delete('/marketplace-fees/{id}', [AdminController::class, 'marketplaceFeeDestroy']);

    // Configuracoes
    Route::get('/settings', [AdminController::class, 'settings']);
    Route::put('/settings', [AdminController::class, 'settingUpdate']);

    // Wallet e plano do cliente
    Route::get('/clients/{id}/wallet', [AdminController::class, 'clientWallet'])->whereNumber('id');
    Route::put('/clients/{id}/plan', [AdminController::class, 'clientChangePlan'])->whereNumber('id');

    // Fornecedores (CRUD completo) — MUL-153
    Route::get('/suppliers', [AdminController::class, 'suppliers']);
    Route::post('/suppliers', [AdminController::class, 'supplierStore']);
    Route::get('/suppliers/{id}', [AdminController::class, 'supplierShow']);
    Route::put('/suppliers/{id}', [AdminController::class, 'supplierUpdate']);
    Route::delete('/suppliers/{id}', [AdminController::class, 'supplierDestroy']);

    // Depositos de fornecedores (admin-scoped) — MUL-153
    Route::get('/warehouses', [AdminController::class, 'warehousesIndex']);
    Route::post('/warehouses', [AdminController::class, 'warehousesStore']);
    Route::put('/warehouses/{id}', [AdminController::class, 'warehousesUpdate']);
    Route::delete('/warehouses/{id}', [AdminController::class, 'warehousesDestroy']);

    // Shopee — captura de pagamentos (entrega direta)
    Route::get( '/shopee/pending-captures',           [\App\Http\Controllers\Api\V1\ShopeePaymentController::class, 'pendingCaptures']);
    Route::post('/shopee/capture-payment/{orderId}',  [\App\Http\Controllers\Api\V1\ShopeePaymentController::class, 'capturePayment']);
    Route::post('/shopee/capture-batch',              [\App\Http\Controllers\Api\V1\ShopeePaymentController::class, 'captureBatch']);

    // Sync legado
    Route::get('/sync', [AdminController::class, 'sync']);  // historico de runs (Fase 4 fix)
    Route::get('/sync-status', [AdminController::class, 'syncStatus']);
    Route::post('/sync/force', [AdminController::class, 'syncForce']);


    // Criar usuario/cliente
    Route::post('/clients', [AdminController::class, 'clientStore']);

    // SEL-171: subsidios do bonus 50% (3 primeiras vendas /catalogo)
    Route::get('/catalog-subsidies',                       [\App\Http\Controllers\Api\V1\CatalogBonusController::class, 'adminIndex']);
    Route::get('/catalog-subsidies/summary',               [\App\Http\Controllers\Api\V1\CatalogBonusController::class, 'adminSummary']);
    Route::post('/catalog-subsidies/mark-paid-bulk',       [\App\Http\Controllers\Api\V1\CatalogBonusController::class, 'adminMarkPaidBulk']);
    Route::post('/catalog-subsidies/{id}/mark-paid',       [\App\Http\Controllers\Api\V1\CatalogBonusController::class, 'adminMarkPaid'])->whereNumber('id');
    Route::post('/catalog-subsidies/{id}/waive',           [\App\Http\Controllers\Api\V1\CatalogBonusController::class, 'adminWaive'])->whereNumber('id');

    // Afiliados
    Route::get('/affiliates', [AdminController::class, 'affiliates']);
    Route::put('/affiliates/{id}', [AdminController::class, 'affiliateUpdate']);
    Route::get('/affiliates/withdrawals', [AdminController::class, 'affiliateWithdrawals']);
    Route::put('/affiliates/withdrawals/{id}/pay', [AdminController::class, 'affiliateWithdrawalPay']);

    // Platform Settings (taxas financeiras — fee_per_user, fee_per_transaction, etc)
    Route::get('/platform-settings', [AdminController::class, 'platformSettings']);
    Route::patch('/platform-settings/{key}', [AdminController::class, 'updatePlatformSetting']);

    // Impersonação — gerar token para acessar como cliente
    Route::post('/impersonate/{userId}', [ImpersonationController::class, 'generate'])->whereNumber('userId');

    // Integracoes (marketplace_accounts — visao super_admin)
    Route::get('/integrations',                           [AdminController::class, 'integrations']);
    Route::delete('/integrations/{id}',                   [AdminController::class, 'integrationDestroy'])->whereNumber('id');
    Route::post('/integrations/{id}/reset-errors',        [AdminController::class, 'integrationResetErrors'])->whereNumber('id');

    Route::get("/free-leads", [\App\Http\Controllers\Api\V1\AdminController::class, "freeLeads"]); // SEL-082 F5

    // INF-030 (Ruan 12/08) — tela /admin/analytics ("Visitantes"): overview +
    // lista de visitantes (Matomo self-hosted) + heatmap de cliques (proprio).
    Route::get('/analytics/overview', [\App\Http\Controllers\Api\V1\AdminAnalyticsController::class, 'overview']);
    Route::get('/analytics/visitors', [\App\Http\Controllers\Api\V1\AdminAnalyticsController::class, 'visitors']);
    Route::get('/analytics/heatmap', [\App\Http\Controllers\Api\V1\AdminAnalyticsController::class, 'heatmap']);
    // SEL-VISITANTES-2 (Ruan 12/08) — painel de visitantes vira ANALISE:
    // campanha->anuncio->criativo clicavel, drilldown de qualquer numero,
    // diagnostico do video, snapshot da pagina pro mapa de calor, melhorias.
    Route::get('/analytics/campaigns', [\App\Http\Controllers\Api\V1\AdminAnalyticsController::class, 'campaigns']);
    Route::get('/analytics/drilldown', [\App\Http\Controllers\Api\V1\AdminAnalyticsController::class, 'drilldown']);
    Route::get('/analytics/video-diagnosis', [\App\Http\Controllers\Api\V1\AdminAnalyticsController::class, 'videoDiagnosis']);
    Route::get('/analytics/page-snapshot', [\App\Http\Controllers\Api\V1\AdminAnalyticsController::class, 'pageSnapshot']);
    Route::get('/analytics/insights', [\App\Http\Controllers\Api\V1\AdminAnalyticsController::class, 'insights']);
    // INF-030 (Ruan 12/08, ampliacao) — jornada/heatmap/gravacoes POR visitante
    Route::get('/analytics/visitor/{visitorUid}/journey', [\App\Http\Controllers\Api\V1\AdminAnalyticsController::class, 'visitorJourney']);
    Route::get('/analytics/visitor/{visitorUid}/heatmap', [\App\Http\Controllers\Api\V1\AdminAnalyticsController::class, 'visitorHeatmap']);
    Route::get('/analytics/visitor/{visitorUid}/recordings', [\App\Http\Controllers\Api\V1\AdminAnalyticsController::class, 'visitorRecordings']);
    Route::get('/analytics/recording/{sessionId}', [\App\Http\Controllers\Api\V1\AdminAnalyticsController::class, 'recordingEvents']);
}); // End v1/admin

// API de Simulacao (Para Lojistas testarem o Fluxo Hibrido sem credenciais de Producao)
Route::post('/simulator/webhook-order', function (Request $request) {
    if (!app()->environment('local', 'staging')) {
        abort(403, 'Simulator only available in dev/staging');
    }

    // Permite invocar /api/simulator/webhook-order puxando os dados vitais para um pedido
    $subSku = $request->input('custom_sku', 'INSIRA_O_SUB_SKU_AQUI');
    $qty = $request->input('quantity', 1);
    $price = $request->input('price', 99.90);
    $customerName = $request->input('customer_name', 'Cliente Teste Simulação');

    $payload = [
        'order_id' => 'SIMULATOR_' . rand(100000, 999999),
        'items' => [
            ['sku' => $subSku, 'quantity' => $qty, 'price' => $price]
        ],
        'customer' => ['name' => $customerName, 'email' => 'teste@hubai.sim'],
        'shipping_address' => ['street' => 'Rua Ficticia, 123']
    ];

    // Encaminhar "internamente" para o WebhookController fingindo que é o hubaisimulator
    $webhookRequest = Request::create('/api/webhooks/orders/hubaisimulator', 'POST', $payload);
    return app()->handle($webhookRequest);
});


// Supplier Core / Fase 3 / M3 — Tenant API readonly (sub-fase 3b)
Route::prefix('tenant-api/v1')->middleware('tenant.api')->group(function () {
    Route::get('/orders', [\App\Http\Controllers\TenantApi\V1\OrderController::class, 'index']);
    Route::get('/orders/{id}', [\App\Http\Controllers\TenantApi\V1\OrderController::class, 'show']);
    Route::get('/suppliers', [\App\Http\Controllers\TenantApi\V1\SupplierController::class, 'index']);
    Route::get('/products', [\App\Http\Controllers\TenantApi\V1\ProductController::class, 'index']);
    Route::get('/products/{sku}', [\App\Http\Controllers\TenantApi\V1\ProductController::class, 'show']);
    Route::get('/events', [\App\Http\Controllers\TenantApi\V1\EventController::class, 'index']);
});


// Supplier Core / Fase 3 / M4 — Tenant API write (sub-fase 3d)
Route::prefix('tenant-api/v1')->middleware(['tenant.api', 'tenant.write', 'tenant.idempotency'])->group(function () {
    Route::patch('/orders/{id}/status', [\App\Http\Controllers\TenantApi\V1\OrderWriteController::class, 'status']);
    Route::post('/orders/{id}/tracking', [\App\Http\Controllers\TenantApi\V1\OrderWriteController::class, 'tracking']);
    Route::post('/orders/{id}/cancel', [\App\Http\Controllers\TenantApi\V1\OrderWriteController::class, 'cancel']);
    Route::post('/orders/{id}/refund', [\App\Http\Controllers\TenantApi\V1\OrderWriteController::class, 'refund']);
});


// Supplier Core / Fase 3 / M6 — OpenAPI spec publica (sem auth)
Route::get('/tenant-api/v1/openapi.json', function () {
    $path = storage_path('api/tenant-api-v1.json');
    if (!file_exists($path)) {
        return response()->json(['error' => 'spec_not_available'], 503);
    }
    return response()->file($path, ['Content-Type' => 'application/json']);
});

// FASE 2 — HubAI Central: recebe pedidos novos/atualizados do api.hubai.io
Route::post('/webhooks/hubai/orders', [\App\Http\Controllers\Webhooks\HubAIOrderWebhookController::class, 'handle'])
    ->middleware('verify.hubai.signature')
    ->name('webhooks.hubai.orders');


// ===== Drop Internacional =====
Route::prefix('v1/drop')->middleware(['auth:sanctum', 'check.user.active', 'drop.module'])->group(function () {
    // Modulo config
    Route::get('/config',  [\App\Http\Controllers\Api\V1\Drop\DropModuleController::class, 'getConfig']);
    Route::post('/config', [\App\Http\Controllers\Api\V1\Drop\DropModuleController::class, 'saveConfig']);

    // Loja Shopify
    Route::get('/store',             [\App\Http\Controllers\Api\V1\Drop\DropStoreController::class, 'show']);
    Route::post('/store/connect',    [\App\Http\Controllers\Api\V1\Drop\DropStoreController::class, 'connect']);
    Route::post('/store/disconnect', [\App\Http\Controllers\Api\V1\Drop\DropStoreController::class, 'disconnect']);
    Route::get('/store/health',      [\App\Http\Controllers\Api\V1\Drop\DropStoreController::class, 'health']);

    // Produtos importados
    Route::get('/products',              [\App\Http\Controllers\Api\V1\Drop\DropProductController::class, 'index']);
    Route::post('/products',             [\App\Http\Controllers\Api\V1\Drop\DropProductController::class, 'store']);
    Route::put('/products/{id}',         [\App\Http\Controllers\Api\V1\Drop\DropProductController::class, 'update']);
    Route::post('/products/{id}/publish',[\App\Http\Controllers\Api\V1\Drop\DropProductController::class, 'publish']);
    Route::delete('/products/{id}',      [\App\Http\Controllers\Api\V1\Drop\DropProductController::class, 'destroy']);

    // Pedidos
    Route::get('/orders',                        [\App\Http\Controllers\Api\V1\Drop\DropOrderController::class, 'index']);
    Route::get('/orders/{id}',                   [\App\Http\Controllers\Api\V1\Drop\DropOrderController::class, 'show']);
    Route::post('/orders/{id}/supplier-order',   [\App\Http\Controllers\Api\V1\Drop\DropOrderController::class, 'createSupplierOrder']);
    Route::post('/orders/{id}/tracking',         [\App\Http\Controllers\Api\V1\Drop\DropOrderController::class, 'registerTracking']);
    Route::post('/orders/{id}/cancel',           [\App\Http\Controllers\Api\V1\Drop\DropOrderController::class, 'cancel']);

    // Precificacao
    Route::get('/pricing-rules',      [\App\Http\Controllers\Api\V1\Drop\DropPricingController::class, 'index']);
    Route::post('/pricing-rules',     [\App\Http\Controllers\Api\V1\Drop\DropPricingController::class, 'store']);
    Route::put('/pricing-rules/{id}', [\App\Http\Controllers\Api\V1\Drop\DropPricingController::class, 'update']);
    Route::post('/pricing/calculate', [\App\Http\Controllers\Api\V1\Drop\DropPricingController::class, 'calculate']);

    // Fase 3 — Pixel, CAPI, Atribuicao
    Route::apiResource('pixels', \App\Http\Controllers\Api\V1\Drop\DropTrackingController::class)
        ->parameters(['pixels' => 'id']);
    Route::post('/sessions', [\App\Http\Controllers\Api\V1\Drop\DropTrackingController::class, 'storeSession']);
    Route::get('/attribution/performance', [\App\Http\Controllers\Api\V1\Drop\DropTrackingController::class, 'channelPerformance']);
    Route::get('/attribution/campaigns', [\App\Http\Controllers\Api\V1\Drop\DropTrackingController::class, 'campaignRoas']);

    // =========================================================================
    // Drop Internacional - Fase 2: Mining de Produtos (CJ Dropshipping)
    // =========================================================================
    Route::post('/mining/search', [\App\Http\Controllers\Api\V1\Drop\DropMiningController::class, 'search']);
    Route::post('/mining/import', [\App\Http\Controllers\Api\V1\Drop\DropMiningController::class, 'import']);

    // =========================================================================
    // Drop Internacional - Fase 3: Stripe Connect Express
    // =========================================================================
    Route::post('/stripe/connect',   [\App\Http\Controllers\Api\V1\Drop\DropStripeApiController::class, 'connect']);
    Route::get('/stripe/status',     [\App\Http\Controllers\Api\V1\Drop\DropStripeApiController::class, 'status']);
    Route::get('/stripe/events',     [\App\Http\Controllers\Api\V1\Drop\DropStripeApiController::class, 'events']);

    // Relatorio financeiro
    Route::get('/financial/report',  [\App\Http\Controllers\Api\V1\Drop\DropStripeApiController::class, 'financialReport']);
});

// ===== Drop Internacional - Fase 4: Loja Nativa =====
Route::prefix('v1/drop/native')->middleware(['auth:sanctum', 'check.user.active', 'drop.module'])->group(function () {
    Route::post('/store',                   [\App\Http\Controllers\Api\V1\Drop\NativeStoreController::class, 'create']);
    Route::put('/store',                    [\App\Http\Controllers\Api\V1\Drop\NativeStoreController::class, 'update']);
    Route::post('/store/publish',           [\App\Http\Controllers\Api\V1\Drop\NativeStoreController::class, 'publish']);
    Route::post('/store/unpublish',         [\App\Http\Controllers\Api\V1\Drop\NativeStoreController::class, 'unpublish']);
    Route::get('/store/gateways',           [\App\Http\Controllers\Api\V1\Drop\NativeStoreController::class, 'listGateways']);
    Route::post('/store/gateways',          [\App\Http\Controllers\Api\V1\Drop\NativeStoreController::class, 'addGateway']);
    Route::delete('/store/gateways/{id}',   [\App\Http\Controllers\Api\V1\Drop\NativeStoreController::class, 'removeGateway']);
});

// =========================================================================
// Monitor — Observabilidade (super_admin only)
// =========================================================================
Route::middleware(["auth:sanctum", "check.user.active"])->prefix("v1/admin/monitor")->group(function () {
    Route::get("stats",  [\App\Http\Controllers\Api\V1\MonitorController::class, "stats"]);
    Route::get("logs",   [\App\Http\Controllers\Api\V1\MonitorController::class, "logs"]);
    Route::get("health", [\App\Http\Controllers\Api\V1\MonitorController::class, "health"]);
});

// =========================================================================
// HubAI Monitor — Stats publicos protegidos por API key
// =========================================================================
Route::get('/admin/stats', function (\Illuminate\Http\Request $request) {
    if ($request->header('X-Admin-Key') !== env('ADMIN_STATS_KEY', 'hubai-monitor-2026')) {
        return response()->json(['error' => 'unauthorized'], 401);
    }

    $users   = \Illuminate\Support\Facades\DB::table('users')->count();
    $clients = \Illuminate\Support\Facades\DB::table('clients')->count();
    $tenants = \Illuminate\Support\Facades\DB::table('tenants')
                    ->where('name', '!=', 'Fornecefy')
                    ->count();

    return response()->json([
        'success' => true,
        'data' => [
            'total_users'   => $users,
            'total_clients' => $clients,
            'total_tenants' => $tenants,
            'as_of'         => now()->toISOString(),
        ]
    ]);
});

// ==========================================================================


// =========================================================================
// NOV-032 v2: GET /api/v1/client/status
// Auth: Authorization: Bearer {GABRIEL_API_KEY}
// Endpoint seguro para o agente Gabriel consultar status de cliente.
// NUNCA retorna: senha, hash, tokens, cpf, dados de pagamento.
// =========================================================================
Route::middleware(["gabriel.api_key"])->group(function () {
    Route::get("/v1/client/status", [\App\Http\Controllers\Api\V1\GabrielController::class, "clientStatusV2"]);
});

// =========================================================================
// NOV-032: Gabriel API - endpoints internos para o agente de vendas
// Autenticado via X-Gabriel-Token (middleware gabriel.auth)
// Rate limit: 20 req/min por IP | NUNCA retorna dados sensiveis
// =========================================================================
Route::middleware(["gabriel.auth"])->prefix("v1/gabriel")->group(function () {
    Route::get("/client-status", [\App\Http\Controllers\Api\V1\GabrielController::class, "clientStatus"]);
    Route::post("/grant-ia-trial", [\App\Http\Controllers\Api\V1\GabrielController::class, "grantIaTrial"]);
    Route::post("/demo-products", [\App\Http\Controllers\Api\V1\GabrielController::class, "demoProducts"]);
});
// API V2 — Auth nova + proxy legado (HUB-080)
// ==========================================================================
require __DIR__ . '/api_v2.php';

// =========================================================================
// NOV-058-C: Proxy Catalogo Central (api.hubai.io)
// Repassa requests de catalogo para a API central com X-Tenant-Slug: mestoredrop.
// Requer autenticacao Sanctum. Paths permitidos: products/*, suppliers/*, catalog/*
// =========================================================================
Route::middleware('auth:sanctum')->prefix('v1/central/catalog')->group(function () {
    Route::get('/{path}', [\App\Http\Controllers\Api\V1\ProxyCatalogController::class, 'get'])->where('path', '.*');
    Route::post('/{path}', [\App\Http\Controllers\Api\V1\ProxyCatalogController::class, 'post'])->where('path', '.*');
});

// =========================================================================
// SEL-030: Chat Gabriel — Central de Ajuda seller.global
// POST   /api/v1/chat/conversations                   inicia conversa (publico)
// POST   /api/v1/chat/conversations/{uuid}/messages   envia msg (publico)
// GET    /api/v1/chat/conversations/{uuid}/messages   polling 3s (publico)
// POST   /api/v1/chat/conversations/{uuid}/handoff    marca handoff (publico)
// POST   /api/v1/chat/webhook/chatwoot                webhook Chatwoot inbound (publico)
// =========================================================================
// SEL-182 pentest fix: throttle nas rotas publicas de chat pra evitar
// flood de conversas fantasma no Chatwoot + abuso de handoff.
Route::prefix('v1/chat')->group(function () {
    Route::post('/conversations', [\App\Http\Controllers\Api\V1\ChatController::class, 'startConversation'])->middleware('throttle:10,1');
    Route::post('/conversations/{uuid}/messages', [\App\Http\Controllers\Api\V1\ChatController::class, 'sendMessage'])->middleware('throttle:60,1');
    Route::get('/conversations/{uuid}/messages', [\App\Http\Controllers\Api\V1\ChatController::class, 'getMessages'])->middleware('throttle:120,1');
    Route::post('/conversations/{uuid}/handoff', [\App\Http\Controllers\Api\V1\ChatController::class, 'handoff'])->middleware('throttle:10,1');
    Route::post('/webhook/chatwoot', [\App\Http\Controllers\Api\V1\ChatController::class, 'chatwootWebhook'])->middleware('throttle:600,1');
});


// =========================================================================
// SEL-050 — Web Push (Seller Global)
// GET  /api/v1/push/public-key · POST /api/v1/push/subscribe|unsubscribe
// POST /api/v1/admin/push/send · GET /api/v1/admin/push/subscriptions
// =========================================================================
Route::prefix('v1/push')->group(function () {
    Route::get('/public-key', [\App\Http\Controllers\Api\V1\PushController::class, 'publicKey'])->middleware('throttle:30,1');
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('/subscribe',    [\App\Http\Controllers\Api\V1\PushController::class, 'subscribe']);
        Route::post('/unsubscribe',  [\App\Http\Controllers\Api\V1\PushController::class, 'unsubscribe']);
        // SEL-259: preferences + test
        Route::get('/preferences',   [\App\Http\Controllers\Api\V1\PushController::class, 'preferences']);
        Route::patch('/preferences', [\App\Http\Controllers\Api\V1\PushController::class, 'updatePreferences']);
        Route::post('/test',         [\App\Http\Controllers\Api\V1\PushController::class, 'test']);
        Route::post('/troubleshoot', [\App\Http\Controllers\Api\V1\PushController::class, 'troubleshoot']);
        // SEL-270: cliente escolheu fallback WhatsApp em vez de push
        Route::post('/fallback-whatsapp', [\App\Http\Controllers\Api\V1\PushController::class, 'fallbackWhatsapp']);
        // SEL-270: test-self envia push pro próprio user (assistente pós-ativação)
        Route::post('/test-self', [\App\Http\Controllers\Api\V1\PushController::class, 'triggerSelf']);
    });
});

// SEL-271: onboarding — marca redirect grupo network (só primeiro login)
Route::middleware(['auth:sanctum'])->prefix('v1/onboarding')->group(function () {
    Route::post('/mark-network-redirect', [\App\Http\Controllers\Api\V1\OnboardingController::class, 'markNetworkRedirect']);
});

// SEL-272: Firebase Phone Auth — sem middleware, valida idToken Firebase e retorna Sanctum token
Route::post('v1/auth/phone/verify', [\App\Http\Controllers\Api\V1\PhoneAuthController::class, 'verify'])
    ->middleware('throttle:20,1');
Route::middleware(['auth:sanctum', 'check.user.active', 'supplier.panel'])->prefix('v1/admin/push')->group(function () {
    Route::post('/send',            [\App\Http\Controllers\Api\V1\PushController::class, 'adminSend']);
    Route::get('/subscriptions',    [\App\Http\Controllers\Api\V1\PushController::class, 'adminSubscriptions']);
    Route::post('/trigger',         [\App\Http\Controllers\Api\V1\PushController::class, 'adminTrigger']);
    // SEL-267: painel campanhas (broadcast por segmento + histórico + testar no próprio telefone)
    Route::get('/subscribers-count', [\App\Http\Controllers\Api\V1\PushController::class, 'subscribersCount']);
    Route::get('/history',           [\App\Http\Controllers\Api\V1\PushController::class, 'history']);
    Route::post('/trigger-self',     [\App\Http\Controllers\Api\V1\PushController::class, 'triggerSelf']);
    Route::post('/campaign',         [\App\Http\Controllers\Api\V1\PushController::class, 'campaign']);
});

// =========================================================================
// SEL-260 — VSL Manager
// GET /api/v1/vsl?menu={slug} — público, lista VSLs ativas por menu
// CRUD admin sob /api/v1/admin/vsl (super_admin only)
// =========================================================================
Route::get('v1/vsl', [\App\Http\Controllers\Api\V1\VslController::class, 'index'])->middleware('throttle:60,1');
Route::middleware(['auth:sanctum', 'role:super_admin'])->prefix('v1/admin/vsl')->group(function () {
    Route::get('/',        [\App\Http\Controllers\Api\V1\VslController::class, 'adminIndex']);
    Route::post('/',       [\App\Http\Controllers\Api\V1\VslController::class, 'adminCreate']);
    Route::patch('/{id}',  [\App\Http\Controllers\Api\V1\VslController::class, 'adminUpdate']);
    Route::delete('/{id}', [\App\Http\Controllers\Api\V1\VslController::class, 'adminDelete']);
});

// SEL-269/SEL-308: proxy imagens TT Shop com Referer + cache em disco (nunca 502)
Route::get('v1/tt/img-proxy', [\App\Http\Controllers\Api\V1\ImageProxyController::class, 'proxy'])->middleware('throttle:600,1');

// SEL-308: proxy server-side de info do criador TikTok (substitui tikwm client-side, cache 6h)
Route::get('v1/tt/creator-info', [\App\Http\Controllers\Api\V1\TikTokCreatorInfoController::class, 'show'])->middleware('throttle:120,1');

// SEL-264: Aba Avisos (canal notícias + push automático)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('v1/avisos', [\App\Http\Controllers\Api\V1\AvisoController::class, 'index']);
    Route::post('v1/avisos/{id}/read', [\App\Http\Controllers\Api\V1\AvisoController::class, 'markRead']);
});
Route::middleware(['auth:sanctum', 'role:super_admin'])->prefix('v1/admin/avisos')->group(function () {
    Route::get('/',        [\App\Http\Controllers\Api\V1\AvisoController::class, 'adminIndex']);
    Route::post('/',       [\App\Http\Controllers\Api\V1\AvisoController::class, 'adminStore']);
    Route::patch('/{id}',  [\App\Http\Controllers\Api\V1\AvisoController::class, 'adminUpdate']);
    Route::delete('/{id}', [\App\Http\Controllers\Api\V1\AvisoController::class, 'adminDestroy']);
    Route::post('/{id}/publish-now', [\App\Http\Controllers\Api\V1\AvisoController::class, 'adminPublishNow']);
});

// SEL-073 admin: modulo Seedance — modelos+precos oficiais, custo real, cobranca oculta
Route::middleware(['auth:sanctum', 'role:admin,super_admin'])->prefix('v1/admin/video')->group(function () {
    Route::get('/models',              [\App\Http\Controllers\Api\V1\AdminVideoController::class, 'models']);
    Route::get('/usage',               [\App\Http\Controllers\Api\V1\AdminVideoController::class, 'usage']);
    Route::get('/billing',             [\App\Http\Controllers\Api\V1\AdminVideoController::class, 'billingGet']);
    Route::put('/billing',             [\App\Http\Controllers\Api\V1\AdminVideoController::class, 'billingPut']);
    Route::get('/credit-transactions', [\App\Http\Controllers\Api\V1\AdminVideoController::class, 'creditTransactions']);
    Route::get('/dashboard',           [\App\Http\Controllers\Api\V1\AdminVideoController::class, 'dashboard']);
    Route::get("/kling-browser", [\App\Http\Controllers\Api\V1\AdminVideoController::class, "klingBrowser"]);
    Route::post("/kling-browser/pause", [\App\Http\Controllers\Api\V1\AdminVideoController::class, "klingBrowserPause"]);
});


// SEL-425 — Pool de motores de vídeo (DICloak, Mac-Flow, etc)
Route::middleware(['auth:sanctum', 'role:super_admin'])->prefix('v1/admin/video-engines')->group(function () {
    Route::get('/',                    [\App\Http\Controllers\Api\V1\Admin\VideoEnginesController::class, 'index']);
    Route::post('/',                   [\App\Http\Controllers\Api\V1\Admin\VideoEnginesController::class, 'store']);
    Route::patch('/{id}',              [\App\Http\Controllers\Api\V1\Admin\VideoEnginesController::class, 'update']);
    Route::delete('/{id}',             [\App\Http\Controllers\Api\V1\Admin\VideoEnginesController::class, 'destroy']);
    Route::post('/{id}/reset-cooldown',[\App\Http\Controllers\Api\V1\Admin\VideoEnginesController::class, 'resetCooldown']);
    Route::get('/dicloak/profiles',    [\App\Http\Controllers\Api\V1\Admin\VideoEnginesController::class, 'dicloakProfiles']);
});

// SEL-429 -- Motor Universal: ai-engines (video + llm + image + scraping + viral + flow)
Route::middleware(['auth:sanctum', 'role:super_admin'])->prefix('v1/admin/ai-engines')->group(function () {
    Route::get('/',                    [\App\Http\Controllers\Api\V1\Admin\AiEnginesController::class, 'index']);
    Route::post('/',                   [\App\Http\Controllers\Api\V1\Admin\AiEnginesController::class, 'store']);
    Route::patch('/{id}',              [\App\Http\Controllers\Api\V1\Admin\AiEnginesController::class, 'update']);
    Route::delete('/{id}',             [\App\Http\Controllers\Api\V1\Admin\AiEnginesController::class, 'destroy']);
    Route::post('/{id}/reset-cooldown',[\App\Http\Controllers\Api\V1\Admin\AiEnginesController::class, 'resetCooldown']);
    Route::get('/dicloak/profiles',    [\App\Http\Controllers\Api\V1\Admin\AiEnginesController::class, 'dicloakProfiles']);
    // SEL-ENGRENAGEM (12/08): retrato AO VIVO da frota de video (motor a motor:
    // conta, estado, o que gera agora, entregas, tempo medio) pro painel admin.
    Route::get('/frota',               [\App\Http\Controllers\Api\V1\Admin\AiEnginesController::class, 'frota']);
});

// SEL-358: Feed global de vídeos gerados (super_admin only)
Route::middleware(['auth:sanctum', 'role:super_admin'])->prefix('v1/admin/videostudio')->group(function () {
    Route::get('/feed', [\App\Http\Controllers\Api\V1\VideoFeedController::class, 'index']);
    // SEL-405: tirar/devolver video da galeria do admin (nao apaga o registro)
    Route::delete('/feed/{id}', [\App\Http\Controllers\Api\V1\VideoFeedController::class, 'ocultarDaGaleria'])
        ->where('id', '[A-Za-z0-9\\-]+');
    Route::post('/feed/{id}/restaurar', [\App\Http\Controllers\Api\V1\VideoFeedController::class, 'restaurarNaGaleria'])
        ->where('id', '[A-Za-z0-9\\-]+');
});

// SEL-329: broadcast por email admin
Route::middleware(['auth:sanctum', 'role:admin,super_admin'])->prefix('v1/admin/notifications')->group(function () {
    Route::get('/templates',  [\App\Http\Controllers\Api\V1\AdminNotificationController::class, 'templates']);
    Route::get('/users',      [\App\Http\Controllers\Api\V1\AdminNotificationController::class, 'users']);
    Route::post('/send-email', [\App\Http\Controllers\Api\V1\AdminNotificationController::class, 'sendEmail']);
});

// SEL-115: VideoStudio — wizard completo com persistencia e polling server-side.
// Diferencia do /v1/video/* (SEL-033, proxy fino sem persistencia) por usar
// PollSeedanceTasksJob em background e retornar ai_generations.id.
Route::middleware(["auth:sanctum", "check.user.active", "convite.active"])->prefix("v1/videostudio")->group(function () {
    Route::post("/generate", [\App\Http\Controllers\Api\V1\VideoStudioController::class, "generate"]);
    Route::get("/status/{id}", [\App\Http\Controllers\Api\V1\VideoStudioController::class, "status"]);
    // SEL-120 F2: avatares de video (index/reserve/upload)
    Route::get('/avatars', [\App\Http\Controllers\Api\V1\VideoAvatarController::class, 'index']);
    Route::post('/avatars/{id}/reserve', [\App\Http\Controllers\Api\V1\VideoAvatarController::class, 'reserve']);
    Route::post('/avatars/upload', [\App\Http\Controllers\Api\V1\VideoAvatarController::class, 'upload']);
    // SEL-AVATAR-APAGAR (14/08): o backend respondia "Voce ja tem 3 apresentadores.
    // Apague um para criar outro" e NAO existia rota de apagar em todo o projeto.
    // 6 clientes ficaram presos no limite seguindo uma instrucao sem botao.
    Route::delete('/avatars/{id}', [\App\Http\Controllers\Api\V1\VideoAvatarController::class, 'destroy'])->whereNumber('id');

    // SEL-473: acervo de avatares do ADMIN. O upload acima e do cliente e so
    // grava URL em client_video_avatars; nao acrescenta nada ao acervo
    // video_avatars, que e de onde a grade do Studio tira os apresentadores.
    // Por isso nao havia como o Ruan adicionar o 12o avatar.
    Route::get('/admin/avatars',           [\App\Http\Controllers\Api\V1\AdminVideoAvatarController::class, 'index']);
    Route::post('/admin/avatars',          [\App\Http\Controllers\Api\V1\AdminVideoAvatarController::class, 'store']);
    Route::patch('/admin/avatars/{id}',    [\App\Http\Controllers\Api\V1\AdminVideoAvatarController::class, 'update'])->whereNumber('id');
    // SEL-119: lista publica dos engines/precos Video Studio
    Route::get('/configs', [\App\Http\Controllers\Api\V1\Admin\VideoStudioConfigController::class, 'publicList']);
});

// SEL-galeria-excluir (09/08, Ruan): "excluir" da galeria pessoal do Studio —
// oculta (reversivel, nao apaga registro nem arquivo) o video da VISAO do
// proprio dono, com checagem de user_id. Ver StudioGalleryController — NAO
// reusa /affiliate/videos/{id} (feed GLOBAL de todos os usuarios, sem dono).
Route::middleware(['auth:sanctum', 'check.user.active'])->prefix('v1/studio/gallery')->group(function () {
    Route::delete('/{id}', [\App\Http\Controllers\Api\V1\StudioGalleryController::class, 'hide'])
        ->where('id', '[A-Za-z0-9\-]+');
    Route::post('/{id}/restore', [\App\Http\Controllers\Api\V1\StudioGalleryController::class, 'restore'])
        ->where('id', '[A-Za-z0-9\-]+');
});

// SEL-119 admin: CRUD dos engines/precos Video Studio (super_admin)
Route::middleware(['auth:sanctum', 'check.user.active', 'supplier.panel'])->prefix('v1/admin')->group(function () {
    Route::get('/videostudio/configs', [\App\Http\Controllers\Api\V1\Admin\VideoStudioConfigController::class, 'index']);
    Route::put('/videostudio/configs/{id}', [\App\Http\Controllers\Api\V1\Admin\VideoStudioConfigController::class, 'update']);
});

// SEL-417: Planos de assinatura do servico "Criador de Videos com IA" (video-plans).
// GET /api/v1/video-plans         - publico, sem auth, rate-limit 60/min
// GET /api/v1/video-plans/me      - autenticado, retorna plano+contadores do usuario
Route::get('v1/video-plans', [\App\Http\Controllers\Api\V1\VideoPlansController::class, 'index'])
    ->middleware('throttle:60,1')
    ->name('video-plans.index');

Route::middleware(['auth:sanctum', 'check.user.active'])
    ->get('v1/video-plans/me', [\App\Http\Controllers\Api\V1\VideoPlansController::class, 'me'])
    ->name('video-plans.me');

// SEL-UPGRADE (09/08): botao "Fazer upgrade" — paga so a DIFERENCA pro plano
// superior, sem formulario (reusa dados ja cadastrados). Atras de feature
// flag PLAN_UPGRADE_ENABLED (default false — ver config/services.php).
// GET  /api/v1/plan-upgrade/options  -> plano atual + opcoes com diferenca
// POST /api/v1/plan-upgrade/checkout -> gera cobranca da diferenca (PIX/cartao)
Route::middleware(['auth:sanctum', 'check.user.active'])->prefix('v1/plan-upgrade')->group(function () {
    Route::get('/options', [\App\Http\Controllers\Api\V1\PlanUpgradeController::class, 'options'])
        ->name('plan-upgrade.options');
    Route::post('/checkout', [\App\Http\Controllers\Api\V1\PlanUpgradeController::class, 'checkout'])
        ->middleware('throttle:10,1')
        ->name('plan-upgrade.checkout');
});

// SEL-198: rotas /api/v1/tiktok/sellers (Fornecedores TT dentro do TiktokShopping cliente)
require __DIR__ . '/tiktok-sellers.php';

// SEL-218: vídeos virais TikTok BR (product finds, achadinhos) — populado por ScrapeTiktokViralVideosJob
// RestrictFreeAccess já cobre api/v1/tiktok* e api/v1/tiktok-shop* — free tier pode acessar.
Route::middleware(['auth:sanctum', 'check.user.active'])
    ->get('v1/tiktok-viral-videos', [\App\Http\Controllers\Api\V1\TiktokViralVideoController::class, 'index'])
    ->name('tiktok.viral-videos.index');


// =========================================================================
// SEL-321 — Video Perfeito (Modo A) + Clone (Modo B) + Imagens multi-fonte
// =========================================================================

// Imagens multi-fonte de produto TikTok Shop (cascata kalodata→catálogo→scrape)
Route::middleware(['auth:sanctum', 'check.user.active'])->group(function () {
    Route::get('v1/insights/tiktok/product-images/{key}', [\App\Http\Controllers\Api\V1\TiktokProductImagesController::class, 'index'])
        ->where('key', '[0-9]+');
    Route::post('v1/insights/tiktok/product-images/{key}/upload', [\App\Http\Controllers\Api\V1\TiktokProductImagesController::class, 'upload'])
        ->where('key', '[0-9]+');
});

// Orquestradores de geração de vídeo (Modo A e Modo B)
Route::middleware(['auth:sanctum', 'check.user.active'])->group(function () {
    // Modo A — Vídeo Perfeito (multi-shot Kling v3 + avatar + pitch + lipsync)
    Route::post('v1/ai/video-perfect', [\App\Http\Controllers\Api\V1\AiVideoPerfectController::class, 'videoPerfect']);
    // Modo POV -- So Mao, Sem Rosto (image2video sem avatar + 3 shots POV)
    Route::post('v1/ai/video-pov', [\App\Http\Controllers\Api\V1\AiVideoPerfectController::class, 'videoPov']);
        // Modo B — Clone viral (swap personagem + voz)
    Route::post('v1/ai/video-clone', [\App\Http\Controllers\Api\V1\AiVideoPerfectController::class, 'videoClone']);
    // Modo Showcase Silencioso — avatar apresenta produto SEM FALA (SEL-332)
    Route::post('v1/ai/video-showcase', [\App\Http\Controllers\Api\V1\AiVideoPerfectController::class, 'videoShowcase']);
    // Biblioteca de audio royalty-free (SEL-332)
    Route::get('v1/ai/audio-library', [\App\Http\Controllers\Api\V1\AiVideoPerfectController::class, 'audioLibrary']);
    Route::post('v1/ai/video-director', [\App\Http\Controllers\Api\V1\AiVideoDirectorController::class, 'direct']);
    // SEL-336: Modelar Viral -- gera video Kling seguindo estrutura de video viral Kalodata
    Route::post('v1/ai/video-modelar-viral', [\App\Http\Controllers\Api\V1\AiVideoPerfectController::class, 'videoModelarViral']);
    // SEL-333: Video Director -- analisa produto e escolhe pipeline automaticamente
    // Status do pipeline (labels WHITE-LABEL — nunca expõe provider)
    // SEL-TENHO-VIDEO-GERANDO (14/08, caso aemdcar) — a trava do navegador nem sempre
    // guarda o id do pedido: quando ela nasce de um erro (429), nasce SEM id, e ai a
    // conferencia com o servidor nao tinha o que perguntar. O cliente ficava com o
    // botao dizendo "Aguarde seu video..." girando por 20min com NADA gerando.
    // Esta rota responde a pergunta certa: este cliente tem ALGUM video em andamento?
    Route::get('v1/ai/tenho-video-gerando', function (\Illuminate\Http\Request $r) {
        $emAndamento = \Illuminate\Support\Facades\DB::table('ai_video_pipelines')
            ->where('user_id', $r->user()->id)
            ->whereIn('step', ['queued', 'render', 'processing', 'voice', 'lipsync', 'queued_wait'])
            ->orderByDesc('id')
            ->first(['id', 'step', 'created_at']);

        return response()->json([
            'gerando'     => (bool) $emAndamento,
            'pipeline_id' => $emAndamento->id ?? null,
            'passo'       => $emAndamento->step ?? null,
            'desde'       => $emAndamento->created_at ?? null,
        ]);
    })->middleware(['auth:sanctum']);

    Route::get('v1/ai/video-pipeline/{id}', [\App\Http\Controllers\Api\V1\AiVideoPerfectController::class, 'pipelineStatus'])
        ->where('id', '[0-9]+');
    // Download via nossa URL (sellerglobal-video-{id}.mp4)
    // SEL-MEUS-CENARIOS (14/08, ideia dos clientes na live do Ruan): o cenario
    // que o cliente sobe ou escreve fica guardado pra ele reusar na proxima.
    Route::get('v1/videostudio/scenes', [\App\Http\Controllers\Api\V1\ClientSceneController::class, 'index']);
    Route::post('v1/videostudio/scenes', [\App\Http\Controllers\Api\V1\ClientSceneController::class, 'store']);
    Route::delete('v1/videostudio/scenes/{id}', [\App\Http\Controllers\Api\V1\ClientSceneController::class, 'destroy'])
        ->where('id', '[0-9]+');

    Route::get('v1/ai/video/{id}/download', [\App\Http\Controllers\Api\V1\AiVideoPerfectController::class, 'download'])
        ->where('id', '[0-9]+');

    // SEL-DOWNLOAD-GEN (14/08): irma da rota de cima, pros videos que vem de
    // `ai_generations` (id "gen-*" na galeria). Sao MAIS DA METADE da producao
    // (medido: 390 videos de 54 clientes em 7 dias) e nao tinham download que
    // funcionasse — o site caia no arquivo cru, sem CORS, e o celular so tocava.
    Route::get('v1/ai/generation/{id}/download', [\App\Http\Controllers\Api\V1\AiVideoPerfectController::class, 'downloadGeneration'])
        ->where('id', '[0-9]+');
});

// SEL-360 — Studio Chat: diretor de vídeo conversacional (substitui 16 botões por chat IA)
Route::middleware(['auth:sanctum', 'check.user.active', 'convite.active'])->prefix('v1/studio-chat')->group(function () {
    Route::post('/', [\App\Http\Controllers\Api\V1\StudioChatController::class, 'chat']);
    Route::post('/upload', [\App\Http\Controllers\Api\V1\StudioChatController::class, 'upload']);
    // SEL-360 Fase 2: SSE EventSource nao suporta headers customizados.
    // sanctum.query injeta ?token=xxx como Bearer ANTES do auth:sanctum do grupo rodar.
    Route::get('/generation/{id}/progress', [\App\Http\Controllers\Api\V1\StudioChatController::class, 'progress'])
        ->where('id', '[0-9]+')
        ->middleware('sanctum.query');
    Route::post('/tts', [\App\Http\Controllers\Api\V1\StudioChatController::class, 'tts']);
    Route::get('/conversations', [\App\Http\Controllers\Api\V1\StudioChatController::class, 'conversations']);
    // SEL-361 Fase E — Modo Prompt Livre
    Route::post('/custom-prompt', [\App\Http\Controllers\Api\V1\StudioChatController::class, 'customPrompt']);
    Route::get('/custom-prompt/history', [\App\Http\Controllers\Api\V1\StudioChatController::class, 'customPromptHistory']);
    Route::post('/improve-prompt', [\App\Http\Controllers\Api\V1\StudioChatController::class, 'improvePrompt']);
});

// SEL-361 Fase A — Studio prepare-context (entry point unico) + avatar exclusivo + audio + feedback
Route::middleware(["auth:sanctum", "check.user.active", "convite.active"])->prefix("v1/studio")->group(function () {
    Route::post("/prepare-context", [\App\Http\Controllers\Api\V1\StudioPrepareController::class, "prepareContext"]);
    Route::get("/context/{contextId}", [\App\Http\Controllers\Api\V1\StudioPrepareController::class, "getContext"]);
    Route::get("/kling-catalog", [\App\Http\Controllers\Api\V1\StudioPrepareController::class, "klingCatalog"]);
    // SEL-417: idiomas que ESTA conta pode escolher (pt-BR so super_admin, marcado demo)
    Route::get("/languages", [\App\Http\Controllers\Api\V1\StudioPrepareController::class, "languages"]);
    // SEL-437: metadados REAIS do produto pros selos da Galeria (nunca inventa)
    Route::get("/gallery-meta", [\App\Http\Controllers\Api\V1\StudioGalleryMetaController::class, "show"]);
});

// SEL-361 Fase A — Endpoints adicionais studio-chat
Route::middleware(["auth:sanctum", "check.user.active", "convite.active"])->prefix("v1/studio-chat")->group(function () {
    Route::post("/generate-exclusive-avatar", [\App\Http\Controllers\Api\V1\StudioPrepareController::class, "generateExclusiveAvatar"]);
    Route::post("/upload-audio", [\App\Http\Controllers\Api\V1\StudioPrepareController::class, "uploadAudio"]);
    Route::post("/feedback", [\App\Http\Controllers\Api\V1\StudioPrepareController::class, "storeFeedback"]);
});

// SEL-505 — Feedback pos-video (estrelas 1-5 + sugestao de negocio pro Ruan)
Route::middleware(["auth:sanctum", "check.user.active"])->group(function () {
    Route::post("v1/video-feedback", [\App\Http\Controllers\Api\V1\VideoFeedbackController::class, "store"]);
});
Route::middleware(["auth:sanctum", "check.user.active", "role:admin,super_admin"])->group(function () {
    Route::get("v1/admin/video-feedbacks", [\App\Http\Controllers\Api\V1\VideoFeedbackController::class, "adminIndex"]);
});

// SEL-feedback-video (12/08, Ruan ao vivo) — ciclo de feedback guiado pos-video:
// motivo pre-definido -> detalhe -> responsavel automatico -> admin conserta ->
// avisa cliente (push) -> cliente aperta Refazer. Tabela isolada
// video_feedback_reports (distinta de video_feedback SEL-361 e video_feedbacks
// SEL-505 -- essas duas nao tem workflow/responsavel/aviso).
Route::middleware(["auth:sanctum", "check.user.active"])->prefix("v1/video-feedback-reports")->group(function () {
    Route::post("/", [\App\Http\Controllers\Api\V1\VideoFeedbackReportController::class, "store"]);
    Route::get("/mine", [\App\Http\Controllers\Api\V1\VideoFeedbackReportController::class, "mine"]);
});
Route::middleware(["auth:sanctum", "check.user.active", "role:admin,super_admin"])->prefix("v1/admin/video-feedback-reports")->group(function () {
    Route::get("/", [\App\Http\Controllers\Api\V1\VideoFeedbackReportController::class, "adminIndex"]);
    Route::patch("/{id}", [\App\Http\Controllers\Api\V1\VideoFeedbackReportController::class, "adminUpdate"])->whereNumber("id");
});

// SEL-387 — Studio Kaloclip: viral-suggestions + prompt-preview
Route::middleware(['auth:sanctum', 'check.user.active'])->group(function () {
    // POST /api/v1/studio/viral-suggestions — 4 virais Kalodata para o carousel do Studio Chat
    Route::post('v1/studio/viral-suggestions', [\App\Http\Controllers\Api\V1\StudioViralSuggestionsController::class, 'suggestions']);
    // POST /api/v1/ai/prompt-preview — prompt 10 secoes Kaloclip com duration=12 + language=pt-BR + aspect=9:16
    Route::post('v1/ai/prompt-preview', [\App\Http\Controllers\Api\V1\PromptPreviewController::class, 'preview']);
});

// SEL-452 — Studio por OPCOES (Ruan 30/07: "coloca as opcoes e tira a conversa
// com a IA"). Caminho principal deixa de ser conversa: o cliente escolhe e gera.
// A camera vai por TEXTO no prompt — ver o bloco no topo do StudioOptionsController
// explicando por que camera_preset NAO chega no motor em KLING_MODE=browser.
Route::middleware(["auth:sanctum", "check.user.active"])->prefix("v1/studio-options")->group(function () {
    Route::get("/catalog", [\App\Http\Controllers\Api\V1\StudioOptionsController::class, "catalog"]);
    Route::post("/generate", [\App\Http\Controllers\Api\V1\StudioOptionsController::class, "generate"]);
    // SEL-refazer (09/08): refaz reusando a pipeline original + modificacao do cliente
    Route::post("/refazer", [\App\Http\Controllers\Api\V1\StudioOptionsController::class, "refazer"]);
    // INF-030 (07/08): coleta o texto livre dos ganchos (abertura/meio/final/cenario)
    Route::post("/option-bank", [\App\Http\Controllers\Api\V1\StudioOptionsController::class, "optionBank"]);
    // INF-030 (07/08): favoritar (⭐) estilo/gancho/avatar/cenario/formato pra reuso rápido
    Route::get("/favorites", [\App\Http\Controllers\Api\V1\StudioOptionsController::class, "favoritesIndex"]);
    Route::post("/favorites", [\App\Http\Controllers\Api\V1\StudioOptionsController::class, "favoritesToggle"]);
});

// NOV-214 — Antifraude WL MVP: auditoria de clientes por empresa WL
// GET /api/v1/admin/whitelabels/{empresaId}/audit       — suspeitas de fraude pre/pos fechamento
// GET /api/v1/admin/whitelabels/{empresaId}/audit/events — log completo de acoes
Route::middleware(["auth:sanctum", "role:admin,super_admin"])
    ->prefix("v1/admin/whitelabels")
    ->group(function () {
        Route::get("/{empresaId}/audit", [
            \App\Http\Controllers\Api\V1\Admin\WhitelabelAuditController::class,
            "fraudSuspects",
        ])->where("empresaId", "[0-9]+");

        Route::get("/{empresaId}/audit/events", [
            \App\Http\Controllers\Api\V1\Admin\WhitelabelAuditController::class,
            "events",
        ])->where("empresaId", "[0-9]+");
    });

// SEL-430: Rota admin para flush manual do cache billing gate WL (< 1s).
// Útil para Ruan desbloquear WL direto via painel sem esperar TTL de 60s.
// Chamada automática pelo api.seller.global quando Ruan clica Desbloquear/Marcar pago.
// POST /api/v1/admin/wl-billing/{empresa_nome}/flush-cache
// Auth: auth:sanctum + role:super_admin
Route::middleware(['auth:sanctum', 'role:super_admin'])
    ->prefix('v1/admin/wl-billing')
    ->group(function () {
        Route::post('/{empresa_nome}/flush-cache', [
            \App\Http\Controllers\Internal\WlBillingFlushController::class,
            'flush',
        ]);
    });

// ===========================================================================
// NOV-217 — Whitelabel Billing Routes (migrado de edge functions Supabase)
// ===========================================================================

// GET /api/v1/wl/balance/{empresa_id}?token=X
// Equivalente a edge function wl-balance (Supabase omvstizxjosygkcolzzl)
// Publico com validacao por token de acesso da WL
Route::get('v1/wl/balance/{empresa_id}', [\App\Http\Controllers\Api\V1\WlBalanceController::class, 'show'])
    ->where('empresa_id', '[0-9]+');

// POST /api/v1/public/billing-pagarme
// Equivalente a edge function billing-pagarme (checkout PIX/cartao hubai.io)
// Thin proxy: tenta a edge function original, usa fallback Laravel se 404
Route::post('v1/public/billing-pagarme', [\App\Http\Controllers\Api\V1\Public\BillingPagarmeController::class, 'handle']);

// POST /api/v1/wl/cycle/close — Fecha ciclo mensal (admin)
// POST /api/v1/wl/sync       — Sincroniza snapshots diarios (admin)
Route::middleware(['auth:sanctum', 'role:admin,super_admin'])->prefix('v1/wl')->group(function () {
    Route::post('/cycle/close', [\App\Http\Controllers\Api\V1\WlCycleController::class, 'close']);
    Route::post('/sync',        [\App\Http\Controllers\Api\V1\WlCycleController::class, 'sync']);
});

// POST /api/v1/wl/pay-cycle — NOV-223: Gera PIX Pagar.me para fatura HubAI de WL (publico — admin WL chama sem auth seller.global)
Route::post('v1/wl/pay-cycle', [\App\Http\Controllers\Api\V1\WlPayCycleController::class, 'handle']);

// ===========================================================================
// NOV-217 admin WL — Live counts + config update via service_role
// ===========================================================================
// GET  /api/v1/admin/wl/live-counts              — Conta clientes live nos bancos das WLs
// PATCH /api/v1/admin/wl/{id}/config             — Atualiza preco/notas (requer service_role)
// PATCH /api/v1/admin/wl/{id}/block              — Bloquear/desbloquear WL
Route::middleware(['auth:sanctum', 'role:admin,super_admin'])->prefix('v1/admin/wl')->group(function () {
    Route::get('/live-counts',          [\App\Http\Controllers\Api\V1\AdminWlController::class, 'liveCounts']);
    Route::patch('/{empresa_id}/config', [\App\Http\Controllers\Api\V1\AdminWlController::class, 'updateConfig'])->where('empresa_id', '[0-9]+');
    Route::patch('/{empresa_id}/block',  [\App\Http\Controllers\Api\V1\AdminWlController::class, 'toggleBlock'])->where('empresa_id', '[0-9]+');
    // NOV-JT-AUDIT: GET /api/v1/admin/wl/{id}/pedidos-auditoria?cycle=YYYY-MM
    Route::get('/{empresa_id}/pedidos-auditoria', [\App\Http\Controllers\Api\V1\AdminWlController::class, 'pedidosAuditoria'])->where('empresa_id', '[0-9]+');
    // BillingRules v2: comparativo regra antiga vs nova (ZERO write, so SELECT)
    Route::get("/{empresa_id}/pedidos-auditoria-v2", [\App\Http\Controllers\Api\V1\AdminWlController::class, "pedidosAuditoriaV2"])->where("empresa_id", "[0-9]+");
    // SEL-markpaid: marca ciclo de cobranca como pago manualmente (PIX/transf/etc)
    Route::patch("/cycles/{cycle_id}/mark-paid",   [\App\Http\Controllers\Api\V1\AdminWlController::class, "markCyclePaid"]);
    Route::patch("/cycles/{cycle_id}/unmark-paid", [\App\Http\Controllers\Api\V1\AdminWlController::class, "unmarkCyclePaid"]);
    // SEL-wlpaid: marca a WL como paga (ciclo mais recente por empresa_id) + desbloqueia numa chamada so
    Route::patch('/{empresa_id}/mark-paid', [\App\Http\Controllers\Api\V1\AdminWlController::class, 'markPaidAndUnblock'])->where('empresa_id', '[0-9]+');
});

// SEL-painel-jt: endpoint público retorna produtos reais JT Drop (supplier_id=26)
// Usado pelos painéis /afiliado/painel-shopee e /afiliado/painel-mercadolivre
Route::get("v1/public/jtdrop-products", function () {
    // SEL-520: prefer CORS-friendly CDN images (goolhub.io, multdrop.app, b-cdn.net)
    $rows = \Illuminate\Support\Facades\DB::select("
        SELECT p.id, p.name, p.price, pm.url as image_url
        FROM products p
        INNER JOIN product_media pm ON pm.product_id = p.id
        WHERE p.supplier_id = 26
          AND p.is_active = 1
          AND pm.url IS NOT NULL
          AND pm.position = 0
          AND (
            pm.url LIKE '%multdrop-images.b-cdn.net%'
            OR pm.url LIKE '%fornecefy-images.b-cdn.net%'
            OR pm.url LIKE '%hub-imgcdn.b-cdn.net%'
          )
        ORDER BY RAND()
        LIMIT 80
    ");
    if (empty($rows)) {
        $rows = \Illuminate\Support\Facades\DB::select("
            SELECT p.id, p.name, p.price, pm.url as image_url
            FROM products p
            INNER JOIN product_media pm ON pm.product_id = p.id
            WHERE p.supplier_id = 26
              AND p.is_active = 1
              AND pm.url IS NOT NULL
              AND pm.position = 0
            ORDER BY RAND()
            LIMIT 80
        ");
    }
    return response()->json(array_values($rows));
});

// SEL-DC datacenter ao vivo
Route::get("v1/datacenter/live", [\App\Http\Controllers\Api\V1\DatacenterController::class, "live"]);

// SEL-462 Fase 1: Ingest endpoints para o worker Kalodata (VM DICloak).
// Autenticado via X-Kalodata-Token (env KALODATA_INGEST_TOKEN).
// Throttle 500/min: worker faz muitos uploads pequenos de midia.
Route::prefix('v1/insights/tiktok/ingest')
    ->middleware(['auth.kalodata_token', 'throttle:500,1'])
    ->group(function () {
        Route::post('creators', [\App\Http\Controllers\Api\V1\KalodataIngestController::class, 'creators']);
        Route::post('products', [\App\Http\Controllers\Api\V1\KalodataIngestController::class, 'products']);
        Route::post('ads',      [\App\Http\Controllers\Api\V1\KalodataIngestController::class, 'ads']);
        Route::post('lives',    [\App\Http\Controllers\Api\V1\KalodataIngestController::class, 'lives']);
        Route::post('brands',   [\App\Http\Controllers\Api\V1\KalodataIngestController::class, 'brands']);
        Route::post('shops',    [\App\Http\Controllers\Api\V1\KalodataIngestController::class, 'shops']);
        Route::post('media',    [\App\Http\Controllers\Api\V1\KalodataIngestController::class, 'media']);
    });
