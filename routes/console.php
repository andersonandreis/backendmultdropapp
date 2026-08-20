<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('hubai:predict-rupture')->dailyAt('00:00')->withoutOverlapping();

// SEL-GEN-ORFA (14/08): o worker de navegador TERMINA o video e grava o resultado
// no estado da tarefa, mas ninguem escrevia isso de volta em `ai_generations` --
// a linha ficava `processing` pra sempre com o mp4 pronto no disco (medido:
// gen#608, 2.732.102 bytes, o cliente nao via nada). Isto costura de volta.
Schedule::command('video:reconcile-generations')->everyMinute()->withoutOverlapping();

// SEL-TRAVA-SOLTA (14/08): a vaga de geracao so era devolvida no caminho feliz.
// Quando o video terminava por outro caminho, a reserva ficava aberta pra sempre e
// travava o cliente (no plano de 1 video/dia, por 24h). Eram 279 penduradas.
Schedule::command('video:libera-reservas')->everyMinute()->withoutOverlapping();

// SEL-256/SEL-308 — snapshot Kalodata + localizacao de midia TikTok (aprovado Ruan 21/07).
// ATIVO SO no backend com KALODATA_CRON=true no .env (api.seller.global) —
// repo compartilhado por 7 backends, os outros ficam com flag ausente/false.
if (config('services.kalodata.cron')) {
    Schedule::command('kalodata:sync')->dailyAt('06:00')->timezone('America/Sao_Paulo')->withoutOverlapping();
    Schedule::command('kalodata:sync')->dailyAt('12:00')->timezone('America/Sao_Paulo')->withoutOverlapping();
    Schedule::command('kalodata:sync')->dailyAt('20:00')->timezone('America/Sao_Paulo')->withoutOverlapping();
    // tt:media-localize 30min apos cada sync — cobre o que o ensureLocal() inline nao pegou
    Schedule::command('tt:media-localize')->dailyAt('06:30')->timezone('America/Sao_Paulo')->withoutOverlapping();
    Schedule::command('tt:media-localize')->dailyAt('12:30')->timezone('America/Sao_Paulo')->withoutOverlapping();
    Schedule::command('tt:media-localize')->dailyAt('20:30')->timezone('America/Sao_Paulo')->withoutOverlapping();
    // SEL-336 -- transcricao + insight viral dos top videos Kalodata (apos sync 06:00)
    Schedule::command('kalodata:analyze-videos', ['--limit' => 20, '--country' => 'BR'])
        ->dailyAt('07:00')->timezone('America/Sao_Paulo')->withoutOverlapping();
}

// FOR-088b: feeder continuo do banco de titulo/descricao (product_ai_cache) —
// mantem >=12 linhas de reserva por produto ativo, gerado por template (sem OpenAI).
// ATIVO SO no backend com AI_BANK_REFILL_CRON=true no .env (api.fornecefy.io) —
// repo compartilhado por 4 backends, os outros ficam com flag ausente/false.
if (config('services.ai_bank.refill_cron')) {
    Schedule::command('bank:refill-fornecefy')->hourly()->withoutOverlapping()->name('bank-refill-fornecefy');
}

// [DESATIVADO FOR-036 2026-06-26] preco catálogo = preco do fornecedor, sem desconto
// [DESATIVADO FOR-036 2026-06-26] Schedule::command('discount:update-daily')->dailyAt('03:00')->withoutOverlapping();

// OAuth token refresh — renova tokens expirando em < 1h (ML: 6h, Shopee: 4h, Bling: 6h)
// [DESATIVADO NOV-061 2026-06-24] Substituido por lazy refresh em getValidAccessToken()
// Com volume alto de clientes, crons proativos geram milhares de chamadas desnecessarias
// Schedule::job(new \App\Jobs\RefreshTokensJob)->everyThirtyMinutes()->withoutOverlapping();

// Fallback etiquetas — pega pedidos stuck que nao receberam webhook (max 5 tentativas, backoff exponencial)
// MUL-339: alimenta a fila que o CheckLabelAvailabilityJob le. Ate 06/08 a order_label_queues
// so era preenchida por clique manual no admin — 2 linhas em tres meses — e o job rodava de
// meia em meia hora processando NADA, enquanto 2.160 pedidos ficavam parados em
// awaiting_marketplace, 82,6% deles ha mais de 3 dias. De hora em hora, antes da verificacao.
// MUL-372: era hourly/30min — pedido precisa despachar antes das 13h; ciclo lento
// somava 30-40 min de atraso ate etiqueta/autopay quando a 1a tentativa falhava.
Schedule::command("labels:enqueue")->everyThirtyMinutes()->withoutOverlapping()->name("labels-enqueue");

// MUL-340: devolucoes. O sistema nao acompanhava nenhuma — order_returns tinha 0 linhas e a API
// da Shopee nunca era consultada, enquanto uma conta so tinha 63 devolucoes e R$ 2.318,16
// estornados. 79%% delas sao erro de expedicao (WRONG_ITEM, ITEM_MISSING), entao o dado tambem
// mede a qualidade do fornecedor. De hora em hora: prazo vencido vira estorno automatico.
Schedule::command("returns:sync")->hourly()->withoutOverlapping()->name("returns-sync");
Schedule::job(new \App\Jobs\CheckLabelAvailabilityJob)->everyTenMinutes()->withoutOverlapping(); // MUL-372: era 30min

// NOV-072: Dispatcher da fila product_listing_jobs - publica ClientProducts nos marketplaces
// slow=1/min, normal=5/min, fast=20/min por cliente (controlado pelo campo speed do job)
Schedule::job(new \App\Jobs\ProductListingDispatcherJob)->everyMinute()->withoutOverlapping()->name('product-listing-dispatcher');

// AutoListingDispatcherJob schedule will be enabled when AutoListing feature is deployed
// Schedule::job(new \App\Jobs\AutoListingDispatcherJob)->everyMinute()->withoutOverlapping();

// Marketplace health monitoring — verifica reputacao, taxas de cancelamento, reclamacoes
Schedule::job(new \App\Jobs\CheckMarketplaceHealthJob)->dailyAt('06:00')->withoutOverlapping();

// MES-023 — Verifica status real dos anúncios ML ativos a cada 2h (ML não envia webhook quando ele
// próprio pausa um anúncio por foto ruim, review, conta nova, etc). Usa batch GET /items?ids=...
Schedule::job(new \App\Jobs\CheckMLListingsJob)->everyTwoHours()->withoutOverlapping()->name('check-ml-listings');

// Retenta webhooks com status 'pending' (max 3 tentativas, janela de 24h)
Schedule::job(new \App\Jobs\RetryFailedWebhooksJob)->everyFifteenMinutes()->withoutOverlapping();

