<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Fornecedor', description: 'Painel do fornecedor — produtos, estoque e pedidos')]
class SupplierPanelController extends Controller
{
    /**
     * Retorna o Supplier vinculado ao usuario autenticado.
     * Aborta com 403 se o usuario nao tiver perfil de fornecedor.
     */
    private function supplierOrFail(Request $request): Supplier
    {
        $user = $request->user();

        if (! in_array($user->role, ['supplier', 'super_admin'])) {
            abort(403, 'Role supplier required');
        }

        $supplier = $user->supplier ?? null;

        // super_admin sem supplier direto: busca pelo slug do tenant da request
        if (! $supplier && $user->role === 'super_admin') {
            $slug = $request->header('X-Tenant-Slug')
                ?? config('app.tenant_slug')
                ?? null;

            if ($slug) {
                $supplier = Supplier::where('slug', $slug)->first();
            }

            // Fallback: primeiro supplier ativo do banco
            if (! $supplier) {
                $supplier = Supplier::where('is_active', true)->first();
            }
        }

        if (! $supplier) {
            abort(403, 'Usuario nao possui perfil de fornecedor.');
        }

        return $supplier;
    }

    // =========================================================================
    // PRODUTOS
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/supplier/products',
        summary: 'Listar produtos do fornecedor logado',
        description: 'Retorna os produtos do catalogo do fornecedor autenticado com paginacao de 25. Suporta busca por nome ou SKU. Inclui midia e estoque (inventory).',
        tags: ['Fornecedor'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'search',
                in: 'query',
                required: false,
                description: 'Busca por nome ou SKU do produto',
                schema: new OA\Schema(type: 'string', example: 'camiseta')
            ),
            new OA\Parameter(
                name: 'page',
                in: 'query',
                required: false,
                description: 'Pagina atual',
                schema: new OA\Schema(type: 'integer', default: 1, example: 1)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista paginada de produtos do fornecedor',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'name', type: 'string', example: 'Camiseta Polo'),
                                new OA\Property(property: 'sku', type: 'string', example: 'CAMP-001'),
                                new OA\Property(property: 'cost', type: 'number', example: 25.00),
                                new OA\Property(property: 'price', type: 'number', example: 49.90),
                                new OA\Property(property: 'is_active', type: 'boolean', example: true),
                                new OA\Property(property: 'stock_total', type: 'integer', example: 150),
                            ]
                        )),
                        new OA\Property(property: 'meta', type: 'object', properties: [
                            new OA\Property(property: 'total', type: 'integer', example: 80),
                            new OA\Property(property: 'per_page', type: 'integer', example: 25),
                            new OA\Property(property: 'current_page', type: 'integer', example: 1),
                            new OA\Property(property: 'last_page', type: 'integer', example: 4),
                        ]),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Nao autenticado'),
            new OA\Response(response: 403, description: 'Usuario nao e fornecedor'),
        ]
    )]
    public function products(Request $request): JsonResponse
    {
        $supplier = $this->supplierOrFail($request);
        $search   = $request->query('search');

        $query = Product::where('supplier_id', $supplier->id)
            ->with(['media', 'inventory']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        $paginator = $query->orderBy('name')->paginate(25);

        $items = collect($paginator->items())->map(function (Product $p) {
            return [
                'id'          => $p->id,
                'name'        => $p->name,
                'sku'         => $p->sku,
                'service_sku' => $p->service_sku,
                'cost'        => $p->cost,
                'price'       => $p->price,
                'brand'       => $p->brand,
                'ean'         => $p->ean,
                'is_active'   => $p->is_active,
                'stock_total' => $p->inventory->sum('quantity'),
                'images'      => $p->media->where('type', 'image')->pluck('url'),
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

    #[OA\Post(
        path: '/api/v1/supplier/products',
        summary: 'Criar produto no catalogo do fornecedor',
        description: 'Cria um novo produto associado ao fornecedor do usuario autenticado. Campos obrigatorios: name, sku, cost, price.',
        tags: ['Fornecedor'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'sku', 'cost', 'price'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Camiseta Polo Masculina'),
                    new OA\Property(property: 'sku', type: 'string', example: 'CAMP-001'),
                    new OA\Property(property: 'cost', type: 'number', format: 'float', example: 25.00),
                    new OA\Property(property: 'price', type: 'number', format: 'float', example: 49.90),
                    new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Camiseta polo 100% algodao'),
                    new OA\Property(property: 'ean', type: 'string', nullable: true, example: '7891234567890'),
                    new OA\Property(property: 'brand', type: 'string', nullable: true, example: 'Marca X'),
                    new OA\Property(property: 'weight_kg', type: 'number', format: 'float', nullable: true, example: 0.3),
                    new OA\Property(property: 'height_cm', type: 'number', format: 'float', nullable: true, example: 5.0),
                    new OA\Property(property: 'width_cm', type: 'number', format: 'float', nullable: true, example: 30.0),
                    new OA\Property(property: 'length_cm', type: 'number', format: 'float', nullable: true, example: 40.0),
                    new OA\Property(property: 'video_url', type: 'string', nullable: true, example: 'https://youtube.com/watch?v=abc123'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Produto criado com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Produto criado com sucesso.'),
                        new OA\Property(property: 'product_id', type: 'integer', example: 42),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Nao autenticado'),
            new OA\Response(response: 403, description: 'Usuario nao e fornecedor'),
            new OA\Response(response: 422, description: 'Dados invalidos'),
        ]
    )]
    public function storeProduct(Request $request): JsonResponse
    {
        $supplier = $this->supplierOrFail($request);

        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'sku'         => 'required|string|max:100',
            'service_sku' => 'nullable|string|max:100',
            'cost'        => 'required|numeric|min:0',
            'price'       => 'required|numeric|min:0',
            'description' => 'nullable|string',
            'ean'         => 'nullable|string|max:20',
            'brand'       => 'nullable|string|max:100',
            'weight_kg'   => 'nullable|numeric|min:0',
            'height_cm'   => 'nullable|numeric|min:0',
            'width_cm'    => 'nullable|numeric|min:0',
            'length_cm'   => 'nullable|numeric|min:0',
            'video_url'   => 'nullable|url|max:500',
        ]);

        $data['supplier_id'] = $supplier->id;
        $data['is_active']   = true;

        $product = Product::create($data);

        return response()->json([
            'message'    => 'Produto criado com sucesso.',
            'product_id' => $product->id,
        ], 201);
    }

    #[OA\Put(
        path: '/api/v1/supplier/products/{id}',
        summary: 'Atualizar produto do fornecedor',
        description: 'Atualiza os dados de um produto. O produto deve pertencer ao fornecedor do usuario autenticado.',
        tags: ['Fornecedor'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'), example: 42),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Camiseta Polo Masculina'),
                    new OA\Property(property: 'cost', type: 'number', format: 'float', example: 25.00),
                    new OA\Property(property: 'price', type: 'number', format: 'float', example: 49.90),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'is_active', type: 'boolean', example: true),
                    new OA\Property(property: 'ean', type: 'string', nullable: true),
                    new OA\Property(property: 'brand', type: 'string', nullable: true),
                    new OA\Property(property: 'weight_kg', type: 'number', nullable: true),
                    new OA\Property(property: 'height_cm', type: 'number', nullable: true),
                    new OA\Property(property: 'width_cm', type: 'number', nullable: true),
                    new OA\Property(property: 'length_cm', type: 'number', nullable: true),
                    new OA\Property(property: 'video_url', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Produto atualizado',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Produto atualizado com sucesso.'),
                ])
            ),
            new OA\Response(response: 401, description: 'Nao autenticado'),
            new OA\Response(response: 403, description: 'Produto nao pertence ao fornecedor'),
            new OA\Response(response: 404, description: 'Produto nao encontrado'),
            new OA\Response(response: 422, description: 'Dados invalidos'),
        ]
    )]
    public function updateProduct(Request $request, int $id): JsonResponse
    {
        $supplier = $this->supplierOrFail($request);

        $product = Product::where('supplier_id', $supplier->id)->findOrFail($id);

        $data = $request->validate([
            'name'        => 'sometimes|required|string|max:255',
            'service_sku' => 'nullable|string|max:100',
            'cost'        => 'sometimes|required|numeric|min:0',
            'price'       => 'sometimes|required|numeric|min:0',
            'description' => 'nullable|string',
            'is_active'   => 'sometimes|boolean',
            'ean'         => 'nullable|string|max:20',
            'brand'       => 'nullable|string|max:100',
            'weight_kg'   => 'nullable|numeric|min:0',
            'height_cm'   => 'nullable|numeric|min:0',
            'width_cm'    => 'nullable|numeric|min:0',
            'length_cm'   => 'nullable|numeric|min:0',
            'video_url'   => 'nullable|url|max:500',
        ]);

        $product->update($data);

        return response()->json(['message' => 'Produto atualizado com sucesso.']);
    }

    #[OA\Put(
        path: '/api/v1/supplier/products/{id}/stock',
        summary: 'Atualizar estoque de um produto do fornecedor',
        description: 'Atualiza a quantidade em estoque do produto. Busca ou cria o registro de Inventory vinculado ao supplier. O produto deve pertencer ao fornecedor autenticado.',
        tags: ['Fornecedor'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'), example: 42),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['quantity'],
                properties: [
                    new OA\Property(property: 'quantity', type: 'integer', minimum: 0, example: 100),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Estoque atualizado',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Estoque atualizado com sucesso.'),
                    new OA\Property(property: 'quantity', type: 'integer', example: 100),
                ])
            ),
            new OA\Response(response: 401, description: 'Nao autenticado'),
            new OA\Response(response: 403, description: 'Produto nao pertence ao fornecedor'),
            new OA\Response(response: 404, description: 'Produto nao encontrado'),
            new OA\Response(response: 422, description: 'Dados invalidos'),
        ]
    )]
    public function updateStock(Request $request, int $id): JsonResponse
    {
        $supplier = $this->supplierOrFail($request);

        $product = Product::where('supplier_id', $supplier->id)->findOrFail($id);

        $data = $request->validate([
            'quantity' => 'required|integer|min:0',
        ]);

        // Atualiza ou cria o registro de estoque vinculado ao supplier
        $inventory = Inventory::firstOrNew([
            'product_id'  => $product->id,
            'producer_id' => $supplier->id,
        ]);

        $inventory->quantity     = $data['quantity'];
        $inventory->warehouse_id = $inventory->warehouse_id ?? $supplier->id;
        $inventory->save();

        return response()->json([
            'message'  => 'Estoque atualizado com sucesso.',
            'quantity' => $inventory->quantity,
        ]);
    }

    // =========================================================================
    // PEDIDOS
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/supplier/orders',
        summary: 'Listar pedidos do fornecedor',
        description: 'Retorna os pedidos onde o fornecedor e responsavel pelo abastecimento. Suporta filtro por status e paginacao de 25.',
        tags: ['Fornecedor'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'status',
                in: 'query',
                required: false,
                description: 'Filtrar por status do pedido',
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['pending', 'paid', 'processing', 'shipped', 'delivered', 'cancelled'],
                    example: 'paid'
                )
            ),
            new OA\Parameter(
                name: 'page',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', default: 1)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista paginada de pedidos do fornecedor',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 500),
                                new OA\Property(property: 'order_number', type: 'string', example: 'HUB-2026-500'),
                                new OA\Property(property: 'status', type: 'string', example: 'paid'),
                                new OA\Property(property: 'customer_name', type: 'string', example: 'Joao Silva'),
                                new OA\Property(property: 'total', type: 'number', example: 149.90),
                                new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                            ]
                        )),
                        new OA\Property(property: 'meta', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Nao autenticado'),
            new OA\Response(response: 403, description: 'Usuario nao e fornecedor'),
        ]
    )]
    public function orders(Request $request): JsonResponse
    {
        $supplier = $this->supplierOrFail($request);

        $query = Order::where('supplier_id', $supplier->id)
            ->with('items')
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $paginator = $query->paginate(25);

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

    // =========================================================================
    // DASHBOARD
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/supplier/dashboard',
        summary: 'KPIs do painel do fornecedor',
        description: 'Retorna indicadores chave: total de produtos, produtos ativos, total de pedidos, receita total e quantidade de produtos com estoque baixo (abaixo de 10 unidades).',
        tags: ['Fornecedor'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'KPIs do fornecedor',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'total_products', type: 'integer', example: 120),
                        new OA\Property(property: 'active_products', type: 'integer', example: 98),
                        new OA\Property(property: 'total_orders', type: 'integer', example: 540),
                        new OA\Property(property: 'total_revenue', type: 'number', format: 'float', example: 48200.50),
                        new OA\Property(property: 'low_stock_count', type: 'integer', example: 7),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Nao autenticado'),
            new OA\Response(response: 403, description: 'Usuario nao e fornecedor'),
        ]
    )]
    public function dashboard(Request $request): JsonResponse
    {
        $supplier = $this->supplierOrFail($request);

        $totalProducts  = Product::where('supplier_id', $supplier->id)->count();
        $activeProducts = Product::where('supplier_id', $supplier->id)->where('is_active', true)->count();
        $totalOrders    = Order::where('supplier_id', $supplier->id)->count();
        $totalRevenue   = Order::where('supplier_id', $supplier->id)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->sum('supplier_total');

        // Produtos com estoque total < 10 unidades
        $lowStockCount = Product::where('supplier_id', $supplier->id)
            ->whereHas('inventory', function ($q) {
                $q->havingRaw('SUM(quantity) < 10');
            })
            ->orWhereDoesntHave('inventory')
            ->where('supplier_id', $supplier->id)
            ->count();

        return response()->json([
            'total_products'  => $totalProducts,
            'active_products' => $activeProducts,
            'total_orders'    => $totalOrders,
            'total_revenue'   => round((float) $totalRevenue, 2),
            'low_stock_count' => $lowStockCount,
        ]);
    }

    // =========================================================================
    // CLIENTES DO FORNECEDOR
    // =========================================================================

    public function clients(Request $request): JsonResponse
    {
        $supplier = $this->supplierOrFail($request);

        $clientIds = \App\Models\MarketplaceAccount::where('supplier_id', $supplier->id)
            ->distinct()
            ->pluck('client_id');

        $paginator = \App\Models\Client::whereIn('id', $clientIds)
            ->with('user:id,name,email')
            ->withCount(['subscriptions', 'marketplaceAccounts'])
            ->orderByDesc('created_at')
            ->paginate(25);

        $items = collect($paginator->items())->map(fn (\App\Models\Client $c) => [
            'id'                         => $c->id,
            'company_name'               => $c->company_name,
            'user'                       => $c->user ? ['name' => $c->user->name, 'email' => $c->user->email] : null,
            'is_active'                  => $c->is_active,
            'subscriptions_count'        => $c->subscriptions_count,
            'marketplace_accounts_count' => $c->marketplace_accounts_count,
            'created_at'                 => $c->created_at,
        ]);

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

    // =========================================================================
    // PRODUTOS IMPORTADOS PELOS CLIENTES
    // =========================================================================

    public function clientProducts(Request $request): JsonResponse
    {
        $supplier = $this->supplierOrFail($request);
        $search   = $request->query('search');

        $paginator = \App\Models\ClientProduct::whereHas(
            'product',
            fn ($q) => $q->where('supplier_id', $supplier->id)
        )
            // MUL-269 fase 2: nome do seller vem do user (accessor client->company_name).
            ->with(['product:id,name,sku,price', 'client:id,user_id', 'client.user:id,name,full_name'])
            ->when($search, fn ($q) => $q->whereHas(
                'product',
                fn ($q2) => $q2->where('name', 'like', "%{$search}%")
            ))
            ->orderByDesc('created_at')
            ->paginate(25);

        $items = collect($paginator->items())->map(fn (\App\Models\ClientProduct $cp) => [
            'id'           => $cp->id,
            'client'       => $cp->client ? ['id' => $cp->client->id, 'company_name' => $cp->client->company_name] : null,
            'product'      => $cp->product ? ['id' => $cp->product->id, 'name' => $cp->product->name, 'sku' => $cp->product->sku, 'price' => $cp->product->price] : null,
            'custom_sku'   => $cp->custom_sku,
            'custom_price' => $cp->custom_price,
            'sync_status'  => $cp->sync_status,
            'is_active'    => $cp->is_active,
            'created_at'   => $cp->created_at,
        ]);

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

    // =========================================================================
    // AUTO-LISTING CONFIG
    // =========================================================================

    public function autoListingConfig(Request $request): JsonResponse
    {
        $supplier = $this->supplierOrFail($request);

        $config = \App\Models\AutoListingConfig::where('client_id', $supplier->id)
            ->whereNull('marketplace_account_id')
            ->first();

        if (! $config) {
            $config = new \App\Models\AutoListingConfig([
                'client_id'               => $supplier->id,
                'ai_enabled'              => false,
                'ai_generate_title'       => false,
                'ai_generate_description' => false,
                'ai_model'                => 'gpt-4o-mini',
                'ai_instructions'         => null,
                'auto_publish'            => false,
                'skip_existing'           => true,
                'status'                  => 'inactive',
                'max_listings_per_hour'   => 10,
                'max_listings_per_day'    => 100,
            ]);
        }

        return response()->json(['data' => $config]);
    }

    public function updateAutoListingConfig(Request $request): JsonResponse
    {
        $supplier = $this->supplierOrFail($request);

        $validated = $request->validate([
            'ai_enabled'              => 'sometimes|boolean',
            'ai_generate_title'       => 'sometimes|boolean',
            'ai_generate_description' => 'sometimes|boolean',
            'ai_model'                => 'sometimes|string|max:100',
            'ai_instructions'         => 'nullable|string|max:2000',
            'status'                  => 'sometimes|string|in:active,inactive,paused',
            'auto_publish'            => 'sometimes|boolean',
            'skip_existing'           => 'sometimes|boolean',
            'max_listings_per_hour'   => 'sometimes|integer|min:1|max:1000',
            'max_listings_per_day'    => 'sometimes|integer|min:1|max:10000',
        ]);

        $config = \App\Models\AutoListingConfig::updateOrCreate(
            ['client_id' => $supplier->id, 'marketplace_account_id' => null],
            $validated
        );

        return response()->json([
            'message' => 'Configuracao de auto-listing atualizada.',
            'data'    => $config,
        ]);
    }

    // =========================================================================
    // SETTINGS DO SUPPLIER
    // =========================================================================

    public function settings(Request $request): JsonResponse
    {
        $supplier = $this->supplierOrFail($request);

        $settings = \App\Models\Setting::where('group', 'supplier_' . $supplier->id)
            ->get(['key', 'value']);

        $data = $settings->pluck('value', 'key');

        return response()->json(['data' => $data]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $supplier = $this->supplierOrFail($request);

        $data = $request->all();

        if (empty($data)) {
            return response()->json(['message' => 'Nenhum setting enviado.'], 422);
        }

        $group = 'supplier_' . $supplier->id;

        foreach ($data as $key => $value) {
            \App\Models\Setting::updateOrCreate(
                ['group' => $group, 'key' => (string) $key],
                ['value' => (string) $value]
            );
        }

        return response()->json(['message' => 'Settings atualizados com sucesso.', 'count' => count($data)]);
    }

    // =========================================================================
    // PLANOS DO FORNECEDOR
    // =========================================================================

    public function plans(Request $request): JsonResponse
    {
        $supplier = $this->supplierOrFail($request);

        $plans = \App\Models\Plan::whereHas(
            'suppliers',
            fn ($q) => $q->where('supplier_id', $supplier->id)
        )
            ->where('is_active', 1)
            ->get([
                'id',
                'name',
                'slug',
                'description',
                'max_skus',
                'max_marketplace_connections',
                'price_monthly',
                'price_yearly',
                'trial_days',
                'is_active',
            ]);

        return response()->json(['data' => $plans]);
    
    }

    // =========================================================================
    // CLIENTES DO BANCO LEGADO
    // =========================================================================

    public function legacyClients(Request $request): JsonResponse
    {
        $supplier = $this->supplierOrFail($request);

        $empresaId = $supplier->legacy_empresa_id ?? 24;

        $clients = DB::connection('legacy')
            ->table('login')
            ->where('id_empresa', $empresaId)
            ->select('id', 'login', 'email', 'nome_completo', 'empresa', 'plano', 'conta_corrente', 'status', 'data_cad', 'ultimo_acesso', 'qtd_vendas')
            ->orderBy('id', 'desc')
            ->paginate(25);

        return response()->json($clients);
    }
    // =========================================================================
    // DASHBOARD - GRAFICO DE VENDAS
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/supplier/dashboard/chart',
        summary: 'Dados de grafico de vendas por periodo',
        description: 'Retorna agregacao diaria de receita e quantidade de pedidos nao cancelados do fornecedor. Periodo: 7d, 15d, 30d (padrao) ou 90d.',
        tags: ['Fornecedor'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'period',
                in: 'query',
                required: false,
                description: 'Periodo do grafico: 7d, 15d, 30d ou 90d',
                schema: new OA\Schema(type: 'string', enum: ['7d', '15d', '30d', '90d'], example: '30d')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Dados do grafico',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'date', type: 'string', example: '2026-05-01'),
                                    new OA\Property(property: 'revenue', type: 'number', format: 'float', example: 1250.00),
                                    new OA\Property(property: 'orders_count', type: 'integer', example: 8),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Nao autenticado'),
            new OA\Response(response: 403, description: 'Usuario nao e fornecedor'),
        ]
    )]
    public function dashboardChart(Request $request): JsonResponse
    {
        $supplier = $this->supplierOrFail($request);

        $periodMap = [
            '7d'  => 7,
            '15d' => 15,
            '30d' => 30,
            '90d' => 90,
        ];

        $period = $request->query('period', '30d');
        $days   = $periodMap[$period] ?? 30;

        $rows = Order::where('supplier_id', $supplier->id)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->where('created_at', '>=', now()->subDays($days)->startOfDay())
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(supplier_total) as revenue'),
                DB::raw('COUNT(*) as orders_count')
            )
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date', 'asc')
            ->get()
            ->map(fn ($row) => [
                'date'         => $row->date,
                'revenue'      => round((float) $row->revenue, 2),
                'orders_count' => (int) $row->orders_count,
            ]);

        return response()->json(['data' => $rows]);
    }

    // =========================================================================
    // DASHBOARD - TOP PRODUTOS
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/supplier/dashboard/top-products',
        summary: 'Top 10 produtos mais vendidos do fornecedor',
        description: 'Agrega order_items de pedidos nao cancelados do fornecedor, ordenados por quantidade total vendida, com imagem, SKU e receita.',
        tags: ['Fornecedor'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Top produtos',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 42),
                                    new OA\Property(property: 'name', type: 'string', example: 'Camiseta Polo'),
                                    new OA\Property(property: 'sku', type: 'string', example: 'CAMP-001'),
                                    new OA\Property(property: 'image', type: 'string', nullable: true, example: 'https://cdn.example.com/img.jpg'),
                                    new OA\Property(property: 'qty_sold', type: 'integer', example: 150),
                                    new OA\Property(property: 'revenue', type: 'number', format: 'float', example: 7485.00),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Nao autenticado'),
            new OA\Response(response: 403, description: 'Usuario nao e fornecedor'),
        ]
    )]
    public function dashboardTopProducts(Request $request): JsonResponse
    {
        $supplier = $this->supplierOrFail($request);

        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.supplier_id', $supplier->id)
            ->whereNotIn('orders.status', ['cancelled', 'refunded'])
            ->whereNotNull('order_items.product_id')
            ->select(
                'products.id',
                'products.name',
                'products.sku',
                DB::raw('SUM(order_items.quantity) as qty_sold'),
                DB::raw('SUM(order_items.supplier_total_cost) as revenue')
            )
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->orderByDesc('qty_sold')
            ->limit(10)
            ->get();

        // Busca a primeira midia de cada produto em batch
        $productIds = $rows->pluck('id');
        $mediaMap   = \App\Models\ProductMedia::whereIn('product_id', $productIds)
            ->where('type', 'image')
            ->orderBy('position')
            ->get()
            ->groupBy('product_id')
            ->map(fn ($medias) => $medias->first()?->url);

        $data = $rows->map(fn ($row) => [
            'id'       => $row->id,
            'name'     => $row->name,
            'sku'      => $row->sku,
            'image'    => $mediaMap[$row->id] ?? null,
            'qty_sold' => (int) $row->qty_sold,
            'revenue'  => round((float) $row->revenue, 2),
        ]);

        return response()->json(['data' => $data]);
    }

    // =========================================================================
    // DASHBOARD - RESUMO DE PEDIDOS POR STATUS
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/supplier/dashboard/orders-summary',
        summary: 'Contagem de pedidos por status para donut chart',
        description: 'Retorna contagem de todos os pedidos do fornecedor agrupados por status, mais total geral e total do dia.',
        tags: ['Fornecedor'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Resumo de pedidos por status',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'pending', type: 'integer', example: 13),
                                new OA\Property(property: 'paid', type: 'integer', example: 8),
                                new OA\Property(property: 'processing', type: 'integer', example: 2),
                                new OA\Property(property: 'shipped', type: 'integer', example: 4),
                                new OA\Property(property: 'delivered', type: 'integer', example: 45),
                                new OA\Property(property: 'cancelled', type: 'integer', example: 3),
                                new OA\Property(property: 'total', type: 'integer', example: 75),
                                new OA\Property(property: 'today', type: 'integer', example: 5),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Nao autenticado'),
            new OA\Response(response: 403, description: 'Usuario nao e fornecedor'),
        ]
    )]
    public function dashboardOrdersSummary(Request $request): JsonResponse
    {
        $supplier = $this->supplierOrFail($request);

        $counts = Order::where('supplier_id', $supplier->id)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $statuses = ['pending', 'paid', 'processing', 'shipped', 'delivered', 'cancelled'];

        $summary = [];
        foreach ($statuses as $status) {
            $summary[$status] = (int) ($counts[$status] ?? 0);
        }

        $summary['total'] = (int) $counts->sum();
        $summary['today'] = Order::where('supplier_id', $supplier->id)
            ->whereDate('created_at', today())
            ->count();

        return response()->json(['data' => $summary]);
    }

    // =========================================================================
    // FINANCEIRO
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/supplier/financial/balance',
        summary: 'Saldo financeiro do fornecedor',
        description: 'Retorna o saldo atual, total ganho, total sacado e saldo pendente do fornecedor autenticado.',
        tags: ['Fornecedor'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Saldo do fornecedor',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'balance',         type: 'number', format: 'float', example: 1500.00),
                        new OA\Property(property: 'total_earned',    type: 'number', format: 'float', example: 8500.00),
                        new OA\Property(property: 'total_withdrawn', type: 'number', format: 'float', example: 7000.00),
                        new OA\Property(property: 'pending',         type: 'number', format: 'float', example: 250.00),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Nao autenticado'),
            new OA\Response(response: 403, description: 'Usuario nao e fornecedor'),
        ]
    )]
    public function financialBalance(Request $request): JsonResponse
    {
        $supplier = $this->supplierOrFail($request);

        $balance = DB::table('supplier_balances')
            ->where('producer_id', $supplier->id)
            ->first(['balance', 'total_earned', 'total_withdrawn']);

        // Creditos ainda nao vinculados a saque
        $pending = DB::table('supplier_transactions')
            ->where('producer_id', $supplier->id)
            ->where('type', 'credit')
            ->whereNull('withdrawal_request_id')
            ->sum('amount');

        return response()->json([
            'balance'         => $balance ? round((float) $balance->balance,         2) : 0.00,
            'total_earned'    => $balance ? round((float) $balance->total_earned,    2) : 0.00,
            'total_withdrawn' => $balance ? round((float) $balance->total_withdrawn, 2) : 0.00,
            'pending'         => round((float) $pending, 2),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/supplier/financial/transactions',
        summary: 'Listar transacoes financeiras do fornecedor',
        description: 'Retorna as transacoes financeiras paginadas (25/page). Suporta filtro por tipo (credit/debit) e busca por descricao.',
        tags: ['Fornecedor'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'type',
                in: 'query',
                required: false,
                description: 'Filtrar por tipo: credit ou debit',
                schema: new OA\Schema(type: 'string', enum: ['credit', 'debit'], example: 'credit')
            ),
            new OA\Parameter(
                name: 'search',
                in: 'query',
                required: false,
                description: 'Busca parcial na descricao',
                schema: new OA\Schema(type: 'string', example: 'pedido')
            ),
            new OA\Parameter(
                name: 'page',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', default: 1)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista paginada de transacoes',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'id',          type: 'integer', example: 1),
                                new OA\Property(property: 'type',        type: 'string',  example: 'credit'),
                                new OA\Property(property: 'amount',      type: 'number',  format: 'float', example: 150.00),
                                new OA\Property(property: 'description', type: 'string',  nullable: true, example: 'Venda pedido #500'),
                                new OA\Property(property: 'order_id',    type: 'integer', nullable: true, example: 500),
                                new OA\Property(property: 'created_at',  type: 'string',  format: 'date-time'),
                            ]
                        )),
                        new OA\Property(property: 'meta', type: 'object', properties: [
                            new OA\Property(property: 'total',        type: 'integer', example: 200),
                            new OA\Property(property: 'per_page',     type: 'integer', example: 25),
                            new OA\Property(property: 'current_page', type: 'integer', example: 1),
                            new OA\Property(property: 'last_page',    type: 'integer', example: 8),
                        ]),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Nao autenticado'),
            new OA\Response(response: 403, description: 'Usuario nao e fornecedor'),
        ]
    )]
    public function financialTransactions(Request $request): JsonResponse
    {
        $supplier = $this->supplierOrFail($request);

        $query = DB::table('supplier_transactions')
            ->where('producer_id', $supplier->id)
            ->orderByDesc('created_at');

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        if ($search = $request->query('search')) {
            $query->where('description', 'like', "%{$search}%");
        }

        $paginator = $query->paginate(25);

        $items = collect($paginator->items())->map(fn ($row) => [
            'id'          => $row->id,
            'type'        => $row->type,
            'amount'      => round((float) $row->amount, 2),
            'description' => $row->description,
            'order_id'    => $row->order_id,
            'created_at'  => $row->created_at,
        ]);

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
    // =========================================================================
    // PIX DO FORNECEDOR (FOR-027)
    // =========================================================================

    /**
     * Retorna a chave PIX configurada para o fornecedor.
     * Endpoint autenticado - somente o próprio fornecedor pode consultar sua chave.
     *
     * FOR-027 hotfix: bloqueia IDOR. Mesmo recebendo {supplier} na URL,
     * só retorna se o ID coincidir com o supplier do usuário autenticado.
     */
    public function getSupplierPix(Request $request, int $supplierId): JsonResponse
    {
        $authSupplier = $this->supplierOrFail($request);

        if ($authSupplier->id !== (int) $supplierId) {
            abort(403, 'Acesso negado: você só pode consultar sua própria chave PIX.');
        }

        if (!$authSupplier->pix_key) {
            return response()->json(['message' => 'Chave PIX nao configurada para este fornecedor.'], 404);
        }

        return response()->json([
            'supplier_id'  => $authSupplier->id,
            'company'      => $authSupplier->company_name,
            'pix_key'      => $authSupplier->pix_key,
            'pix_key_type' => $authSupplier->pix_key_type,
        ]);
    }

    /**
     * Solicita saque via PIX para o fornecedor autenticado (MVP).
     * O saldo e descontado imediatamente; admin aprova o pagamento real no Filament.
     *
     * FOR-027 hotfix: a chave PIX usada é SEMPRE a do cadastro do supplier.
     * Mesmo se vier `pix_key` no payload, ela é ignorada — evita que conta
     * comprometida desvie o PIX pra terceiro.
     */
    public function requestWithdrawal(Request $request): JsonResponse
    {
        $supplier = $this->supplierOrFail($request);

        $request->validate([
            'amount' => 'required|numeric|min:5',
        ]);

        // Força usar a chave PIX cadastrada — payload não pode sobrescrever
        $pixKey = $supplier->pix_key;
        if (empty($pixKey)) {
            return response()->json([
                'error'   => 'pix_key_not_configured',
                'message' => 'Cadastre uma chave PIX no perfil antes de solicitar saque.',
            ], 422);
        }

        try {
            // warehouse_id == supplier->id (padrao: fornecedor e o proprio galpao)
            $withdrawal = app(\App\Services\Financial\WithdrawalService::class)
                ->requestWithdrawal(
                    $supplier->id,
                    $supplier->id,
                    (float) $request->input('amount'),
                    $pixKey
                );
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message'       => 'Solicitacao de saque registrada com sucesso.',
            'withdrawal_id' => $withdrawal->id,
            'amount'        => $withdrawal->amount,
            'pix_key'       => $withdrawal->pix_key,
            'status'        => $withdrawal->status,
            'requested_at'  => $withdrawal->requested_at,
        ], 201);
    }
}