# Pagar.me V2 — Smoke Test Report
**Data:** 2026-04-24  
**Agente:** novohubai-financeiro (Ledger)  
**Servidor:** `api.hubai.io` (66.94.100.155)  

---

## 1. Ambiente da API Key

| Campo | Valor |
|---|---|
| `APP_ENV` | `production` |
| `PAGARME_API_KEY` | `sk_edbe0e1212d9413fbf0a1cc04822fb93` |
| `PAGARME_VERSION` | `v2` (no .env) |
| **Formato da key** | `sk_` puro — **NÃO** começa com `sk_test_` nem `sk_live_` |
| **Diagnóstico** | Formato ambíguo. No Pagar.me V5 (que o código usa), test keys são `sk_test_*` e live são `sk_live_*`. Esta chave não segue o padrão — pode ser key de sandbox legado ou chave V4/V5 sem prefixo de ambiente. **Verificar no dashboard Pagar.me antes de qualquer integração real.** |

> ⚠️ ATENÇÃO: O `.env` declara `PAGARME_VERSION=v2`, mas `PagarmeService.php` aponta para a API V5 (`https://api.pagar.me/core/v5`). Há inconsistência de versão declarada vs. utilizada.

---

## 2. Validação do PagarmeService

**Arquivo:** `app/Services/Payment/PagarmeService.php`

### O que funciona corretamente
- Classe implementa `PaymentInterface`
- Montagem do HTTP client: `Basic base64(api_key:)` — padrão correto do Pagar.me V5
- Métodos implementados: `createCustomer`, `createSubscription`, `cancelSubscription`, `createOrderPayment`, `handleWebhook`
- `handleWebhook` mapeia eventos: `charge.paid`, `charge.payment_failed`, `subscription.canceled`, `subscription.created`

### Bugs encontrados (sem chamada real à API)

| # | Severidade | Problema |
|---|---|---|
| BUG-1 | **Crítico** | `PaymentFactory` **não inclui** `pagarme` como gateway — `PaymentFactory::make('pagarme')` lança `InvalidArgumentException` |
| BUG-2 | **Crítico** | `SubscriptionService` está hardcoded para `PaymentFactory::make('asaas')` — `PagarmeService` nunca é chamado pelo fluxo principal |
| BUG-3 | **Alto** | `Subscription.$fillable` não inclui campos pagarme (`pagarme_subscription_id`, `pagarme_customer_id`, `pagarme_status`) — `updateOrCreate` silenciosamente ignora esses campos por mass assignment |
| BUG-4 | **Alto** | `Client.$fillable` não inclui `pagarme_customer_id` — `$client->update(['pagarme_customer_id' => ...])` é ignorado |
| BUG-5 | **Médio** | `AppServiceProvider` faz `bind(PaymentInterface::class, PagarmeService::class)` mas o bind só funciona via `app(PaymentInterface::class)` — `SubscriptionService` usa `PaymentFactory` diretamente, ignorando o bind |

---

## 3. Teste do Webhook Endpoint

**Rota real:** `POST api/v1/webhooks/pagamentos/{slug}` (não `api/webhooks/pagamentos/pagarme` como documentado na missão)

**Curl executado:**
```bash
curl -X POST https://api.hubai.io/api/v1/webhooks/pagamentos/pagarme \
  -H "Content-Type: application/json" \
  -d '{"type":"charge.paid","data":{"id":"ch_TEST_1745527XXX","status":"paid","amount":100,"metadata":{"subscription_id":"test"}}}'
```

**Resposta:** `HTTP 404` — `{"error":"Webhook não configurado"}`

**Diagnóstico:** Esperado. O `DynamicWebhookController` exige um registro em `webhook_configs` com `slug='pagarme'` e `is_active=true`. Nenhum registro existe. O endpoint responde corretamente (sem panic/500).

> Nota: O `DynamicWebhookController` (endpoint genérico) e o `PagarmeService::handleWebhook()` são dois mecanismos diferentes e **não estão conectados** — o controller genérico não delega para `PagarmeService`.

---

## 4. Status da Migration e Campos no DB

**Migration:** `2026_04_23_100002_add_pagarme_fields_to_subscriptions_and_clients`  
**Status:** ✅ `Ran` (executada)

**Campos em `subscriptions`:**
```
pagarme_subscription_id   varchar(255)  NULL
pagarme_customer_id       varchar(255)  NULL
pagarme_status            varchar(255)  NULL
```

