# API Documentation Generation — Scribe

**Data:** 2026-04-24  
**Servidor:** api.hubai.io (66.94.100.155)  
**Ferramenta:** knuckleswtf/scribe ^5.9  
**Executado por:** Agente novohubai-backend

---

## Status da Instalação

| Item | Status |
|---|---|
| Scribe instalado | ✅ Sim — v5.9 via composer (PHP 8.2) |
| Config publicada | ✅ `config/scribe.php` |
| Docs geradas | ✅ `public/docs/` |
| URL acessível | ✅ https://api.hubai.io/docs/ |

---

## Configurações Aplicadas

```php
'title'    => 'HubAI Platform API'
'base_url' => 'https://api.hubai.io'
'type'     => 'static'         // → public/docs/index.html
'auth' => [
    'enabled' => true,
    'default' => true,
    'in'      => BEARER,
    'name'    => 'Authorization',
]
```

---

## Endpoints Documentados — 38 total

| Grupo | Endpoints |
|---|---|
| Auth | POST /api/login, POST /api/logout |
| Me | GET /api/v1/me |
| Lojas (Stores) | GET/POST /api/v1/stores, GET/PUT /api/v1/stores/{id} |
| Fornecedores | GET /api/v1/suppliers, GET /api/v1/suppliers/{id}/catalog |
| Produtos | GET/POST /api/v1/products, GET/PUT/DELETE /api/v1/products/{id} |
| Produtos (imagens) | POST/DELETE /api/v1/products/{id}/images, PUT reorder |
| Produtos (vídeo) | POST/DELETE /api/v1/products/{id}/video |
| Variações | GET/POST /api/v1/products/{id}/variations, PUT/DELETE /{vid} |
| Pedidos | GET /api/v1/orders, GET /api/v1/orders/{id} |
| Financeiro | GET /api/v1/financial/balance, GET /api/v1/financial/transactions |
| Webhooks | POST /api/v1/webhooks/mercadolivre/questions, /pagamentos/{slug} |
| Webhooks diretos | POST /api/webhooks/mercadolivre, /orders/{platform} |
| OAuth | GET /api/oauth/{platform}/redirect, /callback |
| Simulador | POST /api/simulator/webhook-order |
| Swagger UI | GET /api/documentation, /api/oauth2-callback |
| Health | GET /api/health |

---

## Artefatos Gerados

| Arquivo | Tamanho | Uso |
|---|---|---|
| `public/docs/index.html` | 273 KB | Docs interativas (browser) |
| `public/docs/collection.json` | 67 KB | Postman Collection |
| `public/docs/openapi.yaml` | 31 KB | OpenAPI 3.0 spec |
| `public/docs/css/`, `js/`, `images/` | — | Assets da UI |

---

## URLs

| Recurso | URL |
|---|---|
| Documentação HTML | https://api.hubai.io/docs/ |
| Postman Collection | https://api.hubai.io/docs/collection.json |
| OpenAPI YAML | https://api.hubai.io/docs/openapi.yaml |

---

## Problemas Encontrados

### Problema #1 — composer usa PHP 8.1 por padrão
O comando `composer` global usa PHP 8.1 do sistema. Foi necessário invocar com:
```bash
/usr/local/lsws/lsphp82/bin/php /usr/bin/composer require --dev knuckleswtf/scribe
```

### Nota #1 — Mais endpoints do que esperado (38 vs ~18)
O ProductController no servidor tem endpoints extras de imagens, vídeo e variações (além dos básicos do CRUD). Todos foram documentados automaticamente pelo Scribe.

### Nota #2 — Swagger UI (/api/documentation) também documentado
O endpoint do L5-Swagger aparece na lista. Não causa problema — é apenas descritivo.

---

## Como Regenerar

```bash
cd /home/api.hubai.io/public_html
/usr/local/lsws/lsphp82/bin/php artisan scribe:generate
```

## Como Adicionar Anotações

Edite os controllers com docblocks Scribe:

```php
/**
 * @authenticated
 * @queryParam per_page int Itens por página. Example: 15
 * @response 200 {"data": [], "meta": {...}}
 */
public function index() { ... }
```

Ou use PHP attributes do L5-Swagger (já presentes nos controllers V1):
```php
#[OA\Get(path: '/api/v1/products', ...)]
```
