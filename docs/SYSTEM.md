# HubAI - Plataforma de Integracao E-commerce

## Contexto

O dono da plataforma HubAI conecta **Fornecedores** (que tem produtos/estoque) com **Clientes/Sellers** (que vendem nos marketplaces). Tudo numa unica instalacao multi-tenant, simples de instalar no CyberPanel como WordPress.

## Modelo de Negocio

```
SUPER ADMIN (dono da plataforma)
  └── gerencia Fornecedores, Planos, Configuracoes globais

FORNECEDOR PRODUTOR (sem galpao)
  └── cadastra Catalogo de Produtos (com imagens/videos)
  └── cria Remessas para enviar produtos a um Galpao
  └── sistema gera etiqueta por produto na remessa
  └── acompanha saldo financeiro (conciliacao)
  └── solicita saques do saldo acumulado

FORNECEDOR GALPAO (com galpao)
  └── recebe Remessas de VARIOS fornecedores produtores
  └── faz conferencia dos produtos recebidos (escaneia etiquetas)
  └── gerencia Estoque (compartilhado entre clientes)
  └── define preco proprio (markup sobre preco do produtor)
  └── ve pedidos dos seus clientes
  └── imprime etiquetas de envio
  └── blipa/escaneia produtos (separacao)
  └── gerencia fulfillment (preparacao → envio)
  └── aprova solicitacoes de saque dos produtores

CLIENTE (Seller)
  └── conecta a 1 Fornecedor
  └── cria sub-catalogo com sub-SKUs (pode customizar tudo)
  └── OU cadastra seus proprios produtos (catalogo independente)
  └── conecta 1 Marketplace (ML ou Shopee) por fornecedor
  └── conecta multiplos Bling
  └── define precos (manual ou margem de lucro)
  └── gerencia pedidos
```

## Stack Tecnico

| Componente | Escolha | Detalhes |
|------------|---------|----------|
| Servidor | CyberPanel + OpenLiteSpeed | Host principal |
| PHP | 8.3 | Com OPcache otimizado |
| Framework | Laravel 11 | Ecossistema maduro |
| Admin Panel | Filament 3 Multi-Panel | /admin (super+fornecedor), /app (cliente) |
| Banco | MySQL 8+ | Via CyberPanel |
| Queue | Database driver | Sem Redis, roda com cron |
| Cache App | File driver + LSCache | OPcache para bytecode, LSCache para paginas |
| Cache CDN | Cloudflare (default) + Bunny CDN (midia) | Configuravel |
| Storage | Local (default) + S3/R2/Bunny Storage (configuravel) | Flexivel |
| URLs | Dinamicas estilo WordPress (slugs configuráveis) | Tabela options + rewrites |
| Instalador | Web installer em /install | Como WordPress |
| Dominio | Unico (app.hubai.com.br) | Simples |
| Pagamentos | Asaas (planos + PIX pedidos) | Unificado |

## Estrutura de Pastas