// Expirar transacoes PIX vencidas sem confirmacao do gateway
Schedule::job(new \App\Jobs\ExpirePixTransactionsJob)->everyFiveMinutes()->withoutOverlapping();
// SEL (07/08, Ruan pra nunca mais): monitor de saude da geracao de video -> push pro Ruan se travar.
Schedule::command("video:health-monitor")->everyFiveMinutes()->withoutOverlapping();
// AUTO-CURA do pool de video do PC: probe host + reintegra remotos + alerta so se PC fora >8min (video segue no motor local enquanto isso).
Schedule::command("video:pc-autocura")->everyMinute()->withoutOverlapping();
// SEL-ENGRENAGEM (12/08): cruza o estado REAL dos perfis do PC (pool.json) com
// ai_engines -> o pool para de reservar motor deslogado (que so queimava o timeout
// do cliente) e devolve sozinho o motor que voltou. Tambem publica o retrato unico
// da frota em storage/app/frota-video.json pro painel admin.
Schedule::command("video:frota-sync")->everyMinute()->withoutOverlapping();
// SEL-LEASE (12/08) — as duas metades do guardiao rodam em RITMOS DIFERENTES,
// de proposito:
//
//  ZELADOR (todo minuto): devolve a trava de motor cujo dono parou de bater.
//  E local, instantaneo e nao toca em rede -- entao roda no ritmo mais rapido
//  que faz sentido. Antes disso, trava vazada so sumia quando o TTL de 600s
//  vencia; no dia 12/08 foram 36 reservas nunca devolvidas contra 98 geracoes.
//
//  SONDA (a cada 3 min): pergunta ao proprio Google, dentro de cada perfil, se a
//  conta ainda esta logada. Cadenciada junto com o keep-alive do PC (tambem 3
//  min) pra NAO somar batida de robo na conta do Google -- ainda mais com
//  trabalho de migracao de sessao em curso na maquina. 3 min tambem continua
//  bem abaixo do SESSAO_FRESCA_S (300s) que o pool exige pra aceitar o veredito.
Schedule::command("video:lease-guardiao --so-zelar")->everyMinute()->withoutOverlapping();

Schedule::command("video:lease-guardiao --so-sondar")->everyThreeMinutes()->withoutOverlapping();


// Auditoria financeira: detecta pedidos que passaram sem pagar o fornecedor e
// registra divida como saldo negativo. Roda a cada 4h (rede de segurança geral).
Schedule::job(new \App\Jobs\AuditUnpaidOrdersJob)->everySixHours()->withoutOverlapping()->name('audit-unpaid-orders');

// Fallback PIX: sincroniza status de PIX pendentes com o gateway a cada 15min.
// Cobre casos em que o postback_url nao foi chamado (downtime, erro transiente do Shipay).
// Limita a 50 por execucao para nao sobrecarregar a API do gateway.
Schedule::call(function () {
    $pixService = app(\App\Services\Financial\PixService::class);
    \App\Models\PixTransaction::where('status', 'pending')
        ->where('expires_at', '>', now())
        ->where('created_at', '>', now()->subHours(24))
        ->orderBy('updated_at')
        ->limit(50)
        ->get()
        ->each(function ($pix) use ($pixService) {
            try {
                $pixService->syncPixStatus($pix);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[PixPollCron] Erro ao sincronizar PIX', [
                    'pix_id'      => $pix->id,
                    'external_id' => $pix->external_id,
                    'error'       => $e->getMessage(),
                ]);
            }
        });
})->everyFifteenMinutes()->name('pix-status-poll')->withoutOverlapping();

// Sync catalogo legado -> inventory.quantity. everyMinute: near-real-time p/ os 28 suppliers ativos (trigger bumpa data_update em mudanca de estoque). Era 2h.
if (config('app.legacy_sync_enabled')) {
    Schedule::job(new \App\Jobs\SyncLegacyCatalogJob)->everyMinute()->withoutOverlapping();
} // NOV-169/INF-023: sync legado desativado por decisao Ruan 02/07 (religar: LEGACY_SYNC_ENABLED=true)

// Taxas de marketplace — seed defaults + sync ML via API (toda segunda 03:00)
Schedule::command('fees:sync all')->weeklyOn(1, '03:00')->withoutOverlapping();

// [DESATIVADO 2026-05-17] CancelExpiredManualOrdersJob
// Motivo: pedidos manuais agora tem legacy_id apontando para o banco legado.
// O legado controla expiracao de PIX para esses pedidos.
// O job continua existindo para possivel reativacao com WHERE legacy_id IS NULL
// caso queira cancelar pedidos manuais orfaos (sem legacy_id + source=manual + 24h+).
// Schedule::job(new \App\Jobs\CancelExpiredManualOrdersJob)->everyFiveMinutes()->withoutOverlapping();

// Sync status pedidos manuais do legado -- puxa status/rastreio/etiqueta a cada 5min
if (config('app.legacy_sync_enabled')) {
    Schedule::job(new \App\Jobs\SyncLegacyOrdersJob)->everyFiveMinutes()->withoutOverlapping()->name('sync-legacy-orders');
} // NOV-169/INF-023: sync legado desativado por decisao Ruan 02/07 (religar: LEGACY_SYNC_ENABLED=true)
// INF-023: BackfillOrderCostsJob - preenche supplier_unit_cost incrementalmente (N+1 removido do SyncLegacyOrdersJob)
Schedule::job(new \App\Jobs\BackfillOrderCostsJob)->everyTenMinutes()->withoutOverlapping()->name('backfill-order-costs');
// MUL-216: refill de custos via catalogo (race fillCosts x sync Bling - price chega depois da promocao)
Schedule::command('orders:refill-costs')->hourlyAt(50)->withoutOverlapping();
// [DESATIVADO FOR-038 2026-06-27] SyncLegacyFinanceJob nao se aplica ao Fornecefy
// No Fornecefy nao existem clientes migrados do legado com supplier_id=30.
// Schedule::job(new \App\Jobs\SyncLegacyFinanceJob)->everyFiveMinutes()->withoutOverlapping()->name('sync-legacy-finance');

// Importa pedidos NOVOS do legado que ainda nao existem no NovoHubAI (de-dup por legacy_id).
// Roda a cada 5 min, BATCH_PER_CLIENT=100 por cliente por execucao. Complementa o SyncLegacyOrdersJob
// que so atualiza status de pedidos ja importados.
if (config('app.legacy_sync_enabled')) {
    Schedule::job(new \App\Jobs\ImportLegacyOrdersJob)->everyFiveMinutes()->withoutOverlapping()->name('import-legacy-orders');
} // NOV-169/INF-023: sync legado desativado por decisao Ruan 02/07 (religar: LEGACY_SYNC_ENABLED=true)

