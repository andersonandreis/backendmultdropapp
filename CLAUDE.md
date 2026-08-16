# NovoHubAI — Contexto para Agentes IA

> Documento canônico carregado por agentes que conectam via SSH a este servidor.
> Fonte completa: `C:\Users\ruani\OneDrive\Documentos\Obsidian HubAI\Recursos\Arquitetura\`
> Atualizado: 2026-06-27

## O que é este sistema

NovoHubAI é uma plataforma SaaS de dropshipping/marketplace multi-tenant em Laravel + MySQL + Redis rodando em `66.94.100.155`. Serve três frontends (HubAI, MultDrop, Fornecefy) através de três backends que compartilham o **mesmo repositório git** (`hubai-plataforma`) mas têm `.env` e banco MySQL próprios.

Modelo: lojistas (`clients`) cadastram-se numa WL, conectam contas de marketplace (ML/Shopee/Bling), vendem produtos de fornecedores (`suppliers`). Cada pedido é pago em PIX gerado pelas credenciais do PRÓPRIO FORNECEDOR — HubAI nunca toca no dinheiro do pedido. Assinatura mensal do lojista (Start/Scaling/Pro) cobrada pela HubAI via Pagar.me.

OAuth de marketplaces tem relay central em `api.hubai.io` — apps externos têm redirect_uri única apontando ao hub, que redistribui tokens via HMAC para WLs. Legado Goolhub/K3s está em desligamento.

## Arquitetura Multi-Backend

| Atributo | api.hubai.io | api.multdrop.app | api.fornecefy.io |
|---|---|---|---|
| APP_TENANT | `hubai` | `multdrop` | `fornecefy` |
| Path | `/home/api.hubai.io/public_html` | `/home/api.multdrop.app/public_html` | `/home/api.fornecefy.io/public_html` |
| DB host:porta | 127.0.0.1:**3306** | 127.0.0.1:**3307** | 127.0.0.1:**3307** |
| DB nome | `hubaiapp` | `multdropapp_production` | `fornecefyapp_production` |
| Cache/session | Redis | File | File |
| LOCAL_SUPPLIER_ID | 30 (Multdrop) | 1 (DropRio legacy) | 1 (JTDrop) |
| SHOPEE_IS_BRIDGE_HUB | `true` | `false` | `false` |
| OAUTH_RELAY_URL | (é o hub) | `https://api.hubai.io` | `https://api.hubai.io` |

Os três rodam código idêntico. Diferença é só `.env` e banco. Cron `hubai-sync.sh` (5min) faz `git pull` em todos.

## Regras Críticas — Leia antes de qualquer coisa

### Núcleo