```
hubai/
├── app/
│   ├── Enums/
│   │   ├── UserRole.php                    # super_admin, supplier, client
│   │   ├── SupplierType.php                # producer (sem galpao), warehouse (com galpao)
│   │   ├── ShipmentStatus.php              # draft/sent/in_transit/received/checking/checked
│   │   ├── WithdrawalStatus.php            # pending/approved/paid/rejected
│   │   ├── OrderStatus.php                 # pending_payment/paid/preparing/separated/shipped/delivered/cancelled/returned
│   │   ├── FulfillmentStatus.php           # awaiting_label/label_printed/separating/separated/awaiting_shipment/shipped
│   │   ├── ProductSource.php               # supplier, own
│   │   ├── ListingMode.php                 # manual, semi_auto, full_auto
│   │   ├── SyncStatus.php                  # pending, synced, error, paused
│   │   └── SubscriptionStatus.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── InstallController.php       # Instalador web
│   │   │   ├── SlugController.php          # Resolve URLs dinamicas por slug
│   │   │   └── Webhook/
│   │   │       ├── AsaasWebhookController.php
│   │   │       ├── MercadoLivreWebhookController.php
│   │   │       ├── ShopeeWebhookController.php
│   │   │       └── BlingWebhookController.php
│   │   └── Middleware/
│   │       ├── CheckInstalled.php          # Redireciona pra /install se nao instalado
│   │       ├── CheckSubscription.php       # Verifica se plano do cliente esta ativo
│   │       └── LscacheMiddleware.php       # Headers de cache LSCache (rotas publicas)
│   ├── Models/
│   │   ├── User.php                        # Todos os usuarios (role diferencia)
│   │   ├── Supplier.php                    # Perfil do fornecedor (1:1 com User)
│   │   ├── Client.php                      # Perfil do cliente (1:1 com User)
│   │   ├── Plan.php                        # Planos de assinatura
│   │   ├── Subscription.php                # Assinatura do cliente
│   │   ├── Product.php                     # Produto do fornecedor (catalogo master)
│   │   ├── ProductVariation.php            # Variacoes (cor/tamanho) do produto
│   │   ├── ProductMedia.php                # Imagens e videos do produto/variacao
│   │   ├── ClientProduct.php               # Sub-SKU do cliente (referencia Product)
│   │   ├── Category.php
│   │   ├── Order.php
│   │   ├── OrderItem.php
│   │   ├── Inventory.php                   # Estoque do fornecedor (compartilhado)
│   │   ├── MarketplaceAccount.php          # Conexao ML/Shopee do cliente
│   │   ├── MarketplaceCategory.php         # Cache de categorias dos marketplaces
│   │   ├── ErpAccount.php                  # Conexao Bling do cliente
│   │   ├── Shipment.php                    # Remessa (produtor → galpao)
│   │   ├── ShipmentItem.php                # Itens da remessa
│   │   ├── SupplierBalance.php             # Saldo do produtor no galpao
│   │   ├── SupplierTransaction.php         # Movimentacoes financeiras
│   │   ├── WithdrawalRequest.php           # Solicitacao de saque
│   │   ├── PlatformDiscount.php            # Desconto da plataforma
│   │   ├── PlatformDiscountTier.php        # Faixas do desconto gradual
│   │   ├── SupplierDiscount.php            # Desconto do fornecedor
│   │   ├── SupplierDiscountTier.php        # Faixas do desconto fornecedor
│   │   ├── Coupon.php                      # Cupons (plataforma ou fornecedor)
│   │   ├── MarketplaceFee.php              # Taxas por marketplace/categoria
│   │   ├── Payment.php                     # Pagamentos de pedidos (Asaas PIX)
│   │   ├── Slug.php                        # URLs dinamicas (slugs)
│   │   ├── SyncLog.php                     # Log de sincronizacao
│   │   └── Setting.php                     # Configuracoes gerais
│   ├── Traits/
│   │   └── HasSlug.php                     # Trait para gerar slugs automaticamente
│   ├── Observers/
│   │   ├── ProductObserver.php             # Gera slug + purge cache ao salvar
│   │   ├── InventoryObserver.php           # Purge cache ao mudar estoque
│   │   └── OrderObserver.php               # Credita saldo produtor ao vender
│   ├── Services/
│   │   ├── Marketplace/
│   │   │   ├── MarketplaceInterface.php
│   │   │   ├── MercadoLivreService.php
│   │   │   ├── ShopeeService.php
│   │   │   └── TokenRefreshService.php     # Refresh automatico de OAuth tokens
│   │   ├── Erp/
│   │   │   ├── ErpInterface.php
│   │   │   └── BlingService.php
│   │   ├── Payment/
│   │   │   ├── PaymentInterface.php
│   │   │   ├── AsaasService.php            # PIX para pedidos
│   │   │   └── ShipayService.php           # PIX alternativo
│   │   ├── Subscription/
│   │   │   └── SubscriptionService.php     # Gerencia assinaturas/planos
│   │   ├── Catalog/
│   │   │   ├── SupplierCatalogService.php  # Catalogo do fornecedor
│   │   │   └── ClientCatalogService.php    # Sub-catalogo do cliente
│   │   ├── Pricing/
│   │   │   └── PricingCalculator.php       # Calculo taxas + margem
│   │   ├── Discount/
│   │   │   ├── DiscountEngine.php          # Motor de descontos (aplica regras)
│   │   │   └── CouponService.php           # Validacao e uso de cupons
│   │   ├── Inventory/
│   │   │   └── InventoryService.php        # Estoque compartilhado
│   │   ├── Shipment/
│   │   │   └── ShipmentService.php         # Remessas entre fornecedores
│   │   ├── Financial/
│   │   │   ├── ReconciliationService.php   # Conciliacao financeira
│   │   │   └── WithdrawalService.php       # Solicitacoes de saque
│   │   ├── Cdn/
│   │   │   ├── CdnInterface.php            # Interface para CDN providers
│   │   │   ├── CloudflareService.php       # Purge cache Cloudflare
│   │   │   └── BunnyCdnService.php         # Upload/purge Bunny CDN
│   │   ├── Cache/
│   │   │   └── CacheService.php            # Gerencia LSCache + purge por tags
│   │   └── Install/
│   │       └── InstallerService.php        # Logica do instalador
│   ├── Jobs/
│   │   ├── SyncProductsToMarketplace.php   # Sobe produtos pro ML/Shopee
│   │   ├── SyncOrdersFromMarketplace.php   # Puxa pedidos do ML/Shopee
│   │   ├── SyncInventory.php               # Atualiza estoque nos marketplaces
│   │   ├── SyncErpOrders.php               # Envia pedidos pro Bling
│   │   ├── SyncMarketplaceCategories.php   # Atualiza cache de categorias (semanal)
│   │   ├── RefreshMarketplaceTokens.php    # Renova tokens OAuth (a cada 5h ML, 3h Shopee)
│   │   ├── AutoListSupplierProducts.php    # Modo full-auto: cadastra novos produtos
│   │   ├── FetchShippingLabel.php          # Busca etiqueta na API do marketplace
│   │   ├── UpdateShipmentStatus.php        # Atualiza status de envio
│   │   ├── CleanupSyncLogs.php             # Limpa logs antigos (> 30 dias)
│   │   └── ProcessAsaasWebhook.php
│   ├── Filament/
│   │   ├── Admin/                          # Painel /admin (Super Admin + Fornecedor)
│   │   │   ├── AdminPanelProvider.php
│   │   │   ├── Resources/
│   │   │   │   ├── SupplierResource.php        # Super: gerenciar fornecedores
│   │   │   │   ├── ClientResource.php          # Super: gerenciar clientes
│   │   │   │   ├── PlanResource.php            # Super: gerenciar planos
│   │   │   │   ├── ProductResource.php         # Fornecedor: catalogo + variacoes
│   │   │   │   ├── InventoryResource.php       # Fornecedor: estoque
│   │   │   │   ├── OrderResource.php           # Fornecedor: pedidos dos clientes
│   │   │   │   ├── ShipmentResource.php        # Remessas (produtor cria, galpao confere)
│   │   │   │   ├── WithdrawalRequestResource.php # Saques (produtor solicita, galpao aprova)
│   │   │   │   ├── PlatformDiscountResource.php  # Super: descontos da plataforma
│   │   │   │   ├── SupplierDiscountResource.php  # Galpao: descontos para clientes
│   │   │   │   ├── CouponResource.php           # Cupons (super + galpao)
│   │   │   │   ├── SyncLogResource.php          # Super: logs de sincronizacao
│   │   │   │   └── SettingResource.php         # Super: configuracoes (cache, CDN, URLs)
│   │   │   ├── Pages/
│   │   │   │   ├── Dashboard.php
│   │   │   │   ├── Fulfillment.php             # Tela de fulfillment (imprimir etiquetas, escanear)
│   │   │   │   ├── ScanProduct.php             # Tela de escaneamento/blip de produtos
│   │   │   │   ├── ShipmentCheck.php           # Conferencia de remessa (galpao escaneia)
│   │   │   │   └── FinancialReport.php         # Conciliacao financeira entre fornecedores
│   │   │   └── Widgets/
│   │   └── App/                            # Painel /app (Cliente/Seller)
│   │       ├── AppPanelProvider.php
│   │       ├── Resources/
│   │       │   ├── ClientProductResource.php   # Meu catalogo (sub-SKUs + variacoes)
│   │       │   ├── OrderResource.php           # Meus pedidos
│   │       │   ├── MarketplaceAccountResource.php  # Minhas conexoes ML/Shopee
│   │       │   └── ErpAccountResource.php      # Minhas conexoes Bling
│   │       ├── Pages/
│   │       │   ├── Dashboard.php
│   │       │   ├── SupplierCatalog.php         # Navegar catalogo do fornecedor
│   │       │   ├── PricingSettings.php         # Configurar margem/precos
│   │       │   └── MySubscription.php          # Ver plano atual
│   │       └── Widgets/
│   ├── Console/Commands/
│   │   └── ProcessScheduledSync.php
│   └── Providers/
├── config/
│   ├── hubai.php                           # Configs da plataforma
│   ├── lscache.php                         # Configs do LSCache
│   └── cdn.php                             # Configs do CDN (Cloudflare/Bunny)
├── database/migrations/                    # (ver secao Tabelas)
├── public/                                 # Document root OLS/CyberPanel
│   ├── index.php
│   ├── .htaccess                           # APENAS rewrite rules (OLS limitation)
│   └── storage -> ../storage/app/public    # Symlink para uploads
├── resources/views/
│   ├── install/                            # Telas do instalador
│   │   ├── welcome.blade.php
│   │   ├── requirements.blade.php
│   │   ├── database.blade.php
│   │   ├── admin.blade.php
│   │   └── complete.blade.php
│   └── emails/                             # Notificacoes
├── routes/
│   ├── web.php                             # Instalador + slugs + redirect
│   ├── api.php                             # Webhooks + OAuth callbacks
│   └── console.php                         # Scheduled tasks
├── storage/
│   └── app/public/
│       ├── products/                       # Imagens/videos dos produtos
│       ├── variations/                     # Imagens especificas de variacoes
│       └── brands/                         # Logos
├── .env.example
├── composer.json
└── artisan
```

## Tabelas do Banco de Dados

### Usuarios e Autenticacao
```sql
users
  id, name, email, password, role (super_admin/supplier/client),
  is_active, email_verified_at, timestamps

suppliers (perfil do fornecedor, 1:1 com user)
  id, user_id, type (producer/warehouse),
  company_name, document (CNPJ), phone,
  logo, description,
  address, city, state, zipcode,
  is_active, timestamps

clients (perfil do cliente/seller, 1:1 com user)
  id, user_id, supplier_id (FK suppliers - conecta ao GALPAO),
  company_name, document (CPF/CNPJ), phone,
  listing_mode (manual/semi_auto/full_auto),
  is_active, timestamps
```