// Backfill legacy_id_login -- popula novos clients migrados do legado (diario 02:30)
if (config('app.legacy_sync_enabled')) {
    Schedule::command('backfill:legacy-id-login')->dailyAt('02:30')->withoutOverlapping();
} // NOV-169/INF-023: sync legado desativado por decisao Ruan 02/07 (religar: LEGACY_SYNC_ENABLED=true)

// Reconciliacao de pedidos perdidos — detecta pedidos nao capturados por webhook
// Roda a cada hora; itera clientes ativos (Pro/Scale) e despacha um job por cliente
Schedule::call(function () {
    \App\Models\Client::whereHas('subscriptions', fn($q) => $q->whereIn('status', ['active', 'trialing']))
        ->chunkById(50, function ($clients) {
            foreach ($clients as $client) {
                // Apenas clientes Pro ou Scale (max_skus > 30)
                // Start fica de fora para economizar rate limit das APIs de marketplace
                if (optional($client->subscriptions()->whereIn('status', ['active', 'trialing'])->with('plan')->first())->plan?->max_skus > 30) {
                    \App\Jobs\ReconcileMarketplaceOrdersJob::dispatch($client->id);
                }
            }
        });
})->hourly()->name('reconcile-missed-orders')->withoutOverlapping();

// Backfill legacy_empresa_id -- popula suppliers.legacy_empresa_id a partir de deposito.menu_titulo (diario 02:35)
if (config('app.legacy_sync_enabled')) {
    Schedule::command('backfill:legacy-empresa-id')->dailyAt('02:35')->withoutOverlapping();
} // NOV-169/INF-023: sync legado desativado por decisao Ruan 02/07 (religar: LEGACY_SYNC_ENABLED=true)

// NOV-166-B: Monitora NFs do fornecedor via Bling ERP (diario 03:00)
Schedule::job(new \App\Jobs\FetchSupplierInvoicesJob)->dailyAt('03:00')->withoutOverlapping();



// Supplier Core / Fase 3 / M5 — monitor de divergencia (every 5 min)
\Illuminate\Support\Facades\Schedule::command('tenant:divergence-check')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ;

// Drop Internacional - Fase 2: polling de rastreio a cada 6h
Schedule::job(new \App\Jobs\Drop\TrackingPollingJob)->everySixHours()->name('drop-tracking-poll')->withoutOverlapping();


// ML auto-retry — re-tenta produtos com sync_status=error a cada 15 min (max 10 por vez, ignora erros < 15min)
// [DESATIVADO 2026-06-15 - comando ml:retry-failed nao existe] Schedule::command('ml:retry-failed --max=10 --age=15')->everyFifteenMinutes()->withoutOverlapping();



// —————————————————————————————————————————————————————————————————————————————
// Sentinela Fornecefy — monitoramento 24/7 das integracoes (Shopee + ML)
// Sentinela a cada 30min: verifica tokens, auto-renova, alerta Ruan via Telegram
// se houver problema (needs_reauth ou error). Tokens renovados com sucesso = silencio.
// [DESATIVADO 2026-06-13 Ruan - zero Telegram] Schedule::command('integrations:sentinela')->everyThirtyMinutes()->withoutOverlapping();

// Relatorio diario as 07h00: envia resumo completo via Telegram mesmo se tudo OK
// [DESATIVADO 2026-06-13 Ruan - zero Telegram] Schedule::command('integrations:sentinela --daily')->dailyAt('07:00')->withoutOverlapping();

// Shopee connection health check — verifica tokens ativos, marca expired se invalido
Schedule::command('marketplace:health-check')->everyThirtyMinutes()->withoutOverlapping();


// Shopee token refresh — renova tokens legados com expiry NULL e tokens proximos de vencer (< 4h)
// Criado em 17/06/2026 (MUL-021): 25 tokens legados sem token_expires_at nao eram renovados pelo RefreshTokensJob
Schedule::command('shopee:refresh-tokens')->dailyAt('04:00')->withoutOverlapping()->skip(fn () => env('SCHEDULES_TOKEN_SYNC_DISABLED', false));

// MUL-095: Sincroniza shop_tier (Selo Indicado) de todas as contas Shopee ativas — 04:30
// Executa apos o refresh de tokens (04:00) para garantir tokens validos
Schedule::call(function () {
    $shopeeService = app(\App\Services\Integrations\Marketplaces\ShopeeService::class);
    $stats = $shopeeService->syncShopTierForAllAccounts();
    \Illuminate\Support\Facades\Log::info('[MUL-095] shopee:sync-shop-tier concluido', $stats);
})->dailyAt('04:30')->name('shopee-sync-shop-tier')->withoutOverlapping();

// Sync periodico de pedidos Shopee — busca pedidos das ultimas 25h para todas as lojas ativas
// Complementa o trigger por evento (MarketplaceAccountObserver) com polling horario seguro
// Garante que pedidos nao recebidos via webhook sejam capturados (ex: downtime, erros transientes)
Schedule::call(function () {
    // MUL-212 F2: guard por instalacao (installation_settings) — pull de pedidos
    // controlado dinamicamente no banco; WL nao puxa conta gerida pelo hub
    // (o hub puxa e entrega via fanout — puxar em paralelo duplicava, MUL-187).
    $cfg = app(\App\Services\InstallationConfig::class);
    if (! $cfg->pullsOrders('shopee')) {
        return;
    }
    \App\Models\MarketplaceAccount::where('platform', 'shopee')
        ->where('status', 'active')
        ->whereNotNull('access_token')
        ->whereNotNull('shop_id')
        ->whereNull('sync_blocked_at')
        ->when(! $cfg->isHub(), fn ($q) => $q->where('centrally_managed', false))
        ->chunkById(20, function ($accounts) {
            foreach ($accounts as $account) {
                \App\Jobs\SyncShopeeOrdersJob::dispatch($account->id);
            }
        });
})->hourly()->name('shopee-orders-periodic-sync')->withoutOverlapping()->skip(fn () => env('SCHEDULES_TOKEN_SYNC_DISABLED', false));

// MUL-197: enriquecimento automatico de pedidos rascunho (is_draft=1).
// Re-dispatch de EnrichShopeeOrderJob (Shopee) + re-check de promocao (demais sources).
// Garante que nenhuma casca fica orfa: rascunho e sempre re-visitado a cada 15min
// (respeitando cooldown de 30min por pedido e max-attempts no command).
Schedule::command('orders:enrich-drafts')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->name('enrich-draft-orders')
    ;