1. **`user_id` NUNCA é `client_id`.** Sempre buscar via `SELECT * FROM clients WHERE user_id = ?` ou `$user->client`. Bug histórico: `Client::find($user->id)` causou OAuth quebrado.
2. **Pagamento de pedido vai DIRETO ao fornecedor**, nunca à HubAI. `PaymentGatewayFactory::makeForSupplier()` SEMPRE usa credenciais do `supplier_payment_settings` daquele supplier.
3. **OAuth Bling SEMPRE via api.hubai.io.** Único `redirect_uri` registrado = `https://api.hubai.io/bling/callback`. NUNCA registrar URL de WL no console Bling. Bling aceita só 1 redirect por app.
4. **Isolamento de tenant é via `supplier_id`, NÃO `tenant_id`.** Tabelas de negócio (`orders`, `clients`, `products`) NÃO têm `tenant_id` — foi dropado em 30/05/2026. Filtrar sempre por `supplier_id IN (SELECT supplier_id FROM tenant_supplier WHERE tenant_id = ?)` ou usar `TenantSupplierScope`.
5. **3 backends, 1 repo git.** Editar SEMPRE no servidor de produção (NovoHubAI). `git add -A && git commit && git push` obrigatório antes de sair. Sem commit → cron `hubai-sync.sh` trava.
6. **`supplier_id` ≠ `legacy_id` ≠ `legacy_empresa_id` ≠ `legacy_loja_id`.** Cada um aponta para tabela diferente do legado. Qualificar SEMPRE qual ID está em uso.
7. **MEStoreDrop**: `supplier_id=25`, `legacy_empresa_id=447`, `legacy_loja_id=515`. O número **20** é Drop Auto Peças (`supplier_id=10`). Bug recorrente — não confundir.
8. **Webhooks retornam HTTP 200 imediatamente** + Job assíncrono. Nunca trabalho pesado no controller. ProcessSupplierPaymentWebhookJob, ProcessAsaasWebhookJob, ProcessPaymentWebhookJob.
9. **Refresh de token é lazy.** Use `MercadoLivreService::getValidToken()` ou `BlingAuthService::getValidToken()`. Nunca acessar `$account->access_token` direto para chamar API externa.
10. **Tokens são `encrypted` no banco** (cast Laravel). Acessar via `decrypt($account->bling_access_token)`. SELECT direto retorna lixo binário.
11. **`auth:sanctum + check.user.active` é o stack padrão.** `check.user.active` bloqueia se `users.is_active=false` ou `clients.is_active=false` e revoga todos tokens.
12. **`super_admin` NÃO tem registro em `clients`.** Endpoints `/api/v1/admin/*` validam role via `CheckRole` ou internamente.
13. **`supplier_admin`** = user com `role=supplier` e `supplier_id` vinculado. Endpoints `/api/v1/supplier-admin/*` checam via `requireSupplierAdmin()` interno.
14. **`tenant_supplier` é N:N**. Tenant `hubai` é especial (`default_supplier_visibility=all` → sem filtro). Demais (`multdrop`, `multdrop.app`, `fornecefy`, `mestoredrop`, `dropautopecas`) são `scoped`.
15. **Recover-tokens é GLOBAL.** Mudanças em integrações ML/Shopee/Bling precisam ser aplicadas nos 3 backends (mesmo repo, mas valida nos 3).
16. **Bling redirect = `hubai.io/bling/callback`**, NÃO `api.hubai.io/api/oauth/bling/callback`. A rota tem alias `/bling/callback`.
17. **NUNCA reiniciar o servidor / `taskkill /f` / `Restart-Computer`**. Avisa o Ruan via Telegram em caso de necessidade.
18. **NUNCA alterar senha de usuário existente em produção** para testar. Criar usuário descartável.

### Descobertas da wave 2 (2026-06-27) — Painéis, jobs e gateways

19. **NÃO existe painel `/supplier-admin` Filament.** Só existem 2 painéis: `/admin` (super_admin + supplier) e `/app` (client). Fornecedor usa `/admin` com visibilidade scoped via `canViewAny()` em cada Resource. Qualquer agente que tentar criar provider/rota `/supplier-admin` para Filament está errado. O controller `SupplierAdminPanelController` (API REST `/api/v1/supplier-admin/*`) serve o painel **Lovable** de tenant — NÃO é Filament.
20. **Rota canônica de pagamento = `POST /api/v1/orders/{id}/pay`** (OrderController@pay). A rota `POST /api/v1/financial/pay` NÃO EXISTE no código — era erro de doc. Existem também: `POST /api/v1/financial/pay-with-balance` (lote 100% saldo) e `POST /api/v1/financial/pay-partial` (lote saldo+PIX).
21. **Wallet topup e pagamento parcial bloqueados para MercadoPago.** `WalletController@deposit` e `WalletController@payPartial` validam `instanceof ShipayService` explicitamente. Suppliers com `gateway=mercadopago` (ids 33 UnicDrop, 34 Letielly Shore, 27 Peg Comercial) só aceitam PIX direto — lojistas não conseguem depositar ou pagar parcial nesses fornecedores.
22. **Queue `product-listing` SEM worker dedicado no Supervisor.** Jobs `ProcessProductListingJob` enfileirados aqui ficam presos. Workers atuais: `hubaiapp-worker` (webhooks,default,auto-listing — 4 procs), `hubaiapp-worker-inventory` (inventory,reconciliation — 2 procs), `hubaiapp-worker-legacy-import` (legacy-import — 2 procs). Antes de despachar jobs em `product-listing`, configurar worker ou usar outra queue.
23. **`ProductObserver::$disableSync` é flag anti-loop sagrada.** `SyncLegacyCatalogJob` seta `ProductObserver::$disableSync = true` antes do upsert para evitar que `SyncProductToLegacy` mande de volta ao legado. Código novo de sync DEVE respeitar essa flag — senão cria loop infinito legado↔novo.
24. **`SyncLegacyCatalogJob` roda a cada 1 minuto.** Catálogo está sempre near-real-time. Não criar workarounds tipo "forçar sync manual" — o cron já cobre. Cursor `legacy_catalog_last_sync` em Cache; batch 500.
25. **Cobrança WL = 100% legado.** Zero implementação no NovoHubAI. `SyncLegacyFinanceJob` e `import:legacy-history` desativados em 2026-06-27 (FOR-038). Não criar billing WL no NovoHubAI sem consultar o Ruan.
26. **`SyncInventoryJob` desligado por feature flag** (`MARKETPLACE_SYNC_INVENTORY_ENABLED=false`). Religar SEM revisar `effective_stock` causa o mesmo bug de 29/05/2026 (35k anúncios zerados).