### Planos e Assinaturas
```sql
plans
  id, name, slug, description,
  max_skus (int, ex: 100),
  max_marketplace_connections (int, ex: 1),
  max_erp_connections (int),
  price_monthly (decimal),
  price_yearly (decimal),
  trial_days (int, ex: 7),
  is_active, timestamps

subscriptions
  id, client_id, plan_id,
  status (active/trial/expired/cancelled),
  payment_method, external_payment_id (ID do Asaas),
  current_period_start, current_period_end,
  trial_ends_at, cancelled_at, timestamps
```

### Catalogo de Produtos
```sql
categories
  id, supplier_id, name, slug, parent_id, timestamps

products (catalogo master do fornecedor)
  id, supplier_id, sku, name, description,
  price (preco base do fornecedor), cost (custo de producao),
  gtin (varchar, EAN/UPC - obrigatorio ML e Shopee),
  brand, model,
  weight_kg (decimal, em KG - padrao ML/Shopee),
  height_cm, width_cm, length_cm (decimal, em CM),
  category_id,
  condition (new/used - ML exige),
  attributes (JSON - cor, tamanho, material, etc),
  warranty_type (varchar, nullable - garantia do fabricante),
  warranty_days (int, nullable),
  is_active, timestamps

product_variations (variacoes do produto - ML: variations, Shopee: models)
  id, product_id,
  sku (SKU unico da variacao),
  name (ex: "Azul / M"),
  price (preco da variacao, pode diferir do produto pai),
  cost (custo da variacao),
  gtin (EAN da variacao, pode ser diferente do pai),
  attributes (JSON - ex: {"Cor":"Azul","Tamanho":"M"}),
  position (ordem de exibicao),
  is_active, timestamps

-- ML: cada variation tem attribute_combinations + price + available_quantity + picture_ids
-- Shopee: cada model tem model_sku + price + stock
-- Nosso product_variations unifica ambos

product_media (imagens e videos)
  id, product_id,
  product_variation_id (nullable - se for imagem especifica da variacao),
  type (image/video),
  path (caminho local), url (URL publica / CDN),
  external_id (varchar, nullable - ID da imagem no ML/Shopee apos upload),
  position (ordem de exibicao),
  timestamps

-- ML: maximo 10 imagens + 1 video por anuncio
-- Shopee: maximo 9 imagens + 1 video por produto
-- Variacoes podem ter imagens proprias (ML: picture_ids por variation)

client_products (sub-catalogo do cliente)
  id, client_id,
  product_id (FK products, nullable - null = produto proprio do cliente),
  product_variation_id (FK product_variations, nullable),
  supplier_product_sku (SKU original do fornecedor, para referencia),
  custom_sku (sub-SKU do cliente),
  custom_title (varchar, titulo customizado para marketplace),
  custom_description (text, descricao customizada),
  custom_price (decimal, preco final no marketplace),
  custom_attributes (JSON, atributos customizados),
  pricing_mode (manual/margin),
  profit_margin (decimal, % margem de lucro),
  listing_type_id (varchar - ML: gold_special/gold_pro/gold/free),
  external_listing_id (varchar - ID do anuncio no ML ou product_id Shopee),
  external_variation_id (varchar - ID da variacao no ML/Shopee),
  external_category_id (varchar - ID da categoria no marketplace),
  marketplace_account_id (FK marketplace_accounts),
  sync_status (pending/synced/error/paused),
  last_sync_at, last_sync_error (text, nullable),
  is_active, timestamps

-- listing_type_id (ML): define taxa cobrada
--   gold_special = Classico (16%), gold_pro = Premium (17.5%), free = Gratis (sem taxa)
-- Shopee nao tem listing_type, taxa e por categoria/faixa de preco
```

### Remessas (Produtor → Galpao)
```sql
shipments (remessa de produtos)
  id, producer_id (FK suppliers, type=producer),
  warehouse_id (FK suppliers, type=warehouse),
  shipment_number, status (draft/sent/in_transit/received/checking/checked),
  notes, total_items, total_checked,
  sent_at, received_at, checked_at, timestamps

shipment_items (itens da remessa)
  id, shipment_id, product_id,
  quantity (enviado), quantity_received (conferido pelo galpao),
  unit_cost (preco do produtor),
  label_code (codigo unico gerado para etiqueta),
  checked_at, timestamps

-- Quando galpao recebe: escaneia label_code de cada item
-- Se quantity_received < quantity → divergencia registrada
```

### Estoque
```sql
inventory (estoque no galpao - compartilhado entre clientes)
  id, warehouse_id (FK suppliers, type=warehouse),
  product_id, producer_id (de qual produtor veio),
  quantity (total disponivel),
  reserved (reservado por pedidos),
  warehouse_price (preco que o galpao cobra dos clientes),
  timestamps

-- Estoque e do GALPAO, nao do produtor.
-- Galpao define seu proprio preco (warehouse_price).
-- Produtor recebe unit_cost (do shipment_item) quando produto e vendido.
-- Estoque e compartilhado entre clientes: quem vender primeiro, descontou.
-- O plano limita quantos SKUs o cliente pode listar, nao o estoque em si.
```

### Conciliacao Financeira
```sql
supplier_balances (saldo do produtor em cada galpao)
  id, producer_id, warehouse_id,
  balance (saldo atual disponivel para saque),
  total_earned (total acumulado historico),
  total_withdrawn (total ja sacado),
  timestamps

supplier_transactions (movimentacoes)
  id, producer_id, warehouse_id,
  type (sale/withdrawal/adjustment),
  amount, description,
  order_id (nullable, se for venda),
  withdrawal_request_id (nullable, se for saque),
  timestamps

withdrawal_requests (solicitacoes de saque)
  id, producer_id, warehouse_id,
  amount, status (pending/approved/paid/rejected),
  pix_key (chave PIX do produtor),
  rejection_reason,
  requested_at, approved_at, paid_at, timestamps
```

### Pedidos e Fulfillment
```sql
orders
  id, client_id, supplier_id, order_number,
  source (manual/mercadolivre/shopee),
  external_order_id (varchar - order_id ML numerico / order_sn Shopee string),
  external_pack_id (varchar, nullable - ML pack_id, agrupa pedidos no mesmo envio),
  external_shipping_id (varchar, nullable - ML shipment_id / Shopee shipping_document_id),

  -- Dados do comprador
  buyer_id (varchar, nullable - ID do comprador no marketplace),
  buyer_nickname (varchar, nullable - apelido ML / username Shopee),
  customer_name, customer_email, customer_phone,
  customer_document_type (varchar, nullable - CPF/CNPJ),
  customer_document_number (varchar, nullable - numero do doc),
  customer_address (JSON - endereco completo estruturado),

  -- Valores
  subtotal (decimal), shipping_cost (decimal),
  marketplace_fee (decimal), platform_fee (decimal, nullable - taxa HubAI),
  discount_amount (decimal, default 0),
  total (decimal),
  currency (varchar, default 'BRL'),

  -- Status
  status (pending_payment/paid/preparing/separated/shipped/delivered/cancelled/returned),
  fulfillment_status (awaiting_label/label_printed/separating/separated/awaiting_shipment/shipped),
  cancel_reason (varchar, nullable),

  -- Envio
  shipping_mode (varchar, nullable - ML: me2/custom, Shopee: pickup/dropoff),
  tracking_number (varchar, nullable),
  tracking_url (varchar, nullable),
  label_url (text, nullable - URL ou base64 da etiqueta de envio),
  carrier_name (varchar, nullable - nome da transportadora),

  -- Nota Fiscal (necessario para Shopee e Bling)
  invoice_number (varchar, nullable - numero da NF-e),
  invoice_series (varchar, nullable - serie da NF-e),
  invoice_access_key (varchar(44), nullable - chave de acesso NF-e),
  invoice_issued_at (datetime, nullable),

  -- Timestamps de fulfillment
  paid_at, label_printed_at,
  separated_at (quando fornecedor blipou/escaneou todos itens),
  shipped_at, delivered_at, cancelled_at,
  timestamps

-- ML: external_order_id = numerico (ex: 2000004958741631)
-- ML: pack_id agrupa multiplos pedidos na mesma etiqueta
-- Shopee: external_order_id = string (ex: "2503020ABCDEFG")
-- Shopee: exige NF-e (invoice) para envio em varias categorias
-- customer_document: ML envia em billing_info, Shopee envia buyer_cpf_id

order_items
  id, order_id, client_product_id, product_id,
  product_variation_id (nullable - se for variacao),
  sku (varchar), name (varchar),
  quantity (int), unit_price (decimal), total (decimal),
  external_item_id (varchar, nullable - ID do item no ML),
  external_variation_id (varchar, nullable - ID variacao no marketplace),
  sale_fee (decimal, nullable - taxa cobrada por item),
  listing_type_id (varchar, nullable - tipo de anuncio ML),
  scanned_at (datetime, nullable - quando blipado na separacao),
  timestamps
```

