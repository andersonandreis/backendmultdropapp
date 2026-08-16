<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SyncProductsJob;
use App\Jobs\PublishClientProductToMLJob;
use App\Models\ClientProduct;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;
use App\Services\AppLoggerService;
use App\Services\ProductQualityService;
use App\Jobs\AutoFillProductAttributesJob;
use App\Models\MarketplaceAccount;

class ProductController extends Controller
{
    private function clientOrFail(Request $request)
    {
        $client = $request->user()->client;

        if (! $client) {
            abort(403, 'Usuario nao possui perfil de lojista.');
        }

        return $client;
    }

    // =========================================================================
    // CRUD ClientProducts (catalogo do lojista)
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/products',
        summary: 'Listar produtos do catalogo do lojista',
        description: 'Retorna os produtos que o lojista adicionou ao seu catalogo (ClientProducts), ou seja, produtos de fornecedores que foram personalizados pelo lojista para venda nos marketplaces. Inclui a conta de marketplace associada. Suporta filtro por sync_status e busca por titulo ou SKU personalizado.',
        tags: ['Produtos'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'per_page',
                in: 'query',
                required: false,
                description: 'Quantidade de produtos por pagina',
                schema: new OA\Schema(type: 'integer', default: 15, example: 15)
            ),
            new OA\Parameter(
                name: 'sync_status',
                in: 'query',
                required: false,
                description: 'Filtrar por status de sincronizacao com o marketplace',
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['pending', 'synced', 'error', 'paused'],
                    example: 'pending'
                )
            ),
            new OA\Parameter(
                name: 'search',
                in: 'query',
                required: false,
                description: 'Busca por titulo personalizado ou SKU personalizado',
                schema: new OA\Schema(type: 'string', example: 'camiseta')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista de produtos do catalogo do lojista paginada',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 200),
                                    new OA\Property(property: 'client_id', type: 'integer', example: 7),
                                    new OA\Property(property: 'product_id', type: 'integer', example: 101),
                                    new OA\Property(property: 'marketplace_account_id', type: 'integer', nullable: true, example: 1),
                                    new OA\Property(property: 'custom_sku', type: 'string', example: 'CAMP-LOJA-001'),
                                    new OA\Property(property: 'custom_title', type: 'string', example: 'Camiseta Polo Masculina Premium'),
                                    new OA\Property(property: 'custom_price', type: 'number', nullable: true, example: 99.90),
                                    new OA\Property(
                                        property: 'pricing_mode',
                                        type: 'string',
                                        nullable: true,
                                        enum: ['manual', 'margin', 'competitive'],
                                        example: 'margin'
                                    ),
                                    new OA\Property(property: 'profit_margin', type: 'number', nullable: true, example: 30.0),
                                    new OA\Property(property: 'is_active', type: 'boolean', example: true),
                                    new OA\Property(
                                        property: 'sync_status',
                                        type: 'string',
                                        enum: ['pending', 'synced', 'error', 'paused'],
                                        example: 'synced'
                                    ),
                                    new OA\Property(
                                        property: 'marketplace_account',
                                        type: 'object',
                                        nullable: true,
                                        properties: [
                                            new OA\Property(property: 'id', type: 'integer', example: 1),
                                            new OA\Property(property: 'account_name', type: 'string', example: 'Minha Loja ML'),
                                            new OA\Property(property: 'platform', type: 'string', example: 'mercadolivre'),
                                        ]
                                    ),
                                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2024-03-20T10:00:00Z'),
                                ]
                            )
                        ),
                        new OA\Property(
                            property: 'meta',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'total', type: 'integer', example: 45),
                                new OA\Property(property: 'per_page', type: 'integer', example: 15),
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'last_page', type: 'integer', example: 3),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente ou invalido',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.')]
                )
            ),
        ]
    )]
    public function index(Request $request)
    {
        $client  = $this->clientOrFail($request);
        $perPage = (int) $request->query('per_page', 15);

        $query = ClientProduct::where('client_id', $client->id)
            ->with([
            'marketplaceAccount:id,account_name,platform',
            'product:id,name,sku,price',
            'product.media:id,product_id,url,type',
        ]);

        if ($request->filled('sync_status')) {
            $query->where('sync_status', $request->query('sync_status'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('custom_title', 'like', "%{$search}%")
                  ->orWhere('custom_sku', 'like', "%{$search}%");
            });
        }

        if ($request->filled('marketplace_account_id')) {
            $query->where('marketplace_account_id', $request->integer('marketplace_account_id'));
        }

        $paginator = $query->paginate($perPage);


        // SEL-430 (30/07, Ruan ao vivo: catalogo do Studio abria com todos os
        // produtos em caixinha cinza). client_products.image_url vem NULL pra
        // todo mundo -- a foto real mora em product_media, que este metodo JA
        // carregava no with() e descartava. Sem isso o cliente escolhe produto
        // as cegas e o video pode sair do produto errado.
        $itens = collect($paginator->items())->map(function ($cp) {
            $linha  = $cp->toArray();
            $midias = optional($cp->product)->media;
            $urls   = $midias
                ? $midias->filter(fn ($m) => ! empty($m->url))
                    ->sortBy(fn ($m) => $m->type === 'image' ? 0 : 1)
                    ->pluck('url')->values()->all()
                : [];
            if (empty($linha['image_url'])) {
                $linha['image_url'] = $urls[0] ?? null;
            }
            // galeria completa -- o Studio usava 1 foto havendo 20+
            $linha['images'] = $urls;
            return $linha;
        })->all();

        return response()->json([
            'data' => $itens,
            'meta' => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/products',
        summary: 'Adicionar produto ao catalogo do lojista',
        description: 'Vincula um produto do catalogo de um fornecedor ao perfil do lojista, permitindo personalizar titulo, SKU, preco e estrategia de precificacao. Se marketplace_account_id for informado, o produto sera associado a aquela loja — o fornecedor do produto deve ser o mesmo da loja. O produto e criado com sync_status=pending ate ser publicado.',
        tags: ['Produtos'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['product_id', 'custom_title'],
                properties: [
                    new OA\Property(
                        property: 'product_id',
                        type: 'integer',
                        description: 'ID do produto base no catalogo do fornecedor',
                        example: 101
                    ),
                    new OA\Property(
                        property: 'product_variation_id',
                        type: 'integer',
                        nullable: true,
                        description: 'ID da variacao especifica do produto (opcional)',
                        example: 305
                    ),
                    new OA\Property(
                        property: 'marketplace_account_id',
                        type: 'integer',
                        nullable: true,
                        description: 'ID da conta de marketplace onde o produto sera publicado',
                        example: 1
                    ),
                    new OA\Property(
                        property: 'custom_sku',
                        type: 'string',
                        nullable: true,
                        description: 'SKU personalizado unico por conta (opcional — gerado automaticamente como {sku_fornecedor}-{sequencial} se omitido). Deve ser unico dentro da mesma conta de lojista.',
                        example: 'CAMP-LOJA-001'
                    ),
                    new OA\Property(
                        property: 'custom_title',
                        type: 'string',
                        description: 'Titulo personalizado para exibicao no marketplace',
                        example: 'Camiseta Polo Masculina Premium - HubAI'
                    ),
                    new OA\Property(
                        property: 'custom_description',
                        type: 'string',
                        nullable: true,
                        description: 'Descricao personalizada do produto',
                        example: 'Camiseta polo de alta qualidade, 100% algodao.'
                    ),
                    new OA\Property(
                        property: 'custom_price',
                        type: 'number',
                        nullable: true,
                        description: 'Preco de venda manual em R$. Ignorado se pricing_mode nao for manual.',
                        example: 99.90
                    ),
                    new OA\Property(
                        property: 'pricing_mode',
                        type: 'string',
                        nullable: true,
                        description: 'Modo de precificacao: manual (preco fixo), margin (margem sobre custo), competitive (preco competitivo)',
                        enum: ['manual', 'margin', 'competitive'],
                        example: 'margin'
                    ),
                    new OA\Property(
                        property: 'profit_margin',
                        type: 'number',
                        nullable: true,
                        description: 'Margem de lucro em percentual (0-100). Usado quando pricing_mode=margin.',
                        example: 30.0
                    ),
                    new OA\Property(
                        property: 'is_active',
                        type: 'boolean',
                        description: 'Se o produto esta ativo para sincronizacao',
                        example: true
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Produto adicionado ao catalogo do lojista',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 200),
                                new OA\Property(property: 'client_id', type: 'integer', example: 7),
                                new OA\Property(property: 'product_id', type: 'integer', example: 101),
                                new OA\Property(property: 'custom_sku', type: 'string', example: 'CAMP-LOJA-001'),
                                new OA\Property(property: 'custom_title', type: 'string', example: 'Camiseta Polo Masculina Premium - HubAI'),
                                new OA\Property(property: 'custom_price', type: 'number', nullable: true, example: 99.90),
                                new OA\Property(property: 'pricing_mode', type: 'string', example: 'margin'),
                                new OA\Property(property: 'profit_margin', type: 'number', example: 30.0),
                                new OA\Property(property: 'sync_status', type: 'string', example: 'pending'),
                                new OA\Property(property: 'is_active', type: 'boolean', example: true),
                                new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2024-04-01T12:00:00Z'),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente ou invalido',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.')]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Dados invalidos ou fornecedor incompativel com a loja selecionada',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'A loja selecionada nao esta roteada para o fornecedor deste produto.'),
                        new OA\Property(property: 'errors', type: 'object', example: ['product_id' => ['The selected product_id is invalid.']]),
                    ]
                )
            ),
        ]
    )]
    public function store(Request $request)
    {
        $client = $this->clientOrFail($request);

        $data = $request->validate([
            'product_id'             => 'required|integer|exists:products,id',
            'product_variation_id'   => 'nullable|integer|exists:product_variations,id',
            'marketplace_account_id' => 'nullable|integer|exists:marketplace_accounts,id',
            'custom_sku'             => ['nullable', 'string', 'max:100'],
            'custom_title'           => 'required|string|max:255',
            'custom_description'     => 'nullable|string',
            'custom_price'           => 'nullable|numeric|min:0',
            'pricing_mode'           => 'nullable|string|in:manual,margin,competitive',
            'profit_margin'          => 'nullable|numeric|min:0|max:100',
            'is_active'              => 'boolean',
            'category_id'            => 'nullable|integer|exists:categories,id',
        ]);

        // Atualiza category_id no produto base se fornecido
        if (isset($data['category_id'])) {
            Product::where('id', $data['product_id'])->update(['category_id' => $data['category_id']]);
            unset($data['category_id']);
        }

        if (!empty($data['marketplace_account_id'])) {
            $account = \App\Models\MarketplaceAccount::where('client_id', $client->id)
                ->findOrFail($data['marketplace_account_id']);
            // Guard NOV-156: bloquear publicacao em conta pending/fantasma (OAuth abandonado).
            if ($account->status === 'pending' || empty($account->seller_id)) {
                return response()->json([
                    'message' => 'A conta de marketplace selecionada esta com a conexao incompleta. Reconecte a integracao antes de publicar.',
                ], 422);
            }
            $originalProduct = Product::findOrFail($data['product_id']);
            if ($account->supplier_id && $originalProduct->supplier_id
                && $account->supplier_id !== $originalProduct->supplier_id) {
                return response()->json([
                    'message' => 'A loja selecionada nao esta roteada para o fornecedor deste produto.',
                ], 422);
            }
        }

        // Verifica se o produto ja foi cadastrado para este cliente+marketplace (upsert)
        $existingQuery = ClientProduct::where('client_id', $client->id)
            ->where('product_id', $data['product_id']);
        if (!empty($data['marketplace_account_id'])) {
            $existingQuery->where('marketplace_account_id', $data['marketplace_account_id']);
        } else {
            $existingQuery->whereNull('marketplace_account_id');
        }
        $existing = $existingQuery->first();

        if ($existing) {
            // Produto ja existe: atualiza e re-enfileira sync
            $updateData = array_filter([
                'custom_title'       => $data['custom_title'] ?? null,
                'custom_description' => $data['custom_description'] ?? null,
                'custom_price'       => $data['custom_price'] ?? null,
                'pricing_mode'       => $data['pricing_mode'] ?? null,
                'profit_margin'      => $data['profit_margin'] ?? null,
                'is_active'          => $data['is_active'] ?? null,
                'sync_status'        => 'pending',
                'last_sync_error'    => null,
            ], fn($v) => $v !== null);
            $existing->update($updateData);
            if (!empty($existing->marketplace_account_id)) {
                $account = \App\Models\MarketplaceAccount::find($existing->marketplace_account_id);
                if ($account) {
                    $platform = strtolower($account->platform);
                    if ($platform === 'mercadolivre') {
                        PublishClientProductToMLJob::dispatch($existing->id);
                    } elseif (in_array($platform, ['shopee', 'bling'], true)) {
                        SyncProductsJob::dispatch($existing->id);
                    }
                }
            }
            return response()->json(['data' => $existing->fresh(), 'updated' => true], 200);
        }

        // Auto-gerar custom_sku se nao enviado
        if (empty($data['custom_sku'])) {
            $baseProduct = Product::findOrFail($data['product_id']);
            // Usa timestamp para garantir unicidade
            $data['custom_sku'] = sprintf('%s-%d', $baseProduct->sku, now()->timestamp);
        }

        // Garante unicidade do custom_sku adicionando suffix se necessario
        $sku = $data['custom_sku'];
        $attempts = 0;
        while (ClientProduct::where('client_id', $client->id)->where('custom_sku', $sku)->exists() && $attempts < 5) {
            $sku = $data['custom_sku'] . '-' . ($attempts + 1);
            $attempts++;
        }
        $data['custom_sku'] = $sku;

        $product = ClientProduct::create(array_merge($data, [
            'client_id'   => $client->id,
            'sync_status' => 'pending',
        ]));
        AppLoggerService::info('api', 'product.created', 'Client product created', ['client_product_id' => $product->id, 'product_id' => $data['product_id'] ?? null]);

        // Despacha sync imediato para marketplace conectado (ML ou Shopee)
        if (!empty($product->marketplace_account_id)) {
            $account = \App\Models\MarketplaceAccount::find($product->marketplace_account_id);
            if ($account) {
                $platform = strtolower($account->platform);
                if ($platform === 'mercadolivre') {
                    PublishClientProductToMLJob::dispatch($product->id);
                } elseif (in_array($platform, ['shopee', 'bling'], true)) {
                    SyncProductsJob::dispatch($product->id);
                }
            }
        }

        return response()->json(['data' => $product], 201);
    }

    #[OA\Get(
        path: '/api/v1/products/{id}',
        summary: 'Detalhes de um produto do catalogo do lojista',
        description: 'Retorna os dados completos de um produto do catalogo do lojista, incluindo configuracoes de precificacao, dimensoes personalizadas e a conta de marketplace associada. O produto deve pertencer ao lojista autenticado.',
        tags: ['Produtos'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'ID do produto no catalogo do lojista (ClientProduct)',
                schema: new OA\Schema(type: 'integer', example: 200)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Dados completos do produto do lojista',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 200),
                                new OA\Property(property: 'client_id', type: 'integer', example: 7),
                                new OA\Property(property: 'product_id', type: 'integer', example: 101),
                                new OA\Property(property: 'marketplace_account_id', type: 'integer', nullable: true, example: 1),
                                new OA\Property(property: 'custom_sku', type: 'string', example: 'CAMP-LOJA-001'),
                                new OA\Property(property: 'custom_title', type: 'string', example: 'Camiseta Polo Masculina Premium'),
                                new OA\Property(property: 'custom_description', type: 'string', nullable: true, example: 'Descricao personalizada'),
                                new OA\Property(property: 'custom_price', type: 'number', nullable: true, example: 99.90),
                                new OA\Property(property: 'pricing_mode', type: 'string', nullable: true, example: 'margin'),
                                new OA\Property(property: 'profit_margin', type: 'number', nullable: true, example: 30.0),
                                new OA\Property(property: 'is_active', type: 'boolean', example: true),
                                new OA\Property(property: 'sync_status', type: 'string', example: 'synced'),
                                new OA\Property(property: 'custom_weight_kg', type: 'number', nullable: true, example: 0.5),
                                new OA\Property(property: 'custom_height_cm', type: 'number', nullable: true, example: 5.0),
                                new OA\Property(property: 'custom_width_cm', type: 'number', nullable: true, example: 20.0),
                                new OA\Property(property: 'custom_length_cm', type: 'number', nullable: true, example: 30.0),
                                new OA\Property(
                                    property: 'marketplace_account',
                                    type: 'object',
                                    nullable: true,
                                    properties: [
                                        new OA\Property(property: 'id', type: 'integer', example: 1),
                                        new OA\Property(property: 'account_name', type: 'string', example: 'Minha Loja ML'),
                                        new OA\Property(property: 'platform', type: 'string', example: 'mercadolivre'),
                                    ]
                                ),
                                new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2024-03-20T10:00:00Z'),
                                new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2024-04-05T16:30:00Z'),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente ou invalido',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.')]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Produto nao encontrado ou nao pertence ao lojista',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'No query results for model [ClientProduct] 99')]
                )
            ),
        ]
    )]
    public function show(Request $request, int $id)
    {
        $client  = $this->clientOrFail($request);
        $product = ClientProduct::where('client_id', $client->id)
            ->with([
            'marketplaceAccount:id,account_name,platform',
            'product:id,name,sku,price',
            'product.media:id,product_id,url,type',
        ])
            ->findOrFail($id);

        return response()->json(['data' => $product]);
    }

    #[OA\Put(
        path: '/api/v1/products/{id}',
        summary: 'Atualizar produto do catalogo do lojista',
        description: 'Atualiza os dados de um produto do catalogo do lojista. Todos os campos sao opcionais (PATCH semantico). Atualizar preco ou margem nao dispara sincronizacao automatica — use batch-publish para republicar. Suporta atualizacao de dimensoes para calculo de frete.',
        tags: ['Produtos'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'ID do produto no catalogo do lojista',
                schema: new OA\Schema(type: 'integer', example: 200)
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'custom_title', type: 'string', description: 'Novo titulo para o marketplace', example: 'Camiseta Polo Masculina - Edicao Especial'),
                    new OA\Property(property: 'custom_description', type: 'string', nullable: true, description: 'Nova descricao do produto'),
                    new OA\Property(property: 'custom_price', type: 'number', nullable: true, description: 'Novo preco de venda em R$', example: 109.90),
                    new OA\Property(property: 'pricing_mode', type: 'string', enum: ['manual', 'margin', 'competitive'], example: 'manual'),
                    new OA\Property(property: 'profit_margin', type: 'number', nullable: true, description: 'Nova margem de lucro em percentual (0-100)', example: 35.0),
                    new OA\Property(property: 'is_active', type: 'boolean', description: 'Ativar ou desativar o produto', example: true),
                    new OA\Property(property: 'custom_weight_kg', type: 'number', nullable: true, description: 'Peso do produto em kg', example: 0.5),
                    new OA\Property(property: 'custom_height_cm', type: 'number', nullable: true, description: 'Altura em cm', example: 5.0),
                    new OA\Property(property: 'custom_width_cm', type: 'number', nullable: true, description: 'Largura em cm', example: 20.0),
                    new OA\Property(property: 'custom_length_cm', type: 'number', nullable: true, description: 'Comprimento em cm', example: 30.0),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Produto atualizado com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 200),
                                new OA\Property(property: 'custom_title', type: 'string', example: 'Camiseta Polo Masculina - Edicao Especial'),
                                new OA\Property(property: 'custom_price', type: 'number', example: 109.90),
                                new OA\Property(property: 'pricing_mode', type: 'string', example: 'manual'),
                                new OA\Property(property: 'is_active', type: 'boolean', example: true),
                                new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2024-04-10T09:00:00Z'),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente ou invalido',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.')]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Produto nao encontrado ou nao pertence ao lojista',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'No query results for model [ClientProduct] 99')]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Dados de validacao invalidos',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'The profit margin field must not be greater than 100.'),
                        new OA\Property(property: 'errors', type: 'object', example: ['profit_margin' => ['The profit margin field must not be greater than 100.']]),
                    ]
                )
            ),
        ]
    )]
    public function update(Request $request, int $id)
    {
        $client  = $this->clientOrFail($request);
        $product = ClientProduct::where('client_id', $client->id)->findOrFail($id);

        $data = $request->validate([
            'custom_title'       => 'sometimes|string|max:255',
            'custom_sku'         => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('client_products', 'custom_sku')->where('client_id', $client->id)->ignore($id)],
            'custom_description' => 'sometimes|nullable|string',
            'custom_price'       => 'sometimes|nullable|numeric|min:0',
            'pricing_mode'       => 'sometimes|string|in:manual,margin,competitive',
            'profit_margin'      => 'sometimes|nullable|numeric|min:0|max:100',
            'is_active'          => 'sometimes|boolean',
            'custom_weight_kg'   => 'sometimes|nullable|numeric|min:0',
            'custom_height_cm'   => 'sometimes|nullable|numeric|min:0',
            'custom_width_cm'    => 'sometimes|nullable|numeric|min:0',
            'custom_length_cm'   => 'sometimes|nullable|numeric|min:0',
            'category_id'        => 'sometimes|nullable|integer|exists:categories,id',
        ]);

        // Atualiza category_id no produto base se fornecido
        if (array_key_exists('category_id', $data)) {
            $product->product()->update(['category_id' => $data['category_id']]);
            unset($data['category_id']);
        }

        $product->update($data);
        AppLoggerService::info('api', 'product.updated', 'Client product updated', ['client_product_id' => $product->id]);

        return response()->json(['data' => $product->fresh()]);
    }

    #[OA\Delete(
        path: '/api/v1/products/{id}',
        summary: 'Remover produto do catalogo do lojista',
        description: 'Remove (soft delete ou hard delete) um produto do catalogo do lojista. Isso nao exclui o produto base do fornecedor, apenas desvincuda o produto do perfil do lojista. Se o produto estiver publicado em marketplace, e recomendado desativar antes de remover.',
        tags: ['Produtos'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'ID do produto no catalogo do lojista',
                schema: new OA\Schema(type: 'integer', example: 200)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Produto removido do catalogo com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Produto removido com sucesso.'),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente ou invalido',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.')]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Produto nao encontrado ou nao pertence ao lojista',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'No query results for model [ClientProduct] 99')]
                )
            ),
        ]
    )]
    public function destroy(Request $request, int $id)
    {
        $client  = $this->clientOrFail($request);
        $product = ClientProduct::where('client_id', $client->id)->findOrFail($id);

        $product->delete();
        AppLoggerService::warning('api', 'product.deleted', 'Client product deleted', ['client_product_id' => $id]);

        return response()->json(['message' => 'Produto removido com sucesso.']);
    }

    #[OA\Post(
        path: '/api/v1/products/batch-publish',
        summary: 'Publicar multiplos produtos nos marketplaces em lote',
        description: 'Enfileira jobs de sincronizacao (SyncProductsJob) para os produtos informados. Apenas produtos ativos (is_active=true) e pertencentes ao lojista serao processados. Produtos invalidos ou inativos sao ignorados silenciosamente. O processamento e assicrono — use o campo sync_status do produto para acompanhar o resultado.',
        tags: ['Produtos'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['product_ids'],
                properties: [
                    new OA\Property(
                        property: 'product_ids',
                        type: 'array',
                        description: 'Array com os IDs dos produtos do catalogo do lojista a publicar',
                        items: new OA\Items(type: 'integer'),
                        example: [200, 201, 205]
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 202,
                description: 'Jobs de sincronizacao enfileirados com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'message',
                            type: 'string',
                            description: 'Confirmacao com quantidade de produtos enfileirados',
                            example: '3 produto(s) enfileirado(s) para sincronizacao.'
                        ),
                        new OA\Property(
                            property: 'dispatched',
                            type: 'integer',
                            description: 'Quantidade de jobs enfileirados',
                            example: 3
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente ou invalido',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.')]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Lista de IDs ausente ou vazia',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'The product ids field must have at least 1 items.'),
                        new OA\Property(property: 'errors', type: 'object', example: ['product_ids' => ['The product ids field must have at least 1 items.']]),
                    ]
                )
            ),
        ]
    )]
    public function batchPublish(Request $request)
    {
        $client = $this->clientOrFail($request);

        $data = $request->validate([
            'product_ids'   => 'required|array|min:1',
            'product_ids.*' => 'integer',
        ]);

        $products = ClientProduct::where('client_id', $client->id)
            ->whereIn('id', $data['product_ids'])
            ->where('is_active', true)
            ->get();

        $dispatched = 0;
        foreach ($products as $product) {
            SyncProductsJob::dispatch($product->id);
            $dispatched++;
        }

        return response()->json([
            'message'    => "{$dispatched} produto(s) enfileirado(s) para sincronizacao.",
            'dispatched' => $dispatched,
        ], 202);
    }

    // =========================================================================
    // IMAGENS — opera no Product base (product_media type=image)
    // ML: ate 12 imagens | Shopee: ate 9 imagens
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/products/{id}/images',
        summary: 'Upload de imagem para o produto (multipart/form-data)',
        description: 'Envia uma imagem para o produto base do fornecedor. Aceita JPG, PNG ou WebP com no maximo 10MB. A imagem deve ter no minimo 500x500px (recomendado 1500x1500px para ML). Limite: 12 imagens por produto (ML) / 9 (Shopee). A primeira imagem enviada se torna automaticamente a capa. Use is_cover=true para marcar uma imagem especifica como capa.',
        tags: ['Produtos - Imagens'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'ID do produto base (Product, nao ClientProduct)',
                schema: new OA\Schema(type: 'integer', example: 101)
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['image'],
                    properties: [
                        new OA\Property(
                            property: 'image',
                            type: 'string',
                            format: 'binary',
                            description: 'Arquivo de imagem JPG, PNG ou WebP. Maximo 10MB. Minimo 500x500px.'
                        ),
                        new OA\Property(
                            property: 'is_cover',
                            type: 'boolean',
                            description: 'Marcar como imagem de capa (principal). Desmarca as demais.',
                            example: false
                        ),
                        new OA\Property(
                            property: 'position',
                            type: 'integer',
                            description: 'Posicao na galeria (0 = primeira). Se omitido, a imagem e adicionada ao final.',
                            example: 0
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Imagem enviada e salva com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 55),
                                new OA\Property(property: 'product_id', type: 'integer', example: 101),
                                new OA\Property(property: 'type', type: 'string', example: 'image'),
                                new OA\Property(property: 'path', type: 'string', example: 'products/101/abc123.jpg'),
                                new OA\Property(property: 'url', type: 'string', example: 'https://cdn.hubai.io/storage/products/101/abc123.jpg'),
                                new OA\Property(property: 'position', type: 'integer', example: 0),
                                new OA\Property(property: 'is_cover', type: 'boolean', example: true),
                                new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2024-04-01T12:00:00Z'),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente ou invalido',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.')]
                )
            ),
            new OA\Response(
                response: 403,
                description: 'Limite de imagens atingido para este produto',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Limite maximo de 12 imagens atingido (ML). Shopee aceita ate 9.')]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Produto nao encontrado',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'No query results for model [Product] 99')]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Arquivo invalido ou dimensoes abaixo do minimo',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Imagem muito pequena (300x300px). Minimo recomendado: 500x500px.')]
                )
            ),
        ]
    )]
    public function uploadImage(Request $request, int $id)
    {
        $product = Product::findOrFail($id);

        $request->validate([
            'image'     => 'required|file|mimes:jpg,jpeg,png,webp|max:10240',
            'is_cover'  => 'nullable|boolean',
            'position'  => 'nullable|integer|min:0',
        ]);

        $imageCount = ProductMedia::where('product_id', $id)->where('type', 'image')->count();
        if ($imageCount >= 12) {
            return response()->json(['message' => 'Limite maximo de 12 imagens atingido (ML). Shopee aceita ate 9.'], 403);
        }

        $file = $request->file('image');
        [$imgWidth, $imgHeight] = getimagesize($file->getPathname());
        if ($imgWidth < 500 || $imgHeight < 500) {
            return response()->json([
                'message' => "Imagem muito pequena ({$imgWidth}x{$imgHeight}px). Minimo recomendado: 500x500px.",
            ], 422);
        }

        // FOR-007: estrutura "products/{supplier_id}/{product_id}/..." pra alinhar com importers.
        $supplierId = (int) $product->supplier_id;
        $path = $file->store("products/{$supplierId}/{$id}", 'public');
        $url  = Storage::disk('public')->url($path);

        if ($request->boolean('is_cover', false)) {
            ProductMedia::where('product_id', $id)->where('type', 'image')->update(['is_cover' => false]);
        }

        $position = $request->input('position', $imageCount);

        $media = ProductMedia::create([
            'product_id' => $id,
            'type'       => 'image',
            'path'       => $path,
            'url'        => $url,
            'local_path' => $path,
            'position'   => $position,
            'is_cover'   => $request->boolean('is_cover', $imageCount === 0),
        ]);

        return response()->json(['data' => $media], 201);
    }

    #[OA\Delete(
        path: '/api/v1/products/{id}/images/{imageId}',
        summary: 'Remover imagem do produto',
        description: 'Remove uma imagem especifica do produto e exclui o arquivo do storage. Se a imagem removida era a capa, a proxima imagem na ordem de posicao e promovida automaticamente como nova capa.',
        tags: ['Produtos - Imagens'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'ID do produto base',
                schema: new OA\Schema(type: 'integer', example: 101)
            ),
            new OA\Parameter(
                name: 'imageId',
                in: 'path',
                required: true,
                description: 'ID do registro de midia (ProductMedia)',
                schema: new OA\Schema(type: 'integer', example: 55)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Imagem removida com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Imagem removida com sucesso.'),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente ou invalido',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.')]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Imagem nao encontrada para este produto',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'No query results for model [ProductMedia] 99')]
                )
            ),
        ]
    )]
    public function deleteImage(int $id, int $imageId)
    {
        $media = ProductMedia::where('product_id', $id)
            ->where('type', 'image')
            ->findOrFail($imageId);

        if ($media->path && Storage::disk('public')->exists($media->path)) {
            Storage::disk('public')->delete($media->path);
        }

        $wasCover = $media->is_cover;
        $media->delete();

        if ($wasCover) {
            $first = ProductMedia::where('product_id', $id)
                ->where('type', 'image')
                ->orderBy('position')
                ->first();
            if ($first) {
                $first->update(['is_cover' => true]);
            }
        }

        return response()->json(['message' => 'Imagem removida com sucesso.']);
    }

    #[OA\Put(
        path: '/api/v1/products/{id}/images/reorder',
        summary: 'Reordenar imagens do produto',
        description: 'Atualiza a posicao (ordem de exibicao) de uma ou mais imagens do produto. Envie um array de objetos com id e position para cada imagem a reordenar. Imagens nao mencionadas mantem suas posicoes atuais. Retorna a lista completa de imagens na nova ordem.',
        tags: ['Produtos - Imagens'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'ID do produto base',
                schema: new OA\Schema(type: 'integer', example: 101)
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['order'],
                properties: [
                    new OA\Property(
                        property: 'order',
                        type: 'array',
                        description: 'Array de objetos com id da imagem e nova posicao (0 = primeira)',
                        items: new OA\Items(
                            required: ['id', 'position'],
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', description: 'ID da imagem (ProductMedia)', example: 55),
                                new OA\Property(property: 'position', type: 'integer', description: 'Nova posicao (0 = primeira)', example: 0),
                            ]
                        ),
                        example: [['id' => 55, 'position' => 0], ['id' => 56, 'position' => 1], ['id' => 57, 'position' => 2]]
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Ordem atualizada. Retorna a lista completa de imagens na nova ordem.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 55),
                                    new OA\Property(property: 'product_id', type: 'integer', example: 101),
                                    new OA\Property(property: 'url', type: 'string', example: 'https://cdn.hubai.io/storage/products/101/abc123.jpg'),
                                    new OA\Property(property: 'position', type: 'integer', example: 0),
                                    new OA\Property(property: 'is_cover', type: 'boolean', example: true),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente ou invalido',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.')]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Produto nao encontrado',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'No query results for model [Product] 99')]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Dados de reordenacao invalidos',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'The order.0.id field is required.'),
                        new OA\Property(property: 'errors', type: 'object'),
                    ]
                )
            ),
        ]
    )]
    public function reorderImages(Request $request, int $id)
    {
        Product::findOrFail($id);

        $data = $request->validate([
            'order'              => 'required|array|min:1',
            'order.*.id'         => 'required|integer',
            'order.*.position'   => 'required|integer|min:0',
        ]);

        foreach ($data['order'] as $item) {
            ProductMedia::where('product_id', $id)
                ->where('type', 'image')
                ->where('id', $item['id'])
                ->update(['position' => $item['position']]);
        }

        $images = ProductMedia::where('product_id', $id)
            ->where('type', 'image')
            ->orderBy('position')
            ->get();

        return response()->json(['data' => $images]);
    }

    // =========================================================================
    // VIDEO — opera no Product base (product_media type=video)
    // ML: suporta URL YouTube | Shopee: requer upload via API propria (pendencia)
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/products/{id}/video',
        summary: 'Definir URL de video YouTube para o produto (Mercado Livre)',
        description: 'Associa uma URL de video do YouTube ao produto. O Mercado Livre exige que o video seja do YouTube — URLs de outras plataformas sao rejeitadas. O registro e salvo tanto no campo video_url do produto quanto na tabela product_media. Shopee nao suporta videos via URL (requer upload direto pela API da plataforma).',
        tags: ['Produtos - Video'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'ID do produto base',
                schema: new OA\Schema(type: 'integer', example: 101)
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['video_url'],
                properties: [
                    new OA\Property(
                        property: 'video_url',
                        type: 'string',
                        description: 'URL completa do video no YouTube (youtube.com ou youtu.be)',
                        example: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'URL de video definida com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(
                                    property: 'video_url',
                                    type: 'string',
                                    example: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'
                                ),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente ou invalido',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.')]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Produto nao encontrado',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'No query results for model [Product] 99')]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'URL invalida ou nao e do YouTube',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Apenas URLs do YouTube sao suportadas (exigencia do Mercado Livre).')]
                )
            ),
        ]
    )]
    public function setVideo(Request $request, int $id)
    {
        $product = Product::findOrFail($id);

        $data = $request->validate([
            'video_url' => 'required|url|max:500',
        ]);

        if (! preg_match('/(?:youtube\.com|youtu\.be)/i', $data['video_url'])) {
            return response()->json(['message' => 'Apenas URLs do YouTube sao suportadas (exigencia do Mercado Livre).'], 422);
        }

        $product->update(['video_url' => $data['video_url']]);

        ProductMedia::updateOrCreate(
            ['product_id' => $id, 'type' => 'video'],
            ['url' => $data['video_url'], 'position' => 0]
        );

        return response()->json(['data' => ['video_url' => $product->video_url]]);
    }

    #[OA\Delete(
        path: '/api/v1/products/{id}/video',
        summary: 'Remover video associado ao produto',
        description: 'Remove a URL de video do produto (limpa o campo video_url) e deleta o registro correspondente da tabela product_media. Operacao segura: nao remove nada no YouTube, apenas desvincula a URL do produto no HubAI.',
        tags: ['Produtos - Video'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'ID do produto base',
                schema: new OA\Schema(type: 'integer', example: 101)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Video desvinculado do produto com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Video removido com sucesso.'),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente ou invalido',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.')]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Produto nao encontrado',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'No query results for model [Product] 99')]
                )
            ),
        ]
    )]
    public function removeVideo(int $id)
    {
        $product = Product::findOrFail($id);
        $product->update(['video_url' => null]);

        ProductMedia::where('product_id', $id)->where('type', 'video')->delete();

        return response()->json(['message' => 'Video removido com sucesso.']);
    }

    // =========================================================================
    // VARIACOES — opera no Product base (product_variations)
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/products/{id}/variations',
        summary: 'Listar variacoes de um produto',
        description: 'Retorna todas as variacoes de um produto base (ex: cor, tamanho, modelo), ordenadas por posicao. Cada variacao tem seu proprio SKU, preco de custo e atributos personalizados.',
        tags: ['Produtos - Variacoes'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'ID do produto base',
                schema: new OA\Schema(type: 'integer', example: 101)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista de variacoes do produto ordenadas por posicao',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 305),
                                    new OA\Property(property: 'product_id', type: 'integer', example: 101),
                                    new OA\Property(property: 'sku', type: 'string', example: 'CAMP-001-P'),
                                    new OA\Property(property: 'name', type: 'string', description: 'Descricao da variacao', example: 'Camiseta Polo P'),
                                    new OA\Property(property: 'price', type: 'number', description: 'Preco de custo da variacao em R$', example: 45.90),
                                    new OA\Property(property: 'cost', type: 'number', description: 'Custo de producao em R$', example: 32.00),
                                    new OA\Property(property: 'gtin', type: 'string', nullable: true, description: 'Codigo de barras EAN/GTIN', example: '7896295500016'),
                                    new OA\Property(
                                        property: 'attributes',
                                        type: 'object',
                                        nullable: true,
                                        description: 'Atributos da variacao (ex: cor, tamanho)',
                                        example: ['cor' => 'Azul', 'tamanho' => 'P']
                                    ),
                                    new OA\Property(property: 'position', type: 'integer', example: 0),
                                    new OA\Property(property: 'is_active', type: 'boolean', example: true),
                                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2024-03-01T10:00:00Z'),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente ou invalido',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.')]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Produto nao encontrado',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'No query results for model [Product] 99')]
                )
            ),
        ]
    )]
    public function listVariations(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        // MUL-161-BE1 #14: o frontend /meus-produtos pode mandar tanto o product.id
        // do catalogo quanto o client_product.id. Tentar primeiro pelo product.id;
        // se nao encontrar, tentar via client_products.id -> product.
        $product = Product::find($id);
        $cpDirect = null;
        if (! $product) {
            // Fallback: $id pode ser client_product.id
            $cpDirect = \App\Models\ClientProduct::find($id);
            $product = $cpDirect?->product;
        }
        if (! $product) {
            // MUL-360 item 5: anuncio sem produto de catalogo vinculado ainda pode ter
            // variantes no marketplace — busca ao vivo direto pelo client_product.
            if ($cpDirect) {
                $live = $this->liveListingVariationsForCps(collect([$cpDirect->load('marketplaceAccount:id,platform,account_name,status')]));
                if ($live !== []) {
                    return response()->json(['data' => $live, 'source' => 'marketplace_live']);
                }
            }
            return response()->json(['data' => [], 'empty_reason' => 'Produto nao encontrado.'], 200);
        }
        $variations = $product->variations()->orderBy('position')->get();

        // Buscar client_products vinculados a este produto para o lojista autenticado
        $client = $request->user()?->client;
        $marketplacePrices = [];
        if ($client) {
            $clientProducts = ClientProduct::where('client_id', $client->id)
                ->where('product_id', $id)
                ->where('excluido', false)
                ->with('marketplaceAccount:id,platform,account_name,status')
                ->get();

            foreach ($clientProducts as $cp) {
                $acc = $cp->marketplaceAccount;
                if (!$acc) continue;
                $marketplacePrices[$cp->product_variation_id ?? 'base'][] = [
                    'client_product_id'    => $cp->id,
                    'platform'             => $acc->platform,
                    'account_name'         => $acc->account_name,
                    'account_status'       => $acc->status,
                    'price'                => (float) $cp->custom_price,
                    'sync_status'          => $cp->sync_status,
                    'listing_status'       => $cp->listing_status,
                    'external_listing_id'  => $cp->external_listing_id,
                    'external_listing_url' => $cp->external_listing_url,
                ];
            }
        }

        $data = $variations->map(function ($v) use ($marketplacePrices) {
            return [
                'id'             => $v->id,
                'product_id'     => $v->product_id,
                'sku'            => $v->sku,
                'name'           => $v->name,
                'price'          => (float) $v->price,
                'cost'           => (float) $v->price, // custo do seller = preco de catalogo
                'gtin'           => $v->gtin,
                'ean'            => $v->ean,
                'stock'          => (int) $v->virtual_stock_qty,
                'attributes'     => $v->attributes,
                'position'       => $v->position,
                'is_active'      => (bool) $v->is_active,
                // Preços por marketplace para esta variação específica
                'marketplace_prices' => $marketplacePrices[$v->id] ?? [],
                'created_at'     => $v->created_at?->toISOString(),
                'updated_at'     => $v->updated_at?->toISOString(),
            ];
        });

        // MUL-360 item 5: catalogo sem variacoes (product_variations esta vazio na WL) —
        // busca AO VIVO as variantes do ANUNCIO no marketplace (Shopee model_list /
        // ML variations), cacheado 30min por listing. Read-through: mesmo padrao da
        // NF-e do fornecedor (item 4). Nunca escreve no catalogo.
        if ($data->isEmpty() && $client) {
            $live = $this->liveListingVariations($client->id, $product->id);
            if ($live !== []) {
                return response()->json(['data' => $live, 'source' => 'marketplace_live']);
            }
        }

        return response()->json(['data' => $data]);
    }

    /**
     * MUL-360 item 5: variantes reais do anuncio, direto da API do marketplace.
     * Uma entrada por variante, com marketplace_prices por conta que tem o anuncio.
     */
    private function liveListingVariations(int $clientId, int $productId): array
    {
        $cps = ClientProduct::where('client_id', $clientId)
            ->where('product_id', $productId)
            ->where('excluido', false)
            ->with('marketplaceAccount:id,platform,account_name,status')
            ->get();

        return $this->liveListingVariationsForCps($cps);
    }

    private function liveListingVariationsForCps($cps): array
    {
        $out = [];
        foreach ($cps as $cp) {
            $acc = $cp->marketplaceAccount;
            if (! $acc || $acc->status !== 'active') {
                continue;
            }

            $models = \Illuminate\Support\Facades\Cache::remember(
                "listing_variations:cp:{$cp->id}",
                1800,
                function () use ($cp, $acc) {
                    try {
                        // a relacao vem com colunas enxutas (id,platform,...) — os services
                        // precisam de token/shop_id: hidratar o model completo antes do fetch
                        $fullAcc = \App\Models\MarketplaceAccount::withoutTenantSupplierScope()->find($acc->id);
                        if (! $fullAcc) {
                            return [];
                        }
                        if ($acc->platform === 'shopee' && ($cp->shopee_external_item_id ?? $cp->external_listing_id)) {
                            return $this->fetchShopeeModels($fullAcc, (int) ($cp->shopee_external_item_id ?? $cp->external_listing_id));
                        }
                        if ($acc->platform === 'mercadolivre' && ($cp->ml_external_item_id ?? $cp->external_listing_id)) {
                            return $this->fetchMlVariations($fullAcc, (string) ($cp->ml_external_item_id ?? $cp->external_listing_id));
                        }
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::info('[listVariations] live fetch falhou: ' . $e->getMessage(), [
                            'client_product_id' => $cp->id, 'platform' => $acc->platform,
                        ]);
                    }
                    return [];
                }
            );

            foreach ($models as $m) {
                $key = $m['sku'] !== null && $m['sku'] !== '' ? 'sku:' . $m['sku'] : 'nome:' . $m['name'];
                if (! isset($out[$key])) {
                    $out[$key] = [
                        'id'                 => $m['id'],
                        'sku'                => $m['sku'],
                        'name'               => $m['name'],
                        'price'              => $m['price'],
                        'stock'              => $m['stock'],
                        'is_active'          => true,
                        'marketplace_prices' => [],
                    ];
                }
                $out[$key]['marketplace_prices'][] = [
                    'client_product_id' => $cp->id,
                    'platform'          => $acc->platform,
                    'account_name'      => $acc->account_name,
                    'price'             => $m['price'],
                    'sync_status'       => $cp->sync_status,
                    'listing_status'    => $cp->listing_status,
                ];
            }
        }

        return array_values($out);
    }

    private function fetchShopeeModels(\App\Models\MarketplaceAccount $account, int $itemId): array
    {
        $svc = app(\App\Services\Integrations\Marketplaces\ShopeeService::class);
        $ref = new \ReflectionClass($svc);
        $getShopId = $ref->getMethod('getShopId'); $getShopId->setAccessible(true);
        $getToken  = $ref->getMethod('getValidAccessToken'); $getToken->setAccessible(true);
        $callApi   = $ref->getMethod('callApi'); $callApi->setAccessible(true);

        $shopId = $getShopId->invoke($svc, $account);
        $token  = $getToken->invoke($svc, $account);
        if (! $shopId || ! $token) {
            return [];
        }

        $resp = $callApi->invoke($svc, '/api/v2/product/get_model_list', [
            'shop_id' => $shopId, 'access_token' => $token, 'item_id' => $itemId,
        ], 'GET');

        $tiers  = $resp['response']['tier_variation'] ?? [];
        $models = $resp['response']['model'] ?? [];
        $out = [];
        foreach ($models as $m) {
            $nameParts = [];
            foreach (($m['tier_index'] ?? []) as $i => $optIdx) {
                $opt = $tiers[$i]['option_list'][$optIdx]['option'] ?? null;
                if ($opt !== null) { $nameParts[] = $opt; }
            }
            $stock = $m['stock_info_v2']['summary_info']['total_available_stock']
                ?? (isset($m['stock_info']) ? array_sum(array_column($m['stock_info'], 'current_stock')) : null);
            $out[] = [
                'id'    => $m['model_id'],
                'sku'   => $m['model_sku'] ?: null,
                'name'  => $nameParts !== [] ? implode(' / ', $nameParts) : ($m['model_name'] ?? null),
                'price' => isset($m['price_info'][0]['current_price']) ? (float) $m['price_info'][0]['current_price'] : null,
                'stock' => $stock !== null ? (int) $stock : null,
            ];
        }
        return $out;
    }

    private function fetchMlVariations(\App\Models\MarketplaceAccount $account, string $itemId): array
    {
        $mlService = app(\App\Services\Integrations\Marketplaces\MercadoLivreService::class);
        $token = $mlService->getAccessToken($account);
        if (! $token) {
            return [];
        }
        $resp = \Illuminate\Support\Facades\Http::withToken($token)->timeout(10)
            ->get("https://api.mercadolibre.com/items/{$itemId}", ['include_attributes' => 'all']);
        if ($resp->failed()) {
            return [];
        }
        $out = [];
        foreach (($resp->json('variations') ?? []) as $v) {
            $nameParts = [];
            foreach (($v['attribute_combinations'] ?? []) as $ac) {
                if (! empty($ac['value_name'])) { $nameParts[] = $ac['value_name']; }
            }
            $sku = null;
            foreach (($v['attributes'] ?? []) as $a) {
                if (($a['id'] ?? '') === 'SELLER_SKU') { $sku = $a['value_name'] ?? null; break; }
            }
            $out[] = [
                'id'    => $v['id'],
                'sku'   => $sku ?? ($v['seller_custom_field'] ?? null),
                'name'  => $nameParts !== [] ? implode(' / ', $nameParts) : null,
                'price' => isset($v['price']) ? (float) $v['price'] : null,
                'stock' => isset($v['available_quantity']) ? (int) $v['available_quantity'] : null,
            ];
        }
        return $out;
    }

    #[OA\Post(
        path: '/api/v1/products/{id}/variations',
        summary: 'Criar nova variacao para um produto (ex: cor, tamanho)',
        description: 'Adiciona uma nova variacao ao produto base. Cada variacao deve ter um SKU unico no sistema. O campo attributes aceita um objeto JSON livre para descrever os atributos da variacao (ex: {"cor":"Azul","tamanho":"M"}). O preco (price) e o custo (cost) sao obrigatorios pois variacoes podem ter precos diferentes.',
        tags: ['Produtos - Variacoes'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'ID do produto base',
                schema: new OA\Schema(type: 'integer', example: 101)
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['sku', 'name', 'price', 'cost'],
                properties: [
                    new OA\Property(property: 'sku', type: 'string', description: 'SKU unico da variacao no sistema', maxLength: 100, example: 'CAMP-001-M'),
                    new OA\Property(property: 'name', type: 'string', description: 'Nome ou descricao da variacao', maxLength: 255, example: 'Camiseta Polo M'),
                    new OA\Property(property: 'price', type: 'number', description: 'Preco de venda da variacao em R$', example: 45.90),
                    new OA\Property(property: 'cost', type: 'number', description: 'Custo de producao/aquisicao em R$', example: 32.00),
                    new OA\Property(property: 'gtin', type: 'string', nullable: true, description: 'Codigo de barras EAN-13 ou GTIN-14 (somente numeros)', maxLength: 14, example: '7896295500023'),
                    new OA\Property(
                        property: 'attributes',
                        type: 'object',
                        nullable: true,
                        description: 'Objeto JSON com os atributos da variacao',
                        example: ['cor' => 'Azul', 'tamanho' => 'M']
                    ),
                    new OA\Property(property: 'position', type: 'integer', nullable: true, description: 'Posicao na listagem de variacoes (0 = primeira)', example: 1),
                    new OA\Property(property: 'is_active', type: 'boolean', description: 'Se a variacao esta ativa para venda', example: true),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Variacao criada com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 306),
                                new OA\Property(property: 'product_id', type: 'integer', example: 101),
                                new OA\Property(property: 'sku', type: 'string', example: 'CAMP-001-M'),
                                new OA\Property(property: 'name', type: 'string', example: 'Camiseta Polo M'),
                                new OA\Property(property: 'price', type: 'number', example: 45.90),
                                new OA\Property(property: 'cost', type: 'number', example: 32.00),
                                new OA\Property(property: 'gtin', type: 'string', nullable: true, example: '7896295500023'),
                                new OA\Property(property: 'attributes', type: 'object', example: ['cor' => 'Azul', 'tamanho' => 'M']),
                                new OA\Property(property: 'position', type: 'integer', example: 1),
                                new OA\Property(property: 'is_active', type: 'boolean', example: true),
                                new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2024-04-01T12:00:00Z'),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente ou invalido',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.')]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Produto nao encontrado',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'No query results for model [Product] 99')]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'SKU duplicado ou dados invalidos',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'The sku has already been taken.'),
                        new OA\Property(property: 'errors', type: 'object', example: ['sku' => ['The sku has already been taken.']]),
                    ]
                )
            ),
        ]
    )]
    public function storeVariation(Request $request, int $id)
    {
        $product = Product::findOrFail($id);

        $data = $request->validate([
            'sku'        => 'required|string|max:100|unique:product_variations,sku',
            'name'       => 'required|string|max:255',
            'price'      => 'required|numeric|min:0',
            'cost'       => 'required|numeric|min:0',
            'gtin'       => 'nullable|string|max:14',
            'attributes' => 'nullable|array',
            'position'   => 'nullable|integer|min:0',
            'is_active'  => 'boolean',
        ]);

        $variation = $product->variations()->create($data);

        return response()->json(['data' => $variation], 201);
    }

    #[OA\Put(
        path: '/api/v1/products/{id}/variations/{vid}',
        summary: 'Atualizar variacao de um produto',
        description: 'Atualiza os dados de uma variacao especifica de um produto. Todos os campos sao opcionais. Ao atualizar o SKU, o novo valor deve ser unico no sistema (exceto o proprio SKU atual da variacao). Atualizar preco ou custo nao dispara sincronizacao automatica.',
        tags: ['Produtos - Variacoes'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'ID do produto base',
                schema: new OA\Schema(type: 'integer', example: 101)
            ),
            new OA\Parameter(
                name: 'vid',
                in: 'path',
                required: true,
                description: 'ID da variacao (ProductVariation)',
                schema: new OA\Schema(type: 'integer', example: 305)
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'sku', type: 'string', description: 'Novo SKU (deve ser unico no sistema)', example: 'CAMP-001-P-V2'),
                    new OA\Property(property: 'name', type: 'string', description: 'Novo nome da variacao', example: 'Camiseta Polo P - Versao 2'),
                    new OA\Property(property: 'price', type: 'number', description: 'Novo preco de venda em R$', example: 49.90),
                    new OA\Property(property: 'cost', type: 'number', description: 'Novo custo em R$', example: 34.00),
                    new OA\Property(property: 'gtin', type: 'string', nullable: true, description: 'Novo codigo de barras', example: '7896295500030'),
                    new OA\Property(property: 'attributes', type: 'object', nullable: true, description: 'Novos atributos da variacao', example: ['cor' => 'Vermelho', 'tamanho' => 'P']),
                    new OA\Property(property: 'position', type: 'integer', description: 'Nova posicao na listagem', example: 2),
                    new OA\Property(property: 'is_active', type: 'boolean', description: 'Ativar ou desativar a variacao', example: true),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Variacao atualizada com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 305),
                                new OA\Property(property: 'product_id', type: 'integer', example: 101),
                                new OA\Property(property: 'sku', type: 'string', example: 'CAMP-001-P-V2'),
                                new OA\Property(property: 'name', type: 'string', example: 'Camiseta Polo P - Versao 2'),
                                new OA\Property(property: 'price', type: 'number', example: 49.90),
                                new OA\Property(property: 'cost', type: 'number', example: 34.00),
                                new OA\Property(property: 'is_active', type: 'boolean', example: true),
                                new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2024-04-10T08:30:00Z'),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente ou invalido',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.')]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Variacao nao encontrada para este produto',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'No query results for model [ProductVariation] 99')]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Dados invalidos ou SKU duplicado',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'The sku has already been taken.'),
                        new OA\Property(property: 'errors', type: 'object'),
                    ]
                )
            ),
        ]
    )]
    public function updateVariation(Request $request, int $id, int $vid)
    {
        $variation = ProductVariation::where('product_id', $id)->findOrFail($vid);

        $data = $request->validate([
            'sku'        => "sometimes|string|max:100|unique:product_variations,sku,{$vid}",
            'name'       => 'sometimes|string|max:255',
            'price'      => 'sometimes|numeric|min:0',
            'cost'       => 'sometimes|numeric|min:0',
            'gtin'       => 'sometimes|nullable|string|max:14',
            'attributes' => 'sometimes|nullable|array',
            'position'   => 'sometimes|integer|min:0',
            'is_active'  => 'sometimes|boolean',
        ]);

        $variation->update($data);

        return response()->json(['data' => $variation->fresh()]);
    }

    #[OA\Delete(
        path: '/api/v1/products/{id}/variations/{vid}',
        summary: 'Remover variacao de um produto',
        description: 'Exclui permanentemente uma variacao de um produto. Atencao: se a variacao estiver em uso em pedidos ativos, a remocao pode causar inconsistencias. Certifique-se de desativar a variacao antes de remover em producao.',
        tags: ['Produtos - Variacoes'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'ID do produto base',
                schema: new OA\Schema(type: 'integer', example: 101)
            ),
            new OA\Parameter(
                name: 'vid',
                in: 'path',
                required: true,
                description: 'ID da variacao a remover',
                schema: new OA\Schema(type: 'integer', example: 305)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Variacao removida com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Variacao removida com sucesso.'),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente ou invalido',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.')]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Variacao nao encontrada para este produto',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'No query results for model [ProductVariation] 99')]
                )
            ),
        ]
    )]
    public function deleteVariation(int $id, int $vid)
    {
        $variation = ProductVariation::where('product_id', $id)->findOrFail($vid);
        $variation->delete();

        return response()->json(['message' => 'Variacao removida com sucesso.']);
    }


    // =========================================================================
    // IA - Geracao de Conteudo
    // =========================================================================

    #[OA\Post(
        path: "/api/v1/products/{id}/generate-title",
        summary: "Gerar titulo otimizado com IA",
        description: "Gera titulo de anuncio otimizado para SEO no marketplace do ClientProduct. Respeita limite de chars da plataforma (Shopee: 120, ML: 60). Aceita instrucoes customizadas.",
        tags: ["IA - Geracao de Conteudo"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(
                name: "id", in: "path", required: true,
                description: "ID do ClientProduct",
                schema: new OA\Schema(type: "integer", example: 200)
            ),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "instructions", type: "string", nullable: true,
                        description: "Instrucoes extras para guiar a geracao", example: "foco em preco baixo"),
                    new OA\Property(property: "platform", type: "string", nullable: true,
                        description: "Plataforma alvo", example: "shopee"),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Titulo gerado com sucesso",
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: "title", type: "string", example: "Smartphone Samsung Galaxy A55 256GB"),
                    new OA\Property(property: "chars", type: "integer", example: 35),
                ])
            ),
            new OA\Response(response: 401, description: "Token ausente ou invalido",
                content: new OA\JsonContent(properties: [new OA\Property(property: "message", type: "string", example: "Unauthenticated.")])
            ),
            new OA\Response(response: 403, description: "Produto nao pertence ao lojista",
                content: new OA\JsonContent(properties: [new OA\Property(property: "message", type: "string", example: "Proibido.")])
            ),
            new OA\Response(response: 404, description: "ClientProduct nao encontrado",
                content: new OA\JsonContent(properties: [new OA\Property(property: "message", type: "string", example: "No query results for model [ClientProduct] 999")])
            ),
        ]
    )]
    public function generateTitle(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $client = $this->clientOrFail($request);
        $cp     = ClientProduct::where('client_id', $client->id)->with('product')->findOrFail($id);

        // Banco de conteudo: clicou = pega do BANCO (instantaneo, sem custo, sem
        // erro). So gera ao vivo se o banco estiver vazio pra este produto.
        // Guard hasTable: os outros backends do repo compartilhado que nao tem a
        // tabela caem direto no fluxo ao vivo original (zero regressao).
        if (\Schema::hasTable('product_content_bank') && $cp->product
            && ! $request->boolean('force_live')) {
            $bankEntry = app(\App\Services\ProductContentBankService::class)->serve($cp->product);
            if ($bankEntry) {
                // Grava titulo+descricao juntos (mesmo registro do banco) pra
                // generateDescription() nao consumir um segundo registro
                // desalinhado.
                $cp->update([
                    'custom_title'       => $bankEntry['title'],
                    'custom_description' => $bankEntry['description'] ?: $cp->custom_description,
                ]);
                return response()->json(['title' => $bankEntry['title'], 'chars' => mb_strlen($bankEntry['title']), 'source' => 'bank']);
            }
        }

        try {
            $title = app(\App\Services\AIProductContentService::class)
                ->generateTitleForClientProduct($cp, $request->input('instructions'));
        } catch (\Throwable $e) {
            // IA ao vivo indisponivel: NUNCA mostra erro ao cliente -- devolve o
            // titulo/nome atual do produto (degradacao graciosa).
            \Log::warning('generateTitle fallback gracioso: '.$e->getMessage());
            $title = $cp->custom_title ?: ($cp->product->name ?? 'Produto');
        }

        $cp->update(['custom_title' => $title]);

        return response()->json(['title' => $title, 'chars' => mb_strlen($title)]);
    }

    #[OA\Post(
        path: "/api/v1/products/{id}/generate-description",
        summary: "Gerar descricao persuasiva com IA",
        description: "Gera descricao de anuncio em texto puro, persuasiva e otimizada para o marketplace. Maximo de 3000 caracteres.",
        tags: ["IA - Geracao de Conteudo"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(
                name: "id", in: "path", required: true,
                description: "ID do ClientProduct",
                schema: new OA\Schema(type: "integer", example: 200)
            ),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "instructions", type: "string", nullable: true,
                        description: "Instrucoes extras para guiar a geracao", example: "destaque garantia de 1 ano"),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Descricao gerada com sucesso",
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: "description", type: "string", example: "Produto de alta qualidade..."),
                    new OA\Property(property: "chars", type: "integer", example: 1840),
                ])
            ),
            new OA\Response(response: 401, description: "Token ausente ou invalido",
                content: new OA\JsonContent(properties: [new OA\Property(property: "message", type: "string", example: "Unauthenticated.")])
            ),
            new OA\Response(response: 403, description: "Produto nao pertence ao lojista",
                content: new OA\JsonContent(properties: [new OA\Property(property: "message", type: "string", example: "Proibido.")])
            ),
            new OA\Response(response: 404, description: "ClientProduct nao encontrado",
                content: new OA\JsonContent(properties: [new OA\Property(property: "message", type: "string", example: "No query results for model [ClientProduct] 999")])
            ),
        ]
    )]
    public function generateDescription(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $client = $this->clientOrFail($request);
        $cp     = ClientProduct::where('client_id', $client->id)->with('product')->findOrFail($id);

        // Banco de conteudo (guard hasTable: outros backends caem no fluxo antigo).
        if (\Schema::hasTable('product_content_bank') && ! $request->boolean('force_live')) {
            // Se generateTitle() ja serviu do banco pra este ClientProduct, o
            // custom_description ja veio junto (mesmo registro, pareado) -- so
            // devolve, sem consumir um segundo registro desalinhado.
            if (! empty($cp->custom_description)) {
                return response()->json([
                    'description' => $cp->custom_description,
                    'chars'       => mb_strlen($cp->custom_description),
                    'source'      => 'bank',
                ]);
            }
            if ($cp->product) {
                $bankEntry = app(\App\Services\ProductContentBankService::class)->serve($cp->product);
                if ($bankEntry) {
                    $cp->update([
                        'custom_description' => $bankEntry['description'],
                        'custom_title'       => $cp->custom_title ?: $bankEntry['title'],
                    ]);
                    return response()->json(['description' => $bankEntry['description'], 'chars' => mb_strlen($bankEntry['description']), 'source' => 'bank']);
                }
            }
        }

        try {
            $desc = app(\App\Services\AIProductContentService::class)
                ->generateDescriptionForClientProduct($cp, $request->input('instructions'));
        } catch (\Throwable $e) {
            // Degradacao graciosa: nunca mostra erro -- devolve descricao/nome atual.
            \Log::warning('generateDescription fallback gracioso: '.$e->getMessage());
            $desc = $cp->custom_description ?: ($cp->product->name ?? '');
        }

        return response()->json(['description' => $desc, 'chars' => mb_strlen($desc)]);
    }

    #[OA\Post(
        path: "/api/v1/products/{id}/generate-bullets",
        summary: "Gerar bullet points de beneficios com IA",
        description: "Gera 5 bullet points concisos e persuasivos destacando os principais beneficios do produto.",
        tags: ["IA - Geracao de Conteudo"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(
                name: "id", in: "path", required: true,
                description: "ID do ClientProduct",
                schema: new OA\Schema(type: "integer", example: 200)
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: "Bullets gerados com sucesso",
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: "bullets", type: "array",
                        items: new OA\Items(type: "string"),
                        example: ["Bateria de longa duracao", "Camera de alta resolucao", "Design ergonomico", "Resistente a agua", "Processador rapido"]
                    ),
                ])
            ),
            new OA\Response(response: 401, description: "Token ausente ou invalido",
                content: new OA\JsonContent(properties: [new OA\Property(property: "message", type: "string", example: "Unauthenticated.")])
            ),
            new OA\Response(response: 403, description: "Produto nao pertence ao lojista",
                content: new OA\JsonContent(properties: [new OA\Property(property: "message", type: "string", example: "Proibido.")])
            ),
            new OA\Response(response: 404, description: "ClientProduct nao encontrado",
                content: new OA\JsonContent(properties: [new OA\Property(property: "message", type: "string", example: "No query results for model [ClientProduct] 999")])
            ),
        ]
    )]
    public function generateBullets(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $client = $this->clientOrFail($request);
        $cp     = ClientProduct::where('client_id', $client->id)->with('product')->findOrFail($id);

        // Banco de conteudo primeiro (guard hasTable: outros backends caem no
        // fluxo antigo). Best-effort -- nao ha coluna de pareamento, serve solto.
        if (\Schema::hasTable('product_content_bank') && $cp->product
            && ! $request->boolean('force_live')) {
            $bankEntry = app(\App\Services\ProductContentBankService::class)->serve($cp->product);
            if ($bankEntry && ! empty($bankEntry['bullet_points'])) {
                return response()->json(['bullets' => $bankEntry['bullet_points'], 'source' => 'bank']);
            }
        }

        try {
            $bullets = app(\App\Services\AIProductContentService::class)
                ->generateBulletPoints($cp->product);
        } catch (\Throwable $e) {
            // Degradacao graciosa: nunca mostra erro -- devolve lista vazia.
            \Log::warning('generateBullets fallback gracioso: '.$e->getMessage());
            $bullets = [];
        }

        return response()->json(['bullets' => $bullets]);
    }

    #[OA\Post(
        path: "/api/v1/products/{id}/suggest-category",
        summary: "Sugerir categoria do marketplace com IA",
        description: "Sugere a categoria mais adequada do marketplace para o produto. Retorna formato hierarquico do Mercado Livre.",
        tags: ["IA - Geracao de Conteudo"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(
                name: "id", in: "path", required: true,
                description: "ID do ClientProduct",
                schema: new OA\Schema(type: "integer", example: 200)
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: "Categoria sugerida com sucesso",
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: "category", type: "string", example: "Eletronicos > Celulares e Smartphones"),
                ])
            ),
            new OA\Response(response: 401, description: "Token ausente ou invalido",
                content: new OA\JsonContent(properties: [new OA\Property(property: "message", type: "string", example: "Unauthenticated.")])
            ),
            new OA\Response(response: 403, description: "Produto nao pertence ao lojista",
                content: new OA\JsonContent(properties: [new OA\Property(property: "message", type: "string", example: "Proibido.")])
            ),
            new OA\Response(response: 404, description: "ClientProduct nao encontrado",
                content: new OA\JsonContent(properties: [new OA\Property(property: "message", type: "string", example: "No query results for model [ClientProduct] 999")])
            ),
        ]
    )]
    public function suggestCategory(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $client = $this->clientOrFail($request);
        $cp     = ClientProduct::where('client_id', $client->id)->with('product')->findOrFail($id);

        $category = app(\App\Services\AIProductContentService::class)
            ->suggestCategory($cp->product);

        return response()->json(['category' => $category]);
    }

    // =========================================================================
    // GET /api/v1/client/my-products
    // Dashboard: lista produtos do lojista com scores de qualidade e status por marketplace
    // =========================================================================

    public function myProducts(Request $request): \Illuminate\Http\JsonResponse
    {
        $client = $this->clientOrFail($request);

        $perPage = (int) $request->input('per_page', 15);
        $search  = $request->input('search');
        $status  = $request->input('sync_status');

        $query = ClientProduct::with([
            'product',
            'product.media',
            'marketplaceAccount',
        ])
            ->where('client_id', $client->id)
            ->where('excluido', false)
            ->orderByDesc('updated_at');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('custom_title', 'like', "%{$search}%")
                  ->orWhere('custom_sku', 'like', "%{$search}%");
            });
        }

        if ($status) {
            $query->where('sync_status', $status);
        }

        $items = $query->paginate($perPage);

        $qualityService = app(ProductQualityService::class);

        $data = $items->getCollection()->map(function (ClientProduct $cp) use ($qualityService) {
            $product = $cp->product;

            // Imagens (custom primeiro, depois do produto)
            $images = [];
            if (!empty($cp->custom_images)) {
                $images = $cp->custom_images;
            } elseif ($product) {
                $images = $product->media()
                    ->where('type', 'image')
                    ->orderBy('sort_order')
                    ->take(5)
                    ->pluck('url')
                    ->toArray();
            }

            // Quality score do client_product
            $qualityResult = $qualityService->calculateClientProductScore($cp);
            $qualityScore  = $qualityResult['score'];
            $qualityIssues = $qualityResult['issues'];

            // Persiste o score calculado
            $cp->update([
                'listing_quality_score'  => $qualityScore,
                'listing_quality_issues' => $qualityIssues,
            ]);

            // Info do marketplace
            $marketplaceInfo = null;
            if ($cp->marketplaceAccount) {
                $acc = $cp->marketplaceAccount;
                $marketplaceInfo = [
                    'id'                  => $acc->id,
                    'platform'            => $acc->platform,
                    'account_name'        => $acc->account_name,
                    'status'              => $acc->status,
                    'listing_status'      => $cp->listing_status,
                    'sync_status'         => $cp->sync_status,
                    'external_listing_id' => $cp->external_listing_id,
                    'external_listing_url'=> $cp->external_listing_url,
                    'last_sync_at'        => $cp->last_sync_at?->toISOString(),
                    'last_sync_error'     => $cp->last_sync_error,
                    'quality_score'       => $qualityScore,
                    'quality_label'       => ProductQualityService::scoreLabel($qualityScore),
                    'quality_issues'      => $qualityIssues,
                ];
            }

            return [
                'id'               => $cp->id,
                'product_id'       => $cp->product_id,
                'custom_sku'       => $cp->custom_sku,
                'title'            => $cp->custom_title ?: $product?->name,
                'price'            => (float) $cp->custom_price,
                'brand'            => $cp->custom_brand ?: $product?->brand,
                'condition'        => $cp->custom_condition ?: $product?->condition,
                'images'           => $images,
                'quality_score'    => $qualityScore,
                'quality_label'    => ProductQualityService::scoreLabel($qualityScore),
                'quality_issues'   => $qualityIssues,
                'marketplace'      => $marketplaceInfo,
                // Campos do produto base (para referencia)
                'product'          => $product ? [
                    'id'                  => $product->id,
                    'name'               => $product->name,
                    'sku'                => $product->sku,
                    'quality_score_ml'   => $product->quality_score_ml,
                    'quality_score_shopee'=> $product->quality_score_shopee,
                    'ml_category_id'     => $product->ml_category_id,
                    'ml_attributes'      => $product->ml_attributes,
                ] : null,
                'is_active'        => (bool) $cp->is_active,
                'created_at'       => $cp->created_at?->toISOString(),
                'updated_at'       => $cp->updated_at?->toISOString(),
            ];
        });

        return response()->json([
            'data'        => $data,
            'total'       => $items->total(),
            'per_page'    => $items->perPage(),
            'current_page'=> $items->currentPage(),
            'last_page'   => $items->lastPage(),
        ]);
    }

    // =========================================================================
    // POST /api/v1/products/{id}/auto-fill-attributes
    // Dispara AutoFillProductAttributesJob para 1 produto
    // =========================================================================

    public function autoFillAttributes(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $client = $this->clientOrFail($request);
        $cp     = ClientProduct::where('client_id', $client->id)->with('product')->findOrFail($id);

        if (!$cp->product) {
            return response()->json(['message' => 'Produto base nao encontrado.'], 404);
        }

        AutoFillProductAttributesJob::dispatch($cp->product_id);

        return response()->json([
            'message'    => 'Job de auto-preenchimento de atributos enfileirado.',
            'product_id' => $cp->product_id,
        ]);
    }
    // =========================================================================
    // MUL-100: GET /api/v1/categories
    // Lista todas as categorias disponíveis do supplier local com contagem de produtos.
    // Retorna todas as categorias (com ou sem produtos categorizados), pois category_id
    // nos produtos pode ser NULL mesmo com categorias existentes na tabela.
    // =========================================================================
    public function categories(Request $request): \Illuminate\Http\JsonResponse
    {
        $supplierId = (int) config('app.local_supplier_id', env('LOCAL_SUPPLIER_ID', 1));

        $categories = \DB::table('categories')
            ->leftJoin('products', function ($join) use ($supplierId) {
                $join->on('products.category_id', '=', 'categories.id')
                     ->where('products.supplier_id', $supplierId)
                     ->where('products.is_active', 1);
            })
            ->where(function ($q) use ($supplierId) {
                $q->where('categories.supplier_id', $supplierId)
                  ->orWhereNull('categories.supplier_id');
            })
            ->select(
                'categories.id',
                'categories.name',
                'categories.parent_id',
                \DB::raw('COUNT(products.id) as product_count')
            )
            ->groupBy('categories.id', 'categories.name', 'categories.parent_id')
            ->orderBy('categories.name')
            ->get();

        return response()->json(['data' => $categories]);
    }

    // =========================================================================
    // MUL-119: GET /api/v1/client/products-grouped-by-sku
    // Agrupa client_products do lojista por SKU base do produto.
    // Cada linha representa 1 produto com badges de todos os marketplaces onde está publicado.
    // =========================================================================
    public function groupedBySku(Request $request): \Illuminate\Http\JsonResponse
    {
        $client = $this->clientOrFail($request);
        $search = $request->input('search');
        $perPage = (int) $request->input('per_page', 15);

        // Buscar todos os client_products ativos do lojista com produto base e conta de marketplace
        $query = ClientProduct::with([
            'product:id,sku,name,price,supplier_id',
            'product.media' => function ($q) {
                $q->where('is_cover', 1)->select('id', 'product_id', 'url');
            },
            'marketplaceAccount:id,platform,account_name,status',
        ])
            ->where('client_id', $client->id)
            ->where('excluido', false);
        // MUL-360 item 6: anuncio SEM vinculo de catalogo (product_id null) tambem entra
        // na visao agrupada — antes 61 anuncios sumiam da tela (12 so da Conecte).

        if ($search) {
            // MUL-214 item 14: busca resolve product_ids primeiro para nao quebrar o
            // agrupamento — se so 1 conta casa com o custom_sku, as outras contas do
            // mesmo produto sumiam do grupo
            $matching = ClientProduct::where('client_id', $client->id)
                ->where('excluido', false)
                ->where(function ($q) use ($search) {
                    $q->where('custom_sku', 'like', "%{$search}%")
                      ->orWhere('custom_title', 'like', "%{$search}%")
                      ->orWhereHas('product', function ($p) use ($search) {
                          $p->where('sku', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%");
                      });
                })
                ->get(['id', 'product_id']);
            $matchingPids = $matching->pluck('product_id')->filter()->unique();
            $matchingCpIds = $matching->whereNull('product_id')->pluck('id');
            $query->where(function ($q) use ($matchingPids, $matchingCpIds) {
                $q->whereIn('product_id', $matchingPids)
                  ->orWhereIn('id', $matchingCpIds);
            });
        }

        // Paginação baseada em SKU único do produto base
        $allItems = $query->get();

        // Agrupar por produto base; sem produto, agrupa pelo proprio SKU do anuncio
        // (MUL-360 item 6) — mesmo SKU em contas diferentes vira um grupo so.
        $grouped = $allItems->groupBy(function ($cp) {
            if ($cp->product_id) { return 'p:' . $cp->product_id; }
            $sku = trim((string) ($cp->custom_sku ?? ''));
            return $sku !== '' ? 's:' . mb_strtolower($sku) : 'cp:' . $cp->id;
        })->map(function ($items) {
            $first   = $items->first();
            $product = $first->product;

            $coverUrl = null;
            if ($product && $product->media && $product->media->isNotEmpty()) {
                $coverUrl = $product->media->first()->url;
            }

            $marketplaces = $items->map(function ($cp) {
                $acc = $cp->marketplaceAccount;
                return [
                    'client_product_id'    => $cp->id,
                    'platform'             => $acc?->platform,
                    'account_name'         => $acc?->account_name,
                    'account_status'       => $acc?->status,
                    'price'                => (float) $cp->custom_price,
                    'sync_status'          => $cp->sync_status,
                    'listing_status'       => $cp->listing_status,
                    'external_listing_id'  => $cp->external_listing_id,
                    'external_listing_url' => $cp->external_listing_url,
                    'last_sync_at'         => $cp->last_sync_at?->toISOString(),
                    'last_sync_error'      => $cp->last_sync_error,
                ];
            })->values()->toArray();

            // MUL-227 item 3: quando product.price nao esta cadastrado, cai no menor custom_price das
            // contas vinculadas para nao exibir "—" no /meus-produtos (custo interno do fornecedor
            // continua nao vazando — apenas usamos o preco publicado pelo proprio seller como base).
            $catalogPrice = (float) ($product?->price ?? 0);
            if ($catalogPrice <= 0) {
                $catalogPrice = (float) ($items->map(fn ($cp) => (float) $cp->custom_price)
                    ->filter(fn ($v) => $v > 0)
                    ->min() ?? 0);
            }

            return [
                'product_id'   => $product?->id,
                'sku'          => $product?->sku ?? $first->custom_sku,
                'title'        => $first->custom_title ?? $product?->name,
                'cost'         => $catalogPrice,
                'cover_url'    => $coverUrl,
                'marketplaces' => $marketplaces,
                'marketplace_count' => count($marketplaces),
            ];
        })->values();

        // Paginação manual
        $page    = max(1, (int) $request->input('page', 1));
        $offset  = ($page - 1) * $perPage;
        $total   = $grouped->count();
        $items   = $grouped->slice($offset, $perPage)->values();

        return response()->json([
            'data' => $items,
            'meta' => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => (int) ceil($total / max(1, $perPage)),
            ],
        ]);
    }

    // =========================================================================
    // MUL-115: PATCH /api/v1/client-products/{cpId}/price
    // Atualiza o preço de um ClientProduct específico (por marketplace).
    // Lojista pode ter o mesmo produto em ML e Shopee com preços diferentes —
    // cada ClientProduct representa 1 anúncio em 1 conta de marketplace.
    // =========================================================================
    public function updateClientProductPrice(Request $request, int $cpId): \Illuminate\Http\JsonResponse
    {
        $client = $this->clientOrFail($request);

        $cp = ClientProduct::where('client_id', $client->id)
            ->where('excluido', false)
            ->findOrFail($cpId);

        $data = $request->validate([
            'price' => 'required|numeric|min:0.01|max:99999.99',
        ]);

        $oldPrice = (float) $cp->custom_price;
        $cp->update(['custom_price' => $data['price']]);

        // Se o anúncio está publicado em marketplace, enfileira sync de preço
        if ($cp->marketplace_account_id && $cp->external_listing_id && $cp->listing_status === 'active') {
            $cp->update([
                'sync_status'  => 'pending',
                'last_sync_at' => null,
            ]);
            // ApplyRepricingPriceJob usada para push de preço ao marketplace
            if (class_exists(\App\Jobs\ApplyRepricingPriceJob::class)) {
                \App\Jobs\ApplyRepricingPriceJob::dispatch($cp->id, (float) $data['price']);
            }
        }

        return response()->json([
            'message'   => 'Preco atualizado com sucesso.',
            'data'      => [
                'client_product_id' => $cp->id,
                'old_price'         => $oldPrice,
                'new_price'         => (float) $cp->custom_price,
                'sync_queued'       => $cp->sync_status === 'pending',
            ],
        ]);
    }

    /**
     * MUL-142-H: Upload de video local para produto (mp4/webm, max 50MB).
     *
     * POST /api/v1/products/{id}/upload-video
     * Salva no storage local (padrao MUL-137) e registra em product_media type=video.
     */
    public function uploadVideo(Request $request, int $id)
    {
        $product = Product::findOrFail($id);

        $request->validate([
            "video" => "required|file|mimes:mp4,webm|max:51200",
        ]);

        $file       = $request->file("video");
        $supplierId = (int) $product->supplier_id;
        $path       = $file->store("products/{$supplierId}/{$id}/videos", "public");
        $url        = Storage::disk("public")->url($path);

        $media = ProductMedia::updateOrCreate(
            ["product_id" => $id, "type" => "video"],
            [
                "url"        => $url,
                "local_path" => $path,
                "path"       => $path,
                "position"   => 0,
                "is_cover"   => false,
            ]
        );

        // Atualiza video_url no produto para compatibilidade com campo legado
        $product->update(["video_url" => $url]);

        return response()->json(["data" => ["video_url" => $url, "media_id" => $media->id]], 201);
    }
}