### Regras da wallet do seller (2026-08-11 — MUL-362; doc completo: Obsidian `Recursos/Arquitetura/16-financeiro-wallet.md`)

30. **Ledger (`client_supplier_transactions`) é append-only.** Nunca UPDATE/DELETE. Correção = contra-partida via `ClientWalletService::creditRefund()`.
31. **Só `ClientWalletService` escreve em saldo/ledger.** NÃO criar `ClientSupplierTransaction::create` nem mexer em `ClientSupplierBalance` fora do service — os 14 bypasses existentes serão migrados pela MUL-363; não criar o 15º.
32. **Idempotência pelo ledger, não pelo carimbo do pedido.** Antes de debitar pedido, conferir débito ativo por `order_id` no ledger. `wallet_paid_at` pode ter sido apagado por bug de espelho (MUL-362); o ledger é a fonte de verdade.
33. **Pedido de WL paga na wallet daquela WL.** Cada backend tem ledger próprio; `client_id` é LOCAL de cada banco (mesma pessoa = ids diferentes em hub e WL). Débito cross-backend é bug.
34. **Estorno credita a wallet de onde o débito saiu** — nunca o "bolso" do outro backend.
35. **Campos `wallet_paid_at`/`wallet_transaction_id` não atravessam o espelho hub↔WL como autoridade.** O receptor ignora clear quando há débito local no ledger (guarda MUL-287/MUL-362 no `HubAIOrderWebhookController`). Não remover essa guarda.
36. **Extrato sempre ordenado com desempate por `id`** (`created_at DESC, id DESC`) — sem isso, lotes do mesmo segundo embaralham o `running_balance` na tela.

## Modelos e IDs — Mapa Rápido

| Entidade | Tabela | PK | FKs importantes | Campos `legacy_*` |
|---|---|---|---|---|
| User | `users` | `id` | — | — |
| Client | `clients` | `id` | `user_id` (UNIQUE) | `legacy_id_login` (= `login.id` no legado) |
| Supplier | `suppliers` | `id` | `user_id` | `legacy_id` (= `deposito.id`), `legacy_empresa_id` (= `login.id_empresa`), `legacy_loja_id` (= `loja.id`) |
| Tenant | `tenants` | `id` (UUID) | — | `legacy_empresa_id` (= `empresas.id`, nullable) |
| TenantSupplier | `tenant_supplier` | (tenant_id, supplier_id) | ambos | — |
| Subscription | `subscriptions` | `id` | `client_id`, `plan_id` | — |
| Order | `orders` | `id` | `client_id`, `supplier_id` | `legacy_id` (= `pedido.id`) |
| MarketplaceAccount | `marketplace_accounts` | `id` | `client_id`, `supplier_id` | `legacy_id` |
| Product | `products` | `id` | `supplier_id` | `legacy_sku_pai_id` |
| ClientProduct | `client_products` | `id` | `client_id`, `product_id`, `marketplace_account_id` | `legacy_id` |
| Inventory | `inventory` | `id` | `product_id`, `warehouse_id` (→suppliers), `producer_id` (→suppliers) | — |
| ClientSupplierBalance | `client_supplier_balances` | `id` | `client_id`, `supplier_id` | — |
| SupplierPaymentSetting | `supplier_payment_settings` | `id` | `supplier_id` | — (api_key/secret CRIPTOGRAFADOS) |
| PixTransaction | `pix_transactions` | `id` | `supplier_id`, `client_id`, `order_id` | — |

### Suppliers principais (produção 2026-06-27)