### Integracoes
```sql
marketplace_accounts
  id, client_id, platform (mercadolivre/shopee),

  -- OAuth tokens separados (nao mais JSON encriptado)
  app_id (varchar - ML: app_id / Shopee: partner_id),
  access_token (text, encrypted),
  refresh_token (text, encrypted),
  token_expires_at (datetime - ML: 6h, Shopee: 4h),
  refresh_token_expires_at (datetime, nullable - ML: 6 meses, Shopee: 30 dias),

  -- IDs do seller
  seller_id (varchar - ML: user_id numerico / Shopee: shop_id numerico),
  seller_nickname (varchar, nullable),
  shop_id (varchar, nullable - Shopee: shop_id separado do seller_id),

  -- Status e sync
  status (active/disconnected/expired/pending),
  last_sync_at, last_token_refresh_at,
  sync_errors_count (int, default 0),
  timestamps

-- ML OAuth: access_token expira em 6h, refresh_token em 6 meses
-- Shopee OAuth: access_token expira em 4h, refresh_token em 30 dias
-- Token refresh automatico via job agendado (a cada 5h ML, 3h Shopee)
-- Se refresh_token expirar: status = 'expired', usuario precisa reconectar

erp_accounts
  id, client_id, platform (bling),
  api_key (text, encrypted - Bling API key),
  api_version (varchar, default 'v3'),
  status (active/disconnected/error),
  last_sync_at, timestamps

marketplace_fees (tabela de taxas por marketplace/categoria)
  id, platform (mercadolivre/shopee),
  category_id (varchar, nullable - ID da categoria no marketplace),
  category_name (varchar),
  listing_type_id (varchar, nullable - ML: gold_special/gold_pro/gold/free),
  fee_percentage (decimal - taxa percentual),
  fixed_fee (decimal, default 0 - taxa fixa por venda),
  shipping_fee_type (varchar, nullable - tipo de frete),
  min_price (decimal, nullable - faixa de preco minima, Shopee usa faixas),
  max_price (decimal, nullable - faixa de preco maxima),
  is_active, timestamps

-- ML: taxa varia por listing_type (Classico 16%, Premium 17.5%, Gratis 0%)
-- ML: frete gratis acima de R$79 em gold_special/gold_pro
-- Shopee: taxa varia por categoria + faixa de preco (ex: 14-20%)
-- Shopee: comissao + taxa de servico + taxa de pagamento (separadas)

marketplace_categories (cache de categorias dos marketplaces)
  id, platform (mercadolivre/shopee),
  external_id (varchar - ID da categoria na API),
  name, full_path (varchar - caminho completo),
  parent_external_id (varchar, nullable),
  attributes_schema (JSON, nullable - atributos obrigatorios da categoria),
  last_synced_at, timestamps

-- ML: /sites/MLB/categories → arvore de categorias com atributos obrigatorios
-- Shopee: /product/get_category → arvore similar
-- Cache local para nao bater na API toda hora
-- Sync semanal via job agendado
```

### Pagamentos
```sql
payments
  id, order_id, client_id,
  gateway (asaas/shipay),
  method (pix),
  amount, status (pending/paid/failed/refunded),
  external_id (ID no Asaas/Shipay),
  gateway_response (JSON),
  paid_at, timestamps
```

### Descontos

```sql
-- DESCONTOS DE PLATAFORMA (Super Admin configura)
-- Incentivos para novas contas com desconto gradual por pedido

platform_discounts (regras de desconto da plataforma)
  id, name, description,
  type (graduated_order/first_purchase/coupon),
  is_active, timestamps

platform_discount_tiers (faixas do desconto gradual)
  id, platform_discount_id,
  from_order (numero do pedido, ex: 1),
  to_order (ex: 1, ou null para "em diante"),
  discount_type (percentage/fixed),
  discount_value (ex: 90 para 90%, ou 50.00 para R$50),
  timestamps

-- Exemplo: Desconto gradual para novos sellers
-- Tier 1: pedido 1 → 90% desconto na taxa da plataforma
-- Tier 2: pedido 2 → 70% desconto
-- Tier 3: pedido 3-5 → 50% desconto
-- Tier 4: pedido 6-10 → 30% desconto
-- Tier 5: pedido 11+ → 0% (preco normal)

-- DESCONTOS DO FORNECEDOR (Galpao configura para seus clientes)

supplier_discounts (regras de desconto do fornecedor)
  id, supplier_id (FK suppliers, type=warehouse),
  name, description,
  target (all_clients/specific_client/client_group),
  target_id (nullable, client_id se specific),
  trigger_type (volume_qty/volume_value/category/sku/first_buy/coupon),
  is_stackable (pode combinar com outros descontos?),
  starts_at, ends_at (nullable = sem prazo),
  is_active, timestamps

supplier_discount_tiers (faixas do desconto do fornecedor)
  id, supplier_discount_id,
  min_quantity (ex: 10 unidades),
  max_quantity (nullable),
  min_value (ex: R$500 em compras),
  max_value (nullable),
  discount_type (percentage/fixed),
  discount_value,
  timestamps

-- Exemplo 1: Desconto por volume de quantidade
-- Tier 1: 10-49 unidades → 5% desconto
-- Tier 2: 50-99 unidades → 10% desconto
-- Tier 3: 100+ unidades → 15% desconto

-- Exemplo 2: Desconto por volume de valor (R$)
-- Tier 1: R$500-R$999 → R$25 desconto fixo
-- Tier 2: R$1000-R$4999 → 5% desconto
-- Tier 3: R$5000+ → 8% desconto

-- Exemplo 3: Primeira compra do cliente
-- Tier 1: pedido 1 → 10% desconto

-- CUPONS (ambos podem criar)

coupons
  id, code (ex: BEMVINDO10),
  owner_type (platform/supplier),
  owner_id (nullable, supplier_id se fornecedor),
  discount_type (percentage/fixed),
  discount_value,
  min_order_value (nullable),
  max_uses (nullable = ilimitado),
  max_uses_per_client (nullable),
  uses_count,
  starts_at, ends_at,
  is_active, timestamps
```

