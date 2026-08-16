# Sprint 3 — Fixes Pagar.me — 2026-04-24

> Executado por: Claude Code (Windows) via SSH direto.
> Contexto: correções dos 7 bugs identificados pelo smoke test (novohubai-financeiro/Ledger) em `docs/TEST-PAGARME-2026-04-24.md`.

---

## Estado inicial vs após correções

### BUGS REALMENTE CORRIGIDOS

| # | Bug reportado | Situação | Fix |
|---|---|---|---|
| BUG-1 | PaymentFactory não suporta 'pagarme' | **CONFIRMADO** — arquivo estava corrompido (`string  = 'asaas'`, `strtolower()` sem arg) | Reescrito com sig correta + leitura de config |
| BUG-2 | PagarmeService nunca é chamado | **FALSO POSITIVO** — SubscriptionService.php usa `PaymentFactory::make($gatewayName)` onde `$gatewayName = config('payment.default_gateway', 'pagarme')`. Não é hardcoded 'asaas'. |
| BUG-3 | Subscription.$fillable sem campos pagarme | **JÁ CORRIGIDO** em commit `ca49400` do subagent Ledger — fillable atual tem `pagarme_subscription_id`, `pagarme_customer_id`, `pagarme_status` |
| BUG-4 | Client.$fillable sem pagarme_customer_id | **JÁ CORRIGIDO** (está no fillable atual) |
| BUG-5 | Nenhum WebhookConfig cadastrado | **JÁ CORRIGIDO** — `webhook_configs.id=1` com `slug=pagarme`, `event_field=type`, `expected=charge.paid`, `is_active=1` |
| BUG-6 | .env PAGARME_VERSION=v2 inconsistente | **CORRIGIDO** — alterado para `v5` |
| BUG-7 | API key `sk_edbe...` sem prefixo test/live | **NOTA PRA RUAN** — não é bug de código, é config. Verificar no dashboard Pagar.me se é chave de produção ou sandbox antes de cobrar de verdade |

### Conclusão real

Dos 7 "bugs" reportados, **apenas 2 eram bugs reais de código**:
1. PaymentFactory corrompido (corrigido agora)
2. `.env` com versão incorreta (cosmético, corrigido)

Os outros 5 ou já estavam corrigidos pelo próprio Ledger (fillable + WebhookConfig) ou eram interpretação errada (SubscriptionService não é hardcoded).

---

## Commit a9 — fix

`fix(payment): corrigir PaymentFactory + .env PAGARME_VERSION=v5`

Arquivos:
- `app/Services/Payment/PaymentFactory.php` — reescrito (era `public static function make(string  = 'asaas')` → `make(?string $gateway = null)`, lê de config)
- `.env` — `PAGARME_VERSION=v2` → `v5`

Validação:
- `php -l app/Services/Payment/PaymentFactory.php` → no syntax errors
- `PaymentFactory::make()` → `App\Services\Payment\PagarmeService` (via config)
- `PaymentFactory::make('pagarme')` → `App\Services\Payment\PagarmeService`
- Webhook endpoint aceita POST sem crash (ainda precisa assinatura correta pra processar)

---

## Verificações de segurança

- ✅ Backup do arquivo quebrado em `/tmp/PaymentFactory.php.broken-*`
- ✅ Backup do `.env` em `.env.bak-pagarme-*`
- ✅ Nenhuma chamada real à API Pagar.me foi feita
- ✅ Nenhum registro alterado no DB (produtos, subscriptions, clients intactos)

---

## Pendências pro Ruan

1. **Verificar API key** `sk_edbe0e1212d9413fbf0a1cc04822fb93` no dashboard Pagar.me — é key de teste ou produção? Se teste, trocar por `sk_live_*` antes de cobrar cliente real.
2. **Criar Plan** no painel admin pra testar fluxo completo de subscription (atualmente plans table pode estar vazia).
3. **Criar usuário lojista (client)** de teste pra validar createCustomer + createSubscription com card token real.