| `id` | Nome | `legacy_id` | `legacy_empresa_id` | `legacy_loja_id` |
|---|---|---|---|---|
| 1 | DropRio (arquivado) | NULL | 24 | 565 |
| 7 | Plug Lar | 61 | 61 | 133 |
| 8 | Drop - SP | 11 | 11 | 75 |
| 9 | Envio Nacional - RJ | 13 | 13 | 79 |
| 10 | **Drop Auto Peças - SP** | 20 | **20** | 93 |
| 11 | Envio Nacional | 25 | 25 | 97 |
| 13 | JTDrop | 53 | 53 | 128 |
| 25 | **M&E Store (MEStoreDrop)** | 447 | **447** | 515 |
| 30 | Multdrop | 498 | 498 | 565 |
| 156 | LogiDrop SP | 500 | 500 | 567 |

### Tenants registrados

| Slug | Visibility | Suppliers visíveis |
|---|---|---|
| `hubai` | `all` | TODOS |
| `multdrop` | `scoped` | 1, 30 |
| `multdrop.app` | `scoped` | 30 |
| `fornecefy` | `scoped` | quase todos (exceto mestoredrop e dropautopecas) |
| `mestoredrop` | `scoped` | 25 |
| `dropautopecas` | `scoped` | 10 |

## Isolamento de Tenant

```sql
-- Mecanismo subjacente do TenantSupplierScope
SELECT * FROM orders
WHERE supplier_id IN (
  SELECT supplier_id FROM tenant_supplier WHERE tenant_id = '<UUID>'
);
```

**Como o scope é resolvido (Eloquent):**
- Middleware `EnsureTenantContext` lê `X-Tenant-Slug` no header
- Resolve `Tenant` e faz `app()->instance('current_tenant', $tenant)`
- Trait `BelongsToTenantSupplier` (em `Order`, `Product`, `MarketplaceAccount`) injeta o scope global automaticamente
- Tenant `hubai` (`visibility=all`) bypassa o filtro

**Bypass para CLI/admin/jobs:**
```php
Order::withoutTenantSupplierScope()->count();
```

## Pagamentos — Como NÃO errar

### Fluxo 1: Pedido WL (lojista paga fornecedor)
- **Rota canônica (pedido único)**: `POST /api/v1/orders/{id}/pay` → `OrderController@pay`
- **Lote 100% saldo**: `POST /api/v1/financial/pay-with-balance` → `WalletController@payWithBalance`
- **Lote saldo+PIX**: `POST /api/v1/financial/pay-partial` → `WalletController@payPartial`
- `POST /api/v1/financial/pay` **NÃO EXISTE** — era erro de doc.
- `OrderPaymentService` decide: wallet 100% / wallet parcial+PIX / PIX 100%
- PIX é gerado nas credenciais do FORNECEDOR via `PaymentGatewayFactory::makeForSupplier()`
- Webhook: `POST /api/webhooks/payment/{supplier_slug}/{gateway}` → HMAC → Job
- Gateways suportados: `shipay`, `asaas`, `pagarme`, `mercadopago`
- **MercadoPago só PIX direto**: `WalletController@deposit` e `WalletController@payPartial` verificam `instanceof ShipayService`. Suppliers MP (33, 34, 27) bloqueiam wallet topup/parcial.

### Fluxo 2: Assinatura HubAI
- `POST /api/checkout/subscription` (público) → Pagar.me cria customer + order
- Plans: Start R$97/mês (100 SKUs), Scaling R$197/mês (200), Pro R$297/mês (300+)
- Webhook: `POST /api/webhooks/asaas` (header `asaas-access-token`) ou `POST /api/webhooks/pagarme`
- Cria `User` + `Client` + `Subscription` automaticamente (senha padrão `123456`)

### Fluxo 3: Cobrança WL pela HubAI
- **100% LEGADO** (K3s + Supabase, ZERO implementação no NovoHubAI). `SyncLegacyFinanceJob` e `import:legacy-history` foram DESATIVADOS em 2026-06-27 (FOR-038).
- Quinzenal (dia 1 e 15): R$30/cliente ativo + R$1/pedido confirmado
- Fórmula customizável por WL (`valor_cliente_manual` em `empresas`)
- NÃO criar billing WL no NovoHubAI sem consultar o Ruan — dual-source causa divergência.