### Config e Logs
```sql
settings
  id, group (brand/platform/fees/cache/cdn/urls), key, value, timestamps

-- Exemplos de settings:
-- group=brand: site_name, logo_url, primary_color, favicon
-- group=platform: platform_fee_percentage, currency, timezone
-- group=cache: lscache_enabled, cdn_enabled, cdn_provider
-- group=cdn: cloudflare_zone_id, bunny_cdn_url, bunny_storage_zone
-- group=urls: product_slug_pattern, category_slug_pattern

slugs (URLs dinamicas estilo WordPress)
  id, sluggable_type (product/category/supplier/page),
  sluggable_id (int - ID do registro),
  slug (varchar, unique - ex: "camiseta-azul-m"),
  is_canonical (boolean, default true - slug principal),
  timestamps

-- Permite multiplos slugs por recurso (redirecionamento de antigos)
-- is_canonical = true: slug ativo, false: redireciona 301 para o canonical
-- Gera automaticamente via Str::slug() no observer do model

sync_logs (log de sincronizacao com APIs externas)
  id, syncable_type (product/order/inventory),
  syncable_id (int),
  platform (mercadolivre/shopee/bling),
  marketplace_account_id (nullable),
  action (create/update/delete/sync/webhook),
  direction (outbound/inbound - enviou ou recebeu),
  status (success/error/pending),
  request_payload (JSON, nullable - o que enviou),
  response_payload (JSON, nullable - o que recebeu),
  error_message (text, nullable),
  timestamps

-- Essencial para debug de integracao
-- Registra toda comunicacao com APIs externas
-- Pode ser limpo periodicamente (manter ultimos 30 dias)

jobs (tabela padrao Laravel - queue database driver)
failed_jobs (tabela padrao Laravel - jobs que falharam)
```

## Fluxos Principais

### 1. Cadastro de Produto pelo Cliente

**Manual:**
1. Cliente acessa /app → "Catalogo do Fornecedor"
2. Ve todos produtos do fornecedor com imagens/precos
3. Clica "Adicionar ao meu catalogo"
4. Sistema cria ClientProduct com dados do Product como template
5. Cliente pode editar: titulo, descricao, preco, atributos
6. Define preco: manual (digita) ou margem (% sobre custo + taxas marketplace)
7. Salva → produto aparece em "Meu Catalogo"
8. Cliente publica no marketplace (se conectado)

**Semi-automatico:**
1. Cliente seleciona varios produtos do fornecedor
2. Clica "Adicionar selecionados"
3. Sistema cria ClientProducts com dados padrao
4. Sistema sobe automaticamente nos marketplaces conectados

**Full-auto:**
1. Cliente ativa modo "100% automatico" nas configuracoes
2. Job agendado verifica novos produtos do fornecedor
3. Cria ClientProduct automaticamente para cada novo
4. Sobe no marketplace com preco = margem predefinida

### 2. Calculo de Preco

```
Preco Final = Custo do Produto + Margem de Lucro + Taxa Marketplace + Frete (opcional)

Exemplo:
- Custo: R$ 50,00 (preco do fornecedor)
- Margem: 30% = R$ 15,00
- Taxa ML: 16% sobre preco final
- Preco Final = (50 + 15) / (1 - 0.16) = R$ 77,38

O cliente ve isso numa calculadora visual no painel.
Pode aceitar o preco calculado ou definir manualmente.
```

### 3. Fluxo de Remessa (Produtor → Galpao)

```
CRIACAO (Produtor):
1. Produtor acessa /admin → "Nova Remessa"
2. Seleciona galpao de destino
3. Adiciona produtos: SKU, quantidade, preco unitario (custo)
4. Sistema gera codigo unico (label_code) para cada item
5. Produtor confirma → status = "sent"
6. Sistema gera PDF com etiquetas (1 por produto, com QR code/barcode)
7. Produtor imprime etiquetas e cola nos produtos
8. Envia fisicamente para o galpao

RECEBIMENTO (Galpao):
9. Galpao ve remessa com status "sent" no painel
10. Clica "Iniciar Conferencia" → status = "checking"
11. Escaneia cada etiqueta (label_code) dos produtos recebidos
12. Sistema incrementa quantity_received no shipment_item
13. Se divergencia (recebeu menos que enviado): registrada automaticamente
14. Quando conferencia completa → status = "checked"
15. Estoque adicionado automaticamente (inventory.quantity += quantity_received)
16. Galpao define warehouse_price para cada produto

CONCILIACAO:
17. Quando um cliente vende um produto desse produtor:
    → supplier_transactions: type=sale, amount=unit_cost (preco do produtor)
    → supplier_balances.balance += unit_cost
18. Produtor ve saldo acumulado no painel
19. Produtor solicita saque → withdrawal_request criado
20. Galpao aprova → status=approved → paga via PIX/transferencia
21. Galpao marca como pago → status=paid
    → supplier_balances.balance -= amount
    → supplier_balances.total_withdrawn += amount
```

### 4. Sistema de Descontos

```
DESCONTOS DA PLATAFORMA (Super Admin):
- Configura desconto gradual por numero de pedido do seller
- Exemplo pratico:
  Seller novo → 1o pedido: taxa da plataforma com 90% desconto
                2o pedido: 70% desconto
                3o-5o pedido: 50% desconto
                6o-10o pedido: 30% desconto
                11o+ pedido: taxa normal (0% desconto)
- Aplica automaticamente no calculo da taxa da plataforma
- Pode criar cupons de plataforma (BEMVINDO10, BLACKFRIDAY)

DESCONTOS DO FORNECEDOR/GALPAO:
- Galpao cria regras de desconto para seus clientes
- Tipos de gatilho:
  a) Volume por quantidade: +10 unidades = 5%, +50 = 10%, +100 = 15%
  b) Volume por valor: +R$500 = R$25 off, +R$1000 = 5%, +R$5000 = 8%
  c) Primeira compra: 10% no primeiro pedido do cliente
  d) Por categoria: 20% em "Camisetas" ate dia X
  e) Por SKU especifico: R$5 off no SKU-001
  f) Cupom do fornecedor: codigo personalizado

MOTOR DE DESCONTOS (DiscountEngine):
1. Recebe: client_id, items[], order_total
2. Busca descontos aplicaveis (ativos, dentro do prazo, target correto)
3. Ordena por prioridade (nao-stackable primeiro)
4. Aplica em cascata (se stackable) ou melhor desconto (se nao-stackable)
5. Retorna: discount_amount, regras aplicadas, detalhamento

CUPONS:
- Codigo unico (ex: BEMVINDO10)
- Pode ser da plataforma OU do fornecedor
- Validacoes: prazo, usos max, usos por cliente, valor minimo do pedido
- Desconto fixo (R$) ou percentual (%)
```

### 5. Fluxo de Pedido + Fulfillment

