# Sprint 2 — Testes Funcionais — Relatório Consolidado — 2026-04-24

> Consolidado por: Maestro (hubai-gestor)
> Agentes executores: novohubai-backend, novohubai-integracoes, novohubai-financeiro
> Data: 2026-04-24

---

## Resumo Executivo

Sprint 2 executou 5 missões de testes e infraestrutura em api.hubai.io. A API REST central passou em
14/17 endpoints (3 notas de comportamento esperado). O OAuth PKCE + HMAC do Mercado Livre validou
corretamente. Scribe 5.9.0 foi instalado e a documentação HTML foi gerada em public/docs/. O script
post-deploy.sh foi criado para padronizar deploys futuros. O smoke test do Pagar.me revelou 7 bugs
(2 críticos) que impedem o uso do gateway em produção — nenhuma chamada real foi feita, o ambiente
está seguro.

---

## Missão A — Testes API REST (commit `6f1d76d`)

**Agente:** novohubai-backend  
**Relatório:** `docs/TEST-API-REST-2026-04-24.md`

### Resultados

| Resultado | Qtd |
|---|---|
| OK (2xx/esperado) | 14 |
| NOTAS (comportamento esperado) | 3 |
| FAIL | 0 |

### Endpoints testados (17 total)

| Endpoint | Status | Resultado |
|---|---|---|
| POST /api/login | 200 | Token Sanctum gerado OK |
| GET /api/health | 200 | OK |
| GET /api/v1/me | 200 | super_admin sem Client (esperado) |
| GET /api/v1/stores | 403 | Middleware seller-only funcionando |
| GET /api/v1/suppliers | 200 | 2 fornecedores (DropRio + PlugLar) |
| GET /api/v1/suppliers/1/catalog | 200 | 541 produtos DropRio paginados |
| GET /api/v1/products | 403 | Middleware seller-only OK |
| GET /api/v1/orders | 403 | Middleware seller-only OK |
| GET /api/v1/financial/balance | 403 | Middleware seller-only OK |
| GET /api/v1/financial/transactions | 403 | Middleware seller-only OK |
| POST /api/v1/webhooks/orders/ml | 405 | NOTA: rota webhook sem prefixo /v1 |
| POST /api/webhooks/orders/mercadolivre | 404 | SKU D53-TEST sem mapeamento no DB |
| POST /api/webhooks/mercadolivre | 401 | HMAC inválido rejeitado OK |
| POST /api/v1/webhooks/pagamentos/pagarme | 404 | slug 'pagarme' sem WebhookConfig |
| GET /api/oauth/mercadolivre/redirect | 401 | NOTA: requer sessão web, não Bearer |
| POST /api/logout | 200 | Token revogado OK |
| GET /api/documentation | 200 | Swagger UI acessível |

### Issues encontrados

| # | Severidade | Descrição |
|---|---|---|
| API-1 | Média | Sem usuário lojista de teste — endpoints seller não validados no happy path |
| API-2 | Nota | Rotas de webhook ficam em /api/webhooks/ (sem prefixo v1) — documentar no Swagger |
| API-3 | Nota | OAuth redirect requer sessão PHP — Lovable deve abrir via window.open(), não fetch() |

---

## Missão B — Documentação Scribe (commit `e36426f`)

**Agente:** novohubai-backend  
**Versão instalada:** knuckleswtf/scribe 5.9.0 (2026-03-21)

### O que foi feito

- Scribe instalado via composer
- Documentação HTML gerada em `public/docs/`
- Arquivos gerados: `collection.json`, `index.html`, `css/`, `js/`, `images/`
- Acessível em: `https://api.hubai.io/docs`

### Status

- Instalacao: CONCLUIDA
- Docs geradas: CONCLUIDAS

---

## Missão C — ML OAuth PKCE + HMAC (commit `590b039`)

**Agente:** novohubai-integracoes  
**Relatório:** `docs/TEST-ML-OAUTH-2026-04-24.md`

### Validações (todas PASS)

| Check | Resultado |
|---|---|
| Redirect URL gerada corretamente | PASS |
| 6/6 parâmetros PKCE presentes | PASS |
| code_challenge SHA256 não vazio | PASS |
| HMAC assinatura inválida → 401 | PASS |
| HMAC sem header → 401 | PASS |
| refreshToken() existe e usa env vars | PASS |
| Tokens criptografados no DB (encrypt/decrypt) | PASS |

### Fix aplicado

- `config/services.php`: adicionados blocos `mercadolivre`, `openai`, `pagarme` (ausentes causavam TypeError)
- `php artisan config:cache + route:cache` re-executados

---

## Missão D — Pagar.me Smoke Test (commit `bb1da06`)

**Agente:** novohubai-financeiro (Ledger)  
**Relatório:** `docs/TEST-PAGARME-2026-04-24.md`

### Status do ambiente

| Item | Status |
|---|---|
| PAGARME_API_KEY presente | Sim (`sk_edbe0e12...`) |
| Formato da chave | Ambiguo — sem prefixo `sk_test_` ou `sk_live_` |
| PAGARME_VERSION no .env | `v2` (inconsistente — codigo usa V5) |
| Migration pagarme_* | Executada — 4 campos existem no DB |