// Cleanup de states OAuth Shopee expirados (TTL = 10min; remove entradas > 24h)
Schedule::call(function () {
    \Illuminate\Support\Facades\DB::table('shopee_oauth_states')
        ->where('expires_at', '<', now()->subHours(24))
        ->delete();
})->dailyAt('05:00')->name('shopee-oauth-states-cleanup');


// ML token recovery + renovacao proativa -- MES-023 2026-06-25
// Antes: dailyAt(03:30) -> token expirava durante o dia (conta 814 afetada).
// Agora: everyFifteenMinutes, renova needs_reauth E tokens com < 3h de vida (igual ao legado refresh_marketplace_tokens.php)
Schedule::command('ml:recover-tokens')->everyFifteenMinutes()->withoutOverlapping()->name('ml-recover-tokens')->skip(fn () => env('SCHEDULES_TOKEN_SYNC_DISABLED', false));
// GOL-111B: ml:refresh-legacy-tokens cobre contas platform='ml' (formato legado DKS/importacao) que ml:recover-tokens ignora (usa token_expires_at, nao ml_token_expires_at)
Schedule::command('ml:refresh-legacy-tokens')->everyFifteenMinutes()->withoutOverlapping()->name('ml-refresh-legacy-tokens')->skip(fn () => env('SCHEDULES_TOKEN_SYNC_DISABLED', false));

// NOV-078: Shopee token recovery -- equivalente ao ml:recover-tokens para contas Shopee needs_reauth
// Antes: nao havia schedule para recuperar contas Shopee com refresh_token ainda valido.
// O shopee:refresh-tokens roda diariamente (04:00) mas so pega status=active -- nao recupera needs_reauth.
// Este comando recupera automaticamente contas needs_reauth com refresh_token valido sem reconexao manual.
Schedule::command('shopee:recover-tokens')->everyFifteenMinutes()->withoutOverlapping()->name('shopee-recover-tokens')->skip(fn () => env('SCHEDULES_TOKEN_SYNC_DISABLED', false));


// Refresh proativo de tokens -- reativado FOR-084 2026-07-21
// Frequencia reduzida para hourly() (era everyFifteenMinutes em NOV-061).
// FOR-082 adicionou recover-tokens para sync_blocked_at; camada proativa
// evita novo cenario 'circuit breaker + refresh revogado apos 6 meses'.
Schedule::command('tokens:proactive-refresh')
    ->hourly()
    ->withoutOverlapping()
    ->name('tokens-proactive-refresh')
    ;


// Sync periodico de pedidos ML — busca pedidos das ultimas 25h para todas as lojas ativas
// Criado em 2026-06-21 (NOV-025): equivalente ao shopee-orders-periodic-sync para ML
// Garante que pedidos nao recebidos via webhook sejam capturados (ex: downtime, rate limit)
Schedule::call(function () {
    // MUL-212 F2: guard por instalacao — ver shopee-orders-periodic-sync
    $cfg = app(\App\Services\InstallationConfig::class);
    if (! $cfg->pullsOrders('mercadolivre')) {
        return;
    }
    \App\Models\MarketplaceAccount::whereIn('platform', ['mercadolivre', 'mercado_livre'])
        ->whereIn('status', ['active', 'connected'])
        ->whereNotNull('access_token')
        ->whereNull('sync_blocked_at')
        ->where('status', '!=', 'needs_reauth')
        ->when(! $cfg->isHub(), fn ($q) => $q->where('centrally_managed', false))
        ->chunkById(20, function ($accounts) {
            foreach ($accounts as $account) {
                \App\Jobs\SyncMLOrdersJob::dispatch($account->id);
            }
        });
})->hourly()->name('ml-orders-periodic-sync')->withoutOverlapping()->skip(fn () => env('SCHEDULES_TOKEN_SYNC_DISABLED', false));

// SEL-047: Sync periodico de pedidos TikTok Shop -- mesma cadencia do Shopee/ML (horaria).
// DORMANT: sem contas tiktok em marketplace_accounts (0 rows where platform=tiktok + status=active),
// o chunkById retorna vazio e nenhum job e despachado -- no-op silencioso.
// Ativacao: Ruan coloca TIKTOK_APP_KEY/APP_SECRET/REDIRECT_URI no .env + config:clear.
Schedule::call(function () {
    $cfg = app(\App\Services\InstallationConfig::class);
    if (! $cfg->pullsOrders('tiktok')) {
        return;
    }
    \App\Models\MarketplaceAccount::where('platform', 'tiktok')
        ->where('status', 'active')
        ->whereNotNull('access_token')
        ->whereNotNull('shop_id')
        ->whereNull('sync_blocked_at')
        ->when(! $cfg->isHub(), fn ($q) => $q->where('centrally_managed', false))
        ->chunkById(20, function ($accounts) {
            foreach ($accounts as $account) {
                \App\Jobs\SyncTikTokOrdersJob::dispatch($account->id);
            }
        });
})->hourly()->name('tiktok-orders-periodic-sync')->withoutOverlapping()->skip(fn () => env('SCHEDULES_TOKEN_SYNC_DISABLED', false));



// MUL-077b: Pedidos Bling -- sync horario (apenas orders, rapido). Produtos em bling-products-periodic-sync 6h
// Complementa o trigger por webhook (BlingWebhookController) garantindo sync mesmo em caso de downtime
// Mesmo padrao do shopee-orders-periodic-sync e ml-orders-periodic-sync
Schedule::call(function () {
    // MUL-212 F2: guard por instalacao — ver shopee-orders-periodic-sync
    $cfg = app(\App\Services\InstallationConfig::class);
    if (! $cfg->pullsOrders('bling')) {
        return;
    }
    \App\Models\MarketplaceAccount::where('platform', 'bling')
        ->where('status', 'active')
        ->whereNotNull('bling_access_token')
        ->whereNull('sync_blocked_at')
        ->when(! $cfg->isHub(), fn ($q) => $q->where('centrally_managed', false))
        ->chunkById(20, function ($accounts) {
            foreach ($accounts as $account) {
                \App\Jobs\SyncBlingJob::dispatch($account->id, 'orders');
            }
        });
})->cron(config('imports.bling_orders_sync_cron', '0 * * * *'))->name('bling-orders-periodic-sync')->withoutOverlapping()->skip(fn () => env('SCHEDULES_TOKEN_SYNC_DISABLED', false));

