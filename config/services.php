<?php

return [

    /*
     * Chaves do Pagar.me usadas pelo tokfy:concilia (o que libera o acesso de quem pagou).
     * Ficam AQUI e nao em env() direto no comando: com config em cache o .env nao e lido,
     * e o comando morria toda execucao (medido 16/08, quebrado desde as 15:49).
     *   legado -> conta antiga (Tudo On Line), ainda recebe cobranca velha
     *   nova   -> conta ON LINE RJ, para onde o checkout novo aponta desde 16/08
     */
    'pagarme_tokfy' => [
        'legado' => env('PAGARME_TOKFY_LEGADO', ''),
        'nova'   => env('PAGARME_TOKFY_NOVA', ''),
    ],



    /*
     * SEL-463 (31/07): as chaves do Web Push viviam so em env(). Com
     * config:cache ligado (bootstrap/cache/config.php existe em producao),
     * env() devolve NULL -- entao o PushController achava que nao havia chave e
     * nenhuma notificacao saiu. Resultado medido: 289 assinaturas de clientes e
     * ZERO campanha enviada na historia. Mesmo defeito que ja tinha derrubado o
     * KLING_MODE e o client/status neste mesmo servidor.
     * Config e lido na hora de gerar o cache, entao sobrevive.
     */
    'vapid' => [
        'public'  => env('VAPID_PUBLIC_KEY'),
        'private' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'mailto:contato@seller.global'),
    ],
    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],


    'mercadolivre' => [
        'app_id'        => env('ML_APP_ID'),
        'secret_key'    => env('ML_SECRET_KEY'),
        'client_secret' => env('ML_SECRET_KEY'),
        'redirect_uri'  => env('ML_REDIRECT_URI'),
    ],

    // HUB-113: bloco bling ausente fazia config('services.bling.client_id') retornar null
    // sob config:cache, quebrando BlingService::$clientId (typed string) com
    // "Cannot assign null to property" — visto na conta 25 e em SyncBlingJob failures.
    'bling' => [
        'client_id'     => env('BLING_CLIENT_ID', ''),
        'client_secret' => env('BLING_CLIENT_SECRET', ''),
        'redirect_uri'  => env('BLING_REDIRECT_URI', ''),
        'push_enabled'  => env('BLING_PUSH_ENABLED', false),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model'   => env('OPENAI_MODEL', 'gpt-4o-mini'),
    ],

    'pagarme' => [
        'api_key'        => env('PAGARME_API_KEY'),
        'webhook_secret' => env('PAGARME_WEBHOOK_SECRET', ''),
    ],

    'shopee' => [
        'partner_id'    => env('SHOPEE_PARTNER_ID', ''),
        'partner_key'   => env('SHOPEE_PARTNER_KEY', ''),
        'push_partner_key' => env('SHOPEE_PUSH_PARTNER_KEY', ''),
        'redirect_uri'  => env('SHOPEE_REDIRECT_URI', ''),
        'use_bridge'    => env('SHOPEE_USE_BRIDGE', false),
        'bridge_url'    => env('SHOPEE_BRIDGE_URL', ''),
        'bridge_secret'  => env('SHOPEE_BRIDGE_SECRET', ''),
        'is_bridge_hub'  => env('SHOPEE_IS_BRIDGE_HUB', true),
        'oauth_service'  => env('SHOPEE_OAUTH_SERVICE', 'hubai'), // NOV-033: hubai|multdrop|fornecefy
    ],

    'goolhub' => [
        'base_url' => env('GOOLHUB_BASE_URL', 'https://goolhub.io/api'),
        'root_url'         => env('GOOLHUB_ROOT_URL', 'https://goolhub.io'), // NOV-088: URL raiz legado (sem /api) para redirects
        'token'    => env('GOOLHUB_API_TOKEN', ''),
        'bridge_key' => env('GOOLHUB_BRIDGE_KEY', 'hb-bridge-2026-xK9mP3qR7vL2nW8'),
        'events_bridge_key' => env('GOOLHUB_EVENTS_BRIDGE_KEY', 'hb-bridge-2026-8ANH8TF5'),
    ],

    'legacy' => [
        'import_cutoff_date' => env('LEGACY_IMPORT_CUTOFF_DATE', null),
        'sync_key' => env('LEGACY_SYNC_KEY', 'hubai-sync-2026'),
    ],

    // MUL-212: env() direto no controller retorna null com config cacheada —
    // multdrop gravava supplier_id=1 (deletado no MUL-183) e todo webhook de
    // pedido hub->WL morria com FK 500.
    'hubai_federation' => [
        'default_supplier_id' => env('HUBAI_DEFAULT_SUPPLIER_ID', 1),
        'default_tenant_id'   => env('HUBAI_DEFAULT_TENANT_ID', 'b43c2e8a-00cb-4f44-9c45-3b045ea51aab'),
        // FOR-112: o segredo era lido com env() DENTRO do controller. Com
        // `config:cache` ligado — que e o estado normal em producao — env() devolve
        // vazio, o WL mandava X-Federation-Secret em branco e o hub respondia 404.
        // Resultado: 141 etiquetas do Fornecefy quebradas no painel do seller.
        'secret'      => env('FEDERATION_HMAC_SECRET', ''),
        'storage_url' => env('FEDERATION_HUB_URL', 'https://api.hubai.io'),
    ],

    'shopify' => [
        'api_key'      => env('SHOPIFY_API_KEY'),
        'api_secret'   => env('SHOPIFY_API_SECRET'),
        'redirect_uri' => env('SHOPIFY_REDIRECT_URI', 'https://api.hubai.io/oauth/shopify/callback'),
        'scopes'       => env('SHOPIFY_SCOPES', 'read_products,write_products,read_orders,write_orders,read_inventory,write_inventory'),
    ],


    'cj' => [
        'api_key' => env('CJ_API_KEY', ''),
    ],

    'aliexpress' => [
        'app_key'      => env('ALIEXPRESS_APP_KEY', ''),
        'app_secret'   => env('ALIEXPRESS_APP_SECRET', ''),
        'access_token' => env('ALIEXPRESS_ACCESS_TOKEN'),
        'redirect_uri' => env('ALIEXPRESS_REDIRECT_URI', ''),
    ],

    'shopee_stats' => [
        'email'       => env('SHOPEE_STATS_EMAIL', ''),
        'password'    => env('SHOPEE_STATS_PASSWORD', ''),
        'marketplace' => env('SHOPEE_STATS_MARKETPLACE', '3'),
    ],
    'swagger' => [
        'secret_token' => env('SWAGGER_SECRET_TOKEN', ''),
    ],

    'stripe' => [
        'secret_key'        => env('STRIPE_SECRET_KEY'),
        'publishable_key'   => env('STRIPE_PUBLISHABLE_KEY'),
        'webhook_secret'    => env('STRIPE_WEBHOOK_SECRET'),
        'connect_client_id' => env('STRIPE_CONNECT_CLIENT_ID'),
    ],
    'central_api' => [
        'url'          => env('CENTRAL_API_URL', 'https://api.hubai.io'),
        'internal_key' => env('CENTRAL_API_INTERNAL_KEY', ''),
    ],

    'bunnycdn' => [
        'storage_zone' => env('BUNNYCDN_STORAGE_ZONE', 'hub-imgcdn-storage'),
        'access_key' => env('BUNNYCDN_ACCESS_KEY'),
        'pull_zone_url' => env('BUNNYCDN_PULL_ZONE_URL', 'https://hub-imgcdn.b-cdn.net'),
        // MUL-208 Fase 4: prefixo tenant no path pra unificar storage por WL
        'tenant_prefix' => env('CDN_TENANT_PREFIX', ''),
        'storage_host' => env('BUNNYCDN_STORAGE_HOST', 'storage.bunnycdn.com'),
    ],

    'shipay' => [
        'base_url'   => env('SHIPAY_BASE_URL', 'https://api.shipay.com.br'),
        'client_id'  => env('SHIPAY_CLIENT_KEY', ''),
        'secret_key' => env('SHIPAY_SECRET_KEY', ''),
        'access_key' => env('SHIPAY_ACCESS_KEY', ''),
    ],

    'meta_ads' => [
        'access_token'     => env('META_ADS_ACCESS_TOKEN'),
        'hub_account_id'   => env('META_ADS_HUB_ACCOUNT_ID'),
        'tokfy_account_id' => env('META_ADS_TOKFY_ACCOUNT_ID'),
    ],

    'internal_bridge_key' => env('INTERNAL_BRIDGE_KEY', ''),
    // MUL-214 item 36: Google OAuth login (usa Sign-In JS SDK client-side, backend valida id_token)
    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri'  => env('GOOGLE_REDIRECT_URI', 'https://multdrop.app/login'),
    ],

        // SEL-033: Seedance 2.0 video generation (BytePlus ModelArk)
    'ark' => [
        'api_key' => env('ARK_API_KEY'),
        'endpoint' => env('ARK_ENDPOINT', 'https://ark.ap-southeast.bytepluses.com/api/v3'),
        'video_model' => env('ARK_VIDEO_MODEL', 'dreamina-seedance-2-0-260128'),
    ],
    // SEL-272 Ruan 19/07 15:09: Firebase Phone Auth (SMS) — só a Web API Key
    // pra validar idToken via identitytoolkit accounts:lookup.
    'firebase' => [
        'web_api_key' => env('FIREBASE_WEB_API_KEY'),
        'project_id'  => env('FIREBASE_PROJECT_ID'),
    ],
    // SEL-040: Kling AI (video img2vid + Virtual Try-On + Lip Sync)
    'kling' => [
        'api_key' => env('KLING_API_KEY'),
        'access_key' => env('KLING_ACCESS_KEY'),
        'secret_key' => env('KLING_SECRET_KEY'),
        'base_url' => env('KLING_BASE_URL', 'https://api.klingai.com'),
        'default_video_model' => env('KLING_VIDEO_MODEL', 'kling-v1-6'),
        // SEL-411: estas chaves eram lidas com env() DIRETO no AppServiceProvider e no
        // AdminVideoController. env() devolve null quando existe bootstrap/cache/config.php,
        // e o binding caia no KlingService (API), que esta sem saldo — apagao de video.
        // Default do mode e 'browser' de proposito: o navegador e o caminho vivo.
        // Nos outros 6 backends do repo compartilhado, 'browser_enabled' fica false
        // (a env nao existe nos .env deles), entao o AND do binding preserva o
        // comportamento atual — nenhum deles passa a usar o navegador.
        'mode' => env('KLING_MODE', 'browser'),
        'browser_enabled' => env('KLING_BROWSER_ENABLED', false),
        'browser_account_email' => env('KLING_BROWSER_ACCOUNT_EMAIL'),
        'browser_plan' => env('KLING_BROWSER_PLAN', 'Pro'),
        'browser_session_path' => env('KLING_BROWSER_SESSION_PATH'),
        'browser_worker_js' => env('KLING_BROWSER_WORKER_JS'),
        'browser_worker_dir' => env('KLING_BROWSER_WORKER_DIR'),
        'browser_videos_dir' => env('KLING_BROWSER_VIDEOS_DIR'),
        'browser_rate_lock' => env('KLING_BROWSER_RATE_LOCK'),
    ],

    // SEL-486: MOTOR de video selecionavel (kling | veo). Lido pelo binding em
    // AppServiceProvider E pelo KlingBrowserGenerateJob (escolhe qual worker Node
    // rodar). Default 'kling' de PROPOSITO: sem esta env no .env, tudo continua
    // exatamente como hoje (Kling via navegador). Nos outros 6 backends do repo
    // compartilhado a env nao existe -> default kling -> nenhum deles muda.
    // Motivo do config() (nao env() direto): com bootstrap/cache/config.php ligado
    // env() vira null e cairia sempre no default — mesma armadilha do KLING_MODE.
    'video_engine' => env('VIDEO_ENGINE', 'kling'),

    // SEL-30s: video longo (ate 30s) costurando N clipes de ~10s do Veo, com UMA
    // narracao continua (ElevenLabs) dublada por cima. 'enabled' default FALSE:
    // enquanto off, nada muda (fluxo de 1 clipe intacto). Ligar so depois de o Ruan
    // assistir 1 video de 30s gerado de verdade. Planos que liberam 30s sao
    // configuraveis (default: video_ultra=297 e promo_live_297).
    // SEL-MEMORIA (12/08): memoria de prompt — reaproveita a receita que ja deu
    // video aprovado pro mesmo (produto, estilo, duracao). Desligar aqui volta ao
    // comportamento de antes (todo prompt do zero), sem tocar em codigo.
    'memoria_prompt' => [
        'enabled' => env('SEL_MEMORIA_PROMPT', true),
    ],

    'sel30s' => [
        'enabled'      => env('SEL30S_ENABLED', false),
        'clip_seconds' => (int) env('SEL30S_CLIP_SECONDS', 10),   // Omni Flash faz ~10s/clipe (medido)
        'max_seconds'  => (int) env('SEL30S_MAX_SECONDS', 30),
        'max_segments' => (int) env('SEL30S_MAX_SEGMENTS', 3),    // 30s = 3 clipes de 10s
        // SEL-NAOPARTIRATOA: sobra ate 25% acima do corte NAO parte o video em dois.
        'tolerancia'   => (float) env('SEL30S_TOLERANCIA', 1.25),
        'plans'        => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SEL30S_PLANS', 'video_ultra,promo_live_297'))
        ))),
    ],

    // SEL-486: Google Flow / Veo via NAVEGADOR (Playwright + sessao Google logada).
    // Espelha o bloco kling.browser_*. Compartilha a MESMA sessao Google usada pelo
    // gemini_content_worker (google-session.json). 'browser_enabled' fica false por
    // padrao: so liga quando VIDEO_ENGINE=veo E a sessao Google estiver viva.
    'veo' => [
        /**
         * SEL-TUDO-NO-GRATIS (15/08, Ruan: "mantem tudo no gratis, preferencia e gratis").
         *
         * true  = TODO pedido roda no "Veo 3.1 - Lite [Lower Priority]" (0 credito),
         *         independente de plano, resgate ou tentativa.
         * false = volta a valer o modelo do plano (ultra/ilimitado) e os resgates.
         *
         * Esta em true porque as contas do Flow estao sem saldo: medido no log do
         * worker, 18 abortos "veo_sem_credito" em 6h — cliente PAGANTE saindo sem
         * video enquanto o gratis entregava. Quando houver credito, e so virar aqui.
         */
        'tudo_no_gratis'         => env('VEO_TUDO_NO_GRATIS', true),
        'browser_enabled'        => env('VEO_BROWSER_ENABLED', false),
        'browser_account_email'  => env('VEO_BROWSER_ACCOUNT_EMAIL'),
        'browser_plan'           => env('VEO_BROWSER_PLAN', 'Pro'),
        // Reaproveita a sessao Google ja existente (a mesma do motor de texto Gemini).
        'browser_session_path'   => env('VEO_BROWSER_SESSION_PATH', '/home/api.seller.global/storage/kling-browser/google-session.json'),
        'browser_worker_js'      => env('VEO_BROWSER_WORKER_JS', '/home/api.seller.global/browser-worker/veo_generate.js'),
        'browser_worker_dir'     => env('VEO_BROWSER_WORKER_DIR', '/home/api.seller.global/browser-worker'),
        // SEL-425: worker CDP (DICloak). Usa connectOverCDP em vez de launch.
        'browser_worker_cdp_js'  => env('VEO_BROWSER_WORKER_CDP_JS', '/home/api.seller.global/browser-worker/veo_generate_cdp.js'),
        // Omni Flash e o padrao (10s, 9:16, audio pt-BR nativo, 10 creditos/video).
        'model'                  => env('VEO_MODEL', 'Omni Flash'),
        // URL do projeto Flow do Ruan (o Flow exige um projeto aberto pra gerar).
        'project_url'            => env('VEO_PROJECT_URL'),
    ],

    // TOK-22 — intake de video vindo de produto EXTERNO (hoje so o Tokfy).
    // Segredo compartilhado servidor-a-servidor. Sem env definida o valor fica
    // vazio e o middleware RECUSA tudo (503) — endpoint nasce fechado.
    // Lido via config() e nao env() por causa do config cache (SEL-411).
    'external_video' => [
        'shared_token' => env('EXTERNAL_VIDEO_SHARED_TOKEN', ''),
    ],

    // SEL-425 — DICloak VM (perfil Chrome com Google AI Pro).
    // DICLOAK_TUNNEL_URL: preenchido após INF-072 concluir (ex: https://dicloak-vm.hubai.club)
    // DICLOAK_PROFILE_ID: ID do perfil padrão (pode ser sobrescrito por video_engines.config_json)
    'dicloak' => [
        'tunnel_url'  => env('DICLOAK_TUNNEL_URL'),
        'profile_id'  => env('DICLOAK_PROFILE_ID'),
        // Cooldown em minutos após falha de um perfil
        'cooldown_min' => env('DICLOAK_COOLDOWN_MINUTES', 5),
    ],

    // SEL-411: Telegram dos alarmes. O token estava CHUMBADO em
    // TelegramNotificationService (token morto, respondia Unauthorized) e em
    // KlingBrowserHealthCheck. Credencial em repo compartilhado por 7 backends, e os
    // alarmes ficaram mudos sem ninguem perceber. Agora vem de config, nunca de env()
    // direto (env() vira null com config cache — mesma armadilha do KLING_MODE).
    // 'strict' => quando true, send() LANCA em vez de so logar que o alarme esta mudo.
    // Fica false por padrao porque 5 dos 7 backends ainda nao tem TELEGRAM_BOT_TOKEN
    // no .env: ligar strict antes disso derrubaria o Sentinela deles.
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID_RUAN', env('TELEGRAM_CHAT_ID')),
        'strict' => env('TELEGRAM_STRICT', false),
    ],
    // SEL-040: ElevenLabs (TTS + dubbing + voice clone + sound effects)
    'elevenlabs' => [
        'api_key' => env('ELEVENLABS_API_KEY'),
        'base_url' => env('ELEVENLABS_BASE_URL', 'https://api.elevenlabs.io'),
        'default_voice_id' => env('ELEVENLABS_DEFAULT_VOICE', 'pFZP5JQG7iQjIQuC4Bku'),
        'default_model' => env('ELEVENLABS_DEFAULT_MODEL', 'eleven_multilingual_v2'),
    ],
    // SEL-046: TikTok Shop Partner API (org Hubai ORG)
    'tiktok' => [
        'app_key' => env('TIKTOK_APP_KEY'),
        'app_secret' => env('TIKTOK_APP_SECRET'),
        'auth_url' => env('TIKTOK_AUTH_URL', 'https://services.tiktokshop.com/open/authorize'),
        'api_url' => env('TIKTOK_API_URL', 'https://open-api.tiktokglobalshop.com'),
        'redirect_uri' => env('TIKTOK_REDIRECT_URI', 'https://api.seller.global/oauth/tiktok/callback'),
    ],
    // SEL-040: OpenAI (roteiro + analise imagem + DALL-E + TTS + Whisper) — reusa OPENAI_API_KEY ja existente
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
        'chat_model' => env('OPENAI_CHAT_MODEL', 'gpt-4o-mini'),
        'image_model' => env('OPENAI_IMAGE_MODEL', 'dall-e-3'),
        'tts_model' => env('OPENAI_TTS_MODEL', 'tts-1'),
    ],

    // NOV-032 / INF-067: chave do endpoint GET /api/v1/client/status (Gabriel
    // consulta status de cliente). Registrada aqui pra funcionar com
    // config:cache (env() direto no middleware retornava vazio -> 500
    // "Service misconfigured" mesmo com GABRIEL_API_KEY setado no .env).
    'gabriel' => [
        'api_key' => env('GABRIEL_API_KEY', ''),
    ],

    // SEL-183: chat Gabriel seller.global (ChatController).
    // Registrado aqui pra funcionar com config:cache (env() so funciona em
    // config files quando cache esta ligado; chamar env() direto retorna null).
    'chat' => [
        'chatwoot_token' => env('CHATWOOT_TOKEN'),
        'telegram_bot_token' => env('TELEGRAM_BOT_TOKEN_CHAT'),
        'telegram_chat_id' => env('TELEGRAM_CHAT_ID_RUAN'),
        'webhook_secret' => env('CHATWOOT_WEBHOOK_SECRET'),
    ],

    // SEL-308: cron Kalodata/tt-media ativo so no seller.global (repo compartilhado)
    'kalodata' => [
        'cron' => env('KALODATA_CRON', false),

        // SEL-409: desde ~23/07/2026 o Cloudflare da kalodata.com devolve 403 pra
        // qualquer cliente HTTP simples (curl/Guzzle). Dentro de um Chromium real
        // os mesmos endpoints respondem 200. 'browser' usa o worker Playwright;
        // 'http' mantem o comportamento antigo. Default http: dos 7 backends que
        // compartilham este repo, so o seller.global tem o browser-worker.
        'fetch_mode'     => env('KALODATA_FETCH_MODE', 'http'),
        'browser_script' => env('KALODATA_BROWSER_SCRIPT', '/home/api.seller.global/browser-worker/kalodata_fetch.js'),
        'browser_dir'    => env('KALODATA_BROWSER_DIR', '/home/api.seller.global/browser-worker'),
        'browser_timeout' => (int) env('KALODATA_BROWSER_TIMEOUT', 420),
        // SEL-KALO-CONFIG (14/08): veio do bloco duplicado que existia mais
        // abaixo neste mesmo array. Chave repetida em PHP: a ultima ganha, e
        // aquele bloco apagava calado o fetch_mode/browser_script daqui.
        'ingest_token' => env('KALODATA_INGEST_TOKEN', ''),
    ],

    // FOR-088b: cron do feeder de banco de titulo/descricao ativo so no fornecefy
    // (repo compartilhado por 4 backends, os outros ficam com flag ausente/false).
    'ai_bank' => [
        'refill_cron' => env('AI_BANK_REFILL_CRON', false),
    ],

    // SEL-321: dry-run flag — com true, orquestrador monta e valida payloads sem chamar provider
    'ai_video' => [
        'dry_run' => env('AI_VIDEO_DRY_RUN', false),
        // SEL-329: circuit breaker global — false bloqueia videoGenerate + videoPerfect com 503
        'generation_enabled' => env('AI_VIDEO_GEN_ENABLED', true),
    ],

    // SEL-326: JWT curto (5min) usado pra autenticar init OAuth Shopee.
    // Secret compartilhado entre hub e todos os WLs (via .env). WL emite via
    // POST /api/auth/oauth-init-token (Sanctum-auth); hub valida via header
    // Authorization Bearer no GET /api/shopee/oauth/init. Fecha spoof do user_id
    // que hoje vem cru no query string sem middleware auth.
    'shopee_init_jwt' => [
        'secret' => env('SHOPEE_INIT_JWT_SECRET', ''),
    ],

    // INF-064: Asaas (recebimento consumer). Base default = sandbox pra nao
    // mudar comportamento de backend sem ASAAS_BASE_URL no .env.
    'asaas' => [
        'base_url' => env('ASAAS_BASE_URL', 'https://sandbox.asaas.com/api/v3'),
        'api_key'  => env('ASAAS_API_KEY', ''),
    ],

    // INF-066: Anthropic (Claude) — camada central de resiliencia (retry,
    // backoff+jitter, Retry-After, limite de concorrencia) vive em
    // app/Services/Ai/AnthropicClient.php. Sem ANTHROPIC_API_KEY o provider
    // fica dormente (ProductAiController::buildProviderChain nao o inclui).
    // NOV-217: token interno para artisan commands chamarem seus proprios endpoints
    'internal_admin_token' => env('INTERNAL_ADMIN_TOKEN', ''),

    // NOV-217: Supabase HubAI — usado pelos controllers WL (Billing, Balance, Cycle).
    'supabase_hubai' => [
        'url'          => env('SUPABASE_URL', 'https://omvstizxjosygkcolzzl.supabase.co'),
        'anon_key'     => env('SUPABASE_ANON_KEY', ''),
        'service_role' => env('SUPABASE_HUBAI_SERVICE_ROLE_KEY', ''),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY', ''),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
        'model' => env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'),
        // Modelo Claude alternativo tentado SOMENTE depois de esgotar as
        // tentativas no modelo primario. Vazio = desligado -- nao muda
        // custo/modelo padrao sem ANTHROPIC_FALLBACK_MODEL explicito no .env.
        'fallback_model' => env('ANTHROPIC_FALLBACK_MODEL', ''),
        'max_attempts' => env('ANTHROPIC_MAX_ATTEMPTS', 6),
        'max_concurrency' => env('ANTHROPIC_MAX_CONCURRENCY', 3),
        'slot_wait_ms' => env('ANTHROPIC_SLOT_WAIT_MS', 8000),
    ],


    // SEL-424: Supabase HubAI — gate de cobrança WL
    // SEL-424 / 06-08-2026: gate de cobranca de WL desligado por padrao. Ligar so no backend
    // que realmente deve barrar cliente inadimplente, nunca por heranca do repo compartilhado.
    'wl_billing_gate' => [
        'enabled' => env('WL_BILLING_GATE_ENABLED', false),
    ],

    // NOV-XXX: gate de cobranca do DONO da WL (role=supplier) no painel Filament /admin.
    // Irmao do wl_billing_gate acima (que trava role=client). Mesma licao do SEL-424:
    // desligado por padrao, so liga explicitamente no .env do backend que for usar.
    'wl_owner_billing_gate' => [
        'enabled' => env('WL_OWNER_BILLING_GATE_ENABLED', false),
    ],

    'supabase' => [
        'url'      => env('SUPABASE_URL', ''),
        'anon_key' => env('SUPABASE_ANON_KEY', ''),
        // SEL-424: a anon so le. UPDATE com ela volta 200 e nenhuma linha alterada — o RLS
        // barra em silencio. Escrita em whitelabel_billing_config exige a chave de servico.
        'service_role_key' => env('SUPABASE_HUBAI_SERVICE_ROLE_KEY', ''),
    ],

    // SEL-XXX (04/08): motor de titulo/descricao/bullets do AIProductContentService.
    // 'gemini' (default) ou 'openai' (fallback -- OPENAI_API_KEY ficou sem credito 04/08).
    'ai_text' => [
        'provider' => env('AI_TEXT_PROVIDER', 'gemini'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY', ''),
        'model'   => env('GEMINI_MODEL', 'gemini-2.0-flash'),
    ],



    // SEL-UPGRADE (09/08): feature flag do botao de upgrade de plano (paga so
    // a diferenca). Comeca DESLIGADA (default false) — so ligar depois que a
    // sessao principal validar o fluxo end-to-end. Ligar setando
    // PLAN_UPGRADE_ENABLED=true no .env (sem precisar de deploy/branch).
    'plan_upgrade' => [
        'enabled' => env('PLAN_UPGRADE_ENABLED', false),
    ],

    // INF-030 (Ruan 12/08): Matomo self-hosted (track.hubai.club, site 2) —
    // Reporting API pro AdminAnalyticsController (visao de visitantes/overview).
    'matomo' => [
        'url' => env('MATOMO_URL', 'https://track.hubai.club/'),
        'site_id' => env('MATOMO_SITE_ID', '2'),
        'token' => env('MATOMO_TOKEN', ''),
    ],

];
