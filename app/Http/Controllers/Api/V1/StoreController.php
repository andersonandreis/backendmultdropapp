<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Models\Supplier;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class StoreController extends Controller
{
    private function clientOrFail(Request $request)
    {
        $user = $request->user();
        $client = $user->client;

        // SEL-146 Ruan 16/07 01:26: admin/super_admin em "modo seller" tambem precisa
        // conectar marketplace real. Se nao tem client vinculado, auto-cria um stub
        // pra ele operar como cliente. Ainda mantem role=admin no user.
        if (! $client && in_array($user->role ?? null, ['admin', 'super_admin'], true)) {
            // MUL-269 fase 2: company_name removido de clients — nome vem do user (accessor).
            $client = \App\Models\Client::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'service'      => 'hubai',
                    'is_active'    => 1,
                ]
            );
            $user->setRelation('client', $client);
        }

        if (! $client) {
            abort(403, 'Usuario nao possui perfil de lojista.');
        }

        return $client;
    }

    #[OA\Get(
        path: '/api/v1/stores',
        summary: 'Listar contas de marketplace do lojista',
        description: 'Retorna todas as contas de marketplace (lojas) vinculadas ao perfil de lojista do usuario autenticado. Cada conta representa uma integracao com uma plataforma (Mercado Livre, Shopee, Shopify, etc.). Suporta filtro por supplier_id e paginacao.',
        tags: ['Lojas'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'per_page',
                in: 'query',
                required: false,
                description: 'Quantidade de registros por pagina',
                schema: new OA\Schema(type: 'integer', default: 15, example: 15)
            ),
            new OA\Parameter(
                name: 'supplier_id',
                in: 'query',
                required: false,
                description: 'Filtrar lojas de um fornecedor especifico',
                schema: new OA\Schema(type: 'integer', example: 3)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista de contas de marketplace paginada',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 1),
                                    new OA\Property(property: 'client_id', type: 'integer', example: 7),
                                    new OA\Property(property: 'supplier_id', type: 'integer', example: 3),
                                    new OA\Property(
                                        property: 'platform',
                                        type: 'string',
                                        description: 'Plataforma de marketplace',
                                        enum: ['mercadolivre', 'shopee', 'shopify', 'hubaisimulator'],
                                        example: 'mercadolivre'
                                    ),
                                    new OA\Property(
                                        property: 'account_name',
                                        type: 'string',
                                        description: 'Nome amigavel da loja',
                                        example: 'Minha Loja ML'
                                    ),
                                    new OA\Property(
                                        property: 'status',
                                        type: 'string',
                                        enum: ['active', 'inactive', 'pending'],
                                        example: 'active'
                                    ),
                                    new OA\Property(
                                        property: 'pricing_strategy',
                                        type: 'string',
                                        nullable: true,
                                        enum: ['margin', 'fixed', 'competitive'],
                                        example: 'margin'
                                    ),
                                    new OA\Property(
                                        property: 'price_margin',
                                        type: 'number',
                                        nullable: true,
                                        description: 'Percentual de margem (0-100)',
                                        example: 30.0
                                    ),
                                    new OA\Property(
                                        property: 'supplier',
                                        type: 'object',
                                        nullable: true,
                                        properties: [
                                            new OA\Property(property: 'id', type: 'integer', example: 3),
                                            new OA\Property(property: 'company_name', type: 'string', example: 'Fornecedor XPTO LTDA'),
                                            new OA\Property(property: 'display_name', type: 'string', example: 'XPTO'),
                                            new OA\Property(property: 'logo', type: 'string', nullable: true, example: 'https://cdn.hubai.io/logos/xpto.png'),
                                        ]
                                    ),
                                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2024-03-10T14:00:00Z'),
                                ]
                            )
                        ),
                        new OA\Property(
                            property: 'meta',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'total', type: 'integer', example: 5),
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
            new OA\Response(
                response: 403,
                description: 'Usuario nao possui perfil de lojista',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Usuario nao possui perfil de lojista.')]
                )
            ),
        ]
    )]
    public function index(Request $request)
    {
        $client  = $this->clientOrFail($request);
        $perPage = (int) $request->query('per_page', 15);

        $query = MarketplaceAccount::where('client_id', $client->id)
            ->with('supplier:id,company_name,display_name,logo');

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', (int) $request->query('supplier_id'));
        }

        $paginator = $query->paginate($perPage);

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

    #[OA\Post(
        path: '/api/v1/stores',
        summary: 'Criar nova conta de marketplace para o lojista',
        description: 'Vincula uma nova loja (conta de marketplace) ao perfil do lojista. O fornecedor informado deve estar disponivel no plano ativo do lojista. A plataforma deve ser um dos valores aceitos: mercadolivre, shopee, shopify ou hubaisimulator. A conta e criada com status "pending" ate que a integracao OAuth seja concluida.',
        tags: ['Lojas'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['supplier_id', 'platform', 'account_name'],
                properties: [
                    new OA\Property(
                        property: 'supplier_id',
                        type: 'integer',
                        description: 'ID do fornecedor (deve estar no plano ativo do lojista)',
                        example: 3
                    ),
                    new OA\Property(
                        property: 'platform',
                        type: 'string',
                        description: 'Plataforma de marketplace',
                        enum: ['mercadolivre', 'shopee', 'shopify', 'hubaisimulator'],
                        example: 'mercadolivre'
                    ),
                    new OA\Property(
                        property: 'account_name',
                        type: 'string',
                        description: 'Nome amigavel para identificar a loja',
                        example: 'Minha Loja ML'
                    ),
                    new OA\Property(
                        property: 'pricing_strategy',
                        type: 'string',
                        nullable: true,
                        description: 'Estrategia de precificacao: margin (margem percentual), fixed (preco fixo), competitive (preco competitivo)',
                        enum: ['margin', 'fixed', 'competitive'],
                        example: 'margin'
                    ),
                    new OA\Property(
                        property: 'price_margin',
                        type: 'number',
                        nullable: true,
                        description: 'Percentual de margem sobre o custo do produto (0-100). Obrigatorio se pricing_strategy=margin.',
                        example: 30.0
                    ),
                    new OA\Property(
                        property: 'tax_percentage',
                        type: 'number',
                        nullable: true,
                        description: 'Percentual de imposto a ser incluido no preco (0-100)',
                        example: 10.0
                    ),
                    new OA\Property(
                        property: 'marketplace_commission',
                        type: 'number',
                        nullable: true,
                        description: 'Comissao do marketplace em percentual',
                        example: 12.0
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Conta de marketplace criada com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 10),
                                new OA\Property(property: 'client_id', type: 'integer', example: 7),
                                new OA\Property(property: 'supplier_id', type: 'integer', example: 3),
                                new OA\Property(property: 'platform', type: 'string', example: 'mercadolivre'),
                                new OA\Property(property: 'account_name', type: 'string', example: 'Minha Loja ML'),
                                new OA\Property(property: 'status', type: 'string', example: 'pending'),
                                new OA\Property(property: 'pricing_strategy', type: 'string', example: 'margin'),
                                new OA\Property(property: 'price_margin', type: 'number', example: 30.0),
                                new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2024-03-10T14:00:00Z'),
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
                description: 'Dados invalidos ou fornecedor nao disponivel no plano',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'O fornecedor selecionado nao esta disponivel no seu plano atual.'),
                        new OA\Property(
                            property: 'errors',
                            type: 'object',
                            example: ['platform' => ['The selected platform is invalid.']]
                        ),
                    ]
                )
            ),
        ]
    )]
    public function store(Request $request)
    {
        $client = $this->clientOrFail($request);

        $data = $request->validate([
            'supplier_id'          => 'required|integer|exists:suppliers,id',
            'platform'             => 'required|string|in:mercadolivre,shopee,bling,shopify,tiktok,hubaisimulator',
            'account_name'         => 'required|string|max:255',
            'pricing_strategy'     => 'nullable|string|in:margin,fixed,competitive',
            'price_margin'         => 'nullable|numeric|min:0|max:100',
            'tax_percentage'       => 'nullable|numeric|min:0|max:100',
            'marketplace_commission' => 'nullable|numeric|min:0',
        ]);

        // Buscar subscription ativa (necessaria para validacao de plano e limites)
        $activeSub = $client->subscriptions()
            ->whereIn('status', ['active', 'trialing'])
            ->with('plan.suppliers')
            ->latest()
            ->first();

        // Validate supplier - publicos sao livres, privados requerem plano
        $supplier = Supplier::findOrFail((int) $data['supplier_id']);
        if ($supplier->is_private) {
            $allowedIds = $activeSub?->plan?->suppliers()
                ->where(function ($q) {
                    $q->whereNull('plan_supplier.available_from')
                      ->orWhere('plan_supplier.available_from', '<=', now()->toDateString());
                })
                ->pluck('suppliers.id')->toArray() ?? [];
            if (!in_array((int) $data['supplier_id'], $allowedIds)) {
                return response()->json([
                    'message' => 'O fornecedor selecionado nao esta disponivel no seu plano atual.',
                ], 422);
            }
        }

        // Verificar limite de conexoes do plano
        $maxConn = $activeSub?->plan?->max_marketplace_connections;
        $currentCount = MarketplaceAccount::where('client_id', $client->id)->count();
        if ($maxConn !== null && $currentCount >= $maxConn) {
            return response()->json([
                'message' => "Limite de {$maxConn} conexoes atingido. Faca upgrade.",
            ], 422);
        }

        $account = MarketplaceAccount::create(array_merge($data, [
            'client_id' => $client->id,
            'status'    => 'pending',
        ]));

        return response()->json(['data' => $account], 201);
    }

    #[OA\Get(
        path: '/api/v1/stores/{id}',
        summary: 'Detalhes de uma conta de marketplace',
        description: 'Retorna os dados completos de uma conta de marketplace especifica do lojista, incluindo as configuracoes de precificacao, status de integracao e dados do fornecedor vinculado.',
        tags: ['Lojas'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'ID da conta de marketplace',
                schema: new OA\Schema(type: 'integer', example: 1)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Dados da conta de marketplace',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'client_id', type: 'integer', example: 7),
                                new OA\Property(property: 'supplier_id', type: 'integer', example: 3),
                                new OA\Property(property: 'platform', type: 'string', example: 'mercadolivre'),
                                new OA\Property(property: 'account_name', type: 'string', example: 'Minha Loja ML'),
                                new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive', 'pending'], example: 'active'),
                                new OA\Property(property: 'pricing_strategy', type: 'string', nullable: true, example: 'margin'),
                                new OA\Property(property: 'price_margin', type: 'number', nullable: true, example: 30.0),
                                new OA\Property(property: 'tax_percentage', type: 'number', nullable: true, example: 10.0),
                                new OA\Property(property: 'marketplace_commission', type: 'number', nullable: true, example: 12.0),
                                new OA\Property(property: 'marketplace_fixed_fee', type: 'number', nullable: true, example: 5.0),
                                new OA\Property(property: 'marketplace_shipping_fee', type: 'number', nullable: true, example: 15.0),
                                new OA\Property(
                                    property: 'supplier',
                                    type: 'object',
                                    properties: [
                                        new OA\Property(property: 'id', type: 'integer', example: 3),
                                        new OA\Property(property: 'company_name', type: 'string', example: 'Fornecedor XPTO LTDA'),
                                        new OA\Property(property: 'display_name', type: 'string', example: 'XPTO'),
                                        new OA\Property(property: 'logo', type: 'string', nullable: true, example: 'https://cdn.hubai.io/logos/xpto.png'),
                                    ]
                                ),
                                new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2024-03-10T14:00:00Z'),
                                new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2024-03-15T09:30:00Z'),
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
                description: 'Conta de marketplace nao encontrada ou nao pertence ao lojista',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'No query results for model [MarketplaceAccount] 99')]
                )
            ),
        ]
    )]
    public function show(Request $request, int $id)
    {
        $client  = $this->clientOrFail($request);
        $account = MarketplaceAccount::where('client_id', $client->id)
            ->with('supplier:id,company_name,display_name,logo')
            ->findOrFail($id);

        return response()->json(['data' => $account]);
    }

    #[OA\Put(
        path: '/api/v1/stores/{id}',
        summary: 'Atualizar configuracoes de uma conta de marketplace',
        description: 'Permite atualizar nome, estrategia de precificacao, margens, taxas e status de uma conta de marketplace do lojista. Todos os campos sao opcionais (PATCH semantico). Apenas contas pertencentes ao lojista autenticado podem ser editadas.',
        tags: ['Lojas'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'ID da conta de marketplace',
                schema: new OA\Schema(type: 'integer', example: 1)
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(
                        property: 'account_name',
                        type: 'string',
                        description: 'Novo nome amigavel da loja',
                        example: 'Loja ML Atualizada'
                    ),
                    new OA\Property(
                        property: 'pricing_strategy',
                        type: 'string',
                        enum: ['margin', 'fixed', 'competitive'],
                        example: 'margin'
                    ),
                    new OA\Property(
                        property: 'price_margin',
                        type: 'number',
                        description: 'Percentual de margem (0-100)',
                        example: 35.0
                    ),
                    new OA\Property(
                        property: 'tax_percentage',
                        type: 'number',
                        description: 'Percentual de imposto (0-100)',
                        example: 10.0
                    ),
                    new OA\Property(
                        property: 'marketplace_commission',
                        type: 'number',
                        description: 'Comissao do marketplace em percentual',
                        example: 12.0
                    ),
                    new OA\Property(
                        property: 'marketplace_fixed_fee',
                        type: 'number',
                        description: 'Taxa fixa do marketplace por pedido (R$)',
                        example: 5.0
                    ),
                    new OA\Property(
                        property: 'marketplace_shipping_fee',
                        type: 'number',
                        description: 'Taxa de frete do marketplace (R$)',
                        example: 15.0
                    ),
                    new OA\Property(
                        property: 'status',
                        type: 'string',
                        description: 'Status da integracao',
                        enum: ['active', 'inactive', 'pending'],
                        example: 'active'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Conta atualizada com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'account_name', type: 'string', example: 'Loja ML Atualizada'),
                                new OA\Property(property: 'status', type: 'string', example: 'active'),
                                new OA\Property(property: 'pricing_strategy', type: 'string', example: 'margin'),
                                new OA\Property(property: 'price_margin', type: 'number', example: 35.0),
                                new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2024-03-15T09:30:00Z'),
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
                description: 'Conta de marketplace nao encontrada ou nao pertence ao lojista',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'No query results for model [MarketplaceAccount] 99')]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Dados de validacao invalidos',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'The status field must be one of active, inactive, pending.'),
                        new OA\Property(property: 'errors', type: 'object', example: ['status' => ['The status field must be one of active, inactive, pending.']]),
                    ]
                )
            ),
        ]
    )]
    public function update(Request $request, int $id)
    {
        $client  = $this->clientOrFail($request);
        $account = MarketplaceAccount::where('client_id', $client->id)->findOrFail($id);

        $data = $request->validate([
            'account_name'           => 'sometimes|string|max:255',
            'pricing_strategy'       => 'sometimes|string|in:margin,fixed,competitive',
            'price_margin'           => 'sometimes|numeric|min:0|max:100',
            'tax_percentage'         => 'sometimes|numeric|min:0|max:100',
            'marketplace_commission' => 'sometimes|numeric|min:0',
            'marketplace_fixed_fee'  => 'sometimes|numeric|min:0',
            'marketplace_shipping_fee' => 'sometimes|numeric|min:0',
            'status'                 => 'sometimes|string|in:active,inactive,pending',
            // NOV-189: modo de imagens da integracao Bling
            'bling_images_mode'      => 'sometimes|string|in:external,stored',
            // MUL-082: import filters
            'data_inicial_import'    => 'sometimes|nullable|date',
            'allowed_integrations'   => 'sometimes|nullable|array',
            'allowed_integrations.*' => 'integer',
            // MUL-311: only_ready_to_ship REMOVIDO. Nasceu para a Shopee ("importa somente
            // pedidos com status a enviar") e nunca foi lido por nenhum importador — nem
            // Shopee, nem ML, nem Bling. Era uma caixinha na tela que nao fazia nada.
            // Decisao do Ruan em 31/07/2026: a regra nao faz sentido, remover.
        ]);

        $account->update($data);

        return response()->json(['data' => $account->fresh()]);
    }

    #[OA\Delete(
        path: '/api/v1/stores/{id}',
        summary: 'Excluir uma conta de marketplace',
        description: 'Remove permanentemente uma conta de marketplace do lojista. Produtos vinculados sao desativados (nao deletados). Configs de auto-listing e health checks sao removidos. Tokens sao revogados quando a plataforma suporta. Apenas contas pertencentes ao lojista autenticado podem ser excluidas.',
        tags: ['Lojas'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'ID da conta de marketplace',
                schema: new OA\Schema(type: 'integer', example: 1)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Conta removida com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Loja removida com sucesso. Produtos vinculados foram desativados.'),
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
                description: 'Conta nao encontrada ou nao pertence ao lojista',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'No query results for model [MarketplaceAccount] 99')]
                )
            ),
        ]
    )]
    /**
     * MUL-082: GET /api/v1/stores/{id}/bling-channels
     * Lista canais de venda (integracoes) configurados na conta Bling do seller,
     * para exibir no modal de configuracao ("quais canais importar").
     */
    public function blingChannels(Request $request, int $id)
    {
        $client = $this->clientOrFail($request);
        $account = \App\Models\MarketplaceAccount::where('client_id', $client->id)
            ->where('platform', 'bling')
            ->findOrFail($id);

        $apiClient = app(\App\Services\Integrations\Erps\Bling\BlingApiClient::class);

        try {
            $response = $apiClient->listSalesChannels($account);
            $channels = collect($response['data'] ?? [])->map(fn($c) => [
                'id'        => $c['id'] ?? null,
                'descricao' => $c['descricao'] ?? '',
                'tipo'      => $c['tipo'] ?? null,
                'situacao'  => $c['situacao'] ?? null,
            ])->filter(fn($c) => !empty($c['id']))->values();

            return response()->json(['data' => $channels]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[StoreController@blingChannels] falha', [
                'account_id' => $id,
                'error'      => $e->getMessage(),
            ]);
            return response()->json(['data' => [], 'error' => 'Nao foi possivel listar canais Bling'], 200);
        }
    }

    public function destroy(Request $request, int $id)
    {
        $client = $this->clientOrFail($request);
        $account = MarketplaceAccount::where('client_id', $client->id)->findOrFail($id);

        $platform = $account->platform;
        $name = $account->account_name;

        // 1. Revogar token no marketplace (best effort, nao bloqueia exclusao)
        try {
            $this->revokeMarketplaceToken($account);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Token revocation failed for {$platform} account {$id}: " . $e->getMessage());
        }

        // 2. Desativar client_products vinculados (nao deletar - seller pode querer reativar)
        \App\Models\ClientProduct::where('marketplace_account_id', $id)
            ->update(['is_active' => false, 'sync_status' => 'disconnected']);

        // 3. Limpar auto_listing
        \DB::table('auto_listing_queue_items')->where('marketplace_account_id', $id)->delete();
        \DB::table('auto_listing_configs')->where('marketplace_account_id', $id)->delete();

        // 4. Limpar health checks
        \DB::table('marketplace_health_checks')->where('marketplace_account_id', $id)->delete();

        // 5. Deletar a conta
        $account->delete();

        \Illuminate\Support\Facades\Log::info("Store deleted: {$platform} '{$name}' (ID {$id}) by client {$client->id}. Products deactivated, configs cleaned.");

        return response()->json([
            'message' => 'Loja removida com sucesso. Produtos vinculados foram desativados.',
        ]);
    }

    private function revokeMarketplaceToken(MarketplaceAccount $account): void
    {
        switch ($account->platform) {
            case 'mercadolivre':
                // ML nao tem endpoint de revogacao de token
                break;
            case 'bling':
                // Bling nao tem endpoint de revogacao
                break;
            case 'shopee':
                // Shopee: POST /api/v2/auth/token/unlist_shop
                // Implementar quando necessario
                break;
            case 'shopify':
                // Shopify: DELETE /admin/api_permissions/current.json
                if ($account->access_token) {
                    try {
                        $shopDomain = $account->shop_domain ?? '';
                        if ($shopDomain) {
                            \Illuminate\Support\Facades\Http::withToken(decrypt($account->access_token))
                                ->delete("https://{$shopDomain}/admin/api_permissions/current.json");
                        }
                    } catch (\Throwable $e) {
                        // Best effort
                    }
                }
                break;
        }
    }

    #[OA\Post(
        path: '/api/v1/stores/{id}/reconnect',
        summary: 'Reconectar loja com conexao pendente ou expirada',
        description: 'Gera a URL de autorizacao OAuth para reconectar uma loja que esta com status pending ou inactive. O frontend deve redirecionar o usuario para a URL retornada. Apos autorizacao, o callback atualiza o status para active.',
        tags: ['Lojas'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'ID da conta de marketplace',
                schema: new OA\Schema(type: 'integer', example: 14)
            ),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(
                        property: 'shop_domain',
                        type: 'string',
                        nullable: true,
                        description: 'Dominio .myshopify.com (obrigatorio apenas para Shopify)',
                        example: 'minhaloja.myshopify.com'
                    ),
                    new OA\Property(
                        property: 'return_url',
                        type: 'string',
                        nullable: true,
                        description: 'URL de retorno apos autorizacao (default: /app/minhas-lojas)',
                        example: 'https://fornecedorshop.online/integracoes'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'URL de reconexao gerada',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'redirect_url', type: 'string', example: 'https://api.hubai.io/api/oauth/mercadolivre/redirect?client_id=6&supplier_id=1&account_name=ML+Teste'),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Loja ja esta ativa',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Loja ja esta conectada e ativa.')]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Loja nao encontrada ou nao pertence ao lojista',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'No query results for model [MarketplaceAccount] 99')]
                )
            ),
        ]
    )]
    public function reconnect(Request $request, int $id)
    {
        $client  = $this->clientOrFail($request);
        $account = MarketplaceAccount::where('client_id', $client->id)->findOrFail($id);

        if ($account->status === 'active') {
            return response()->json(['message' => 'Loja ja esta conectada e ativa.'], 400);
        }

        $platform    = $account->platform;
        $supplierId  = $account->supplier_id;
        $accountName = $account->account_name;
        $returnUrl   = $request->get('return_url', '');
        $shopDomain  = $request->get('shop_domain', '');

        $redirectUrl = "/api/oauth/{$platform}/redirect?client_id={$client->id}&supplier_id={$supplierId}&account_name=" . urlencode($accountName);

        if ($returnUrl) {
            $redirectUrl .= '&return_url=' . urlencode($returnUrl);
        }
        if ($shopDomain) {
            $redirectUrl .= '&shop_domain=' . urlencode($shopDomain);
        }

        return response()->json([
            'redirect_url' => url($redirectUrl),
        ]);
    }

}
