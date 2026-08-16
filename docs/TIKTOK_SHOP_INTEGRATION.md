# TikTok Shop Integration — API Documentation

**Status:** DORMANT (aguardando aprovacao do app TikTok ID 7661679514094684178)
**Ativacao:** Ruan coloca `TIKTOK_APP_KEY`, `TIKTOK_APP_SECRET`, `TIKTOK_REDIRECT_URI` no `.env` da api.hubai.io + `config:clear`
**Referencia:** SEL-046 (OAuth), SEL-047 (fiacao sync)

---

## Arquitetura

A integracao segue o **padrao matriz-unica** da HubAI:
- Tokens vivem em `api.hubai.io` (tabela `tiktok_shop_connections` + espelho em `marketplace_accounts`)
- WLs (seller.global, multdrop, fornecefy, mestoredrop) chamam o relay OAuth central
- Jobs de sync iteram `marketplace_accounts` (canal `platform='tiktok'`) exatamente como ML/Shopee

### Tabelas envolvidas

| Tabela | Papel |
|---|---|
| `tiktok_shop_connections` | Fonte de verdade OAuth (tokens, open_id, shop_id, expiry) |
| `marketplace_accounts` | Espelho para jobs de sync (platform='tiktok', tiktok_connection_id aponta para connections) |
| `orders` | Pedidos TikTok (source='tiktok', marketplace_order_id=order_id da API) |

---

## Endpoints OAuth (Partner API - TikTok Shop)

### Base URLs
- **Auth:** `https://services.tiktokshop.com` (authorize)
- **API:** `https://open-api.tiktokglobalshop.com` (versao 202309)

### 1. Iniciar OAuth (relay hub)

```
GET /api/tiktok/oauth/init?service=<wl_slug>&user_id=<id>&return_url=<url>
```

- Nao requer autenticacao (chamado pelo frontend da WL antes do login)
- Redireciona para o authorize do TikTok com state em cache (TTL 15min)
- Sem `TIKTOK_APP_KEY`: retorna `503 tiktok_not_configured`

### 2. Callback OAuth (publico)

```
GET /oauth/tiktok/callback?code=<code>&state=<state>
```

- Troca `code` por tokens via POST `open.tiktokapis.com/v2/oauth/token/`
- Grava/atualiza `tiktok_shop_connections` e `marketplace_accounts` (espelho)
- Redireciona para `return_url?tiktok_status=ok&shop=<open_id>`

### 3. Status da conexao (autenticado)

```
GET /api/v1/tiktok/oauth/status
Authorization: Bearer <sanctum_token>
```

Resposta:
```json
{
  "connected": true,
  "open_id": "abc123",
  "shop_id": "abc123",
  "access_token_expire_at": 1752873600
}
```

### 4. Desconectar

```
POST /api/v1/tiktok/oauth/disconnect
Authorization: Bearer <sanctum_token>
```

Revoga `tiktok_shop_connections` e `marketplace_accounts` (status='revoked').

---

## Assinatura de API (HMAC-SHA256)

Toda chamada a `open-api.tiktokglobalshop.com` requer assinatura no query string.

**Algoritmo (spec Partner API 202309):**

```
base_string = app_secret + path + sorted_params_string + app_secret
sign = HMAC-SHA256(app_secret, base_string)
```

**Params excluidos da assinatura:** `sign`, `access_token`

**Params incluidos nos query params de toda chamada:**
- `app_key` — chave do app
- `timestamp` — epoch seconds
- `sign` — assinatura calculada
- `access_token` — token do usuario (header `x-tts-access-token` tambem obrigatorio)

Implementado em `TikTokShopService::buildSignature()` e `buildQueryParams()`.

---

## Endpoints da Partner API usados

### Pedidos

```
POST /order/202309/orders/search
Query: app_key, timestamp, sign, access_token, shop_id
Body: { create_time_ge: <epoch>, create_time_lt: <epoch>, page_size: 50 }
```

Resposta:
```json
{
  "code": 0,
  "data": {
    "orders": [
      {
        "id": "ORDER_ID",
        "status": "AWAITING_SHIPMENT",
        "create_time": 1752700000,
        "buyer_username": "...",
        "recipient_address": { "name": "..." },
        "payment": { "total_amount": "99.90" },
        "tracking_number": null
      }
    ],
    "next_page_token": null
  }
}
```

**Status mapeados:**
| TikTok | HubAI |
|---|---|
| UNPAID | pending |
| AWAITING_SHIPMENT, ON_HOLD | processing |
| IN_TRANSIT | shipped |
| DELIVERED, COMPLETED | completed |
| CANCELLED | cancelled |