// MUL-077b: Produtos Bling a cada 6h (catalogo muda pouco, sync varre todos sem filtro de data)
Schedule::call(function () {
    \App\Models\MarketplaceAccount::where('platform', 'bling')
        ->where('status', 'active')
        ->whereNotNull('bling_access_token')
        ->whereNull('sync_blocked_at')
        ->chunkById(20, function ($accounts) {
            foreach ($accounts as $account) {
                // MUL-320b: conta de seller NAO baixa catalogo — so o ERP do fornecedor
                // (erp_accounts, via syncForSupplierErp). O seller SOBE produto pelo
                // exportProduct; baixar traz o catalogo particular dele para dentro do
                // catalogo do fornecedor, carimbado com o supplier da conta.
                // Estoque e pedido continuam normais — o que sai daqui e so 'products'.
                \App\Jobs\SyncBlingJob::dispatch($account->id, 'stock');
            }
        });
})->everySixHours()->name('bling-stock-periodic-sync')->withoutOverlapping();

// NOV-XXX (28/06/2026) — Sync periodico do ERP Bling do fornecedor (erp_accounts).
// Diferente do bling-periodic-sync acima (que varre marketplace_accounts), este varre
// erp_accounts onde o supplier conectou o Bling pra emissao de NFe / atualizacao de
// preco/estoque pelo ERP. Comando bling:sync-supplier-erp e implementado por outro agente.
Schedule::command('bling:sync-supplier-erp')->everySixHours()->withoutOverlapping()->name('bling-sync-supplier-erp');

// MUL-334 (06/08/2026) — Estoque do ERP do fornecedor. O comando existia desde a MUL-198
// mas nunca entrou no agendador: nada puxava saldo do ERP, e o estoque do MultDrop ficou
// congelado. De hora em hora porque saldo muda a cada venda, e o custo e' baixo (~42
// requisicoes, 74s para 371 produtos). O de cima, sync-supplier-erp, traz produto e roda
// de 6 em 6h — catalogo muda pouco, saldo muda o tempo todo.
Schedule::command('bling:sync-stock-erp')->hourly()->withoutOverlapping()->name('bling-sync-stock-erp');
// MUL-144: Renova tokens ErpAccounts Bling antes de expirarem

// Auto-vinculacao periodica -- re-tenta linkar client_products sem product_id
// Criado em 2026-06-21 (NOV-030): garante que produtos importados dos marketplaces
// sejam vinculados ao catalogo de fornecedores mesmo se nao vinculados no import
Schedule::call(function () {
    \App\Models\MarketplaceAccount::whereIn('status', ['active', 'connected'])
        ->whereNotNull('access_token')
        ->chunkById(50, function ($accounts) {
            foreach ($accounts as $account) {
                $hasPending = \App\Models\ClientProduct::where('marketplace_account_id', $account->id)
                    ->whereNull('product_id')
                    ->whereNotNull('external_listing_id')
                    ->exists();
                if ($hasPending) {
                    \App\Jobs\AutoLinkMarketplaceProductsJob::dispatch($account->id);
                }
            }
        });
})->everyTwoHours()->name('auto-link-marketplace-products')->withoutOverlapping();


// Marketplace import health monitoring -- NOV-031
// Roda a cada 30min: verifica contas ativas criadas/atualizadas nas ultimas 2h sem client_products.
// Apos 30min zerado: re-despacha ImportMarketplaceAccountDataJob (max 2 tentativas).
// Apos 2 tentativas ainda 0: alerta via Telegram (TELEGRAM_BOT_TOKEN + TELEGRAM_CHAT_ID).
Schedule::call(function () {
    \App\Models\MarketplaceAccount::where('status', 'active')
        ->where(function ($q) {
            $q->where('created_at', '>=', now()->subHours(2))
              ->orWhere('updated_at', '>=', now()->subHours(2));
        })
        ->chunkById(20, function ($accounts) {
            foreach ($accounts as $account) {
                $productsCount = \App\Models\ClientProduct::where('marketplace_account_id', $account->id)->count();

                if ($productsCount > 0) {
                    continue;
                }

                $cacheKey = 'import_attempts_' . $account->id;
                $attempts = (int) \Illuminate\Support\Facades\Cache::get($cacheKey, 0);

                // Auto-fix sem limite nem Telegram: re-despacha a cada 30min ate importar
                \App\Jobs\ImportMarketplaceAccountDataJob::dispatch($account->id);
                \Illuminate\Support\Facades\Cache::put($cacheKey, $attempts + 1, now()->addHours(24));
                \Illuminate\Support\Facades\Log::channel('marketplace')->info('[MarketplaceHealthCron] Auto-fix', [
                    'account_id' => $account->id,
                    'platform'   => $account->platform,
                    'attempt'    => $attempts + 1,
                ]);
            }
        });
})->everyThirtyMinutes()->name('marketplace-import-health-check')->withoutOverlapping();


// NOV-041 — Retry fila bridge_relay_queue: reprocessa eventos ML/Shopee que falharam no relay ao legado
// Backoff exponencial: 30s -> 5min -> 30min -> 2h -> failed_max
// Garante que NENHUM pedido seja perdido quando bridge cai
Schedule::job(new \App\Jobs\RetryBridgeRelayJob, 'webhooks')->everyFiveMinutes()->withoutOverlapping()->name('retry-bridge-relay');

// NOV-041 — Recovery diario: busca pedidos ML/Shopee com legacy_id NULL (+1h) e tenta relay novamente
// Cobre casos que escaparam do webhook E do polling horario
Schedule::job(new \App\Jobs\RecoverPendingOrdersJob)->everyThirtyMinutes()->withoutOverlapping()->name('recover-pending-orders');


// MUL-029 — Resumo Telegram dos eventos dos clientes migrados a cada hora
// Lista em /root/migracao-multdrop/clientes-migrados.txt; anti-spam interno (1 alerta/(email+evento)/h)
// [DESATIVADO 2026-06-25 Ruan - zero Telegram flood] Schedule::command("migracao:summary --hours=1")->hourly()->withoutOverlapping()->name("migracao-summary");


// MIGRA-V3 — Sync continuo saldo+historico legado->novo (roda ate Ruan desligar o legado)
// Mode full: ajusta saldo + importa tx/pedidos. Idempotente por composite unique key.
// [DESATIVADO FOR-038 2026-06-27] Schedule::command("import:legacy-history --wl=multdrop --mode=full")->everyFiveMinutes()->withoutOverlapping()->name("migra-v3-multdrop");
// MIGRA-V3 — Sync continuo saldo+historico legado->novo MEStoreDrop (roda ate Ruan desligar o legado)
// Mode full: ajusta saldo + importa tx/pedidos. Idempotente por composite unique key.
// [DESATIVADO FOR-038 2026-06-27] Schedule::command("import:legacy-history --wl=mestoredrop --mode=full")->everyFiveMinutes()->withoutOverlapping()->name("migra-v3-mestoredrop");