```
RECEBIMENTO:
1. Comprador faz pedido no ML/Shopee
2. Webhook chega → cria Order com status "pending_payment"
3. Estoque reservado (inventory.reserved += qty)

PAGAMENTO:
4. Comprador paga → webhook atualiza status para "paid"
5. Estoque confirmado (inventory.quantity -= qty, reserved -= qty)
6. Sistema busca etiqueta de envio na API do marketplace
7. fulfillment_status = "awaiting_label" → "label_printed" (quando disponivel)

PREPARACAO (Fornecedor):
8. Fornecedor ve pedidos "paid" no painel /admin
9. Fornecedor clica "Imprimir Etiqueta" → sistema gera PDF com etiqueta
   (pode imprimir em lote: seleciona varios pedidos)
10. status = "preparing", fulfillment_status = "label_printed"

SEPARACAO (Fornecedor):
11. Fornecedor escaneia/blipa o produto (codigo de barras ou SKU manual)
12. Sistema valida se o SKU pertence ao pedido
13. Cada item escaneado: order_items.scanned_at = now()
14. Quando TODOS os itens do pedido estao escaneados:
    → status = "separated", fulfillment_status = "separated"
    → orders.separated_at = now()

ENVIO:
15. Fornecedor entrega pacote ao transportador
16. Sistema aguarda webhook do marketplace confirmando "shipped"
17. Webhook chega → status = "shipped", shipped_at = now()
18. Se Bling conectado → pedido sincronizado com NF

ENTREGA:
19. Webhook do marketplace → status = "delivered", delivered_at = now()
```

### Painel do Fornecedor - Fulfillment

```
Tela de Fulfillment (Filament):
┌─────────────────────────────────────────────────┐
│ Pedidos Pendentes (paid)          [Imprimir Lote]│
│ ┌───┬──────────┬─────────┬────────┬────────────┐│
│ │ ☑ │ #12345   │ Joao    │ 3 itens│ ML         ││
│ │ ☑ │ #12346   │ Maria   │ 1 item │ Shopee     ││
│ │ ☐ │ #12347   │ Pedro   │ 2 itens│ ML         ││
│ └───┴──────────┴─────────┴────────┴────────────┘│
│                                                  │
│ Separacao (escanear produto)                     │
│ ┌──────────────────────────────────────────────┐ │
│ │ SKU: [________________] [Escanear]           │ │
│ │                                              │ │
│ │ Pedido #12345 - Itens:                       │ │
│ │ [x] SKU-001 - Camiseta P    (escaneado)      │ │
│ │ [x] SKU-002 - Calca M       (escaneado)      │ │
│ │ [ ] SKU-003 - Bone          (pendente)       │ │
│ └──────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────┘
```

### 6. Webhook de Assinatura (Asaas)

```
POST /api/webhooks/asaas
{
  "event": "PAYMENT_CONFIRMED",
  "payment": {
    "customer": "cus_xxx",
    "value": 99.90,
    "billingType": "PIX",
    "subscription": "sub_xxx"
  }
}

→ Sistema identifica client pelo external_payment_id
→ Atualiza subscription.status = active
→ Atualiza current_period_end
```

## Cache e Performance (OpenLiteSpeed)

### LSCache (Full-Page Cache)
```
Pacote: litespeed/lscache-laravel
Funcao: Cache de pagina inteira no nivel do servidor (antes do PHP)

Configuracao (.env):
  LSCACHE_ENABLED=true
  LSCACHE_PUBLIC_TTL=600          # 10 min para paginas publicas
  LSCACHE_PRIVATE_TTL=120         # 2 min para paginas autenticadas
  LSCACHE_ESI_ENABLED=false       # OLS nao suporta ESI

Uso no Laravel:
  - Middleware LscacheMiddleware em rotas publicas
  - Tag-based purge: ao atualizar produto, purga tag "product:{id}"
  - Rotas autenticadas (/admin, /app): cache privado ou sem cache
  - Webhooks e API: sem cache (excluir do middleware)

Purge automatico:
  - Product::saved → purge tag "product:{id}" + "catalog"
  - Order::saved → purge tag "order:{id}"
  - Inventory::saved → purge tag "inventory:{product_id}"
  - Settings::saved → purge all
```

### OPcache (PHP 8.3)
```
Configuracao no php.ini (CyberPanel → PHP → Edit PHP ini):

  opcache.enable=1
  opcache.memory_consumption=256          # 256MB para bytecode cache
  opcache.interned_strings_buffer=32      # 32MB para strings
  opcache.max_accelerated_files=20000     # Maximo de arquivos cacheados
  opcache.validate_timestamps=0           # DESLIGAR em producao (reiniciar OLS apos deploy)
  opcache.revalidate_freq=0
  opcache.save_comments=1                 # Necessario para Filament/annotations
  opcache.jit=1255                        # JIT tracing mode (PHP 8.3)
  opcache.jit_buffer_size=128M

-- Em producao: validate_timestamps=0 = melhor performance
-- Apos deploy: reiniciar OLS para limpar OPcache
-- JIT 1255: tracing mode, melhor para apps web
```

### Cache da Aplicacao (Laravel)
```
Driver: file (sem Redis)

Estrategia de cache por camada:
  1. LSCache (OLS) → full-page para visitantes (10 min)
  2. OPcache (PHP) → bytecode compilado (permanente ate restart)
  3. File Cache (Laravel) → queries e dados computados
  4. CDN (Cloudflare) → assets estaticos (CSS/JS/imagens)

Cache manual no codigo:
  - Categorias do marketplace: cache 24h (sync diario)
  - Taxas do marketplace: cache 1h
  - Calculos de preco: cache 5 min (invalida ao mudar produto/taxa)
  - Dashboard widgets: cache 15 min

Config (config/cache.php):
  CACHE_DRIVER=file
  CACHE_PREFIX=hubai_
```

## URLs Dinamicas (Estilo WordPress)

```
Sistema de slugs configuravel para URLs amigaveis.
Tabela 'slugs' armazena todos os slugs com redirect automatico.

Exemplos de URLs:
  /produto/camiseta-azul-100-algodao          → ProductController@show
  /categoria/roupas-masculinas                → CategoryController@show
  /fornecedor/acme-textil                     → SupplierController@show
  /p/camiseta-azul-100-algodao                → shortcut (configuravel)

Padroes configuraveis via settings:
  urls.product_prefix = "produto"              # /produto/{slug}
  urls.category_prefix = "categoria"           # /categoria/{slug}
  urls.supplier_prefix = "fornecedor"          # /fornecedor/{slug}

Implementacao:
  1. Trait HasSlug nos Models (Product, Category, Supplier)
  2. Observer gera slug automaticamente via Str::slug(name)
  3. Se slug duplicado: adiciona sufixo numerico (camiseta-azul-2)
  4. Slug antigo vira redirect 301 (is_canonical = false)
  5. Middleware SlugResolver intercepta rotas com {slug}
  6. Route::get('/{prefix}/{slug}', [SlugController::class, 'resolve'])

Vantagens:
  - SEO-friendly (Google indexa melhor)
  - Historico de URLs (antigos redirecionam)
  - Prefixos configuraveis pelo admin
  - Sem quebrar links ao renomear produto
```

## Integracao CDN

### Cloudflare (Cache Geral)
```
Funcao: Cache de assets estaticos + protecao DDoS + SSL

Configuracao:
  - DNS apontado para Cloudflare (proxy ativado)
  - Page Rules:
    /admin/* → Cache Level: Bypass (painel admin sem cache)
    /app/* → Cache Level: Bypass (painel cliente sem cache)
    /api/* → Cache Level: Bypass (webhooks sem cache)
    /storage/* → Cache Level: Cache Everything, Edge TTL: 1 month
    /*.css, /*.js → Edge TTL: 1 year (versionado via mix/vite)

  - Cache-Control headers (via OpenLiteSpeed):
    Assets estaticos: public, max-age=31536000, immutable
    Imagens de produto: public, max-age=2592000 (30 dias)
    HTML: gerenciado pelo LSCache

Purge via API (ao fazer deploy ou atualizar midia):
  - POST https://api.cloudflare.com/client/v4/zones/{zone_id}/purge_cache
  - Purge por URL, tag, ou tudo
  - Configurado via settings: cloudflare_api_token, cloudflare_zone_id

.env:
  CLOUDFLARE_ENABLED=false
  CLOUDFLARE_ZONE_ID=
  CLOUDFLARE_API_TOKEN=
```