### Wallet (ClientSupplierBalance)
- Saldo do lojista POR fornecedor
- `ClientWalletService::credit()` / `debitAvailable()` / `debitForOrder()` (com `lockForUpdate`)
- Auto-pay: se `client.auto_pay_from_wallet=true`, debita ao receber pedido novo
- Topup: `POST /api/v1/financial/deposit {supplier_id, amount}` → PIX → webhook `POST /api/webhooks/shipay/wallet`
- **Topup/parcial bloqueado para MercadoPago**: `WalletController` valida `instanceof ShipayService`. Suppliers MP (33 UnicDrop, 34 Letielly Shore, 27 Peg Comercial) só aceitam PIX direto via `POST /api/v1/orders/{id}/pay`.

## OAuth — Relay Central

- **api.hubai.io é o ÚNICO ponto de entrada OAuth** (ML, Shopee, Bling)
- Apps externos têm 1 redirect_uri registrada apontando ao hub
- `state` (JSON base64) carrega: `client_id`, `supplier_id`, `code_verifier`, `return_url`, `source_system`
- `source_system` identifica qual WL iniciou o fluxo (validado contra `config('bling.relay_endpoints')` anti-SSRF)
- Após callback no hub: HubAI salva localmente + relay HMAC para WL de origem
- HMAC sobre raw body com `BLING_RELAY_HMAC_SECRET` / `SHOPEE_BRIDGE_SECRET` (compartilhados entre instalações)
- Header relay: `X-HubAI-Bridge-Sig: <hmac-sha256>`

### Particularidades
- **ML**: PKCE S256, token expira ~6h, `MercadoLivreService::getValidToken()` faz refresh lazy
- **Shopee**: NÃO retorna state no callback → workaround com `state_token` embebido na redirect_uri; HMAC próprio por requisição (partner_id+path+timestamp+token+shop_id)
- **Bling**: Basic Auth no exchange, refresh_token expira em 30 dias, registra webhooks (pedidos, NF-e, estoques) após OAuth

### Status flow do MarketplaceAccount
```
pending  → criado em /redirect (firstOrCreate)
active   → após exchange bem-sucedido
needs_reauth → invalid_grant ou HTTP 401 (sync pausado)
active   ← clearReauthBlock() após reconexão
```

## Endpoints Chave

| Método | Path | Descrição |
|---|---|---|
| POST | `/api/login` | Login Sanctum (throttle 5/min) |
| GET | `/api/v1/me` | Dados do user autenticado |
| GET | `/api/v1/orders` | Lista pedidos do lojista |
| POST | `/api/v1/orders/{id}/pay` | **CANÔNICA** — Paga pedido único (wallet/PIX/misto) |
| GET | `/api/v1/financial/balance` | Saldo atual |
| POST | `/api/v1/financial/deposit` | Depósito PIX (gera QR) — só ShiPay |
| POST | `/api/v1/financial/pay-with-balance` | Lote 100% saldo |
| POST | `/api/v1/financial/pay-partial` | Lote saldo + PIX — só ShiPay |
| GET | `/api/v1/products` | Produtos do lojista |
| POST | `/api/v1/products/batch-publish` | Publica em lote |
| GET | `/api/v1/suppliers` | Fornecedores disponíveis |
| GET | `/api/v1/marketplace/status` | Status conexões marketplace |
| GET | `/api/oauth/{platform}/redirect` | Inicia OAuth ML/Shopee/Bling |
| GET | `/api/oauth/{platform}/callback` | Callback OAuth (recebe code) |
| POST | `/api/oauth/bling/wl-relay` | Receiver HMAC nas WLs (Bling) |
| POST | `/api/oauth/shopee/hubai-relay` | Receiver HMAC nas WLs (Shopee) |
| GET | `/api/v1/supplier-admin/picking/queue` | Fila picking |
| POST | `/api/v1/supplier-admin/picking/ship` | Confirma envio |
| POST | `/api/webhooks/payment/{slug}/{gateway}` | Webhook pagamento por fornecedor |
| POST | `/api/webhooks/asaas` | Webhook Asaas (assinaturas) |
| POST | `/api/webhooks/pagarme` | Webhook Pagar.me |
| GET | `/api/tenant-api/v1/orders` | Pedidos via Tenant API (Bearer `ht_live_*`) |
| GET | `/api/internal/system-health` | Health interno (X-Internal-Key) |
| GET | `/api/admin/stats` | KPIs (X-Admin-Key) |