// NOV-081: Reconciliacao de estoque — equivalente ao auditoria_estoque_zerado.php do legado (rodava a cada 30min).
// Pausa anuncios ativos com estoque=0 e reativa anuncios pausados por stock_zero quando estoque volta.
Schedule::command('inventory:reconcile')->everyThirtyMinutes()->withoutOverlapping()->name('inventory-reconcile');

// NOV-081: Retry automatico de listings com sync_status=failed (backoff exponencial: 1h/4h/24h, max 3 tentativas, 500/execucao).
Schedule::command('marketplace:retry-failed-syncs')->hourly()->withoutOverlapping()->name('marketplace-retry-failed-syncs');

// SEL-400 REVERTIDO em 29/07: eu tinha diagnosticado errado. O seller.global JA
// tem os closures horarios `shopee-orders-periodic-sync` e `ml-orders-periodic-sync`
// (documentados em Obsidian/Recursos/Arquitetura/07-jobs-queues-crons.md), ativos
// e nao pulados. Agendar o SyncOrdersJob aqui duplicava o mesmo trabalho a cada
// 15min — 4x de chamada a Shopee e ML sem ganho nenhum.
// O que de fato travava era `marketplace_accounts.supplier_id` NULL (contas 1506,
// 1514, 1569), corrigido para 26 — essa parte do SEL-400 continua valendo.

// NOV-081: Bling token recovery -- recupera contas Bling needs_reauth com bling_refresh_token ainda valido.
// Refresh token Bling expira em 30 dias -- sem este command, usuario teria que reconectar manualmente.
// Equivalente ao ml:recover-tokens e shopee:recover-tokens para o ERP Bling.
Schedule::command('bling:recover-tokens')->everyFifteenMinutes()->withoutOverlapping()->name('bling-recover-tokens')->skip(fn () => env('SCHEDULES_TOKEN_SYNC_DISABLED', false));

// MUL-363 Fase 0: invariante saldo == SUM(ledger) conferido diariamente; divergencia loga error
Schedule::command('wallet:reconcile')->dailyAt('05:20')->withoutOverlapping()->name('wallet-reconcile');

// MUL-362 F4: dupla e internamente consistente (reconcile nao ve) — auditoria por pedido
Schedule::command('wallet:audit-orders')->dailyAt('05:30')->withoutOverlapping()->name('wallet-audit-orders');


// HUB-032 - Agregador de logs unificados de integracao.
// Le tabelas existentes (webhook_logs, webhook_deliveries, bridge_relay_queue,
// app_logs, legacy_sync_runs, email_logs) e copia para integration_logs
// (visivel em /admin/integration-logs). Idempotente via UNIQUE (source_table, source_id).
Schedule::command("integration:aggregate-logs")
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name("integration-aggregate-logs")
    ;

// NOV-118 - Alerta de estoque minimo: notifica supplier admin via Filament quando
// inventory.quantity <= stock_alert_threshold. Anti-duplicata por 24h via cache.
Schedule::job(new \App\Jobs\CheckLowStockJob)->dailyAt('08:00')->withoutOverlapping()->name('check-low-stock');

// NOV-123 - Sync de perguntas de compradores (ML/Shopee) a cada 30min.
Schedule::job(new \App\Jobs\SyncProductQuestionsJob)->everyThirtyMinutes()->withoutOverlapping()->name('sync-product-questions');

// NOV-132 - Sync de pedidos Magalu a cada 15min
\Illuminate\Support\Facades\Schedule::job(new \App\Jobs\SyncMagaluOrdersJob)->everyFifteenMinutes()->withoutOverlapping()->name('sync-magalu-orders');

 
// NOV-127 - Smart Repricing
Schedule::command('hubai:smart-repricing')->dailyAt('02:00')->withoutOverlapping()->name('smart-repricing');

// NOV-139 - Checks periodicos de notificacao supplier (estoque, NFe, SAC, token)
\Illuminate\Support\Facades\Schedule::job(new \App\Jobs\SupplierNotificationsCheckJob)->hourly()->withoutOverlapping()->name('supplier-notifications-check');

// ACAO-1.2 — Queue health alert: alerta Telegram quando queue default > 50k jobs (anti-spam 1h via cache)
// SEL-404: religado. Ficou silenciado desde 29/06 e, do jeito que estava,
// nunca teria alertado nada — vigiava so a fila `default` acima de 50 mil
// jobs, com variaveis de Telegram que nao existem neste .env. Reescrito
// para alertar por IDADE de fila parada.
// SEL-406: alarme quando video comeca a falhar em serie. Em 28-29/07 foram 111
// falhas em 143 tentativas (78%) sem ninguem ver — cada uma queimando credito.
// SEL-NERVO (13/08): roda INLINE, nao pela fila. Se a fila morrer, o alarme
// que avisa que a fila morreu nao pode morrer junto. E o worker carrega o
// codigo em memoria: pela fila, corrigir o alarme so valia apos restart.
Schedule::call(fn () => (new \App\Jobs\VideoFailureAlertJob)->handle())
    ->name('video-failure-alert')   // CallbackEvent exige name() ANTES de withoutOverlapping()
    ->everyFiveMinutes()
    // 10min, nao o padrao de 24h: se este processo morrer no meio, o cadeado
    // do withoutOverlapping fica preso e o ALARME emudece pelo tempo do
    // cadeado. Alarme calado por 24h por causa de um crash e exatamente o
    // tipo de falha silenciosa que este ticket veio matar.
    ->withoutOverlapping(10);

Schedule::job(new \App\Jobs\QueueHealthCheckJob)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->name('queue-health-check');


// NOV-156 - Limpa contas marketplace pending/fantasma (OAuth iniciado mas nunca concluido).
// Bloqueavam publicacao com store=all retornando "Falha ao publicar" sem mensagem util.
Schedule::job(new \App\Jobs\CleanOrphanedPendingMarketplaceAccountsJob)
    ->hourly()
    ->withoutOverlapping()
    ->name('clean-orphaned-pending-marketplace-accounts');




// NOV-171-B: Federation catalog sync -- entrega delta de catalogo hub->WLs a cada 5min.
// withoutOverlapping() previne empilhamento. Guard interno: so dispara quando ha endpoints.
// Query direta em tenant_supplier (sem whereHas -- Supplier model nao tem a relacao).
\Illuminate\Support\Facades\Schedule::call(function () {
    $pairs = \Illuminate\Support\Facades\DB::table("tenant_supplier as ts")
        ->join("tenants as t", "t.id", "=", "ts.tenant_id")
        ->where("t.status", "active")
        ->select("t.id as tenant_id", "ts.supplier_id")
        ->get();
    foreach ($pairs as $pair) {
        \App\Jobs\SyncTenantSupplierCatalogJob::dispatch(
            (string) $pair->tenant_id,
            (int) $pair->supplier_id,
            now()->subMinutes(10)->toIso8601String()
        );
    }
})->everyFiveMinutes()->name("federation-catalog-sync")->withoutOverlapping();