### Bunny CDN (Midia / Storage)
```
Funcao: CDN dedicado para imagens e videos de produtos
Alternativa ao S3/R2 com pull zone nativo

Opcao A - Pull Zone (simples):
  - Bunny puxa de /storage/products/ via origin URL
  - URL: https://hubai.b-cdn.net/products/img-001.jpg
  - Configurar: ASSET_URL=https://hubai.b-cdn.net no .env
  - Laravel Storage::url() retorna URL do CDN automaticamente

Opcao B - Bunny Storage + Pull Zone (completo):
  - Upload direto para Bunny Storage via API
  - Pull Zone serve os arquivos
  - Melhor performance (sem origin pull)
  - PUT https://{region}.storage.bunnycdn.com/{zone}/{path}

.env:
  BUNNY_CDN_ENABLED=false
  BUNNY_CDN_URL=https://hubai.b-cdn.net
  BUNNY_STORAGE_API_KEY=
  BUNNY_STORAGE_ZONE=hubai-media
  BUNNY_STORAGE_REGION=br

Implementacao:
  - Filesystem driver custom (BunnyCdnAdapter)
  - OU pacote: platformcommunity/flysystem-bunnycdn
  - config/filesystems.php: disco 'bunny' com driver custom
  - ProductMedia: se CDN ativo, upload para Bunny ao inves de local
  - URL das imagens: CDN URL + path relativo

Fallback:
  - Se CDN desligado: storage local (public/storage/products/)
  - Configuravel pelo admin em Settings → CDN
  - Troca transparente sem alterar dados no banco
```

### Configuracao no .env
```env
# CDN e Storage
ASSET_URL=                          # URL base para assets (vazio = local)
MEDIA_DISK=local                    # local, s3, r2, bunny

# Cloudflare (opcional)
CLOUDFLARE_ENABLED=false
CLOUDFLARE_ZONE_ID=
CLOUDFLARE_API_TOKEN=

# Bunny CDN (opcional)
BUNNY_CDN_ENABLED=false
BUNNY_CDN_URL=
BUNNY_STORAGE_API_KEY=
BUNNY_STORAGE_ZONE=
BUNNY_STORAGE_REGION=br

# S3/R2 (alternativa - padrao Laravel)
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=
AWS_BUCKET=
AWS_URL=
AWS_ENDPOINT=                       # Para R2: https://xxx.r2.cloudflarestorage.com
```

## Instalador Web

1. **GET /install** → Boas-vindas + verificacao se ja instalado
2. **GET /install/requirements** → PHP >= 8.3, extensoes (mbstring, openssl, pdo_mysql, gd/imagick, curl, zip), pastas gravaveis (storage/, bootstrap/cache/)
3. **POST /install/database** → Testa conexao MySQL 8+, grava .env, roda migrations + seeders
4. **POST /install/admin** → Cria super_admin + config inicial (nome, logo, timezone)
5. **POST /install/settings** → CDN (opcional), storage (local/S3/Bunny), cache (LSCache on/off)
6. **GET /install/complete** → Sucesso + link para /admin + checklist pos-instalacao
7. Middleware bloqueia /install depois de instalado (flag em .env: APP_INSTALLED=true)

## Deploy CyberPanel + OpenLiteSpeed

### Instalacao
```
1. Criar website no CyberPanel (Create Website)
2. Selecionar PHP 8.3 como versao do PHP
3. Upload arquivos via SSH: git clone ou scp para /home/{user}/public_html/
4. Mover conteudo do projeto para /home/{user}/public_html/
5. CyberPanel → Website → {site} → Document Root → apontar para public/
   (ou criar symlink: ln -s /home/{user}/public_html/hubai/public /home/{user}/public_html)
6. SSH:
   cd /home/{user}/public_html/hubai
   composer install --no-dev --optimize-autoloader
   php artisan key:generate
7. Acessar URL → /install aparece automaticamente
8. Preencher dados do MySQL (criado via CyberPanel → Databases)
9. Criar super admin
10. Configurar cron:
    crontab -e
    * * * * * cd /home/{user}/public_html/hubai && php artisan schedule:run >> /dev/null 2>&1
11. Pronto!
```

### OpenLiteSpeed - Configuracoes Importantes
```
ATENCAO: OLS .htaccess so suporta regras de REWRITE.
Diretivas como Header, SetEnv, Options NAO funcionam no .htaccess.
Essas configuracoes devem ir no WebAdmin Console do OLS.

.htaccess (public/.htaccess) - APENAS rewrites:
  <IfModule LiteSpeed>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]

    # LSCache headers
    CacheLookup on
  </IfModule>

Headers (configurar via CyberPanel → Vhost Conf):
  # Ir em: CyberPanel → Website → {site} → vHost Conf
  # Adicionar no contexto do vHost:

  context / {
    extraHeaders <<<END_extraHeaders
      Header set X-Content-Type-Options "nosniff"
      Header set X-Frame-Options "SAMEORIGIN"
      Header set X-XSS-Protection "1; mode=block"
      Header set Referrer-Policy "strict-origin-when-cross-origin"
    END_extraHeaders
  }

  # Cache headers para assets estaticos:
  context /storage {
    extraHeaders <<<END_extraHeaders
      Header set Cache-Control "public, max-age=2592000"
      Header set Access-Control-Allow-Origin "*"
    END_extraHeaders
  }

PHP OPcache (CyberPanel → PHP → Edit PHP.ini):
  opcache.enable=1
  opcache.memory_consumption=256
  opcache.interned_strings_buffer=32
  opcache.max_accelerated_files=20000
  opcache.validate_timestamps=0
  opcache.save_comments=1
  opcache.jit=1255
  opcache.jit_buffer_size=128M

IMPORTANTE: Apos qualquer alteracao em .htaccess ou vHost:
  → CyberPanel → Restart LiteSpeed (graceful restart)
  → Ou via SSH: killall -USR1 lsphp
```

### Checklist de Deploy
```
[ ] Website criado no CyberPanel com PHP 8.3
[ ] Banco MySQL criado
[ ] Arquivos uploadados e composer install executado
[ ] Document root apontando para public/
[ ] .htaccess com rewrite rules (sem Header directives)
[ ] vHost conf com headers de seguranca
[ ] php.ini com OPcache configurado
[ ] Cron configurado para schedule:run
[ ] /install acessado e configuracao concluida
[ ] Storage symlink: php artisan storage:link
[ ] Permissoes: storage/ e bootstrap/cache/ com 775
[ ] SSL ativado (CyberPanel → SSL → Issue SSL)
[ ] Cloudflare DNS configurado (se usando)
[ ] Bunny CDN pull zone criada (se usando)
[ ] OLS reiniciado apos todas configuracoes
```

## Ordem de Implementacao

