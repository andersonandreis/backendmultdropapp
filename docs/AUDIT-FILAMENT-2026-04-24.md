# Auditoria Filament Resources

> Gerado por: Shield (novohubai-qa)
> Escopo: 15 resources excluindo os já auditados anteriormente.

---

## BUG-1 — ClientResource: campo `supplier_id` referencia coluna removida

- **Arquivo:** `app/Filament/Resources/ClientResource.php`
- **Severidade:** Critico
- **Descricao:** A migration `2026_03_08_195801_remove_supplier_id_from_clients_table.php` removeu a coluna `supplier_id` da tabela `clients`. O Client model nao tem relacionamento `supplier()` nem `supplier_id` em fillable. Mas o resource ainda tem: Form com `Select::make('supplier_id')->relationship('supplier', 'company_name')` e Table com `TextColumn::make('supplier.company_name')`. Causa QueryException ao salvar e undefined relationship na tabela.
- **Correcao:** Remover `supplier_id` do form e `supplier.company_name` da table.

---

## BUG-2 — InventoryResource: todos os campos do form nao existem no DB

- **Arquivo:** `app/Filament/Resources/InventoryResource.php`
- **Severidade:** Critico
- **Descricao:** Tabela `inventory` tem: `warehouse_id (NOT NULL)`, `product_id (NOT NULL)`, `producer_id (NOT NULL)`, `quantity`, `reserved`, `warehouse_price`. O resource usa campos completamente errados:
  - `variation_id` nao existe na tabela (model nao tem relacao `variation`)
  - `supplier_id` nao existe (deveria ser `warehouse_id`)
  - `reserved_quantity` nao existe (coluna e `reserved`)
  - `is_active` nao existe na tabela
  - `warehouse_id` e `producer_id` (NOT NULL, FK) ausentes do form — INSERT falha com SQLSTATE
  - Table: `variation.name`, `supplier.company_name`, `reserved_quantity` quebrados
  - `available_quantity` usa `record->reserved_quantity` mas coluna e `reserved` — PHP TypeError
- **Correcao:** Reconstruir form e table com os campos corretos da tabela e model.

---

## BUG-3 — MarketplaceAccountResource: campo `credentials` nao existe no DB

- **Arquivo:** `app/Filament/Resources/MarketplaceAccountResource.php`
- **Severidade:** Critico
- **Descricao:** `Textarea::make('credentials')` referencia coluna `credentials` que nao existe em `marketplace_accounts` (confirmado via DESCRIBE). O model tambem nao tem `credentials` em fillable. Salvar qualquer conta causara QueryException.
- **Correcao:** Remover o campo `credentials` do form.

---

## BUG-4 — MarketplaceAccountResource: `is_active` nao existe, DB usa `status` (string)

- **Arquivo:** `app/Filament/Resources/MarketplaceAccountResource.php`
- **Severidade:** Alto
- **Descricao:** `Toggle::make('is_active')` e `IconColumn::make('is_active')` referenciam coluna que nao existe. A tabela tem `status varchar NOT NULL default 'active'` com valores: active, disconnected, expired, pending. A coluna `is_active` e NULL para todos os registros ao carregar.
- **Correcao:** Substituir Toggle por Select com opcoes de status; ajustar column na table.

---

## BUG-5 — PlanDiscountResource: model e tabela nao existem

- **Arquivo:** `app/Filament/Resources/PlanDiscountResource.php`
- **Severidade:** Critico
- **Descricao:** `PlanDiscountResource` usa `App\Models\PlanDiscount::class`, mas nao existe `app/Models/PlanDiscount.php`, nao existe tabela `plan_discounts` no banco (confirmado via SHOW TABLES), e nao existe migration. Acesso a qualquer rota resulta em ClassNotFoundException ou SQLSTATE table not found.
- **Correcao:** Criar model `PlanDiscount` e migration `create_plan_discounts_table`.

---

## BUG-6 — SettingsResource: campo `type` nao existe na tabela `settings`

- **Arquivo:** `app/Filament/Resources/SettingsResource.php`
- **Severidade:** Alto
- **Descricao:** `Select::make('type')` referencia coluna `type` que nao existe na tabela `settings`. A tabela possui apenas `group`, `key`, `value` (confirmado via DESCRIBE). Salvar um Setting via form causa QueryException: Column not found.
- **Correcao:** Remover campo `type` do form.

---

## BUG-7 — WebhookConfigResource: `customer_email_field` com validacao de email incorreta

- **Arquivo:** `app/Filament/Resources/WebhookConfigResource.php`
- **Severidade:** Medio
- **Descricao:** `TextInput::make('customer_email_field')->email()` aplica validacao de formato de e-mail RFC. Mas este campo armazena um caminho JSON como `['Customer']['email']`, nao um email real. A validacao bloqueia salvar qualquer config de webhook.
- **Correcao:** Remover `->email()` deste campo.

---

## Resumo

| # | Resource | Severidade | Status |
|---|---|---|---|
| BUG-1 | ClientResource | Critico | Corrigido |
| BUG-2 | InventoryResource | Critico | Corrigido |
| BUG-3 | MarketplaceAccountResource | Critico | Corrigido |
| BUG-4 | MarketplaceAccountResource | Alto | Corrigido |
| BUG-5 | PlanDiscountResource | Critico | Corrigido |
| BUG-6 | SettingsResource | Alto | Corrigido |
| BUG-7 | WebhookConfigResource | Medio | Corrigido |

**Total:** 7 bugs (4 criticos, 2 altos, 1 medio)