// INF-039: polling ativo hub->WL de pedidos do supplier local — rede de seguranca
// do webhook em tempo real. Delta por updated_at cobre criacao E atualizacao.
// No-op nos backends sem FEDERATION_HUB_TOKEN (ex.: o proprio hub).
\Illuminate\Support\Facades\Schedule::command('federation:pull-orders-from-hub')
    ->everyFifteenMinutes()
    ->name('federation-pull-orders-from-hub')
    ->withoutOverlapping();

// SEL-086: enriquece tiktok_shop_trends com matched_supplier_id diariamente
Schedule::command("tiktok:enrich-trends")->dailyAt("08:15")->withoutOverlapping();

// SEL-490 (31/07): o kalodata:sync roda 3x por dia (06h, 12h, 20h) e traz
// produtos novos SEM FOTO. Os dois comandos que buscam a foto real existiam
// desde 30/07 e NUNCA foram agendados — por isso a tela voltava a ter produto
// sem imagem depois de cada sync, e parecia que o conserto "nao pegou".
// Medido em 31/07 04h: 43 produtos com foto, 7 sem — os 7 do snapshot novo.
//
// Rodam 25 min depois de cada sync, tempo de o snapshot fechar. Ordem importa:
// primeiro o mapeamento produto->video (nao precisa de sessao), depois a foto
// oficial do anuncio (precisa da sessao logada da Kalodata, que pode expirar --
// nesse caso ele falha avisando e o outro ja cobriu parte).
foreach (['06:25', '12:25', '20:25'] as $hora) {
    Schedule::command('tiktok:enrich-images-from-videos')
        ->dailyAt($hora)->timezone('America/Sao_Paulo')->withoutOverlapping();
    Schedule::command('tiktok:enrich-images-from-kalodata')
        ->dailyAt($hora)->timezone('America/Sao_Paulo')->withoutOverlapping();
}

// SEL-426: prewarm noturno de imagens — roda 02:30 e tenta enriquecer
// TODOS os produtos sem foto do ultimo snapshot (nao so os novos do sync).
Schedule::command('tiktok:prewarm-kalodata-photos', ['--limit' => 30])
    ->dailyAt('02:30')->timezone('America/Sao_Paulo')->withoutOverlapping()
    ->name('kalodata-prewarm-noturno');


// SEL-096: liberação semanal de fornecedores pro plano supplier_only
Schedule::command("suppliers:unlock-weekly")->weeklyOn(1, "06:00")->withoutOverlapping();

// SEL-115: faz polling das tasks Seedance processando a cada 30s (substitui polling manual do frontend).
// Limite de 50 tasks por run, ignora tasks > 6h (expiradas). INERTE sem ARK_API_KEY configurado.
Schedule::job(new \App\Jobs\PollSeedanceTasksJob)->everyThirtySeconds()->withoutOverlapping()->name("poll-seedance-tasks");

// SEL-127 Ruan 16/07: re-scrape diario TikTok Affiliate Center (04:00 BRT) — mantem
// captured_at fresco e refetcha avatares dos criadores via tikwm.com.
Schedule::command('tiktok:rescrape-affiliate')
    ->dailyAt('04:00')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping()
    
    ->name('tiktok-rescrape-affiliate');

// SEL-214 Fase2 18/07: sincronizacao diaria de sellers TikTok Shop BR (lojistas afiliados).
// [DESATIVADO SEL-246 18/07 21:xx] catalogava lado errado (creators em vez de lojas)
// Schedule::job(new \App\Jobs\SyncTikTokShopSellersJob(50))
//    ->dailyAt('06:00')->timezone('America/Sao_Paulo')->withoutOverlapping()
//    ->name('sync-tiktok-shop-sellers');

// SEL-246 Ruan 18/07: extrai LOJAS REAIS TT Shop dos anchors dos viral videos.
// Cron 05:30 BRT (antes do backfill perfis 07:00).
Schedule::command('tiktok:extract-shops --limit=200')
    ->dailyAt('05:30')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping()
    ->name('tiktok-extract-shops-from-anchors');

// SEL-181 Ruan 16/07: backfill diario dos perfis dos criadores TikTok via tikwm
// (nickname/avatar/follower_cnt/video_cnt) — 07:00 BRT, apos o tiktok:rescrape-affiliate (04:00).
// --only-empty: so processa rows sem raw popular; --limit=100: ~2min por execucao.
Schedule::command('creators:backfill-from-tikwm --only-empty --limit=100')
    ->dailyAt('07:00')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping()

    ->name('creators-backfill-from-tikwm');

// SEL-239 Ruan 18/07: backfill avatar_url REAL dos ai_creators via tikwm.
// (SEL-181 mexe em tiktok_shop_trends, NAO em ai_creators). Roda 07:15 BRT.
Schedule::command('ai-creators:backfill-avatars --limit=60')
    ->dailyAt('07:15')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping()
    ->name('ai-creators-backfill-avatars');

// SEL-237 Ruan 18/07: pra cada ai_creator, extrai produtos TT Shop dos anchors
// dos vídeos. Roda 07:30 BRT (15min apos backfill avatares 07:15). Rate limit
// tikwm ~1/s → 52 criadores × 30 videos ≈ 25-30min por execucao.
Schedule::command('ai-creators:fetch-products --limit=52')
    ->dailyAt('07:30')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping()
    ->name('ai-creators-fetch-products');

// SEL-126 Ruan 15/07: scrapers de tendencias de outras fontes (Mercado Livre + Shopee)
// gravando em tiktok_shop_trends (tabela generica multi-source). Ambos rodam diario 05:00 BRT.
Schedule::command('trends:scrape-ml')
    ->dailyAt('05:00')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping()
    
    ->name('trends-scrape-ml');

Schedule::command('trends:scrape-shopee')
    ->dailyAt('05:00')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping()
    
    ->name('trends-scrape-shopee');

// SEL-158 Ruan 16/07: TT Shop Mall como fonte adicional de trends.
// Vitrine publica BR (source=tiktok_shop_mall). Roda diario 06:00 BRT (1h depois
// dos outros scrapers pra nao sobrecarregar rede).
Schedule::command('trends:scrape-tt-mall')
    ->dailyAt('06:00')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping()
    
    ->name('trends-scrape-tt-mall');

// SEL-199: scrape diario de criadores IA por hashtag (#aiugc #aivideo #klingai #midjourney #sora)
// Roda 08:00 BRT (11:00 UTC). Job source='scrape', is_approved=false (admin revisa).
Schedule::job(new \App\Jobs\ScrapeAiVideoCreatorsJob)
    ->dailyAt('08:00')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping()
    
    ->name('scrape-ai-video-creators');