Cobertura completa: ~298 endpoints. Swagger ~25% coberto em `/api/documentation`.

## Painéis Filament — 2 painéis, NÃO 3

| Painel | URL | Brand | Roles | Provider |
|--------|-----|-------|-------|----------|
| Admin | `/admin` | MultDrop | `super_admin`, `supplier` | `AdminPanelProvider` |
| App (Seller) | `/app` | MEStoreDrop Seller | `client` | `AppPanelProvider` |

- **NÃO existe `/supplier-admin` Filament.** Fornecedor (`supplier`) entra em `/admin` com visibilidade scoped via `canViewAny()` em cada Resource. Padrão de isolamento: `WHERE supplier_id = auth()->user()->supplier->id`.
- O controller `SupplierAdminPanelController` (`/api/v1/supplier-admin/*` REST) serve o painel **Lovable** de tenant — não tem nada a ver com Filament.
- Role `admin` existe no código mas não tem acesso a nenhum painel Filament (só API REST).
- `User::canAccessPanel()` valida role + `is_active`.

Resources principais em `/admin` (40+): OrderResource, SupplierResource, ProductResource, ProductVariationResource, InventoryResource, SupplierBalanceResource, SupplierTransactionResource, PixTransactionResource, MarketplaceAccountResource, ClientResource, TenantResource, etc.

Pages operacionais em `/admin`: CentroComandoPage, PickingPacking, ProcessOrders, ConfigurarBling, ValidarPix, CarteirasClientes, ContaCorrente, etc.

Resources em `/app` (16): OrderResource (Meus Pedidos), ProductResource, ClientProductResource, MarketplaceAccountResource (Minhas Lojas), InvoiceResource (Minhas Faturas), SubscriptionResource, SyncLogResource, + grupo Drop Internacional (DropStoreResource, DropMiningResource, etc.).

Detalhes completos em [[06-filament-admin-panels]].

## Jobs, Queues e Crons — Resumo Operacional

### Workers Supervisor

```
hubaiapp-worker              4 procs  queues: webhooks,default,auto-listing   timeout: 700s
hubaiapp-worker-inventory    2 procs  queues: inventory,reconciliation         timeout: 3600s
hubaiapp-worker-legacy-import 2 procs queues: legacy-import                    timeout: 1200s
```

### Queues SEM worker dedicado — risco

- **`product-listing`**: `ProcessProductListingJob` enfileirado aqui fica preso. Antes de despachar nessa queue, configurar worker no Supervisor ou mover para `default`.
- `payments`, `notifications`: caem no `default` (ok, mas sem isolamento).

### Jobs DESLIGADOS (não religar sem revisar)

| Job | Motivo |
|-----|--------|
| `SyncInventoryJob` | `MARKETPLACE_SYNC_INVENTORY_ENABLED=false` — bug `effective_stock` zerou 35k anúncios (29/05) |
| `RefreshTokensJob`, `tokens:proactive-refresh` | NOV-061: substituído por lazy refresh |
| `SyncLegacyFinanceJob`, `import:legacy-history` | FOR-038 (2026-06-27): cobrança WL é legado |
| `integrations:sentinela`, `migracao:summary` | zero Telegram flood |
| `discount:update-daily` | FOR-036: catálogo = preço fornecedor |

### Crons mais sensíveis (1-5min)

| Frequência | Job | Função |
|---|---|---|
| 1min | `SyncLegacyCatalogJob` | Delta `sku_pai` legado → products/inventory (batch 500, cursor `legacy_catalog_last_sync`) |
| 1min | `ProductListingDispatcherJob` | Dispatcher fila publicação (slow=1, normal=5, fast=20 jobs/ciclo) |
| 1min | `products:sync-from-legacy --deposito=498` | Consome `sku_pai_changes_queue` JTDrop |
| 5min | `SyncLegacyOrdersJob` | Status/rastreio/etiqueta de pedidos manuais |
| 5min | `ImportLegacyOrdersJob` | Pedidos NOVOS do legado (batch 30/cliente) |
| 5min | `RetryBridgeRelayJob` | Reprocessa `bridge_relay_queue` (backoff 30s→5min→30min→2h) |
| 5min | `ExpirePixTransactionsJob` | Expira PIX vencidos |
| 5min | `tenant:divergence-check` | Divergências por tenant |
| 5min | `integration:aggregate-logs` | Agrega logs em `integration_logs` |
| 15min | `ml/shopee/bling:recover-tokens` | Recupera contas `needs_reauth` |
| 15min | `pix-status-poll` | Sync PIX pendentes com ShiPay |