### Bugs encontrados (7 total)

| # | Severidade | Problema | Fix |
|---|---|---|---|
| P1 | Critico | `SubscriptionService` hardcoded para `PaymentFactory::make('asaas')` | Trocar por `app(PaymentInterface::class)` |
| P2 | Critico | `PaymentFactory` nao suporta gateway `'pagarme'` — lanca `InvalidArgumentException` | Adicionar case pagarme no factory |
| P3 | Alto | `Subscription.$fillable` sem `pagarme_subscription_id`, `pagarme_customer_id`, `pagarme_status` | Adicionar ao $fillable |
| P4 | Alto | `Client.$fillable` sem `pagarme_customer_id` | Adicionar ao $fillable |
| P5 | Medio | Nenhum `WebhookConfig` para slug `pagarme` no banco | Criar via seeder ou painel admin |
| P6 | Medio | `.env` declara `PAGARME_VERSION=v2` mas codigo usa API V5 | Corrigir .env |
| P7 | Medio | Chave `sk_edbe0...` sem prefixo ambiente | Verificar dashboard Pagar.me |

### O que esta correto

- PagarmeService implementa PaymentInterface corretamente
- Autenticacao HTTP Basic (base64) correta para V5
- AppServiceProvider bind correto (`app(PaymentInterface::class)` resolve para PagarmeService)
- handleWebhook mapeia 4 eventos corretamente
- Endpoint DynamicWebhookController responde sem crash (404 esperado)
- Nenhuma chamada real a API foi feita

---

## Missão E — Deploy Workflow (commit `5953317`)

**Agente:** novohubai-qa  
**Arquivo criado:** `post-deploy.sh` (executavel, 1065 bytes)

### Script criado: `/home/api.hubai.io/public_html/post-deploy.sh`

5 passos padronizados para deploys:

1. `composer install --no-dev --optimize-autoloader`
2. `php artisan migrate --force`
3. `php artisan config:cache && route:cache && view:cache`
4. `php artisan filament:optimize`
5. `php artisan cache:clear`

Usa o binario PHP correto do LiteSpeed (`/usr/local/lsws/lsphp82/bin/php`).
Modo de uso: `bash post-deploy.sh` (manual, nao automatico via webhook/cron).

---

## Commits do Sprint 2

| Hash | Mensagem | Missao |
|---|---|---|
| `bb1da06` | test(pagarme): smoke test and SaaS billing flow documentation | D |
| `5953317` | docs(deploy): add post-deploy.sh and workflow documentation | E |
| `e36426f` | docs(api): add Scribe API documentation | B |
| `ecd6476` | fix(config): remove debug comment from services.php | C (fix) |
| `590b039` | test(ml-oauth): OAuth PKCE and webhook HMAC validation | C |
| `6f1d76d` | test(api): REST endpoint validation | A |

---

## Bugs Criticos para Sprint 3

### Alta prioridade (bloqueiam Pagar.me em producao)

| # | Arquivo | Fix necessario |
|---|---|---|
| PAGARME-1 | `app/Services/SubscriptionService.php:22` | Substituir `PaymentFactory::make('asaas')` por `app(PaymentInterface::class)` |
| PAGARME-2 | `app/Services/Payment/PaymentFactory.php` | Adicionar suporte ao gateway `'pagarme'` |
| PAGARME-3 | `app/Models/Subscription.php` | Adicionar `pagarme_subscription_id`, `pagarme_customer_id`, `pagarme_status` ao `$fillable` |
| PAGARME-4 | `app/Models/Client.php` | Adicionar `pagarme_customer_id` ao `$fillable` |

### Media prioridade

| # | Descricao | Acao |
|---|---|---|
| PAGARME-5 | Criar WebhookConfig para slug `pagarme` | Cadastrar via painel admin |
| PAGARME-6 | `.env` inconsistente (`PAGARME_VERSION=v2` vs codigo V5) | Corrigir para `PAGARME_VERSION=v5` |
| PAGARME-7 | Verificar ambiente da API key no dashboard Pagar.me | Confirmar com Ruan |
| API-4 | Criar usuario lojista de teste | `php artisan tinker` + criar Client record |
| API-5 | Documentar separacao de rotas webhook vs v1 no Swagger | Scribe annotations nos controllers |

---

## Proximos Passos Sugeridos (Sprint 3)

1. **[PAGARME-FIX]** Corrigir 4 bugs criticos: SubscriptionService, PaymentFactory, Subscription.$fillable, Client.$fillable
2. **[PAGARME-CONFIG]** Cadastrar WebhookConfig slug `pagarme` no painel admin
3. **[PAGARME-VERIFY]** Confirmar ambiente da API key e corrigir PAGARME_VERSION no .env
4. **[SELLER-TEST]** Criar usuario lojista de teste para validar endpoints /stores, /products, /orders
5. **[ASAAS-KEY]** Adicionar Asaas API Key no .env (pendente desde Sprint 1)
6. **[PLAN-DISCOUNT]** Implementar logica real de desconto no PlanDiscountResource (modelo criado vazio em Sprint 1)

---

*Relatório gerado em 2026-04-24. Fonte: commits git + relatórios dos agentes em docs/.*