### Fase 1 - Fundacao
1. Criar projeto Laravel 11 (PHP 8.3)
2. Instalar Filament 3, configurar multi-panel (Admin + App)
3. Todas as migrations (incluindo product_variations, slugs, sync_logs, marketplace_categories)
4. Todos os Models com relationships + Traits (HasSlug)
5. Observers (ProductObserver, InventoryObserver, OrderObserver)
6. Enums (incluindo SyncStatus)
7. Instalador web (/install com step de CDN/cache)
8. Middleware CheckInstalled + CheckSubscription + LscacheMiddleware
9. Sistema de slugs/URLs dinamicas (SlugController + HasSlug trait)

### Fase 2 - Painel Admin (Super Admin + Fornecedor)
10. Dashboard super admin
11. SupplierResource, ClientResource, PlanResource
12. ProductResource (catalogo fornecedor com variacoes + upload de imagens/videos)
13. InventoryResource
14. OrderResource (visao fornecedor)
15. Settings (branding, cache, CDN, URLs)

### Fase 3 - Painel App (Cliente/Seller)
16. Dashboard cliente
17. Pagina "Catalogo do Fornecedor" (navegar + adicionar com variacoes)
18. ClientProductResource (meu catalogo, sub-SKUs, listing_type_id)
19. Calculadora de precos (margem + taxas por listing_type)
20. Modos de cadastro (manual/semi-auto/full-auto)
21. OrderResource (visao cliente com NF-e)
22. MySubscription (ver plano)

### Fase 4 - Integracoes
23. MarketplaceInterface + MercadoLivreService (OAuth, items, variations, orders)
24. ShopeeService (OAuth, products, models, orders)
25. TokenRefreshService (renova tokens automaticamente)
26. BlingService
27. AsaasService (PIX pedidos + assinaturas)
28. ShipayService
29. MarketplaceAccountResource + ErpAccountResource
30. Jobs de sincronizacao (produtos, pedidos, estoque, categorias, tokens)
31. Webhooks (ML, Shopee, Bling, Asaas)
32. SyncLogResource (visualizar logs de integracao)
33. MarketplaceCategories sync (cache local de categorias)

### Fase 5 - Remessas e Conciliacao
34. ShipmentResource (produtor cria remessas, galpao ve)
35. Geracao de etiquetas por produto na remessa (PDF com QR/barcode)
36. Tela de conferencia no galpao (escanear label_code)
37. Estoque atualizado automaticamente apos conferencia
38. supplier_balances + supplier_transactions (credito automatico na venda)
39. WithdrawalRequestResource (produtor solicita, galpao aprova/paga)
40. FinancialReport page (relatorio de conciliacao)

### Fase 6 - Fulfillment (Galpao)
41. Pagina Fulfillment no /admin (lista pedidos pagos, botao imprimir)
42. Impressao de etiquetas de envio (individual + lote) via API marketplace
43. Tela de escaneamento/blip de produtos (input SKU + validacao)
44. Atualizacao automatica de status (preparing → separated → shipped)
45. Dashboard fulfillment (pedidos por status, metricas)

### Fase 7 - Descontos
46. PlatformDiscountResource (super admin configura descontos graduais)
47. PlatformDiscountTiers (interface visual para configurar faixas)
48. SupplierDiscountResource (galpao cria descontos para clientes)
49. SupplierDiscountTiers (faixas por volume/valor)
50. CouponResource (cupons de plataforma e fornecedor)
51. DiscountEngine (motor que aplica regras automaticamente)
52. Integracao do DiscountEngine no calculo de preco/taxa

### Fase 8 - Cache, CDN e Polish
53. LSCache configuracao + LscacheMiddleware
54. CacheService (purge por tags ao atualizar dados)
55. CloudflareService (purge API)
56. BunnyCdnService (upload + purge)
57. CdnInterface + fallback para local
58. Marketplace fees (tabela de taxas configuravel por listing_type)
59. CleanupSyncLogs job (limpar logs > 30 dias)
60. Notificacoes por email
61. README com instrucoes de instalacao + deploy CyberPanel

## Verificacao

### Fundacao
- `composer install` sem erros
- /install funciona: requirements → database → admin → complete
- /admin acessivel pelo super admin
- /app acessivel pelo cliente
- OPcache ativo e JIT habilitado (PHP 8.3)

### Catalogo e Variacoes
- Super admin cria fornecedor, planos, clientes
- Fornecedor cadastra produtos com imagens e variacoes (cor/tamanho)
- Produto tem GTIN/EAN, peso em KG, dimensoes em CM
- Variacoes tem SKU proprio, preco proprio, imagens proprias
- Cliente ve catalogo do fornecedor, adiciona ao seu
- Cliente customiza titulo/preco/SKU
- Calculadora de preco calcula taxas + margem
- Estoque compartilhado desconta ao vender
- Plano limita SKUs e conexoes

### Integracoes Marketplace
- OAuth ML: autoriza, recebe tokens, refresh automatico a cada 5h
- OAuth Shopee: autoriza, recebe tokens, refresh automatico a cada 3h
- Token expirado marca conta como 'expired' e notifica usuario
- Produto com variacoes sincroniza corretamente (ML variations / Shopee models)
- GTIN/EAN enviado na criacao do produto
- listing_type_id (ML) define taxa correta
- Categorias do marketplace cacheadas localmente (sync semanal)
- Sync logs registram toda comunicacao com APIs
- Webhook ML cria pedido com pack_id + buyer doc (CPF/CNPJ)
- Webhook Shopee cria pedido com order_sn (string) + buyer_cpf_id
- NF-e (invoice) enviada para Shopee quando exigido
- Webhook Asaas atualiza assinatura

### Remessas e Conciliacao
- Produtor cria remessa com produtos → etiquetas geradas (PDF)
- Galpao recebe remessa → conferencia com escaneamento funciona
- Divergencias registradas quando quantity_received != quantity
- Estoque atualizado apos conferencia
- Galpao define warehouse_price diferente do custo do produtor
- Venda de produto credita saldo do produtor automaticamente
- Produtor solicita saque → galpao aprova → status atualiza
- Relatorio financeiro mostra movimentacoes e saldos

### Descontos
- Super admin configura desconto gradual por pedido (90%, 70%, 50%...)
- Desconto aplicado automaticamente na taxa da plataforma
- Galpao cria descontos por volume para clientes
- Cupons funcionam: validacao de prazo, usos, valor minimo
- DiscountEngine calcula desconto correto baseado nas regras ativas

### Fulfillment
- Galpao ve pedidos pagos na tela de fulfillment
- Impressao de etiqueta (individual e lote) funciona
- Escaneamento de SKU valida e marca item como separado
- Quando todos itens escaneados, pedido muda para "separated"
- Webhook de envio atualiza status para "shipped"
- Tracking number e URL salvos no pedido

### Cache e CDN
- LSCache ativo para paginas publicas (10 min TTL)
- Purge automatico ao atualizar produto/estoque
- Cloudflare cacheando assets estaticos (se configurado)
- Bunny CDN servindo imagens de produtos (se configurado)
- Fallback para storage local quando CDN desligado
- Settings permitem ligar/desligar CDN pelo painel admin

### URLs Dinamicas
- Produto acessivel via /produto/{slug}
- Categoria acessivel via /categoria/{slug}
- Slug gerado automaticamente ao salvar
- Slug antigo redireciona 301 para o novo
- Prefixos configuraveis pelo admin (settings)

### Deploy CyberPanel
- .htaccess contem apenas rewrite rules (compativel OLS)
- Headers de seguranca no vHost conf
- php.ini com OPcache otimizado
- Cron agendado para artisan schedule:run
- SSL ativo via CyberPanel
- Storage symlink funcionando
