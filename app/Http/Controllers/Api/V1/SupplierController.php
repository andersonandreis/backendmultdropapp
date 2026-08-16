<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Subscription;
use App\Services\CurrentTenant;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class SupplierController extends Controller
{
    /**
     * Retorna os IDs de supplier visíveis ao lojista autenticado.
     *
     * FOR-029: corrigido para usar tenant_supplier em vez de plan_supplier.
     * O tenant é resolvido pelo CurrentTenant service (header X-Tenant-Slug OU
     * legacy_empresa_id do client). Quando nao há tenant configurado (hubai nativo
     * ou default_supplier_visibility=all), retorna null sinalizando acesso irrestrito.
     *
     * @return int[]|null  null = sem filtro (ve todos os ativos); array = IDs permitidos
     */
    private function getTenantSupplierIds(): ?array
    {
        $currentTenant = app(CurrentTenant::class);
        $tenant = $currentTenant->tenant();

        // Sem tenant (super_admin, CLI) ou visibilidade all -> sem filtro
        if ($tenant === null || $tenant->default_supplier_visibility === "all") {
            return null;
        }

        // Tenant scoped -> retorna apenas os supplier_ids vinculados via tenant_supplier
        $ids = $currentTenant->supplierIds();

        // MUL-219: suppliers privados do proprio client tambem sao visiveis a ele
        $client = auth()->user()?->client;
        if ($client) {
            $private = Supplier::where('is_private', true)
                ->where('owner_client_id', $client->id)
                ->pluck('id')
                ->all();
            $ids = array_values(array_unique(array_merge($ids, $private)));
        }

        return $ids;
    }

    #[OA\Get(
        path: '/api/v1/suppliers',
        summary: 'Listar fornecedores disponiveis para o plano do lojista',
        description: 'Retorna os fornecedores que o lojista autenticado pode acessar com base no seu plano ativo. Se o lojista nao tiver plano ativo, retorna apenas fornecedores publicos (is_private=false). Fornecedores privados so aparecem se estiverem explicitamente vinculados ao plano do lojista.',
        tags: ['Fornecedores'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'per_page',
                in: 'query',
                required: false,
                description: 'Quantidade de registros por pagina',
                schema: new OA\Schema(type: 'integer', default: 15, example: 15)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista de fornecedores paginada',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 3),
                                    new OA\Property(
                                        property: 'company_name',
                                        type: 'string',
                                        description: 'Razao social do fornecedor',
                                        example: 'Fornecedor XPTO LTDA'
                                    ),
                                    new OA\Property(
                                        property: 'display_name',
                                        type: 'string',
                                        description: 'Nome de exibicao (nome fantasia)',
                                        example: 'XPTO'
                                    ),
                                    new OA\Property(
                                        property: 'logo',
                                        type: 'string',
                                        nullable: true,
                                        description: 'URL do logotipo do fornecedor',
                                        example: 'https://cdn.hubai.io/logos/xpto.png'
                                    ),
                                    new OA\Property(
                                        property: 'description',
                                        type: 'string',
                                        nullable: true,
                                        description: 'Descricao do fornecedor',
                                        example: 'Especialista em eletronicos'
                                    ),
                                    new OA\Property(property: 'city', type: 'string', nullable: true, example: 'Sao Paulo'),
                                    new OA\Property(property: 'state', type: 'string', nullable: true, example: 'SP'),
                                    new OA\Property(
                                        property: 'allows_direct_payment',
                                        type: 'boolean',
                                        description: 'Indica se o fornecedor aceita pagamento direto pelo lojista',
                                        example: true
                                    ),
                                    new OA\Property(
                                        property: 'is_factory',
                                        type: 'boolean',
                                        description: 'Indica se o fornecedor e uma fabrica (nao revenda)',
                                        example: false
                                    ),
                                ]
                            )
                        ),
                        new OA\Property(
                            property: 'meta',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'total', type: 'integer', example: 12),
                                new OA\Property(property: 'per_page', type: 'integer', example: 15),
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'last_page', type: 'integer', example: 1),
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
        $perPage     = (int) $request->query('per_page', 15);
        // FOR-029: supplier visibility controlled by tenant_supplier, not plan_supplier
        $tenantSupplierIds = $this->getTenantSupplierIds();

        if ($tenantSupplierIds === null) {
            // No tenant context (hubai native, super_admin) -> show all active suppliers
            $query = Supplier::where("is_active", true);
        } elseif (empty($tenantSupplierIds)) {
            // Tenant scoped but no suppliers linked yet -> empty result
            $query = Supplier::where("is_active", true)->whereRaw("1 = 0");
        } else {
            $query = Supplier::whereIn("id", $tenantSupplierIds)->where("is_active", true);
        }

        // MUL-219: supplier privado so aparece pro proprio dono (paridade SupplierCatalog)
        $clientId = $request->user()?->client?->id;
        $query->where(function ($q) use ($clientId) {
            $q->where('is_private', false);
            if ($clientId) {
                $q->orWhere(function ($qq) use ($clientId) {
                    $qq->where('is_private', true)->where('owner_client_id', $clientId);
                });
            }
        });

        $paginator = $query->select([
            'id', 'company_name', 'display_name', 'logo', 'description',
            'city', 'state', 'allows_direct_payment', 'is_factory',
        ])->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

        #[OA\Get(
        path: '/api/v1/suppliers/{id}/catalog',
        summary: 'Catalogo de produtos de um fornecedor especifico',
        description: 'Retorna a lista paginada de produtos ativos de um fornecedor. Se o fornecedor for privado (is_private=true), o lojista deve ter o fornecedor no seu plano ativo para acessar o catalogo. Suporta busca por nome, SKU ou EAN; filtros por marca, categoria, faixa de preco e disponibilidade em estoque; e ordenacao por preco, nome, data de criacao ou estoque.',
        tags: ['Fornecedores'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'ID do fornecedor',
                schema: new OA\Schema(type: 'integer', example: 3)
            ),
            new OA\Parameter(
                name: 'per_page',
                in: 'query',
                required: false,
                description: 'Quantidade de produtos por pagina',
                schema: new OA\Schema(type: 'integer', default: 15, example: 15)
            ),
            new OA\Parameter(
                name: 'search',
                in: 'query',
                required: false,
                description: 'Busca por nome, SKU ou EAN do produto',
                schema: new OA\Schema(type: 'string', example: 'camiseta')
            ),
            new OA\Parameter(
                name: 'brand',
                in: 'query',
                required: false,
                description: 'Filtro exato por marca do produto',
                schema: new OA\Schema(type: 'string', example: 'Nike')
            ),
            new OA\Parameter(
                name: 'category_id',
                in: 'query',
                required: false,
                description: 'Filtro por ID de categoria',
                schema: new OA\Schema(type: 'integer', example: 5)
            ),
            new OA\Parameter(
                name: 'price_min',
                in: 'query',
                required: false,
                description: 'Preco minimo (R$) para filtragem',
                schema: new OA\Schema(type: 'number', example: 10.00)
            ),
            new OA\Parameter(
                name: 'price_max',
                in: 'query',
                required: false,
                description: 'Preco maximo (R$) para filtragem',
                schema: new OA\Schema(type: 'number', example: 500.00)
            ),
            new OA\Parameter(
                name: 'in_stock',
                in: 'query',
                required: false,
                description: 'Filtrar apenas produtos com estoque disponivel (1 = sim)',
                schema: new OA\Schema(type: 'integer', enum: [0, 1], example: 1)
            ),
            new OA\Parameter(
                name: 'sort',
                in: 'query',
                required: false,
                description: 'Ordenacao dos resultados. Prefixo - indica decrescente.',
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['price', '-price', 'name', '-name', 'created_at', '-created_at', 'stock', '-stock'],
                    example: '-price'
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Catalogo de produtos do fornecedor paginado',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 101),
                                    new OA\Property(property: 'supplier_id', type: 'integer', example: 3),
                                    new OA\Property(property: 'name', type: 'string', description: 'Nome do produto', example: 'Camiseta Polo Masculina'),
                                    new OA\Property(property: 'sku', type: 'string', description: 'SKU do produto no fornecedor', example: 'CAMP-001-P'),
                                    new OA\Property(property: 'price', type: 'number', description: 'Preco de custo (R$)', example: 45.90),
                                    new OA\Property(property: 'brand', type: 'string', nullable: true, description: 'Marca do produto', example: 'Nike'),
                                    new OA\Property(property: 'ean', type: 'string', nullable: true, description: 'Codigo EAN/GTIN', example: '7891234567890'),
                                    new OA\Property(property: 'weight_kg', type: 'number', nullable: true, description: 'Peso em quilogramas', example: 0.350),
                                    new OA\Property(property: 'description', type: 'string', nullable: true, description: 'Descricao completa do produto', example: 'Camiseta polo 100% algodao, tamanho P'),
                                    new OA\Property(property: 'stock', type: 'integer', description: 'Quantidade total em estoque', example: 150),
                                    new OA\Property(
                                        property: 'media',
                                        type: 'array',
                                        description: 'Imagens e midias do produto',
                                        items: new OA\Items(
                                            properties: [
                                                new OA\Property(property: 'id', type: 'integer', example: 55),
                                                new OA\Property(property: 'url', type: 'string', example: 'https://cdn.hubai.io/products/camiseta.jpg'),
                                                new OA\Property(property: 'type', type: 'string', example: 'image'),
                                            ]
                                        )
                                    ),
                                ]
                            )
                        ),
                        new OA\Property(
                            property: 'meta',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'total', type: 'integer', example: 350),
                                new OA\Property(property: 'per_page', type: 'integer', example: 15),
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'last_page', type: 'integer', example: 24),
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
                description: 'Fornecedor privado nao disponivel no plano do lojista',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Fornecedor nao disponivel no seu plano.')]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Fornecedor nao encontrado ou inativo',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'No query results for model [Supplier] 99')]
                )
            ),
        ]
    )]
    public function catalog(Request $request, int $id)
    {
        $supplier = Supplier::where('is_active', true)->findOrFail($id);

        // MUL-099: acesso ao catalogo sempre verificado via tenant_supplier,
        // independente de is_private. Suppliers publicos nao devem ser acessiveis
        // a tenants que nao os tem vinculados em tenant_supplier.
        // null = sem tenant (hubai native, super_admin) = acesso irrestrito.
        $tenantSupplierIds = $this->getTenantSupplierIds();
        if ($tenantSupplierIds !== null && ! in_array($id, $tenantSupplierIds)) {
            return response()->json(["message" => "Fornecedor nao disponivel para o seu tenant."], 403);
        }

        // MUL-219: supplier privado so acessivel ao proprio dono
        if ($supplier->is_private && $supplier->owner_client_id !== $request->user()?->client?->id) {
            return response()->json(["message" => "Fornecedor nao disponivel para o seu tenant."], 403);
        }

        // IDs dos produtos que o cliente ja importou (campo is_imported)
        $client = $request->user()->client;
        $importedProductIds = [];
        if ($client) {
            $importedProductIds = \DB::table('client_products')
                ->where('client_id', $client->id)
                ->where('excluido', 0)
                ->whereNotNull('product_id')
                ->pluck('product_id')
                ->toArray();
        }
        $onlyNew = (string) $request->query('only_new') === '1';
        $onlyImported = (string) $request->query('only_imported') === '1';

        $perPage    = (int) $request->query('per_page', 15);
        $search     = $request->query('search');
        $brand      = $request->query('brand');
        $categoryId = $request->query('category_id');
        $priceMin   = $request->query('price_min');
        $priceMax   = $request->query('price_max');
        $inStock    = $request->query('in_stock');
        $sort       = $request->query('sort', '-created_at'); // MUL-140

        $query = Product::where('products.supplier_id', $id)
            ->where('products.is_active', true)
            ->with([
                'media' => function ($q) { $q->select('id', 'product_id', 'url', 'type', 'position', 'is_cover')->orderBy('position')->orderBy('id'); },
                'inventory:id,product_id,quantity',
            ]);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('ean', 'like', "%{$search}%");
            });
        }

        if ($brand) {
            $query->where('brand', $brand);
        }

        if ($categoryId) {
            $query->where('category_id', (int) $categoryId);
        }

        if ($priceMin !== null) {
            $query->where('price', '>=', (float) $priceMin);
        }

        if ($priceMax !== null) {
            $query->where('price', '<=', (float) $priceMax);
        }

        if ((string) $inStock === '1') {
            $query->whereHas('inventory', function ($q) {
                $q->where('quantity', '>', 0);
            });
        }

            if ($onlyNew && !empty($importedProductIds)) {
            $query->whereNotIn('products.id', $importedProductIds);
        }

        if ($onlyImported) {
            if (empty($importedProductIds)) {
                $query->whereRaw('1=0');
            } else {
                $query->whereIn('products.id', $importedProductIds);
            }
        }

    $sortMap = [
            'price'       => ['price', 'asc'],
            '-price'      => ['price', 'desc'],
            'name'        => ['name', 'asc'],
            '-name'       => ['name', 'desc'],
            'created_at'  => ['created_at', 'asc'],
            // MUL-140: -created_at cai no else (prioridade estoque)
        ];

        if (isset($sortMap[$sort])) {
            [$col, $dir] = $sortMap[$sort];
            $query->orderBy($col, $dir);
         } elseif (in_array($sort, ['stock', '-stock'])) {
             $direction = $sort === '-stock' ? 'desc' : 'asc';
             // MUL-140: subquery evita conflito com eager load inventory
             $stockSort = true;
             $query->selectRaw('products.*, (SELECT COALESCE(SUM(i.quantity),0) FROM inventory i WHERE i.product_id = products.id) as total_stock')
                   ->orderByRaw('total_stock ' . $direction);
         } else {
             // MUL-140: default e -created_at — com estoque na frente, depois mais recentes
             $query->orderByRaw('(EXISTS(SELECT 1 FROM inventory inv WHERE inv.product_id = products.id AND inv.quantity > 0)) DESC')
                   ->orderBy('products.created_at', 'desc');
         }

         $query->with(['category:id,name']);
         if (empty($stockSort)) {
             $query->select([
                 'products.id', 'products.supplier_id', 'products.name', 'products.sku', 'products.legacy_sku_pai_id', 'products.price', 'products.brand', 'products.ean',
                 'products.weight_kg', 'products.height_cm', 'products.width_cm', 'products.length_cm',
                 'products.category_id', 'products.description',
                 // MUL-360 item 7: 'origem' e a coluna preenchida (376 produtos); 'origin' e irma vazia
                 'products.ncm', 'products.origin', 'products.origem',
                 'products.inmetro', 'products.homologation_number', 'products.manufacturer',
             ]);
         }
         $paginator = $query->paginate($perPage);

        // MUL-253: SKU canonico do catalogo e D{id_deposito}-{sku} (legado sku_pai);
        // products.sku de parte dos fornecedores veio sem o prefixo do deposito
        $displaySkus = [];
        try {
            $needPrefix = $paginator->getCollection()
                ->filter(fn ($p) => $p->sku && ! preg_match('/^D\\d+-/', $p->sku) && ! empty($p->legacy_sku_pai_id));
            if ($needPrefix->isNotEmpty()) {
                $deps = \DB::connection('legacy')->table('sku_pai')
                    ->whereIn('id', $needPrefix->pluck('legacy_sku_pai_id')->unique()->all())
                    ->pluck('id_deposito', 'id');
                foreach ($needPrefix as $p) {
                    $dep = $deps[$p->legacy_sku_pai_id] ?? null;
                    if ($dep) {
                        $displaySkus[$p->id] = 'D' . $dep . '-' . $p->sku;
                    }
                }
            }
        } catch (\Throwable $e) {
            // legado indisponivel -> exibe sku puro
        }

        $items = $paginator->getCollection()->map(function ($product) use ($importedProductIds, $displaySkus) {
            return [
                'id'            => $product->id,
                'supplier_id'   => $product->supplier_id,
                'name'          => $product->name,
                'sku'           => $product->sku,
                'display_sku'   => $displaySkus[$product->id] ?? $product->sku,
                'price'         => $product->price,
                'brand'         => $product->brand,
                'ean'           => $product->ean,
                'weight_kg'     => $product->weight_kg,
                'height_cm'     => $product->height_cm,
                'width_cm'      => $product->width_cm,
                'length_cm'     => $product->length_cm,
                'category_id'   => $product->category_id,
                'category_name' => $product->category?->name,
                'description'   => $product->description,
                'stock'         => isset($product->total_stock) ? (int)$product->total_stock : $product->inventory->sum('quantity'), // MUL-140
                'is_imported'   => in_array($product->id, $importedProductIds),
                'imageUrl'      => ($product->media->firstWhere('is_cover', true) ?? $product->media->first())?->url,
                'cover_image'   => ($product->media->firstWhere('is_cover', true) ?? $product->media->first())?->url,
                'ncm'                 => $product->ncm,
                // MUL-360 item 7: a coluna real e `origem`; 'origin' lia atributo
                // inexistente e mandava null pra sempre — a ficha nunca mostrava
                'origem'              => $product->origem,
                'origin'              => $product->origem,
                'inmetro'             => $product->inmetro,
                'homologation_number' => $product->homologation_number,
                'manufacturer'        => $product->manufacturer,
                'media'         => $product->media->map(fn($m) => [
                    'id'   => $m->id,
                    'url'  => $m->url,
                    'type' => $m->type,
                ])->values()->all(),
            ];
        });

        return response()->json([
            'data' => $items,
            'meta' => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/suppliers/{id}/catalog/categories
     * Categorias usadas pelos produtos do catálogo deste fornecedor.
     */
    public function catalogCategories(Request $request, int $id)
    {
        $supplier = Supplier::where('is_active', true)->findOrFail($id);

        // MUL-099: mesmo fix de catalog() — sempre verifica tenant_supplier
        $tenantSupplierIds = $this->getTenantSupplierIds();
        if ($tenantSupplierIds !== null && ! in_array($id, $tenantSupplierIds)) {
            return response()->json(["message" => "Fornecedor nao disponivel para o seu tenant."], 403);
        }

        // MUL-219: supplier privado so acessivel ao proprio dono
        if ($supplier->is_private && $supplier->owner_client_id !== $request->user()?->client?->id) {
            return response()->json(["message" => "Fornecedor nao disponivel para o seu tenant."], 403);
        }

        $categories = \DB::table('categories')
            ->join('products', 'products.category_id', '=', 'categories.id')
            ->where('products.supplier_id', $id)
            ->where('products.is_active', true)
            ->whereNotNull('products.category_id')
            ->select('categories.id', 'categories.name')
            ->distinct()
            ->orderBy('categories.name')
            ->get();

        return response()->json(['data' => $categories]);
    }

    /**
     * POST /api/v1/catalog/products/{id}/ai-generate
     * Gera título ou descrição para produto do catálogo do fornecedor.
     * Acessível a qualquer cliente autenticado.
     */
    public function catalogAiGenerate(\Illuminate\Http\Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate(['field' => 'required|string|in:title,description,image,carousel-plan']);

        // Geracao de carrossel/imagem fora do ar para manutencao (FOR-043)
        if (in_array($data['field'], ['image', 'carousel-plan'])) {
            return response()->json(['error' => 'Geracao de imagens temporariamente indisponivel. Em breve estara de volta.'], 503);
        }

        $product = \App\Models\Product::where('is_active', true)->find($id);
        if (!$product) {
            return response()->json(['error' => 'Produto não encontrado.'], 404);
        }

        $svc = app(\App\Services\AIProductContentService::class);
        if (!$svc->hasApiKey() && !$svc->hasBankReserve($product)) {
            return response()->json(['error' => 'IA não configurada.'], 422);
        }

        try {
            $customPrompt = $request->input('prompt');
            if ($data['field'] === 'carousel-plan') {
                $value = json_encode($svc->generateCarouselPlan($product));
            } elseif ($data['field'] === 'image') {
                $value = $svc->generateImage($product, $customPrompt ?: null);
            } elseif ($data['field'] === 'title') {
                $value = $svc->generateTitle($product);
            } else {
                $value = $svc->generateDescription($product);
            }

            return response()->json(['data' => ['field' => $data['field'], 'value' => $value]]);
        } catch (\Throwable $e) {
            // FOR-072: diferenciar 429 (cota OpenAI esgotada) de outros erros
            $msg     = $e->getMessage();
            $is429   = str_contains($msg, '[HTTP:429]') || str_contains($msg, 'quota') || str_contains($msg, 'exceeded');
            $httpCode = $is429 ? 429 : 502;
            \Illuminate\Support\Facades\Log::warning('[AI-Product] ai-generate falhou', [
                'product_id' => $id,
                'field'      => $data['field'] ?? 'unknown',
                'http_code'  => $httpCode,
                'error'      => $msg,
            ]);
            if ($is429) {
                return response()->json(['error' => 'Geração de IA temporariamente indisponível. Tente novamente em alguns minutos.'], 429);
            }
            return response()->json(['error' => 'Falha IA: ' . $e->getMessage()], 502);
        }
    }


    // MUL-103/104 IA catalogo (sem ClientProduct)
    public function catalogGenerateTitle(\Illuminate\Http\Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $product = \App\Models\Product::where("is_active", true)->find($id);
        if (!$product) return response()->json(["error" => "Produto nao encontrado."], 404);
        $svc = app(\App\Services\AIProductContentService::class);
        if (!$svc->hasApiKey() && !$svc->hasBankReserve($product)) return response()->json(["error" => "IA nao configurada."], 422);
        try {
            $title = $svc->generateTitle($product);
            return response()->json(["data" => ["title" => $title, "chars" => mb_strlen($title)]]);
        } catch (\Throwable $e) {
            return response()->json(["error" => "Falha IA: " . $e->getMessage()], 502);
        }
    }

    public function catalogGenerateDescription(\Illuminate\Http\Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $product = \App\Models\Product::where("is_active", true)->find($id);
        if (!$product) return response()->json(["error" => "Produto nao encontrado."], 404);
        $svc = app(\App\Services\AIProductContentService::class);
        if (!$svc->hasApiKey() && !$svc->hasBankReserve($product)) return response()->json(["error" => "IA nao configurada."], 422);
        try {
            $desc = $svc->generateDescription($product);
            return response()->json(["data" => ["description" => $desc, "chars" => mb_strlen($desc)]]);
        } catch (\Throwable $e) {
            return response()->json(["error" => "Falha IA: " . $e->getMessage()], 502);
        }
    }

}