### Anti-loop sagrado: `ProductObserver::$disableSync`

`SyncLegacyCatalogJob` seta `ProductObserver::$disableSync = true` antes do upsert para evitar que o `ProductObserver` dispare `SyncProductToLegacy`. Código novo de sync DEVE respeitar essa flag — senão cria loop infinito legado↔novo (mesmo problema que `_source=hubaiapp` resolve no sentido contrário).

### `DB_QUEUE_RETRY_AFTER=660s` é obrigatório

`SyncLegacyOrdersJob` tem `timeout=600s`. Se `retry_after < timeout`, o worker re-enfileira antes de terminar → gera duplicatas massivas (51k em junho).

Mapa completo em [[07-jobs-queues-crons]].

## Sync Legado e Catalog Bridge

### Bridge Goolhub (Novo → Legado)

- Service: `GoolhubBridgeService` (único ponto de saída para o legado via HTTP)
- Endpoints `/bridge/*.php` (16 total): `produto_upsert`, `produto_delete`, `produto_changes_pop`, `ml_event_relay`, `bling_save_tokens`, `get_label`, `get_invoice`, `import_order`, `order_cancel`, `order_refund`, `picking_packing`, `devolucao`, etc.
- Auth: header `X-Bridge-Key: {GOOLHUB_BRIDGE_KEY}` ou HMAC (`X-HubAI-Bridge-Sig`) para relays
- Fila de retry: `bridge_relay_queue` (backoff 30s/5min/30min/2h/failed_max)
- **NUNCA conectar diretamente ao MySQL legado para escrita** — toda escrita passa pela bridge

### Catalog Bridge (WL ← NovoHubAI)

- Para MEStoreDrop ler catálogo central sem acesso direto ao banco
- `InternalCatalogController` em `api.hubai.io`: `GET /api/internal/catalog/products` e `/{id}`
- Auth: header `X-Internal-Key` (= `INTERNAL_BRIDGE_KEY` = `GOOLHUB_BRIDGE_KEY` = `hb-bridge-2026-xK9mP3qR7vL2nW8`)
- Identificação tenant: header `X-Tenant-Slug` obrigatório (ex: `mestoredrop`)
- Proxy no MEStoreDrop: `ProxyCatalogController` (`GET /api/v1/central/catalog/*` com Sanctum)

Detalhes completos em [[08-sync-legado-catalog-bridge]].

## Como Fazer Deploy

```bash
# 1. Editar diretamente no servidor (NUNCA editar local + scp)
cd /home/api.hubai.io/public_html
vim app/Http/Controllers/...

# 2. Validar
/usr/local/lsws/lsphp83/bin/php artisan tinker

# 3. Commit IMEDIATO (cron sync 5min trava sem isso)
git add -A
git commit -m "fix: <descrição clara>"
git push origin <branch>

# 4. Para repos compartilhados (hubai-plataforma): branch feature + perguntar ao Ruan ANTES do merge em main
```

### Verificação obrigatória antes de declarar pronto
- Backend: testar endpoint real (`curl`) ou query SQL
- Verificar logs em `storage/logs/laravel.log`
- Verificar funcionou ANTES de fechar a tarefa

### Regenerar Swagger
```bash
cd /home/api.hubai.io/public_html
/usr/local/lsws/lsphp83/bin/php artisan l5-swagger:generate
```

## Referências Rápidas

### No servidor
- Código: `/home/api.hubai.io/public_html/`
- Configs: `config/multdrop.php`, `config/bling.php`, `config/payment.php`
- Logs: `storage/logs/laravel.log`
- PHP: `/usr/local/lsws/lsphp83/bin/php`

### URLs
- Swagger: https://api.hubai.io/api/documentation
- OpenAPI JSON: https://api.hubai.io/api/documentation/json
- Tenant API OpenAPI: https://api.hubai.io/api/tenant-api/v1/openapi.json

### Bancos
- Hub principal: `127.0.0.1:3306` `hubaiapp`
- MultDrop: `127.0.0.1:3307` `multdropapp_production`
- Fornecefy: `127.0.0.1:3307` `fornecefyapp_production`
- Legado K3s (em desligamento): `217.216.81.157:32000` `tudoonline_production`

