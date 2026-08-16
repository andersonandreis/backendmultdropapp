# Interface Headless / API Custom

Se no futuro o cliente (dono da HubAI) quiser construir um App Nativo iOS ou React em subdomínio, você poderá consumir a rota `api.php` que inseri na raiz para interagir com o Laravel Sanctum.

O Endereço Base sempre estará em: `https://seusite.com.br/api/v1/`

### O que você pode extrair do App via JSON para Custom Interfaces?

---

### `GET /api/v1/health`
**Descrição:** Checagem rápida de PING se o Backend Laravel 11.0 está subido no CyberPanel.
* Retorno `200 OK`: `{"status": "HubAI API is up and running"}`

---

### `GET /api/v1/products`
**Descrição:** Retorna todo o Catálogo Ativo (Visão Mestre) pronto pra listagem em Cardápio custom. Injetamos o relacionamento "variations" no grid por padrão com Paginação (Page 1).
**Payload JSON Retornado:**
```json
{
  "total": 12,
  "per_page": 15,
  "current_page": 1,
  "data": [
    {
       "id": 1,
       "slug": "tenis-nike-jordan-preto",
       "name": "Tênis Nike Jordan Preto",
       "base_price": "145.50",
       "variations": [
         {"id": 1, "custom_sku": "NK-JD-PRT-42", "size": "42"}
       ]
    }
  ]
}
```

---

### `GET /api/v1/inventory`
**Descrição:** Rota para leitura de estoque bruto físico nos galpões da HubAI. Retorna saldo real por item formatado.
**Payload JSON Retornado:**
```json
{
  "total": 8,
  "data": [
    {
       "id": 1,
       "product_id": 1,
       "quantity": 1850,
       "reserved": 12,
       "product": {
           "name": "Tênis Nike Jordan Preto"
       }
    }
  ]
}
```

---

### `GET /api/v1/orders`
**Descrição:** Uma simulação Endpoint em formato descrescente de dados de expedição logístico por Timestamp (`ordered_at`). Utilíssimo para gráficos em React Dashboard pro Admin ou pro Seller.
**Exige Modificador:** Proteção `Auth:Sanctum` no futuro caso seja atrelada ao log de quem requisitou a porta por token Bearer.

---

### PATCH /api/v1/supplier-admin/orders/{id}

Atualiza um pedido pelo painel supplier-admin, incluindo o **SKU dos itens** (MUL-217).

**Autenticação**: `Authorization: Bearer <token Sanctum>`. Roles aceitas: `super_admin`, `admin`, `supplier`. Para `super_admin`, enviar o header `X-Tenant-Slug: <slug>` (ex: `multdrop`) para resolver o supplier via tenant_supplier.

**Body** (todos os campos opcionais):

```json
{
  "status": "shipped",
  "items": [
    { "id": 117135, "sku": "D773-NS-UT-ABRIGAR-INX", "name": "...", "quantity": 1, "unit_price": 21.32 }
  ]
}
```

- `items[].id` — obrigatório para atualizar um item existente (order_items.id). O item precisa pertencer ao pedido.
- Campos editáveis do item: `sku`, `name`, `quantity`, `unit_price`.
- Editar SKU **não** recalcula custo (supplier_unit_cost intocado — regra MUL-198/216).
- `raw_payload` do pedido nunca é alterado por este endpoint.

**Resposta**: `200` com `{ "data": { ...pedido atualizado com items... } }`.

**Exemplo testado (11/07/2026)**:

```bash
curl -X PATCH https://api.hubai.io/api/v1/supplier-admin/orders/134525   -H 'Authorization: Bearer <token>'   -H 'X-Tenant-Slug: multdrop'   -H 'Content-Type: application/json'   -d '{"items":[{"id":117135,"sku":"D773-NS-UT-ABRIGAR-INX"}]}'
```

### Produtos — campo `service_sku` (código do serviço) — NOV-203

Campo opcional no cadastro de produto do fornecedor. É o SKU do SERVIÇO de
embalagem/sistema daquele produto, enviado ao Bling junto com a NF.

- `POST /api/v1/supplier/products` e `PUT /api/v1/supplier/products/{id}`
  aceitam `service_sku` (string, max 100, nullable).
- `GET /api/v1/supplier/products` retorna `service_sku` em cada item.
- Painel Filament: campo "Código do serviço" na seção de dados do produto.

**Regra de explosão no envio ao Bling** (NF-e via `IssueSellerInvoiceJob` e
pedido de venda via `BlingOrderSync::exportOrder`): cada linha de produto cujo
produto tenha `service_sku` gera uma linha adicional
`{codigo: service_sku, quantidade: MESMA do produto, valor: 0}`.
Ex.: pedido com 2× A + 1× B (ambos com serviço) → 4 linhas no Bling:
SKU-A ×2, SERV-A ×2, SKU-B ×1, SERV-B ×1. Produto sem `service_sku` não gera
linha extra. A precificação do serviço é responsabilidade do cadastro no Bling
do fornecedor.


## Fornecedores privados (is_private) — MUL-219

Suppliers com `is_private=1` + `owner_client_id` são visíveis/acessíveis SOMENTE
ao client dono (paridade com o painel Filament `SupplierCatalog`):

- `GET /api/v1/suppliers` — supplier privado não aparece na listagem para
  outros clients (nem em tenants com `default_supplier_visibility=all`).
- `GET /api/v1/suppliers/{id}/catalog` e `GET /api/v1/suppliers/{id}/catalog/categories`
  — retornam `403` para quem não é o dono.
- Em tenants `scoped`, o client dono vê seus suppliers privados mesmo sem
  vínculo em `tenant_supplier`.

Exemplo vigente: supplier 157 (Multdrop Filial / depósito legado 773) é
exclusivo do client 2 (Snapmix, snapmixbrasil@gmail.com), que também possui o
plano oculto "Snapmix Exclusivo" (`plans.is_active=0`, não listado em
`GET /api/v1/plans`).