**Campos em `clients`:**
```
pagarme_customer_id       varchar(255)  NULL
```

**DB = OK.** Os 4 campos existem. O problema é que os Models não expõem esses campos via `$fillable`.

---

## 5. Fluxo SaaS Documentado

### 5.1 Admin cria plano

1. Admin acessa `/admin/plans` (Filament — `PlanResource`)
2. Preenche: nome, slug, preço mensal, preço anual, max SKUs, max conexões marketplace/ERP, trial days
3. Salva em tabela `plans` (slug gerado automaticamente via `booted()`)
4. **Pagar.me NÃO é chamada** nesta etapa (planos são locais)

### 5.2 Seller assina plano

Fluxo atual (com bugs):
```
SubscriptionService::subscribeClientToPlan($client, $plan, $paymentDetails)
  → cancela assinatura ativa anterior (local)
  → PaymentFactory::make('asaas')    ← ❌ hardcoded Asaas, ignora Pagar.me
  → $gateway->createSubscription()
```

Fluxo intencionado (mas não implementado):
```
PagarmeService::createSubscription($client, $planId, $paymentDetails)
  → getOrCreateCustomer($client)
      → se client.pagarme_customer_id null: POST /customers (cria no Pagar.me)
      → salva pagarme_customer_id no Client   ← ❌ $fillable bloqueado
  → POST /subscriptions (com customer_id + pagarme_plan_id + card_token)
  → Subscription::updateOrCreate(...)          ← ❌ campos pagarme bloqueados por $fillable
  → retorna $subscription local
```

### 5.3 Cobrança mensal (recorrente)

Pagar.me V5 gerencia automaticamente a recorrência via `plan_id` da assinatura criada.  
O webhook `subscription.renewed` ou `charge.paid` é disparado ao servidor → endpoint `/api/v1/webhooks/pagamentos/{slug}` → `DynamicWebhookController`.

**Porém:** nenhum `WebhookConfig` para slug `pagarme` existe, então cobranças recorrentes não ativam lógica local.

### 5.4 Onde Pagar.me é chamada no código

| Ponto | Arquivo | Status |
|---|---|---|
| `createCustomer` | `PagarmeService.php:53` | Código OK, mas nunca chamado pelo fluxo principal |
| `createSubscription` | `PagarmeService.php:100` | Código OK, mas nunca chamado |
| `handleWebhook` | `PagarmeService.php:174` | Código OK, mas sem rota dedicada |
| Bind no container | `AppServiceProvider.php:16` | OK — `app(PaymentInterface::class)` resolve para PagarmeService |
| `SubscriptionService` | `SubscriptionService.php:22` | ❌ usa Asaas hardcoded, ignora PagarmeService |

---

## 6. Resumo de Problemas

| # | Criticidade | Problema | Fix Necessário |
|---|---|---|---|
| P1 | 🔴 Crítico | PagarmeService nunca é chamado (SubscriptionService usa Asaas) | Trocar `PaymentFactory::make('asaas')` → `app(PaymentInterface::class)` |
| P2 | 🔴 Crítico | PaymentFactory não suporta gateway 'pagarme' | Adicionar `'pagarme' => app(PagarmeService::class)` no factory |
| P3 | 🟠 Alto | Subscription.$fillable sem campos pagarme | Adicionar `pagarme_subscription_id`, `pagarme_customer_id`, `pagarme_status` |
| P4 | 🟠 Alto | Client.$fillable sem pagarme_customer_id | Adicionar `pagarme_customer_id` |
| P5 | 🟡 Médio | Nenhum WebhookConfig cadastrado para 'pagarme' | Criar via admin ou seeder |
| P6 | 🟡 Médio | PAGARME_VERSION=v2 no .env mas código usa V5 | Corrigir .env ou docstring |
| P7 | 🟡 Médio | Chave `sk_edbe0...` sem prefixo test_/live_ | Verificar no dashboard Pagar.me |

---

## 7. O que está OK

- ✅ Migration rodou — 4 campos pagarme existem no DB
- ✅ PagarmeService implementa PaymentInterface corretamente
- ✅ Autenticação Basic correta (`base64(api_key:)`)
- ✅ AppServiceProvider bind correto
- ✅ Endpoint webhook responde (sem crash) — 404 esperado sem config
- ✅ handleWebhook mapeia 4 eventos corretamente
- ✅ Nenhuma chamada real à API foi feita (smoke test seguro)

---

*Gerado por: novohubai-financeiro (Ledger) — 2026-04-24*
