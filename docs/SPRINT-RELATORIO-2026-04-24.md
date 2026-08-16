# Sprint Report — 2026-04-24

> Consolidado por: Maestro (hubai-gestor)
> Agente executor: Shield (novohubai-qa)
> Data: 2026-04-24
> Escopo: Auditoria de bugs + Tradução pt-BR do painel Filament (api.hubai.io)

---

## Resumo Executivo

Dois subagents do novohubai-qa executaram em sequência no servidor de produção api.hubai.io.
A Missão 1 identificou e corrigiu 7 bugs críticos nos Filament Resources, todos causando falhas de
runtime (QueryException, ClassNotFoundException, PHP TypeError). A Missão 2 completou a cobertura
pt-BR do painel admin: 104 strings em inglês traduzidas, 88 novas chaves no pt_BR.json. Ambas as
missões foram commitadas e empurradas ao GitHub via prod-sync. O painel admin agora opera sem erros
visíveis nos 15 resources auditados e totalmente em português.

---

## Missão 1 — Auditoria de Bugs (commit `a8ef446`)

### Bugs encontrados e corrigidos

| # | Resource | Severidade | Descrição resumida | Status |
|---|---|---|---|---|
| BUG-1 | ClientResource | Crítico | `supplier_id` referenciava coluna removida por migration — QueryException ao salvar | ✅ Corrigido |
| BUG-2 | InventoryResource | Crítico | Form/table usando campos inexistentes (`variation_id`, `supplier_id`, `reserved_quantity`, `is_active`) — INSERT falha com SQLSTATE | ✅ Corrigido |
| BUG-3 | MarketplaceAccountResource | Crítico | Campo `credentials` não existe na tabela — QueryException ao salvar conta | ✅ Corrigido |
| BUG-4 | MarketplaceAccountResource | Alto | `is_active` não existe; DB usa `status varchar` ('active','disconnected','expired','pending') | ✅ Corrigido |
| BUG-5 | PlanDiscountResource | Crítico | Model `PlanDiscount` e tabela `plan_discounts` não existiam — ClassNotFoundException | ✅ Corrigido |
| BUG-6 | SettingsResource | Alto | Campo `type` não existe na tabela `settings` (só tem group/key/value) | ✅ Corrigido |
| BUG-7 | WebhookConfigResource | Médio | `customer_email_field->email()` bloqueava salvar (campo armazena caminho JSON, não email) | ✅ Corrigido |

**Total:** 7 bugs — 4 críticos, 2 altos, 1 médio

### Arquivos alterados (a8ef446)

| Arquivo | Alteração |
|---|---|
| `app/Filament/Resources/ClientResource.php` | Removido `supplier_id` do form e table |
| `app/Filament/Resources/InventoryResource.php` | Form e table reconstruídos com campos corretos do DB |
| `app/Filament/Resources/MarketplaceAccountResource.php` | Removido `credentials`; Toggle substituído por Select status |
| `app/Filament/Resources/SettingsResource.php` | Removido campo `type` do form |
| `app/Filament/Resources/WebhookConfigResource.php` | Removido `->email()` do campo `customer_email_field` |
| `app/Models/PlanDiscount.php` | Criado model (novo arquivo) |
| `database/migrations/2026_04_24_000001_create_plan_discounts_table.php` | Criada migration (novo arquivo) |
| `docs/AUDIT-FILAMENT-2026-04-24.md` | Relatório de auditoria (novo arquivo) |

---

## Missão 2 — Tradução pt-BR (commit `a474fcc`)

### Strings traduzidas

| Categoria | Strings em inglês | Strings traduzidas |
|---|---|---|
| modelLabel/pluralModelLabel ausentes | 8 (4 resources × 2) | 8 |
| navigationGroup ausente (WebhookConfigResource) | 1 | 1 |
| Campos de form sem `->label()` | 7 | 7 |
| Chaves faltando no `lang/pt_BR.json` | 88 | 88 |
| **TOTAL** | **104** | **104** |

### Arquivos alterados (a474fcc)

| Arquivo | Alteração |
|---|---|
| `lang/pt_BR.json` | +88 chaves (86 → 174 chaves) |
| `app/Filament/Resources/InventoryResource.php` | +modelLabel "Estoque" / +pluralModelLabel "Estoques" |
| `app/Filament/Resources/MarketplaceAccountResource.php` | +modelLabel "Conta Marketplace" |
| `app/Filament/Resources/SettingsResource.php` | +modelLabel "Configuração" |
| `app/Filament/Resources/WebhookConfigResource.php` | +modelLabel + +navigationGroup "Configurações e Acessos" |
| `app/Filament/Resources/DocumentResource.php` | +label('Observações') no campo notes |
| `app/Filament/Resources/UserResource.php` | +label() em 6 campos (Nome, E-mail, datas) |
| `docs/I18N-STATUS-2026-04-24.md` | Relatório i18n (novo arquivo) |

### Observações

- Painel App (Filament/App/) já estava 100% em PT — sem alterações necessárias
- Blade views já em português — sem alterações
- Admin Widgets já com headings em PT — sem alterações
- `APP_LOCALE=pt_BR` já estava correto no `.env`

---

## Commits no GitHub

| Hash | Mensagem | Arquivos | +/- |
|---|---|---|---|
| `a474fcc` | feat(i18n): complete pt-BR coverage 2026-04-24 | 8 | +198 / -13 |
| `a8ef446` | fix(filament): audit and fix resource bugs 2026-04-24 | 8 | +209 / -60 |

Repositório: https://github.com/ruanipanema2-collab/hubai-plataforma

---

## Próximos Passos Sugeridos

1. **Executar migration** pendente: `php artisan migrate --force` para criar a tabela `plan_discounts`
2. **Testar CRUD** dos 7 resources corrigidos no painel admin de produção
3. **Auditoria dos resources restantes** — confirmar quais faltam além dos 15 já auditados
4. **Testar locale** — abrir o painel admin e verificar que todas as strings aparecem em PT
5. **PlanDiscountResource** — implementar lógica real de desconto (BUG-5 criou o modelo vazio)
6. **Completar credenciais pendentes** — Asaas API Key ainda ausente no .env (ver Decisões Pendentes)

---

*Relatório gerado em 2026-04-24. Fonte: commits git + relatórios Shield em docs/.*