### Produtos (publicacao)

```
POST /product/202309/products
PUT  /product/202309/products/{product_id}
```

### Estoque/Preco

```
POST /product/202309/products/{product_id}/inventory/update
PUT  /product/202309/products/{product_id}  (campo skus[].original_price)
```

### Etiquetas (fulfillment)

```
GET  /fulfillment/202309/orders/{order_id}/shipping_services
POST /fulfillment/202309/packages/ship
GET  /fulfillment/202309/packages/{package_id}/shipping_documents?document_type=SHIPPING_LABEL
```

---

## Token Flow

### Token de acesso
- Obtido via callback OAuth (`/v2/oauth/token/`)
- Expira em `expires_in` segundos (tipicamente 24h)
- Renovado via `TikTokShopService::refreshToken()` usando `refresh_token`
- Refresh token expira em `refresh_expires_in` segundos (tipicamente 365 dias)

### Lazy refresh
`TikTokShopService::getValidAccessToken()` verifica `token_expires_at` antes de cada chamada.
Se expirado, chama `refreshToken()` automaticamente (padrao lazy igual ao ShopeeService).

---

## Jobs de Sync

### `SyncTikTokOrdersJob` (SEL-047)
- Despachado pelo scheduler a cada hora para cada `MarketplaceAccount` com `platform='tiktok'` e `status='active'`
- Janela: `data_inicial_import` da conta (fallback: `created_at`, ultimo fallback: 7 dias)
- Pedido nasce com `is_draft=true`, promovido por `DraftOrderPromoter`
- Dedup por `marketplace_order_id` sem filtro de source (licao MUL-187)
- DORMANT com 0 contas ativas: o `chunkById` retorna vazio, nenhum job e despachado

### `SyncInventoryJob` (pre-existente)
- Ja opera sobre `MarketplaceAccount` via `MarketplaceFactory::make($account)`
- Factory retorna `TikTokShopService` para `platform='tiktok'`
- Chama `syncInventoryAndPrice()` que posta em `/product/202309/products/{id}/inventory/update`
- GATE: `MARKETPLACE_SYNC_INVENTORY_ENABLED=false` no .env (gate geral, nao especifico TikTok)

---

## Como ativar (quando app TikTok for aprovado)

1. Ruan adiciona no `.env` da `api.hubai.io`:
   ```
   TIKTOK_APP_KEY=<app_key>
   TIKTOK_APP_SECRET=<app_secret>
   TIKTOK_REDIRECT_URI=https://api.hubai.io/oauth/tiktok/callback
   ```
2. `sudo -u apihu1376 /usr/local/lsws/lsphp83/bin/php artisan config:clear`
3. `sudo -u apihu1376 /usr/local/lsws/lsphp83/bin/php artisan migrate`
   (aplica `2026_07_12_160000_add_tiktok_connection_id_to_marketplace_accounts`)
4. Seller conecta a loja via `/integracoes` no seller.global ou outra WL
5. OAuth callback cria `MarketplaceAccount` com `platform='tiktok'` automaticamente
6. Scheduler detecta a conta nas proximas execucoes hoarias e despacha `SyncTikTokOrdersJob`

---

## Observacoes tecnicas (divergencias identificadas no SEL-047)

1. **`$account->settings`** — `marketplace_accounts` nao tem coluna `settings`.
   `TikTokShopService::syncInventoryAndPrice()` usa `$account->settings['tiktok_warehouse_id']`
   — retornara null (PHP nao lanca erro em array access em null quando coluna nao existe no Eloquent).
   Quando o app for aprovado, Ruan deve avaliar se adiciona coluna `settings` (JSON) ou
   usa campo dedicado `tiktok_warehouse_id` em `marketplace_accounts`.

2. **`$product->marketplace_ids`** — `products` nao tem coluna `marketplace_ids`.
   `TikTokShopService::syncInventoryAndPrice()` busca `$product->marketplace_ids['tiktok']`
   — retornara null. Produto sem `tiktok_product_id` resulta em no-op com warning no log
   (comportamento seguro). Quando ativo, o `syncProduct()` deve persistir o ID retornado
   pela API em campo adequado (ex: `tiktok_product_id` em `products` ou `external_listing_id`
   em `client_products`).

3. **OAuth scope** — O `init()` usa `scope=user.info.basic,video.upload` (TikTok Login Kit).
   A Partner API de Shop usa `scope` diferente. Quando o app for aprovado, revisar os
   scopes corretos para TikTok Shop (ex: `shop.base`, `product.list`, `order.base`).