// SEL-217: sync diário de criadores IA do Supabase Tokfy → ai_creators local.
// Recalcula rank_position sequencial após cada sync.
// Roda 05:00 BRT (antes do scrape-ai-video-creators das 08:00).
Schedule::job(new \App\Jobs\SyncTokfyCreatorsJob)
    ->dailyAt('05:00')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping()
    ->name('sync-tokfy-creators');

// SEL-200C: aviso trial 3d acabando (push+email). Roda a cada 2h.
Schedule::job(new \App\Jobs\TrialEndingReminderJob)
    ->everyTwoHours()
    ->withoutOverlapping()
    ->name('trial-ending-reminder');

// SEL-218: scraper diário de vídeos virais do TikTok BR (product finds, achadinhos).
// Roda 04:00 BRT — 18 keywords de descoberta de produto via tikwm.com.
// Purge automático de vídeos > 7 dias dentro do próprio job.
Schedule::job(new \App\Jobs\ScrapeTiktokViralVideosJob)
    ->dailyAt('04:00')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping()
    ->name('scrape-viral-videos');

// SEL-259: alerta de lives ativas TT Shop BR por nicho (a cada 5min).
// Lê tt_live_snapshots dos últimos 30min, cruza com push_preferences.niches[].
// Anti-spam: 1 push/cliente/live a cada 3h. Respeita quiet_hours_*.
// INERTE sem VAPID_PUBLIC_KEY + VAPID_PRIVATE_KEY no .env.
Schedule::job(new \App\Jobs\AlertActiveLivesJob)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('alert-active-lives');


// SEL-361 Fase A: AnalyzeFeedbackJob -- agrega feedbacks diarios e atualiza pipeline_learnings (win_rate).
// Roda 04:30 BRT apos ScrapeTiktokViralVideosJob.
Schedule::job(new \App\Jobs\AnalyzeFeedbackJob)
    ->dailyAt("04:30")
    ->timezone("America/Sao_Paulo")
    ->withoutOverlapping()
    ->name("analyze-video-feedback");

// SEL-361 Fase D: KalodataResearcherJob -- atualiza win_rate com feedback real + gera insight GPT.
// Roda 03:00 BRT antes do AnalyzeFeedbackJob.
Schedule::job(new \App\Jobs\KalodataResearcherJob)
    ->dailyAt('03:00')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping()
    ->name('kalodata-researcher');

// SEL-381 AUDIT#9+#11 — Kling browser health check horario (Telegram Ruan se sessao velha, worker travado, creditos >80%%, pausa esquecida)
if (env("KLING_BROWSER_ENABLED", false)) {
    Schedule::command("kling-browser:health-check")->hourly()->withoutOverlapping();
}

// NOV-214 / Antifraude WL MVP — snapshot semanal de clientes (toda segunda 03:00 UTC)
// Grava is_active + blocked_at de cada client nas WLs em wl_client_snapshots.
// Ativo SO no backend hub (api.hubai.io) via flag APP_EMPRESA_ID != 0.
// Os outros 6 backends (multdrop, fornecefy, etc) NAO precisam rodar — o hub acessa todos os bancos.
if (env("WL_ANTIFRAUDE_ENABLED", false)) {
    Schedule::job(new \App\Jobs\SnapshotWlClientsJob)
        ->weeklyOn(1, "03:00")
        ->withoutOverlapping()
        ->name("snapshot-wl-clients");
}

// JT-014: conta conectada na WL tem que existir no hub, senao o pedido nasce
// so aqui e o fornecedor nunca ve. Era comando manual e parou de ser rodado.
// Ativo so onde FEDERATION_ACCOUNT_SYNC_CRON=true (repo compartilhado por 7).
if (config('federation.account_sync_cron')) {
    Schedule::command('fornecefy:backfill-marketplace-accounts --only-new')
        ->hourly()->withoutOverlapping();
}

// NOV-217 — Whitelabel Billing: crons equivalentes ao pg_cron do Supabase HubAI.
// ATENCAO: manter pg_cron do Supabase ativo ate este scheduler estar validado em prod.
// Dia 01 de cada mes as 06:00 BRT — equivalente ao close-whitelabel-cycle-dia1 (jobid=30)
// SEL-MERGE1408-guard: fechar ciclo de WL escreve na base de cobranca COMPARTILHADA.
// Este repo roda em 7 backends; so o que tem INTERNAL_ADMIN_TOKEN pode fechar ciclo,
// senao seis agendadores tentariam fechar o MESMO ciclo. Hoje so o seller.global tem
// (conferido nos 7 .env em 14/08) — nos outros isso daria 401 de hora em hora.
if (filled(config("services.internal_admin_token", env("INTERNAL_ADMIN_TOKEN", "")))) {
    Schedule::command('wl:close-cycle')
        ->monthlyOn(1, '06:00')
        ->timezone('America/Sao_Paulo')
        ->withoutOverlapping()
        ->name('wl-close-cycle-monthly');

    // Diariamente as 06:10 BRT — equivalente ao sync-whitelabel-data-daily (jobid=38)
    Schedule::command('wl:sync-snapshot')
        ->dailyAt('06:10')
        ->timezone('America/Sao_Paulo')
        ->withoutOverlapping()
        ->name('wl-sync-snapshot-daily');


}
Schedule::command("tiktok:heal-kalodata")->hourly()->withoutOverlapping(); // SEL 07/08 auto-cura painel Kalodata (nunca mais sumir tudo)

// SEL-MEMORIA (12/08): a memoria de prompt aprende SOZINHA com o laudo do
// conferente — geracao aprovada vira receita, reprovada invalida a receita usada,
// e o placar (usos/acertos) e recalculado. Idempotente e fail-open.
\Illuminate\Support\Facades\Schedule::command('video:memoria-aprende')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('video-memoria-aprende')
    // deixa rastro: sem isto o schedule joga a saida no /dev/null e nao da pra
    // provar que a memoria esta mesmo aprendendo sozinha.
    ->appendOutputTo(storage_path('logs/memoria-aprende.log'));

// INF-030 (Ruan 12/08, ampliacao) — "nao deixar o disco encher": gravacao de
// sessao (rrweb) e amostragem de mouse geram muito dado. Roda 1x/dia de
// madrugada (baixo trafego) e em lotes (ver AnalyticsCleanupCommand).
\Illuminate\Support\Facades\Schedule::command('analytics:cleanup')
    ->dailyAt('04:20')
    ->withoutOverlapping()
    ->name('analytics-cleanup')
    ->appendOutputTo(storage_path('logs/analytics-cleanup.log'));