### Connections Laravel disponíveis no hubai.io
- `mysql` (default) → hubaiapp
- `fornecefy` → fornecefyapp_production (cross-banco)
- `multdrop` → multdropapp_production (cross-banco)
- `legacy` → tudoonline_production (bridge)

### Auth Tokens
- Sanctum (lojista/admin): `Authorization: Bearer 1|abc...`
- Tenant API (externos): `Authorization: Bearer ht_live_<keyId>.<secret>`
- Internal Bridge (legado): `X-Internal-Key: <INTERNAL_BRIDGE_KEY>`
- Gabriel: `X-Gabriel-Token` ou `Authorization: Bearer <GABRIEL_API_KEY>`
- Admin Stats: `X-Admin-Key`

### Documentação completa
Vault: `C:\Users\ruani\OneDrive\Documentos\Obsidian HubAI\Recursos\Arquitetura\`
- `00-INDEX.md` — índice master + 17 regras
- `01-hierarquia-instalacoes.md` — 3 backends + tenants
- `02-modelos-ids.md` — ER, FKs, legacy mapping
- `03-api-endpoints.md` — inventário ~298 endpoints
- `04-fluxo-pagamentos.md` — wallet, PIX, webhooks (rotas canônicas)
- `04b-mercadopago-gateway.md` — MercadoPago supplier vs Drop consumer
- `05-oauth-marketplaces.md` — relay OAuth ML/Shopee/Bling
- `06-filament-admin-panels.md` — painéis `/admin` e `/app`, resources, roles
- `07-jobs-queues-crons.md` — 54 jobs, 6 queues, 33 crons + riscos
- `08-sync-legado-catalog-bridge.md` — sync legado, GoolhubBridge, Catalog Bridge

### Análise de Impacto Cruzado — PROCESSO OBRIGATÓRIO (27/06/2026)

25. **ANTES de mexer em módulo de risco, mapear dependências.** Módulos de risco: SyncLegacyCatalogJob, ImportLegacyOrdersJob, OAuthController, TenantSupplierScope, workers Supervisor, permissões storage, campos legacy_empresa_id/legacy_loja_id. Ver runbook completo em Obsidian: .
26. **Checklist pós-mudança obrigatório:** (a) workers RUNNING (), (b) 0 migrations pending (), (c) legado verificado (sync jobs não quebraram), (d) smoke test endpoint 200.
27. ** ≠  ≠ .** MEStoreDrop:  (tabela ),  (tabela ). O número 447 é o  — bug recorrente (NOV-140, 27/06/2026).
28. **Permissões de storage DEVEM ser do usuário do processo PHP**, não . Sempre verificar  após pull/deploy.  se necessário. Jobs com  falham silenciosamente se o dono for root.
29. **Queue  precisa de worker dedicado** em cada backend que importa do legado. Verificar  antes de criar jobs para essa queue.


### Analise de Impacto Cruzado — PROCESSO OBRIGATORIO (27/06/2026)

25. **ANTES de mexer em modulo de risco, mapear dependencias.** Modulos de risco: SyncLegacyCatalogJob, ImportLegacyOrdersJob, OAuthController, TenantSupplierScope, workers Supervisor, permissoes storage, campos legacy_empresa_id/legacy_loja_id. Ver runbook: Obsidian Recursos/runbook-impacto-cruzado.md.
26. **Checklist pos-mudanca obrigatorio:** (a) workers RUNNING via supervisorctl status, (b) 0 migrations pending via artisan migrate:status, (c) legado verificado (sync jobs nao quebraram), (d) smoke test endpoint 200.
27. **legacy_empresa_id != legacy_loja_id != id_deposito.** MEStoreDrop: legacy_empresa_id=20 (tabela empresas), legacy_loja_id=515 (tabela lojas). O numero 447 e o id_deposito — bug recorrente NOV-140 27/06/2026.
28. **Permissoes de storage DEVEM ser do usuario do processo PHP**, nao root. Verificar ls -la storage/framework/cache/ apos pull/deploy. Jobs com withoutOverlapping() falham silenciosamente se dono for root.
29. **Queue legacy-import precisa de worker dedicado** em cada backend que importa do legado. Verificar /etc/supervisor/conf.d/ antes de criar jobs para essa queue.
