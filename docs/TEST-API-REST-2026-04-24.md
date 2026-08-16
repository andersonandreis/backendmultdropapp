# Teste API REST — 2026-04-24 (Onda 2)

> Executado por: Claude Code (Windows) via SSH direto. Subagents OpenClaw falharam por limitação de runtime (não conseguem spawnar SSH). Feito manualmente.

## Bug CRÍTICO encontrado e corrigido

### BUG-CRIT-1 — `app/Models/Client.php` com syntax error

**Severidade:** CRÍTICA (quebrava múltiplos endpoints)

**Descrição:** O arquivo `app/Models/Client.php` estava corrompido com:
- Linha 9: `protected  = [` (faltando `$fillable`)
- Linha 20: `return ->belongsTo(User::class)` (faltando `$this`)
- Linha 25: `return ->hasMany(Subscription::class)` (faltando `$this`)

Resultado: `ParseError: syntax error, unexpected token "=", expecting variable`. Qualquer rota que tocasse o model Client retornava 500 Server Error (products, orders, financial, stores).

**Correção:** Reescrito o arquivo com `$fillable` e `$this` corretos. Backup do arquivo quebrado em `/tmp/Client.php.broken-*`.

**Arquivos outros afetados:** Nenhum (grep em `app/Models/*.php` não achou mesmo pattern).

**Probable cause:** Subagent anterior (novohubai-qa da Sprint 1) pode ter editado Client.php mal intencionado — o resto dos arquivos do commit `a8ef446` ficou OK, só este foi corrompido.

---

## Resultados dos testes (após o fix)

| Endpoint | Método | Status | Observação |
|---|:---:|:---:|---|
| `/api/health` | GET | **200** | `{"status":"HubAI API is up and running"}` |
| `/api/login` | POST | **200** | Sanctum token retornado (Bearer) |
| `/api/v1/me` | GET | **200** | Retorna user logado + relação client |
| `/api/v1/suppliers` | GET | **200** | Lista DropRio + PlugLar (2 registros) |
| `/api/v1/products` | GET | **403** | Forbidden — admin sem perfil client (**correto**) |
| `/api/v1/orders` | GET | **403** | Forbidden — admin sem perfil client (**correto**) |
| `/api/v1/stores` | GET | **403** | Forbidden — admin sem perfil client (**correto**) |
| `/api/v1/financial/balance` | GET | **403** | Forbidden — admin sem perfil client (**correto**) |
| `/api/webhooks/mercadolivre` | POST | **401** | "Invalid signature" (HMAC validation ativa — **correto**) |
| `/api/webhooks/pagamentos/pagarme` | POST | **405** | Method Not Allowed — rota é `/api/v1/webhooks/pagamentos/{slug}`, não `/api/webhooks/...` |
| `/api/oauth/mercadolivre/1/redirect` | GET | **404** | account_id=1 não existe ainda |

## Rotas registradas (38 total)

Ver `php artisan route:list --path=api`. Principais grupos:
- **Públicas:** `/api/health`, `/api/login`, `/api/oauth/*`, `/api/webhooks/*`, `/api/documentation` (swagger)
- **Autenticadas (admin/client):** `/api/v1/me`, `/api/v1/suppliers`, `/api/v1/suppliers/{id}/catalog`
- **Autenticadas (client only):** `/api/v1/products/*`, `/api/v1/stores/*`, `/api/v1/orders/*`, `/api/v1/financial/*`
- **Webhooks:** `/api/webhooks/mercadolivre`, `/api/webhooks/orders/{platform}`, `/api/v1/webhooks/mercadolivre/questions`, `/api/v1/webhooks/pagamentos/{slug}`

## Para completar testes do lado "client"

Criar um User com role=`client` e um Client associado. Exemplo:

```sql
INSERT INTO users (name, email, password, role, created_at, updated_at)
VALUES ('Test Seller', 'seller-test@hubai.com.br',
  '$2y$12$...bcrypt_hash...', 'client', NOW(), NOW());
-- depois: INSERT INTO clients (user_id, company_name, ...)
```

Depois faz login com esse user e testa `/products`, `/orders`, etc.

## Próximos testes recomendados

1. Criar seller de teste, validar fluxo completo
2. Integração ML OAuth end-to-end (requer user manual pra login ML)
3. Pagar.me V2 webhook
4. Scribe/Swagger docs geration

## Observações

- Subagents OpenClaw (novohubai-qa, novohubai-backend, etc.) **não conseguem usar SSH** para testes de integração. Eles trabalham melhor em edição de código local, não em tests de API remota. Sugestão futura: dar um wrapper pro subagent usar o mesmo `sshpass` que main usa, ou criar conta `api.hubai.io` com credenciais próprias no OpenClaw.
- Pipeline `prod-sync` funcionando: fix aplicado no servidor + commitado + pushed.
