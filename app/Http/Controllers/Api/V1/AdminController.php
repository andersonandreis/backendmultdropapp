<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SyncLegacyCatalogJob;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Admin', description: 'Endpoints administrativos (super_admin only)')]
class AdminController extends Controller
{
    /**
     * Verifica se o usuario autenticado e super_admin.
     * Aborta com 403 se nao for.
     */
    private function requireSuperAdmin(Request $request): void
    {
        if ($request->user()?->role !== 'super_admin') {
            abort(403, 'Acesso restrito a super_admin.');
        }
    }

    // =========================================================================
    // CLIENTES
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/admin/clients',
        summary: 'Listar clientes',
        description: 'Lista todos os clientes com paginacao e filtros. Restrito a super_admin.',
        tags: ['Admin'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'search',
                in: 'query',
                required: false,
                description: 'Busca por nome da empresa ou email do usuario',
                schema: new OA\Schema(type: 'string', example: 'loja')
            ),
            new OA\Parameter(
                name: 'status',
                in: 'query',
                required: false,
                description: 'Filtrar por status do cliente',
                schema: new OA\Schema(type: 'string', enum: ['active', 'inactive'], example: 'active')
            ),
            new OA\Parameter(
                name: 'plan',
                in: 'query',
                required: false,
                description: 'Filtrar por slug ou ID do plano da assinatura ativa',
                schema: new OA\Schema(type: 'string', example: 'start')
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
                description: 'Lista de clientes paginada',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 1),
                                    new OA\Property(property: 'company_name', type: 'string', example: 'Loja Exemplo LTDA'),
                                    new OA\Property(property: 'document', type: 'string', nullable: true, example: '12345678000190'),
                                    new OA\Property(
                                        property: 'user',
                                        type: 'object',
                                        properties: [
                                            new OA\Property(property: 'name', type: 'string', example: 'Joao Silva'),
                                            new OA\Property(property: 'email', type: 'string', example: 'joao@loja.com'),
                                        ]
                                    ),
                                    new OA\Property(property: 'is_active', type: 'boolean', example: true),
                                    new OA\Property(property: 'subscriptions_count', type: 'integer', example: 1),
                                    new OA\Property(property: 'marketplace_accounts_count', type: 'integer', example: 3),
                                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-01-15T10:30:00Z'),
                                ]
                            )
                        ),
                        new OA\Property(
                            property: 'meta',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'total', type: 'integer', example: 120),
                                new OA\Property(property: 'per_page', type: 'integer', example: 25),
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'last_page', type: 'integer', example: 5),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Token ausente ou invalido'),
            new OA\Response(response: 403, description: 'Acesso negado — requer super_admin'),
        ]
    )]
    public function clients(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $query = Client::with([
                'user:id,name,email',
                'subscriptions:id,client_id,status,plan_id,current_period_end,trial_ends_at',
                'subscriptions.plan:id,name,slug',
            ])
            ->withCount(['subscriptions', 'marketplaceAccounts']);

        // Filtro: search por nome do user conectado ou email
        // MUL-269 fase 2: clients.company_name foi removido; nome vem de users.
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', fn ($u) => $u
                    ->where('email', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('full_name', 'like', "%{$search}%"));
            });
        }

        // Filtro: status
        // SEL-112 backend: aba admin/clients aceita 3 status por assinatura
        // (paying|pending|free) alem dos 2 legados por is_active (active|inactive).
        // paying  = plan slug pagante + subscriptions.status IN (active,trialing) + pagarme_status paid/null
        // pending = subscriptions.status trialing OU pagarme_status pending/processing
        // free    = sem assinatura ativa OU plano gratuito (tiktok_free/demo)
        if ($status = $request->query('status')) {
            if ($status === 'paying') {
                $query->whereHas('subscriptions', function ($q) {
                    $q->whereIn('status', ['active', 'trialing'])
                      ->where(function ($w) {
                          $w->where('pagarme_status', 'paid')->orWhereNull('pagarme_status');
                      })
                      ->whereHas('plan', fn ($p) => $p->whereIn('slug', ['start', 'scaling', 'pro', 'enterprise']));
                });
            } elseif ($status === 'pending') {
                $query->whereHas('subscriptions', function ($q) {
                    $q->where(function ($w) {
                        $w->where('status', 'trialing')
                          ->orWhereIn('pagarme_status', ['pending', 'processing', 'past_due']);
                    });
                });
            } elseif ($status === 'free') {
                // Sem assinatura ativa/trialing pagante OU plano marcado como gratuito
                $query->where(function ($outer) {
                    $outer->whereDoesntHave('subscriptions', function ($q) {
                        $q->whereIn('status', ['active', 'trialing']);
                    })
                    ->orWhereHas('subscriptions', function ($q) {
                        $q->whereIn('status', ['active', 'trialing'])
                          ->whereHas('plan', fn ($p) => $p->whereIn('slug', ['tiktok_free', 'demo']));
                    });
                });
            } elseif ($status === 'active' || $status === 'inactive') {
                // Legado: mantem filtro por is_active pra compat frontend antigo
                $query->where('is_active', $status === 'active');
            }
        }

        // Filtro: plano
        if ($plan = $request->query('plan')) {
            $query->whereHas('subscriptions', function ($q) use ($plan) {
                $q->whereIn('status', ['active', 'trialing'])
                  ->whereHas('plan', fn ($p) => $p->where('slug', $plan)->orWhere('id', $plan));
            });
        }

        $paginator = $query->orderByDesc('created_at')->paginate(min(500, (int) $request->query('per_page', 25))); // SEL-112-fix-perpage

        return response()->json([
            'data' => $paginator->through(function ($client) {
                // SEL-058: preferir sub active/trialing mais recente; fallback pra última sub
                $sub = $client->subscriptions
                    ->whereIn('status', ['active', 'trialing'])
                    ->sortByDesc('id')
                    ->first()
                    ?? $client->subscriptions->sortByDesc('id')->first();

                return [
                    'id'                         => $client->id,
                    'company_name'               => $client->company_name,
                    'document'                   => $client->document,
                    'user'                       => $client->user ? [
                        'id'    => $client->user->id,
                        'name'  => $client->user->name,
                        'email' => $client->user->email,
                    ] : null,
                    'is_active'                  => (bool) $client->is_active,
                    'subscriptions_count'        => $client->subscriptions_count,
                    'marketplace_accounts_count' => $client->marketplace_accounts_count,
                    'phone'                      => $client->phone,
                    'plan_id'                    => $sub?->plan_id,
                    'plan_name'                  => $sub?->plan?->name,
                    'plan_slug'                  => $sub?->plan?->slug,
                    'subscription_status'        => $sub?->status,
                    'subscription_expires_at'    => $sub?->current_period_end?->toIso8601String(),
                    'created_at'                 => $client->created_at?->toIso8601String(),
                    'whatsapp_invited_at'        => $client->whatsapp_invited_at?->toIso8601String(), // SEL-113-clients-return-wa
                ];
            })->items(),
            'counts' => $this->clientsGlobalCounts(), // SEL-112-fix-counts
            'meta' => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * SEL-112 Ruan 13:42: contadores GLOBAIS de subscriptions ativas por
     * categoria. Antes o frontend calculava client-side com base nos 25 da
     * pagina atual — Ruan viu "1 pagante" quando o banco tinha 12.
     */
    private function clientsGlobalCounts(): array
    {
        $base = fn () => \DB::table('subscriptions as s')
            ->leftJoin('plans as p', 'p.id', '=', 's.plan_id');
        return [
            'paying' => $base()
                ->whereIn('p.slug', ['start', 'scaling', 'pro', 'enterprise'])
                ->where('s.status', 'active')
                ->where(function ($q) {
                    $q->where('s.pagarme_status', 'paid')->orWhereNull('s.pagarme_status');
                })->count(),
            'pending' => \DB::table('subscriptions as s')
                ->where(function ($q) {
                    $q->where('s.status', 'trialing')
                      ->orWhereIn('s.pagarme_status', ['pending', 'processing']);
                })->count(),
            'supplier' => $base()
                ->where('p.slug', 'supplier_only')
                ->whereIn('s.status', ['active', 'trialing'])
                ->count(),
            'free' => $base()
                ->whereIn('p.slug', ['tiktok_free', 'demo'])
                ->where('s.status', 'active')
                ->count(),
        ];
    }

    #[OA\Get(
        path: '/api/v1/admin/clients/{id}',
        summary: 'Detalhes do cliente',
        description: 'Retorna detalhes completos de um cliente, incluindo assinaturas, contas de marketplace e contagem de pedidos recentes. Restrito a super_admin.',
        tags: ['Admin'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID do cliente', schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Detalhes do cliente',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'id', type: 'integer', example: 1),
                        new OA\Property(property: 'company_name', type: 'string', example: 'Loja Exemplo LTDA'),
                        new OA\Property(property: 'document', type: 'string', nullable: true, example: '12345678000190'),
                        new OA\Property(property: 'phone', type: 'string', nullable: true, example: '11999990000'),
                        new OA\Property(property: 'listing_mode', type: 'string', nullable: true, example: 'dropshipping'),
                        new OA\Property(property: 'is_active', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'user',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 5),
                                new OA\Property(property: 'name', type: 'string', example: 'Joao Silva'),
                                new OA\Property(property: 'email', type: 'string', example: 'joao@loja.com'),
                            ]
                        ),
                        new OA\Property(property: 'subscriptions', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'marketplace_accounts', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'recent_orders_count', type: 'integer', example: 15),
                        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-01-15T10:30:00Z'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Token ausente ou invalido'),
            new OA\Response(response: 403, description: 'Acesso negado — requer super_admin'),
            new OA\Response(response: 404, description: 'Cliente nao encontrado'),
        ]
    )]
    public function clientShow(Request $request, int|string $id): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $client = Client::with([
            'user:id,name,email,role,created_at',
            'subscriptions.plan:id,name,slug',
            'marketplaceAccounts:id,client_id,platform,account_name,seller_nickname,status,last_sync_at',
        ])->findOrFail($id);

        $recentOrdersCount = Order::where('client_id', $id)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        return response()->json([
            'id'                   => $client->id,
            'company_name'         => $client->company_name,
            'document'             => $client->document,
            'phone'                => $client->phone,
            'listing_mode'         => $client->listing_mode,
            'is_active'            => (bool) $client->is_active,
            'user'                 => $client->user ? [
                'id'         => $client->user->id,
                'name'       => $client->user->name,
                'email'      => $client->user->email,
                'role'       => $client->user->role,
                'created_at' => $client->user->created_at?->toIso8601String(),
            ] : null,
            'subscriptions'        => $client->subscriptions->map(fn ($sub) => [
                'id'         => $sub->id,
                'status'     => $sub->status,
                'plan'       => $sub->plan ? ['id' => $sub->plan->id, 'name' => $sub->plan->name, 'slug' => $sub->plan->slug] : null,
                'created_at' => $sub->created_at?->toIso8601String(),
            ])->values(),
            'marketplace_accounts' => $client->marketplaceAccounts->map(fn ($acc) => [
                'id'              => $acc->id,
                'platform'        => $acc->platform,
                'account_name'    => $acc->account_name,
                'seller_nickname' => $acc->seller_nickname,
                'status'          => $acc->status,
                'last_sync_at'    => $acc->last_sync_at?->toIso8601String(),
            ])->values(),
            'recent_orders_count'  => $recentOrdersCount,
            'created_at'           => $client->created_at?->toIso8601String(),
        ]);
    }

    #[OA\Put(
        path: '/api/v1/admin/clients/{id}',
        summary: 'Atualizar cliente',
        description: 'Atualiza dados do cliente. Permite alterar company_name, document, phone, is_active e listing_mode. Restrito a super_admin.',
        tags: ['Admin'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID do cliente', schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'company_name', type: 'string', nullable: true, example: 'Nova Razao Social LTDA'),
                    new OA\Property(property: 'document', type: 'string', nullable: true, example: '12345678000190'),
                    new OA\Property(property: 'phone', type: 'string', nullable: true, example: '11999990000'),
                    new OA\Property(property: 'is_active', type: 'boolean', nullable: true, example: true),
                    new OA\Property(property: 'listing_mode', type: 'string', nullable: true, example: 'dropshipping'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Cliente atualizado com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Cliente atualizado com sucesso.'),
                        new OA\Property(property: 'client', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Token ausente ou invalido'),
            new OA\Response(response: 403, description: 'Acesso negado — requer super_admin'),
            new OA\Response(response: 404, description: 'Cliente nao encontrado'),
            new OA\Response(response: 422, description: 'Dados invalidos'),
        ]
    )]
    public function clientUpdate(Request $request, int $id): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $client = Client::findOrFail($id);

        // MUL-269 fase 2: company_name removido de clients — nome vem do user (accessor).
        // Validacao continua aceitando o campo pra compat, mas ele nao vai pro update.
        $data = $request->validate([
            'company_name'            => 'sometimes|string|max:255',
            'document'                => 'sometimes|nullable|string|max:20',
            'phone'                   => 'sometimes|nullable|string|max:20',
            'is_active'               => 'sometimes|boolean',
            'listing_mode'            => 'sometimes|nullable|string|in:dropshipping,white_label,hybrid',
            'subscription_status'     => 'sometimes|string|in:active,trialing,cancelled,suspended',
            'subscription_expires_at' => 'sometimes|nullable|date',
        ]);

        $clientData = array_diff_key($data, array_flip(['subscription_status', 'subscription_expires_at', 'company_name']));
        if ($clientData) {
            $client->update($clientData);
            if (array_key_exists('is_active', $clientData) && ! $clientData['is_active']) {
                $client->user?->tokens()->delete();
            }
        }

        if ($request->has('subscription_status') || $request->has('subscription_expires_at')) {
            $sub = $client->subscriptions()->latest('id')->first();
            if ($sub) {
                $subUpdate = [];
                if ($request->has('subscription_status')) {
                    $subUpdate['status'] = $data['subscription_status'];
                    if ($data['subscription_status'] === 'active') {
                        $client->update(['is_active' => true]);
                    } elseif (in_array($data['subscription_status'], ['cancelled', 'suspended'])) {
                        $client->update(['is_active' => false]);
                        $client->user?->tokens()->delete();
                    }
                }
                if ($request->has('subscription_expires_at') && $data['subscription_expires_at']) {
                    $subUpdate['current_period_end'] = $data['subscription_expires_at'];
                }
                $sub->update($subUpdate);
            }
        }

        $client->refresh();
        $sub = $client->subscriptions()->latest('id')->first();

        return response()->json([
            'message' => 'Cliente atualizado com sucesso.',
            'client'  => [
                'id'                      => $client->id,
                'company_name'            => $client->company_name,
                'document'                => $client->document,
                'phone'                   => $client->phone,
                'is_active'               => (bool) $client->is_active,
                'listing_mode'            => $client->listing_mode,
                'subscription_status'     => $sub?->status,
                'subscription_expires_at' => $sub?->current_period_end?->toIso8601String(),
            ],
        ]);
    }

    // =========================================================================
    // DASHBOARD
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/admin/dashboard',
        summary: 'KPIs do dashboard administrativo',
        description: 'Retorna metricas agregadas do sistema: total de clientes, produtos, pedidos, fornecedores e receita. Restrito a super_admin.',
        tags: ['Admin'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'KPIs agregados do sistema',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'total_clients', type: 'integer', example: 350),
                        new OA\Property(property: 'active_clients', type: 'integer', example: 280),
                        new OA\Property(property: 'total_products', type: 'integer', example: 45000),
                        new OA\Property(property: 'total_orders', type: 'integer', example: 12000),
                        new OA\Property(property: 'total_suppliers', type: 'integer', example: 18),
                        new OA\Property(property: 'total_revenue', type: 'number', format: 'float', description: 'Soma de orders.total onde status=paid', example: 2850000.50),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Token ausente ou invalido'),
            new OA\Response(response: 403, description: 'Acesso negado — requer super_admin'),
        ]
    )]
    public function dashboard(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $data = Cache::remember('admin_dashboard_kpis', 300, function () {
            return [
                'total_clients'   => Client::count(),
                'active_clients'  => Client::where('is_active', true)->count(),
                'total_products'  => Product::where('is_active', true)->count(),
                'total_orders'    => Order::count(),
                'total_suppliers' => Supplier::where('is_active', true)->count(),
                'total_revenue'   => (float) Order::where('status', 'paid')->sum('total'),
                'orders_chart'    => collect(range(13, 0))->map(fn ($d) => [
                    'date'  => now()->subDays($d)->format('d/m'),
                    'count' => Order::whereDate('created_at', now()->subDays($d))->count(),
                ])->all(),
                'recent_clients'  => Client::with('user:id,name,email')->latest()->limit(8)->get()->map(fn ($c) => [
                    'id' => $c->id, 'company_name' => $c->company_name,
                    'name' => $c->user?->name, 'email' => $c->user?->email,
                    'created_at' => $c->created_at?->toIso8601String(),
                ])->all(),
                'sync_status'     => [
                    'pending_jobs' => (int) \DB::table('jobs')->count(),
                    'failed_jobs'  => (int) \DB::table('failed_jobs')->count(),
                ],
            ];
        });

        return response()->json($data);
    }

    // =========================================================================
    // SINCRONIZACAO — historico pra /admin/sync
    // =========================================================================

    /**
     * GET /api/v1/admin/sync — historico dos jobs de sync legado→novo.
     */
    public function sync(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $runs = \App\Models\LegacySyncRun::orderByDesc('created_at')->limit(50)->get();

        // Status atual: ultima execucao por job
        $latestByJob = $runs->groupBy('job')->map(fn ($g) => $g->first());

        return response()->json([
            'data' => [
                'runs'       => $runs->map(fn ($r) => [
                    'id'           => $r->id,
                    'job'          => $r->job,
                    'status'       => $r->status,
                    'processed'    => $r->processed,
                    'errors'       => $r->errors,
                    'message'      => $r->message,
                    'started_at'   => $r->started_at?->toIso8601String(),
                    'finished_at'  => $r->finished_at?->toIso8601String(),
                    'duration_ms'  => $r->duration_ms,
                ])->values(),
                'latest_by_job' => $latestByJob->map(fn ($r) => [
                    'job'         => $r->job,
                    'status'      => $r->status,
                    'processed'   => $r->processed,
                    'finished_at' => $r->finished_at?->toIso8601String(),
                ])->values(),
            ],
        ]);
    }

    // =========================================================================
    // FORNECEDORES
    // =========================================================================

    /**
     * GET /api/v1/admin/suppliers — lista todos os fornecedores.
     * Antes nao tinha endpoint de listagem, so POST/PUT/DELETE — e por
     * isso o /admin/suppliers do frontend mostrava "Nenhum cadastrado"
     * mesmo com 30 fornecedores no banco.
     */
    public function suppliers(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $query = Supplier::query()
            ->withCount(["clients", "products as products_count" => fn($q) => $q->where("is_active", 1)]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('company_name', 'like', "%{$search}%")
                  ->orWhere('document', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->query('status') !== 'all') {
            $query->where('is_active', $request->query('status') === 'active');
        }

        $paginator = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 25));

        return response()->json([
            'data' => $paginator->through(fn ($s) => [
                'id'              => $s->id,
                'company_name'    => $s->company_name,
                'display_name'    => $s->display_name ?? null,
                'document'        => $s->document,
                'type'            => $s->type,
                'is_active'       => (bool) $s->is_active,
                'is_factory'      => (bool) ($s->is_factory ?? false),
                'allows_direct_payment' => (bool) ($s->allows_direct_payment ?? false),
                'pix_fee'         => $s->pix_fee,
                'clients_count'   => $s->clients_count ?? 0,
                'products_count'  => $s->products_count ?? 0,
                'created_at'      => $s->created_at?->toIso8601String(),
            ])->items(),
            'meta' => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/admin/suppliers',
        summary: 'Criar fornecedor',
        description: 'Cria um novo fornecedor. Restrito a super_admin.',
        tags: ['Admin'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['company_name', 'type'],
                properties: [
                    new OA\Property(property: 'company_name', type: 'string', example: 'Fornecedor ABC LTDA'),
                    new OA\Property(property: 'type', type: 'string', enum: ['producer', 'warehouse'], example: 'warehouse'),
                    new OA\Property(property: 'document', type: 'string', nullable: true, example: '12345678000190'),
                    new OA\Property(property: 'phone', type: 'string', nullable: true, example: '11999990000'),
                    new OA\Property(property: 'address', type: 'string', nullable: true, example: 'Rua Exemplo, 123'),
                    new OA\Property(property: 'city', type: 'string', nullable: true, example: 'Sao Paulo'),
                    new OA\Property(property: 'state', type: 'string', nullable: true, example: 'SP'),
                    new OA\Property(property: 'zipcode', type: 'string', nullable: true, example: '01310100'),
                    new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Especialista em eletronicos'),
                    new OA\Property(property: 'pix_fee', type: 'number', nullable: true, example: 1.5),
                    new OA\Property(property: 'allows_direct_payment', type: 'boolean', nullable: true, example: true),
                    new OA\Property(property: 'is_factory', type: 'boolean', nullable: true, example: false),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Fornecedor criado com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Fornecedor criado com sucesso.'),
                        new OA\Property(property: 'supplier', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Token ausente ou invalido'),
            new OA\Response(response: 403, description: 'Acesso negado — requer super_admin'),
            new OA\Response(response: 422, description: 'Dados invalidos'),
        ]
    )]
    public function supplierStore(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $data = $request->validate([
            'company_name'          => 'required|string|max:255',
            'type'                  => 'required|string|in:producer,warehouse',
            'document'              => 'nullable|string|max:20',
            'phone'                 => 'nullable|string|max:20',
            'address'               => 'nullable|string|max:255',
            'city'                  => 'nullable|string|max:100',
            'state'                 => 'nullable|string|max:2',
            'zipcode'               => 'nullable|string|max:10',
            'description'           => 'nullable|string',
            'pix_fee'               => 'nullable|numeric|min:0|max:100',
            'allows_direct_payment' => 'nullable|boolean',
            'is_factory'            => 'nullable|boolean',
        ]);

        $supplier = Supplier::create(array_merge($data, ['is_active' => true]));

        Cache::forget('admin_dashboard_kpis');

        return response()->json([
            'message'  => 'Fornecedor criado com sucesso.',
            'supplier' => $this->formatSupplier($supplier),
        ], 201);
    }

    #[OA\Put(
        path: '/api/v1/admin/suppliers/{id}',
        summary: 'Atualizar fornecedor',
        description: 'Atualiza dados de um fornecedor existente. Restrito a super_admin.',
        tags: ['Admin'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID do fornecedor', schema: new OA\Schema(type: 'integer', example: 3)),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'company_name', type: 'string', nullable: true, example: 'Fornecedor ABC LTDA'),
                    new OA\Property(property: 'type', type: 'string', enum: ['producer', 'warehouse'], nullable: true, example: 'warehouse'),
                    new OA\Property(property: 'document', type: 'string', nullable: true, example: '12345678000190'),
                    new OA\Property(property: 'phone', type: 'string', nullable: true, example: '11999990000'),
                    new OA\Property(property: 'address', type: 'string', nullable: true, example: 'Rua Exemplo, 123'),
                    new OA\Property(property: 'city', type: 'string', nullable: true, example: 'Sao Paulo'),
                    new OA\Property(property: 'state', type: 'string', nullable: true, example: 'SP'),
                    new OA\Property(property: 'zipcode', type: 'string', nullable: true, example: '01310100'),
                    new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Especialista em eletronicos'),
                    new OA\Property(property: 'pix_fee', type: 'number', nullable: true, example: 1.5),
                    new OA\Property(property: 'allows_direct_payment', type: 'boolean', nullable: true, example: true),
                    new OA\Property(property: 'is_factory', type: 'boolean', nullable: true, example: false),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Fornecedor atualizado com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Fornecedor atualizado com sucesso.'),
                        new OA\Property(property: 'supplier', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Token ausente ou invalido'),
            new OA\Response(response: 403, description: 'Acesso negado — requer super_admin'),
            new OA\Response(response: 404, description: 'Fornecedor nao encontrado'),
            new OA\Response(response: 422, description: 'Dados invalidos'),
        ]
    )]
    public function supplierUpdate(Request $request, int $id): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $supplier = Supplier::findOrFail($id);

        $data = $request->validate([
            'company_name'          => 'sometimes|string|max:255',
            'type'                  => 'sometimes|string|in:producer,warehouse',
            'document'              => 'sometimes|nullable|string|max:20',
            'phone'                 => 'sometimes|nullable|string|max:20',
            'address'               => 'sometimes|nullable|string|max:255',
            'city'                  => 'sometimes|nullable|string|max:100',
            'state'                 => 'sometimes|nullable|string|max:2',
            'zipcode'               => 'sometimes|nullable|string|max:10',
            'description'           => 'sometimes|nullable|string',
            'pix_fee'               => 'sometimes|nullable|numeric|min:0|max:100',
            'allows_direct_payment' => 'sometimes|boolean',
            'is_factory'            => 'sometimes|boolean',
        ]);

        $supplier->update($data);

        return response()->json([
            'message'  => 'Fornecedor atualizado com sucesso.',
            'supplier' => $this->formatSupplier($supplier->fresh()),
        ]);
    }

    #[OA\Delete(
        path: '/api/v1/admin/suppliers/{id}',
        summary: 'Desativar fornecedor (soft-delete)',
        description: 'Desativa um fornecedor setando is_active=false. Nao exclui os dados. Restrito a super_admin.',
        tags: ['Admin'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID do fornecedor', schema: new OA\Schema(type: 'integer', example: 3)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Fornecedor desativado com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Fornecedor desativado com sucesso.'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Token ausente ou invalido'),
            new OA\Response(response: 403, description: 'Acesso negado — requer super_admin'),
            new OA\Response(response: 404, description: 'Fornecedor nao encontrado'),
        ]
    )]
    public function supplierDestroy(Request $request, int $id): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $supplier = Supplier::findOrFail($id);
        $supplier->update(['is_active' => false]);

        Cache::forget('admin_dashboard_kpis');

        return response()->json(['message' => 'Fornecedor desativado com sucesso.']);
    }


    // =========================================================================
    // FORNECEDORES — SHOW + WAREHOUSES ADMIN (MUL-153)
    // =========================================================================

    /** GET /api/v1/admin/suppliers/{id} — detalhes do fornecedor com depositos */
    public function supplierShow(Request $request, int $id): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $supplier = \App\Models\Supplier::withCount([
            'clients',
            'products as products_count' => fn($q) => $q->where('is_active', 1),
        ])->findOrFail($id);

        $warehouses = \App\Models\SupplierWarehouse::withoutTenantSupplierScope()
            ->where('supplier_id', $id)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return response()->json([
            'supplier'   => $this->formatSupplierFull($supplier),
            'warehouses' => $warehouses,
        ]);
    }

    /** GET /api/v1/admin/warehouses?supplier_id=X — lista depositos (admin-scoped) */
    public function warehousesIndex(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $query = \App\Models\SupplierWarehouse::withoutTenantSupplierScope();

        if ($supplierId = $request->query('supplier_id')) {
            $query->where('supplier_id', (int) $supplierId);
        }

        $rows = $query->orderByDesc('is_default')->orderBy('name')->get();
        return response()->json(['data' => $rows]);
    }

    /** POST /api/v1/admin/warehouses */
    public function warehousesStore(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $validated = $request->validate([
            'supplier_id'        => 'required|integer|exists:suppliers,id',
            'name'               => 'required|string|max:255',
            'address'            => 'nullable|string|max:255',
            'number'             => 'nullable|string|max:50',
            'complement'         => 'nullable|string|max:255',
            'district'           => 'nullable|string|max:255',
            'city'               => 'nullable|string|max:255',
            'state'              => 'nullable|string|size:2',
            'zip_code'           => 'nullable|string|max:16',
            'contact_name'       => 'nullable|string|max:255',
            'contact_phone'      => 'nullable|string|max:50',
            'contact_email'      => 'nullable|email|max:255',
            'active'             => 'boolean',
            'is_default'         => 'boolean',
            'legacy_deposito_id' => 'nullable|integer',
        ]);

        if (!empty($validated['is_default'])) {
            \App\Models\SupplierWarehouse::withoutTenantSupplierScope()
                ->where('supplier_id', $validated['supplier_id'])
                ->update(['is_default' => false]);
        }

        $wh = \App\Models\SupplierWarehouse::create($validated);
        return response()->json(['data' => $wh], 201);
    }

    /** PUT /api/v1/admin/warehouses/{id} */
    public function warehousesUpdate(Request $request, int $id): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $wh = \App\Models\SupplierWarehouse::withoutTenantSupplierScope()->findOrFail($id);

        $validated = $request->validate([
            'name'               => 'sometimes|string|max:255',
            'address'            => 'nullable|string|max:255',
            'number'             => 'nullable|string|max:50',
            'complement'         => 'nullable|string|max:255',
            'district'           => 'nullable|string|max:255',
            'city'               => 'nullable|string|max:255',
            'state'              => 'nullable|string|size:2',
            'zip_code'           => 'nullable|string|max:16',
            'contact_name'       => 'nullable|string|max:255',
            'contact_phone'      => 'nullable|string|max:50',
            'contact_email'      => 'nullable|email|max:255',
            'active'             => 'sometimes|boolean',
            'is_default'         => 'sometimes|boolean',
            'legacy_deposito_id' => 'nullable|integer',
        ]);

        if (!empty($validated['is_default'])) {
            \App\Models\SupplierWarehouse::withoutTenantSupplierScope()
                ->where('supplier_id', $wh->supplier_id)
                ->where('id', '!=', $id)
                ->update(['is_default' => false]);
        }

        $wh->update($validated);
        return response()->json(['data' => $wh->fresh()]);
    }

    /** DELETE /api/v1/admin/warehouses/{id} */
    public function warehousesDestroy(Request $request, int $id): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $wh = \App\Models\SupplierWarehouse::withoutTenantSupplierScope()->findOrFail($id);
        $wh->delete();
        return response()->json(['data' => ['deleted' => true]]);
    }

    /** Formata fornecedor com todos os campos para o admin panel */
    private function formatSupplierFull(\App\Models\Supplier $supplier): array
    {
        return array_merge($this->formatSupplier($supplier), [
            'display_name'          => $supplier->display_name,
            'trade_name'            => $supplier->trade_name ?? null,
            'ie'                    => $supplier->ie ?? null,
            'indicator_icms'        => $supplier->indicator_icms ?? null,
            'address_number'        => $supplier->address_number ?? null,
            'address_complement'    => $supplier->address_complement ?? null,
            'neighborhood'          => $supplier->neighborhood ?? null,
            'pix_key_type'          => $supplier->pix_key_type ?? null,
            'pix_key'               => $supplier->pix_key ?? null,
            'allows_direct_deposit' => (bool) ($supplier->allows_direct_deposit ?? true),
            'supports_meli_flex'    => (bool) ($supplier->supports_meli_flex ?? false),
            'flex_fee'              => $supplier->flex_fee ?? 0,
            'theme_color'           => $supplier->theme_color ?? null,
            'logo'                  => $supplier->logo ?? null,
            'clients_count'         => $supplier->clients_count ?? 0,
            'products_count'        => $supplier->products_count ?? 0,
        ]);
    }


    // =========================================================================
    // SYNC LEGADO
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/admin/sync-status',
        summary: 'Status do sync com o catalogo legado',
        description: 'Retorna informacoes sobre o ultimo sync realizado com o banco legado. Restrito a super_admin.',
        tags: ['Admin'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Status do sync',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'last_sync_at', type: 'string', nullable: true, format: 'date-time', example: '2026-05-12T08:00:00Z'),
                        new OA\Property(property: 'total_synced_products', type: 'integer', example: 45000),
                        new OA\Property(property: 'total_synced_suppliers', type: 'integer', example: 18),
                        new OA\Property(property: 'next_sync_estimated', type: 'string', nullable: true, format: 'date-time', example: '2026-05-12T09:00:00Z'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Token ausente ou invalido'),
            new OA\Response(response: 403, description: 'Acesso negado — requer super_admin'),
        ]
    )]
    public function syncStatus(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $recent = \App\Models\LegacySyncRun::orderByDesc('id')->limit(20)->get();
        $lastSync = $recent->first();

        // Status agregado: se a ultima run de qualquer job teve erro -> error;
        // se nada rodou -> pending; senao success.
        $status = 'pending';
        if ($recent->isNotEmpty()) {
            $anyError = $recent->take(3)->contains(fn ($r) => $r->status === 'failed');
            $partial  = $recent->take(3)->contains(fn ($r) => $r->status === 'partial');
            $status = $anyError ? 'error' : ($partial ? 'pending' : 'ok');
        }

        // Pendentes: pedidos com legacy_id mas sem status final (legado pode
        // ter mais por importar).
        $pendingCount = \App\Models\Order::whereNotNull('legacy_id')
            ->whereIn('status', ['pending_payment', 'paid'])
            ->count();

        return response()->json([
            'status'         => $status,
            'last_sync'      => $lastSync?->finished_at?->toIso8601String() ?? $lastSync?->started_at?->toIso8601String(),
            'pending_count'  => $pendingCount,
            'recent_syncs'   => $recent->map(fn ($r) => [
                'id'           => $r->id,
                'job'          => $r->job,
                // Mapeia status do banco pros 3 estados do STATUS_MAP frontend
                'status'       => match ($r->status) {
                    'success' => 'ok',
                    'failed'  => 'error',
                    'partial' => 'pending',
                    default   => 'pending',
                },
                'processed'    => $r->processed,
                'errors'       => $r->errors,
                'message'      => $r->message,
                'started_at'   => $r->started_at?->toIso8601String(),
                'finished_at'  => $r->finished_at?->toIso8601String(),
                'duration_ms'  => $r->duration_ms,
            ])->values(),
            // Legados pra compat:
            'last_sync_at'           => Cache::get('legacy_catalog_last_sync'),
            'total_synced_products'  => Product::whereNotNull('legacy_sku_pai_id')->count(),
            'total_synced_suppliers' => Supplier::whereNotNull('legacy_id')->count(),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/admin/sync/force',
        summary: 'Forcar re-sync do catalogo legado',
        description: 'Dispara o job SyncLegacyCatalogJob imediatamente. Use com cuidado — o job consome conexao com o banco legado. Restrito a super_admin.',
        tags: ['Admin'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 202,
                description: 'Job de sync enfileirado com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Sync enfileirado. O processo sera executado em breve.'),
                        new OA\Property(property: 'dispatched_at', type: 'string', format: 'date-time', example: '2026-05-12T09:00:00Z'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Token ausente ou invalido'),
            new OA\Response(response: 403, description: 'Acesso negado — requer super_admin'),
        ]
    )]
    public function syncForce(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        SyncLegacyCatalogJob::dispatch();

        return response()->json([
            'message'      => 'Sync enfileirado. O processo sera executado em breve.',
            'dispatched_at' => now()->toIso8601String(),
        ], 202);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function formatSupplier(Supplier $supplier): array
    {
        return [
            'id'                    => $supplier->id,
            'company_name'          => $supplier->company_name,
            'type'                  => $supplier->type,
            'document'              => $supplier->document,
            'phone'                 => $supplier->phone,
            'address'               => $supplier->address,
            'city'                  => $supplier->city,
            'state'                 => $supplier->state,
            'zipcode'               => $supplier->zipcode,
            'description'           => $supplier->description,
            'pix_fee'               => $supplier->pix_fee,
            'allows_direct_payment' => (bool) $supplier->allows_direct_payment,
            'is_factory'            => (bool) $supplier->is_factory,
            'is_active'             => (bool) $supplier->is_active,
            'created_at'            => $supplier->created_at?->toIso8601String(),
        ];
    }

    // =========================================================================
    // PEDIDOS (admin — todos os clientes)
    // =========================================================================

    /** GET /api/v1/admin/orders — lista todos os pedidos com filtros e paginacao. */
    public function orders(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $perPage = min((int) $request->query('per_page', 25), 100);
        // MUL-269 fase 2: nome do seller vem do user (accessor client->company_name).
        $query = Order::with(['client:id,user_id', 'client.user:id,name,full_name'])->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('client_id')) {
            $query->where('client_id', (int) $request->query('client_id'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }
        if ($request->filled('search')) {
            $s = $request->query('search');
            $query->where(function ($w) use ($s) {
                $w->where('order_number', 'like', "%{$s}%")
                  ->orWhere('customer_name', 'like', "%{$s}%");
            });
        }

        $p = $query->paginate($perPage);

        return response()->json([
            'data' => collect($p->items())->map(fn ($o) => [
                'id'              => $o->id,
                'order_number'    => $o->order_number,
                'client_id'       => $o->client_id,
                'client_name'     => $o->client?->company_name,
                'status'          => $o->status,
                'total'           => (float) $o->total,
                'source'          => $o->source,
                'customer_name'   => $o->customer_name,
                'tracking_number' => $o->tracking_number,
                'created_at'      => $o->created_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'total'        => $p->total(),
                'per_page'     => $p->perPage(),
                'current_page' => $p->currentPage(),
                'last_page'    => $p->lastPage(),
            ],
        ]);
    }

    /** GET /api/v1/admin/orders/{id} — detalhe de um pedido. */
    public function orderShow(Request $request, int $id): JsonResponse
    {
        $this->requireSuperAdmin($request);

        // MUL-269 fase 2: nome do seller vem do user (accessor client->company_name).
        $o = Order::with(['client:id,user_id', 'client.user:id,name,full_name', 'items'])->findOrFail($id);

        return response()->json([
            'id'              => $o->id,
            'order_number'    => $o->order_number,
            'legacy_id'       => $o->legacy_id,
            'client'          => $o->client ? ['id' => $o->client->id, 'company_name' => $o->client->company_name] : null,
            'status'          => $o->status,
            'source'          => $o->source,
            'total'           => (float) $o->total,
            'subtotal'        => (float) $o->subtotal,
            'shipping_cost'   => (float) $o->shipping_cost,
            'customer_name'   => $o->customer_name,
            'customer_email'  => $o->customer_email,
            'customer_phone'  => $o->customer_phone,
            'tracking_number' => $o->tracking_number,
            'tracking_url'    => $o->tracking_url,
            'label_url'       => $o->label_url,
            'paid_at'         => $o->paid_at?->toIso8601String(),
            'shipped_at'      => $o->shipped_at?->toIso8601String(),
            'delivered_at'    => $o->delivered_at?->toIso8601String(),
            'cancelled_at'    => $o->cancelled_at?->toIso8601String(),
            'created_at'      => $o->created_at?->toIso8601String(),
            'items'           => $o->items->map(fn ($i) => [
                'name'       => $i->name,
                'sku'        => $i->sku,
                'quantity'   => $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'total'      => (float) $i->total,
            ])->values(),
        ]);
    }

    /** PUT /api/v1/admin/orders/{id}/status — altera o status de um pedido. */
    public function orderUpdateStatus(Request $request, int $id): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $data = $request->validate([
            'status' => 'required|string|in:pending_payment,paid,processing,shipped,delivered,cancelled',
        ]);

        $o   = Order::findOrFail($id);
        $new = $data['status'];
        $updates = ['status' => $new];

        if ($new === 'paid'      && !$o->paid_at)      { $updates['paid_at']      = now(); }
        if ($new === 'shipped'   && !$o->shipped_at)   { $updates['shipped_at']   = now(); }
        if ($new === 'delivered' && !$o->delivered_at) { $updates['delivered_at'] = now(); }
        if ($new === 'cancelled' && !$o->cancelled_at) { $updates['cancelled_at'] = now(); }

        $o->update($updates);

        return response()->json([
            'message' => 'Status do pedido atualizado.',
            'order'   => ['id' => $o->id, 'status' => $o->status],
        ]);
    }

    // =========================================================================
    // PLANOS (CRUD)
    // =========================================================================

    public function plans(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $plans = \DB::table("plans")->orderBy("price_monthly")->get()->map(function ($p) {
            $arr = (array) $p;
            $arr["max_connections_per_platform"] = !empty($p->max_connections_per_platform)
                ? json_decode($p->max_connections_per_platform, true)
                : null;
            $pivotRows = \DB::table("plan_supplier")
                ->where("plan_id", $p->id)
                ->get(["supplier_id", "available_from"]);
            $supplierIds = $pivotRows->pluck("supplier_id");
            $releaseDates = [];
            foreach ($pivotRows as $row) {
                if (!empty($row->available_from)) {
                    $releaseDates[(int) $row->supplier_id] = substr((string) $row->available_from, 0, 10);
                }
            }
            $suppliers = \DB::table("suppliers")
                ->whereIn("id", $supplierIds)
                ->select("id", "company_name", "display_name", "is_active")
                ->get()
                ->map(function ($s) use ($releaseDates) {
                    $sa = (array) $s;
                    $sa["available_from"] = $releaseDates[(int) $s->id] ?? null;
                    return $sa;
                })
                ->values();
            $arr["suppliers"] = $suppliers;
            $arr["supplier_ids"] = $supplierIds->values();
            $arr["supplier_release_dates"] = (object) $releaseDates;
            return $arr;
        })->values();

        return response()->json(["data" => $plans]);
    }

    public function planStore(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);
        $data = $request->validate([
            "name"                        => "required|string|max:255",
            "slug"                        => "nullable|string|max:255|unique:plans,slug",
            "description"                 => "nullable|string",
            "max_skus"                    => "required|integer|min:0",
            "max_marketplace_connections" => "sometimes|integer|min:0",
            "max_erp_connections"         => "sometimes|integer|min:0",
            "max_connections_per_platform"   => "sometimes|nullable|array",
            "max_connections_per_platform.*" => "integer|min:0",
            "price_monthly"               => "required|numeric|min:0",
            "price_yearly"                => "sometimes|numeric|min:0",
            "trial_days"                  => "sometimes|integer|min:0",
            "affiliate_commission_percent" => "nullable|numeric|min:0|max:100",
            "is_active"                   => "sometimes|boolean",
            "supplier_ids"                => "sometimes|array",
            "supplier_ids.*"              => "integer|exists:suppliers,id",
            "ai_monthly_video_limit"      => "sometimes|integer|min:0",
            "ai_monthly_credits"          => "sometimes|integer|min:0",
            "supplier_release_dates"      => "sometimes|nullable|array",
            "supplier_release_dates.*"    => "nullable|date",
        ]);

        $supplierIds = $data["supplier_ids"] ?? [];
        unset($data["supplier_ids"]);
        $releaseDates = $data["supplier_release_dates"] ?? [];
        unset($data["supplier_release_dates"]);

        if (empty($data["slug"])) {
            $base = preg_replace("/[^a-z0-9]+/", "-", strtolower($data["name"]));
            $slug = trim($base, "-");
            $n = 0;
            while (\DB::table("plans")->where("slug", $slug)->exists()) {
                $slug = $base . "-" . (++$n);
            }
            $data["slug"] = $slug;
        }

        $data["max_marketplace_connections"] = $data["max_marketplace_connections"] ?? 0;
        $data["max_erp_connections"]         = $data["max_erp_connections"] ?? 0;
        $data["price_yearly"]                = $data["price_yearly"] ?? 0;
        $data["trial_days"]                  = $data["trial_days"] ?? 0;
        $data["is_active"]                   = $data["is_active"] ?? true;
        if (array_key_exists("max_connections_per_platform", $data)) {
            $data["max_connections_per_platform"] = !empty($data["max_connections_per_platform"])
                ? json_encode($data["max_connections_per_platform"])
                : null;
        }
        $data["created_at"]                  = now();
        $data["updated_at"]                  = now();

        $id = \DB::table("plans")->insertGetId($data);

        if (!empty($supplierIds)) {
            $now = now();
            $rows = array_map(fn ($sid) => [
                "plan_id"        => $id,
                "supplier_id"    => $sid,
                "available_from" => !empty($releaseDates[$sid]) ? substr((string) $releaseDates[$sid], 0, 10) : null,
                "created_at"     => $now,
                "updated_at"     => $now,
            ], $supplierIds);
            \DB::table("plan_supplier")->insert($rows);
        }

        return response()->json(["message" => "Plano criado.", "plan" => \DB::table("plans")->find($id)], 201);
    }

    public function planUpdate(Request $request, int $id): JsonResponse
    {
        $this->requireSuperAdmin($request);
        if (!\DB::table("plans")->where("id", $id)->exists()) { abort(404, "Plano nao encontrado."); }

        $data = $request->validate([
            "name"                        => "sometimes|string|max:255",
            "slug"                        => "sometimes|nullable|string|max:255|unique:plans,slug," . $id,
            "description"                 => "sometimes|nullable|string",
            "max_skus"                    => "sometimes|integer|min:0",
            "max_marketplace_connections" => "sometimes|integer|min:0",
            "max_erp_connections"         => "sometimes|integer|min:0",
            "max_connections_per_platform"   => "sometimes|nullable|array",
            "max_connections_per_platform.*" => "integer|min:0",
            "price_monthly"               => "sometimes|numeric|min:0",
            "price_yearly"                => "sometimes|numeric|min:0",
            "trial_days"                  => "sometimes|integer|min:0",
            "affiliate_commission_percent" => "sometimes|nullable|numeric|min:0|max:100",
            "is_active"                   => "sometimes|boolean",
            "supplier_ids"                => "sometimes|array",
            "supplier_ids.*"              => "integer|exists:suppliers,id",
            "ai_monthly_video_limit"      => "sometimes|integer|min:0",
            "ai_monthly_credits"          => "sometimes|integer|min:0",
            "supplier_release_dates"      => "sometimes|nullable|array",
            "supplier_release_dates.*"    => "nullable|date",
        ]);

        $supplierIds = array_key_exists("supplier_ids", $data) ? $data["supplier_ids"] : null;
        unset($data["supplier_ids"]);
        $releaseDates = $data["supplier_release_dates"] ?? [];
        unset($data["supplier_release_dates"]);

        if (isset($data["slug"]) && empty($data["slug"])) {
            $name = $data["name"] ?? \DB::table("plans")->where("id", $id)->value("name");
            $base = preg_replace("/[^a-z0-9]+/", "-", strtolower($name));
            $slug = trim($base, "-");
            $n = 0;
            while (\DB::table("plans")->where("slug", $slug)->where("id", "!=", $id)->exists()) {
                $slug = $base . "-" . (++$n);
            }
            $data["slug"] = $slug;
        }

        if (array_key_exists("max_connections_per_platform", $data)) {
            $data["max_connections_per_platform"] = !empty($data["max_connections_per_platform"])
                ? json_encode($data["max_connections_per_platform"])
                : null;
        }

        if (!empty($data)) {
            $data["updated_at"] = now();
            \DB::table("plans")->where("id", $id)->update($data);
        }

        if ($supplierIds !== null) {
            \DB::table("plan_supplier")->where("plan_id", $id)->delete();
            if (!empty($supplierIds)) {
                $now = now();
                $rows = array_map(fn ($sid) => [
                    "plan_id"        => $id,
                    "supplier_id"    => $sid,
                    "available_from" => !empty($releaseDates[$sid]) ? substr((string) $releaseDates[$sid], 0, 10) : null,
                    "created_at"     => $now,
                    "updated_at"     => $now,
                ], $supplierIds);
                \DB::table("plan_supplier")->insert($rows);
            }
        }

        return response()->json(["message" => "Plano atualizado.", "plan" => \DB::table("plans")->find($id)]);
    }

    public function planDestroy(Request $request, int $id): JsonResponse
    {
        $this->requireSuperAdmin($request);
        if (!\DB::table("plans")->where("id", $id)->exists()) { abort(404, "Plano nao encontrado."); }
        $subs = (int) \DB::table("subscriptions")->where("plan_id", $id)->count();
        if ($subs > 0) {
            return response()->json([
                "error"   => "plan_in_use",
                "message" => "Plano tem {$subs} assinatura(s) vinculada(s). Desative (is_active=false) em vez de apagar.",
            ], 422);
        }
        \DB::table("plan_supplier")->where("plan_id", $id)->delete();
        \DB::table("plans")->where("id", $id)->delete();
        return response()->json(["message" => "Plano removido."]);
    }

    // =========================================================================
    // DIRETORIO DE FORNECEDORES (Lista de Fornecedores — SEL-066)
    // =========================================================================

    public function directorySuppliers(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $query = \DB::table('directory_suppliers');

        $status = $request->input('status', 'active');
        if ($status === 'active') {
            $query->where('is_active', 1);
        } elseif ($status === 'inactive') {
            $query->where('is_active', 0);
        }

        if ($request->filled('q')) {
            $term = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $request->input('q')) . '%';
            $query->where(function ($w) use ($term) {
                $w->where('name', 'like', $term)
                  ->orWhere('description', 'like', $term)
                  ->orWhere('notes', 'like', $term)
                  ->orWhere('phone', 'like', $term)
                  ->orWhere('whatsapp', 'like', $term)
                  ->orWhere('location', 'like', $term)
                  ->orWhere('categories', 'like', $term);
            });
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $p = $query->orderBy('name')->paginate($perPage);

        $items = collect($p->items())->map(function ($r) {
            $arr = (array) $r;
            $arr['categories'] = json_decode($arr['categories'] ?? 'null', true);
            unset($arr['sources'], $arr['other_socials'], $arr['commercial_terms'], $arr['shipping_info']);
            return $arr;
        })->values();

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $p->currentPage(),
                'last_page'    => $p->lastPage(),
                'per_page'     => $p->perPage(),
                'total'        => $p->total(),
            ],
        ]);
    }

    public function directorySupplierUpdate(Request $request, int $id): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $data = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'phone'       => 'sometimes|nullable|string|max:40',
            'whatsapp'    => 'sometimes|nullable|string|max:40',
            'email'       => 'sometimes|nullable|string|max:255',
            'instagram'   => 'sometimes|nullable|string|max:255',
            'site'        => 'sometimes|nullable|string|max:500',
            'catalog_url' => 'sometimes|nullable|string|max:1000',
            'cover_url'   => 'sometimes|nullable|string|max:1000',
            'location'    => 'sometimes|nullable|string|max:500',
            'description' => 'sometimes|nullable|string|max:2000',
            'verified'    => 'sometimes|boolean',
            'is_active'   => 'sometimes|boolean',
        ]);

        if (!\DB::table('directory_suppliers')->where('id', $id)->exists()) {
            abort(404, 'Fornecedor nao encontrado.');
        }

        if ($data !== []) {
            $data['updated_at'] = now();
            \DB::table('directory_suppliers')->where('id', $id)->update($data);
        }

        return response()->json(['message' => 'Fornecedor atualizado.']);
    }

    public function directorySupplierDestroy(Request $request, int $id): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $updated = \DB::table('directory_suppliers')->where('id', $id)->update([
            'is_active'  => 0,
            'updated_at' => now(),
        ]);

        if (!$updated && !\DB::table('directory_suppliers')->where('id', $id)->exists()) {
            abort(404, 'Fornecedor nao encontrado.');
        }

        return response()->json(['message' => 'Fornecedor removido da lista (is_active=0).']);
    }

    // =========================================================================
    // TAXAS DE MARKETPLACE (CRUD — lista simples)
    // =========================================================================

    public function marketplaceFees(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);
        $q = \DB::table('marketplace_fees')->orderBy('platform')->orderBy('category_name');
        if ($request->filled('platform')) { $q->where('platform', $request->query('platform')); }
        return response()->json(['data' => $q->get()->map(fn ($f) => (array) $f)->values()]);
    }

    public function marketplaceFeeStore(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);
        $data = $request->validate([
            'platform'          => 'required|string|max:255',
            'category_id'       => 'nullable|string|max:255',
            'category_name'     => 'required|string|max:255',
            'listing_type_id'   => 'nullable|string|max:255',
            'fee_percentage'    => 'required|numeric|min:0|max:100',
            'fixed_fee'         => 'required|numeric|min:0',
            'shipping_fee_type' => 'nullable|string|max:255',
            'min_price'         => 'nullable|numeric|min:0',
            'max_price'         => 'nullable|numeric|min:0',
            'is_active'         => 'sometimes|boolean',
        ]);
        $data['is_active']  = $data['is_active'] ?? true;
        $data['source']     = 'admin';
        $data['created_at'] = now();
        $data['updated_at'] = now();
        $id = \DB::table('marketplace_fees')->insertGetId($data);
        return response()->json(['message' => 'Taxa criada.', 'fee' => \DB::table('marketplace_fees')->find($id)], 201);
    }

    public function marketplaceFeeUpdate(Request $request, int $id): JsonResponse
    {
        $this->requireSuperAdmin($request);
        if (!\DB::table('marketplace_fees')->where('id', $id)->exists()) { abort(404, 'Taxa nao encontrada.'); }
        $data = $request->validate([
            'platform'          => 'sometimes|string|max:255',
            'category_id'       => 'sometimes|nullable|string|max:255',
            'category_name'     => 'sometimes|string|max:255',
            'listing_type_id'   => 'sometimes|nullable|string|max:255',
            'fee_percentage'    => 'sometimes|numeric|min:0|max:100',
            'fixed_fee'         => 'sometimes|numeric|min:0',
            'shipping_fee_type' => 'sometimes|nullable|string|max:255',
            'min_price'         => 'sometimes|nullable|numeric|min:0',
            'max_price'         => 'sometimes|nullable|numeric|min:0',
            'is_active'         => 'sometimes|boolean',
        ]);
        $data['updated_at'] = now();
        \DB::table('marketplace_fees')->where('id', $id)->update($data);
        return response()->json(['message' => 'Taxa atualizada.', 'fee' => \DB::table('marketplace_fees')->find($id)]);
    }

    public function marketplaceFeeDestroy(Request $request, int $id): JsonResponse
    {
        $this->requireSuperAdmin($request);
        if (!\DB::table('marketplace_fees')->where('id', $id)->exists()) { abort(404, 'Taxa nao encontrada.'); }
        \DB::table('marketplace_fees')->where('id', $id)->delete();
        return response()->json(['message' => 'Taxa removida.']);
    }

    // =========================================================================
    // CONFIGURACOES (settings) — valores secretos sao mascarados
    // =========================================================================

    private function isSecretSetting(string $key): bool
    {
        return (bool) preg_match('/secret|token|password|senha|api[_-]?key|apikey|private/i', $key);
    }

    public function settings(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);
        $rows = \DB::table('settings')->orderBy('group')->orderBy('key')->get();
        $out = [];
        foreach ($rows as $s) {
            $secret = $this->isSecretSetting($s->key);
            $out[$s->group][] = [
                'id'     => $s->id,
                'key'    => $s->key,
                'value'  => $secret ? '***' : $s->value,
                'masked' => $secret,
            ];
        }
        return response()->json(['data' => $out]);
    }

    public function settingUpdate(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);
        $data = $request->validate([
            'group' => 'required|string|max:255',
            'key'   => 'required|string|max:255',
            'value' => 'nullable|string',
        ]);
        if (!\DB::table('settings')->where('group', $data['group'])->where('key', $data['key'])->exists()) {
            abort(404, 'Configuracao nao encontrada.');
        }
        // Nao sobrescrever segredo com o valor mascarado '***' (significa "nao alterado").
        if ($this->isSecretSetting($data['key']) && $data['value'] === '***') {
            return response()->json(['message' => 'Valor mascarado — nenhuma alteracao aplicada.']);
        }
        \DB::table('settings')->where('group', $data['group'])->where('key', $data['key'])
            ->update(['value' => $data['value'], 'updated_at' => now()]);
        return response()->json(['message' => 'Configuracao atualizada.']);
    }

    // =========================================================================
    // WALLET DE CLIENTE + ALTERAR PLANO
    // =========================================================================

    public function clientWallet(Request $request, int $id): JsonResponse
    {
        $this->requireSuperAdmin($request);
        if (!Client::where('id', $id)->exists()) { abort(404, 'Cliente nao encontrado.'); }

        $balances = \DB::table('client_supplier_balances')->where('client_id', $id)->get();
        $perPage  = min((int) $request->query('per_page', 25), 100);
        $tx = \DB::table('client_supplier_transactions')->where('client_id', $id)
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'balances'     => $balances->map(fn ($b) => [
                'supplier_id' => $b->supplier_id,
                'balance'     => (float) $b->balance,
            ])->values(),
            'transactions' => [
                'data' => collect($tx->items())->map(fn ($t) => [
                    'id'          => $t->id,
                    'type'        => $t->type,
                    'amount'      => (float) $t->amount,
                    'description' => $t->description,
                    'created_at'  => $t->created_at,
                ])->values(),
                'meta' => [
                    'total'        => $tx->total(),
                    'per_page'     => $tx->perPage(),
                    'current_page' => $tx->currentPage(),
                    'last_page'    => $tx->lastPage(),
                ],
            ],
        ]);
    }

    public function clientChangePlan(Request $request, int $id): JsonResponse
    {
        $this->requireSuperAdmin($request);
        if (!Client::where('id', $id)->exists()) { abort(404, 'Cliente nao encontrado.'); }

        $data = $request->validate(['plan_id' => 'required|integer|exists:plans,id']);

        $sub = \DB::table('subscriptions')->where('client_id', $id)->orderByDesc('id')->first();
        if (!$sub) {
            // Cria assinatura automaticamente se cliente nao tem nenhuma
            $subId = \DB::table('subscriptions')->insertGetId([
                'client_id'            => $id,
                'plan_id'              => $data['plan_id'],
                'status'               => 'active',
                'current_period_start' => now(),
                'current_period_end'   => now()->addDays(30),
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);
            \DB::table('clients')->where('id', $id)->update(['is_active' => true]);
            return response()->json([
                'message'      => 'Assinatura criada e plano aplicado.',
                'subscription' => ['id' => $subId, 'plan_id' => (int) $data['plan_id']],
            ]);
        }
        \DB::table('subscriptions')->where('id', $sub->id)
            ->update(['plan_id' => $data['plan_id'], 'updated_at' => now()]);
        \DB::table('clients')->where('id', $id)->update(['is_active' => true]);

        return response()->json([
            'message'      => 'Plano do cliente alterado.',
            'subscription' => ['id' => $sub->id, 'plan_id' => (int) $data['plan_id']],
        ]);
    }

    // =========================================================================
    // CRIAR CLIENTE (usuario + client record)
    // =========================================================================

    public function clientStore(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);
        // MUL-269 fase 2: company_name removido de clients — nome vem do user (accessor).
        // Se company_name vier no payload, atualiza o USER.full_name; company_name
        // do client nao existe mais.
        $data = $request->validate([
            'name'         => 'required|string|max:255',
            'email'        => 'required|email|unique:users,email',
            'password'     => 'required|string|min:6',
            'company_name' => 'nullable|string|max:255',
            'document'     => 'nullable|string|max:20',
            'phone'        => 'nullable|string|max:20',
            'role'         => 'sometimes|in:client,supplier,admin,super_admin',
            'plan_id'      => 'nullable|integer|exists:plans,id',
        ], [
            'email.unique' => 'E-mail ja cadastrado no sistema - edite o usuario existente ou use outro e-mail.',
        ]);
        $clientId = null;
        $user = \DB::transaction(function () use ($data, &$clientId) {
            // MUL-269 fase 2: se veio company_name no payload, usa como full_name do user.
            $__role = $data['role'] ?? 'client'; $__pwd = $__role === 'client' ? '123456' : $data['password']; // SEL 08/08 Ruan: cliente SEMPRE senha 123456
            $userAttrs = ['name' => $data['name'], 'email' => $data['email'], 'password' => \Illuminate\Support\Facades\Hash::make($__pwd), 'role' => $__role, 'is_active' => true];
            if (!empty($data['company_name'])) {
                $userAttrs['full_name'] = $data['company_name'];
            }
            $user = \App\Models\User::create($userAttrs);
            if (($data['role'] ?? 'client') === 'client') {
                // updateOrCreate e idempotente com o UserObserver (FOR-027 fix Bug1+2)
                // MUL-269 fase 2: company_name removido de clients.
                $client = \App\Models\Client::updateOrCreate(
                    ['user_id' => $user->id],
                    ['document' => $data['document'] ?? '00000000000000', 'phone' => $data['phone'] ?? null, 'is_active' => true]
                );
                $planId = !empty($data['plan_id']) ? (int) $data['plan_id'] : null;
                $client->subscriptions()->create([
                    'status'               => $planId ? 'active' : 'trialing',
                    'plan_id'              => $planId,
                    'trial_ends_at'        => $planId ? null : now()->addDays(30),
                    'current_period_start' => now(),
                    'current_period_end'   => now()->addDays(30),
                ]);
                $clientId = $client->id;
            }
            return $user;
        });
        // SEL 08/08 Ruan: acesso automatico (email + senha 123456) pra cliente criado no admin.
        if (($data['role'] ?? 'client') === 'client') {
            try {
                $__u = \App\Models\User::find($user->id);
                $__c = \App\Models\Client::where('user_id', $user->id)->first();
                $__p = !empty($data['plan_id']) ? \App\Models\Plan::find($data['plan_id']) : (\App\Models\Plan::where('slug', 'video_ultra')->first() ?: \App\Models\Plan::where('is_active', 1)->first());
                if ($__u && $__c && $__p) {
                    \Illuminate\Support\Facades\Mail::to($__u->email)->queue(new \App\Mail\SellerWelcomeMail($__u, $__c, $__p, '123456', 'https://whatsapp.com/channel/0029VbAzaW30gcfNCtU7MZ0U'));
                }
            } catch (\Throwable $e) { \Illuminate\Support\Facades\Log::warning('[AdminController] SellerWelcomeMail falhou', ['e' => $e->getMessage()]); }
        }
        return response()->json(['message' => 'Usuario criado com sucesso.', 'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role], 'id' => $clientId], 201);
    }

    // =========================================================================
    // AFILIADOS ADMIN
    // =========================================================================

    public function affiliates(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);
        $query = \App\Models\Affiliate::with(['user:id,name,email'])->latest();
        if ($status = $request->query('status')) { $query->where('status', $status); }
        if ($search = $request->query('search')) {
            $query->whereHas('user', fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }
        $list = $query->paginate((int) $request->query('per_page', 20));
        return response()->json([
            'data' => collect($list->items())->map(fn ($a) => ['id' => $a->id, 'user_id' => $a->user_id, 'user_name' => $a->user?->name, 'user_email' => $a->user?->email, 'referral_code' => $a->referral_code, 'commission_rate' => (float) $a->commission_rate, 'status' => $a->status, 'total_earned' => (float) $a->total_earned, 'total_withdrawn' => (float) $a->total_withdrawn, 'balance' => round((float) $a->total_earned - (float) $a->total_withdrawn, 2), 'created_at' => $a->created_at])->values(),
            'meta' => ['total' => $list->total(), 'per_page' => $list->perPage(), 'current_page' => $list->currentPage(), 'last_page' => $list->lastPage()],
        ]);
    }

    public function affiliateUpdate(Request $request, int $id): JsonResponse
    {
        $this->requireSuperAdmin($request);
        $affiliate = \App\Models\Affiliate::findOrFail($id);
        $data = $request->validate(['status' => 'sometimes|in:active,inactive,suspended', 'commission_rate' => 'sometimes|numeric|min:0|max:100']);
        $affiliate->update($data);
        return response()->json(['message' => 'Afiliado atualizado.', 'affiliate' => $affiliate]);
    }

    public function affiliateWithdrawals(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);
        $query = \App\Models\AffiliateWithdrawal::with(['affiliate.user:id,name,email'])->latest();
        if ($status = $request->query('status')) { $query->where('status', $status); }
        $list = $query->paginate((int) $request->query('per_page', 20));
        return response()->json([
            'data' => collect($list->items())->map(fn ($w) => ['id' => $w->id, 'affiliate_id' => $w->affiliate_id, 'user_name' => $w->affiliate?->user?->name, 'user_email' => $w->affiliate?->user?->email, 'amount' => (float) $w->amount, 'pix_key' => $w->pix_key, 'pix_type' => $w->pix_type, 'status' => $w->status, 'created_at' => $w->created_at])->values(),
            'meta' => ['total' => $list->total(), 'per_page' => $list->perPage(), 'current_page' => $list->currentPage(), 'last_page' => $list->lastPage()],
        ]);
    }

    public function affiliateWithdrawalPay(Request $request, int $id): JsonResponse
    {
        $this->requireSuperAdmin($request);
        $withdrawal = \App\Models\AffiliateWithdrawal::findOrFail($id);
        if ($withdrawal->status !== 'pending') { abort(422, 'Saque ja processado.'); }
        \DB::transaction(function () use ($withdrawal) {
            $withdrawal->update(['status' => 'paid']);
            $aff = \App\Models\Affiliate::find($withdrawal->affiliate_id);
            if ($aff) { $aff->increment('total_withdrawn', $withdrawal->amount); }
        });
        return response()->json(['message' => 'Saque marcado como pago.']);
    }


    // =========================================================================
    // PLATFORM SETTINGS (taxas e configuracoes financeiras)
    // =========================================================================

    public function platformSettings(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);
        $rows = \DB::table('settings')
            ->whereIn('group', ['fees', 'platform'])
            ->orderBy('group')
            ->orderBy('key')
            ->get();
        $data = [];
        foreach ($rows as $s) {
            $data[$s->key] = $s->value;
        }
        return response()->json(['data' => $data]);
    }

    public function updatePlatformSetting(Request $request, string $key): JsonResponse
    {
        $this->requireSuperAdmin($request);
        $request->validate(['value' => 'required|string']);
        $updated = \DB::table('settings')
            ->whereIn('group', ['fees', 'platform'])
            ->where('key', $key)
            ->update(['value' => $request->value, 'updated_at' => now()]);
        if (!$updated) {
            abort(404, 'Configuracao nao encontrada.');
        }
        return response()->json(['message' => 'updated']);
    }


    // =========================================================================
    // INTEGRACOES (marketplace_accounts) — super_admin
    // =========================================================================

    public function integrations(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $this->requireSuperAdmin($request);

        $query = \App\Models\MarketplaceAccount::with(['client.user:id,name,email'])->latest();

        if ($platform = $request->query('platform')) {
            $query->where('platform', $platform);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('client.user', fn ($u) =>
                    $u->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")
                )->orWhere('seller_nickname', 'like', "%{$search}%")->orWhere('account_name', 'like', "%{$search}%");
            });
        }

        $list = $query->paginate((int) $request->query('per_page', 30));

        return response()->json([
            'data' => collect($list->items())->map(fn ($acc) => [
                'id'                => $acc->id,
                'client_id'         => $acc->client_id,
                'client_name'       => $acc->client?->user?->name,
                'client_email'      => $acc->client?->user?->email,
                'platform'          => $acc->platform,
                'account_name'      => $acc->account_name,
                'seller_nickname'   => $acc->seller_nickname,
                'shop_id'           => $acc->shop_id,
                'seller_id'         => $acc->seller_id,
                'status'            => $acc->status,
                'import_mode'       => $acc->import_mode,
                'pricing_strategy'  => $acc->pricing_strategy,
                'price_margin'      => (float) $acc->price_margin,
                'token_expires_at'  => $acc->token_expires_at ?? $acc->ml_token_expires_at ?? $acc->bling_token_expires_at,
                'last_sync_at'      => $acc->last_sync_at,
                'sync_errors_count' => $acc->sync_errors_count,
                'sync_blocked_at'   => $acc->sync_blocked_at,
                'created_at'        => $acc->created_at,
            ])->values(),
            'meta' => [
                'total'        => $list->total(),
                'per_page'     => $list->perPage(),
                'current_page' => $list->currentPage(),
                'last_page'    => $list->lastPage(),
            ],
        ]);
    }

    public function integrationDestroy(\Illuminate\Http\Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $this->requireSuperAdmin($request);
        $acc = \App\Models\MarketplaceAccount::findOrFail($id);
        $acc->update([
            'status'                 => 'disconnected',
            'access_token'           => null,
            'refresh_token'          => null,
            'ml_access_token'        => null,
            'ml_refresh_token'       => null,
            'bling_access_token'     => null,
            'bling_refresh_token'    => null,
            'token_expires_at'       => null,
            'ml_token_expires_at'    => null,
            'bling_token_expires_at' => null,
        ]);
        return response()->json(['message' => 'Integracao desconectada.']);
    }

    public function integrationResetErrors(\Illuminate\Http\Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $this->requireSuperAdmin($request);
        $acc = \App\Models\MarketplaceAccount::findOrFail($id);
        $acc->update(['sync_errors_count' => 0, 'sync_blocked_at' => null]);
        return response()->json(['message' => 'Erros resetados. Sync desbloqueado.']);
    }

    // =========================================================================
    // SEL-032: DASHBOARD DO OPERADOR + FUNIL PIX (admin)
    // =========================================================================

    public function operatorDashboard(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $mrr = (float) \App\Models\Subscription::where('subscriptions.status', 'active')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->sum('plans.price_monthly');

        return response()->json([
            'mrr'                    => $mrr,
            'affiliates_active'      => \App\Models\Affiliate::where('status', 'active')->count(),
            'subscriptions_active'   => \App\Models\Subscription::where('status', 'active')->count(),
            'subscriptions_trialing' => \App\Models\Subscription::where('status', 'trialing')->count(),
            'top_affiliates'         => [],
        ]);
    }

    public function deposits(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $perPage = min((int) $request->query('per_page', 100), 500);

        $subs = \App\Models\Subscription::with('plan:id,name,price_monthly')
            ->where('payment_method', 'pix')
            ->latest()
            ->limit($perPage)
            ->get()
            ->map(fn ($s) => [
                'id'                  => $s->id,
                'created_at'          => optional($s->created_at)->toIso8601String(),
                'status'              => $s->status === 'active' ? 'paid' : ($s->status === 'cancelled' ? 'cancelled' : 'pending'),
                'subscription_status' => $s->status,
                'payment_method'      => $s->payment_method,
                'plan'                => $s->plan->name ?? null,
                'amount'              => (float) ($s->plan->price_monthly ?? 0),
            ]);

        return response()->json(['data' => $subs]);
    }


    /**
     * INF-030 (06/08/2026) — Saude da geracao de video (Studio).
     * GET /api/v1/admin/video-health
     *
     * KPIs:
     *  - delivered_1h: pipelines com step=done e output_url preenchido, updated_at na ultima 1h
     *  - delivery_rate_3h_pct: % de pipelines criados nas ultimas 3h que terminaram done+com url
     *  - queue_now: jobs pendentes na tabela jobs, filtrado pelas filas de video
     *  - ghosts_24h: step=done mas output_url vazio, criados nas ultimas 24h (deveria ser ~0)
     *
     * Restrito a super_admin — mesmo padrao de AdminController::deposits().
     */
    public function videoHealth(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $now = now();

        // Vídeos entregues na última 1h (done + output_url preenchido)
        $delivered1h = \DB::table('ai_video_pipelines')
            ->where('step', 'done')
            ->whereNotNull('output_url')
            ->where('output_url', '<>', '')
            ->where('updated_at', '>=', $now->copy()->subHour())
            ->count();

        // Taxa de entrega nas últimas 3h: done-com-url / total criados no período
        $total3h = \DB::table('ai_video_pipelines')
            ->where('created_at', '>=', $now->copy()->subHours(3))
            ->count();
        $doneWithUrl3h = \DB::table('ai_video_pipelines')
            ->where('step', 'done')
            ->whereNotNull('output_url')
            ->where('output_url', '<>', '')
            ->where('created_at', '>=', $now->copy()->subHours(3))
            ->count();
        $deliveryRate3hPct = $total3h > 0 ? round(($doneWithUrl3h / $total3h) * 100, 1) : null;

        // Fila agora — só as filas de vídeo (kling-browser, video, video-priority + variantes)
        $videoQueues = ['kling-browser', 'kling-browser-priority', 'video', 'video-priority'];
        $queueNow = \DB::table('jobs')->whereIn('queue', $videoQueues)->count();
        $queueByQueue = \DB::table('jobs')
            ->select('queue', \DB::raw('count(*) as c'))
            ->whereIn('queue', $videoQueues)
            ->groupBy('queue')
            ->pluck('c', 'queue');

        // Fantasmas — done mas sem output_url, criados nas últimas 24h (deveria ser ~0)
        $ghosts24h = \DB::table('ai_video_pipelines')
            ->where('step', 'done')
            ->where(function ($q) {
                $q->whereNull('output_url')->orWhere('output_url', '');
            })
            ->where('created_at', '>=', $now->copy()->subDay())
            ->count();

        // SEL-quem-espera (12/08): QUEM esta esperando agora — o painel so tinha numero
        // agregado, entao cliente preso so aparecia no monitor de 20 em 20 min.
        // Considera "esperando" quem esta em etapa de geracao, sem video, ha >6min.
        $esperando = \DB::table('ai_video_pipelines as p')
            ->leftJoin('users as u', 'u.id', '=', 'p.user_id')
            ->whereIn('p.step', ['queued', 'queued_wait', 'render', 'processing', 'lipsync', 'voice'])
            ->where(function ($q) {
                $q->whereNull('p.output_url')->orWhere('p.output_url', '');
            })
            ->where('p.updated_at', '<=', $now->copy()->subMinutes(6))
            ->orderBy('p.updated_at')
            ->limit(20)
            ->get([
                'p.id', 'p.user_id', 'p.step', 'p.mode', 'p.updated_at',
                'u.name as user_name', 'u.email as user_email',
            ])
            ->map(function ($r) use ($now) {
                $min = (int) abs($now->diffInMinutes(\Illuminate\Support\Carbon::parse($r->updated_at)));
                // fluxo longo demora de proposito (N clipes) — so vira alerta depois de 40min
                $ehLongo = str_starts_with((string) $r->mode, 'studio_long');
                return [
                    'pipeline_id'  => $r->id,
                    'user_id'      => $r->user_id,
                    'cliente'      => $r->user_name ?: ('user ' . $r->user_id),
                    'email'        => $r->user_email,
                    'etapa'        => $r->step,
                    'formato'      => $ehLongo ? 'longo (varias cenas)' : 'normal',
                    'esperando_min' => $min,
                    'alerta'       => $min >= ($ehLongo ? 40 : 15),
                ];
            })
            ->values();

        return response()->json([
            'data' => [
                'clientes_esperando'    => $esperando,
                'clientes_em_alerta'    => $esperando->where('alerta', true)->count(),
                'delivered_1h'          => $delivered1h,
                'delivery_rate_3h_pct'  => $deliveryRate3hPct,
                'total_3h'              => $total3h,
                'done_with_url_3h'      => $doneWithUrl3h,
                'queue_now'             => $queueNow,
                'queue_by_queue'        => $queueByQueue,
                'ghosts_24h'            => $ghosts24h,
                'as_of'                 => $now->toIso8601String(),
            ],
        ]);
    }


    /**
     * SEL-082 F5 — Leads freemium tiktok_free.
     * GET /api/v1/admin/free-leads?since=YYYY-MM-DD&search=&has_push=1&export=csv
     *
     * Devolve JSON (padrão) ou CSV (?export=csv).
     * - Auto-refresh recomendado a cada 30s no front.
     * - Métricas top-level: total, hoje, 7d, com_push, engajaram_2x.
     */
    public function freeLeads(\Illuminate\Http\Request $request)
    {
        $this->requireSuperAdmin($request);

        $planId = (int) (\DB::table('plans')->where('slug', 'tiktok_free')->value('id') ?? 89);
        $since  = $request->query('since') ? \Carbon\Carbon::parse($request->query('since')) : null;
        $search = trim((string) $request->query('search', ''));
        $hasPush = $request->query('has_push');

        $q = \DB::table('subscriptions as s')
            ->join('clients as c', 'c.id', '=', 's.client_id')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->leftJoin('push_subscriptions as ps', function ($j) {
                $j->on('ps.user_id', '=', 'u.id')->orOn('ps.client_id', '=', 'c.id');
            })
            ->where('s.plan_id', $planId)
            ->where('s.status', 'active')
            ->select([
                'u.id as user_id',
                'c.id as client_id',
                'u.name',
                'u.email',
                'c.phone',
                's.created_at',
                \DB::raw('MAX(ps.id) IS NOT NULL AS has_push'),
            ])
            ->groupBy('u.id', 'c.id', 'u.name', 'u.email', 'c.phone', 's.created_at');

        if ($since) {
            $q->where('s.created_at', '>=', $since);
        }
        if ($search) {
            $q->where(function ($w) use ($search) {
                $w->where('u.email', 'like', "%{$search}%")
                  ->orWhere('u.name', 'like', "%{$search}%")
                  ->orWhere('c.phone', 'like', "%{$search}%");
            });
        }
        if ($hasPush === '1' || $hasPush === 'true') {
            $q->havingRaw('MAX(ps.id) IS NOT NULL');
        }

        $rows = $q->orderByDesc('s.created_at')->limit(500)->get();

        $now = \Carbon\Carbon::now();
        $metrics = [
            'total'     => \DB::table('subscriptions')->where('plan_id', $planId)->where('status', 'active')->count(),
            'hoje'      => \DB::table('subscriptions')->where('plan_id', $planId)->where('status', 'active')->whereDate('created_at', $now->toDateString())->count(),
            'ultimos_7d'=> \DB::table('subscriptions')->where('plan_id', $planId)->where('status', 'active')->where('created_at', '>=', $now->copy()->subDays(7))->count(),
            'com_push'  => \DB::table('subscriptions as s')
                ->join('clients as c', 'c.id', '=', 's.client_id')
                ->join('users as u', 'u.id', '=', 'c.user_id')
                ->join('push_subscriptions as ps', function ($j) {
                    $j->on('ps.user_id', '=', 'u.id')->orOn('ps.client_id', '=', 'c.id');
                })
                ->where('s.plan_id', $planId)->where('s.status', 'active')
                ->distinct('u.id')->count('u.id'),
        ];

        if ($request->query('export') === 'csv') {
            $csv = "email,nome,telefone,cadastro,has_push,utm_source,utm_medium,utm_campaign\n";
            foreach ($rows as $r) {
                $meta = [];
                $csv .= sprintf(
                    "%s,%s,%s,%s,%s,%s,%s,%s\n",
                    $r->email,
                    str_replace([',', "\n"], ' ', (string) $r->name),
                    (string) $r->phone,
                    (string) $r->created_at,
                    $r->has_push ? '1' : '0',
                    $meta['utm_source'] ?? '',
                    $meta['utm_medium'] ?? '',
                    $meta['utm_campaign'] ?? ''
                );
            }
            return response($csv, 200, [
                'Content-Type'        => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename=leads_tiktok_free_' . $now->format('Ymd_His') . '.csv',
            ]);
        }

        return response()->json([
            'metrics' => $metrics,
            'data'    => $rows->map(function ($r) {
                $meta = [];
                return [
                    'user_id'      => (int) $r->user_id,
                    'client_id'    => (int) $r->client_id,
                    'name'         => $r->name,
                    'email'        => $r->email,
                    'phone'        => $r->phone,
                    'created_at'   => $r->created_at,
                    'has_push'     => (bool) $r->has_push,
                    'utm'          => [
                        'source'   => $meta['utm_source']   ?? null,
                        'medium'   => $meta['utm_medium']   ?? null,
                        'campaign' => $meta['utm_campaign'] ?? null,
                    ],
                ];
            }),
        ]);
    }

    /**
     * SEL-113-endpoints Ruan 14:23: GET config grupo WhatsApp.
     */
    public function whatsappGroupGet(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $this->requireSuperAdmin($request);
        $cfg = \DB::table('whatsapp_group_configs')->where('id', 1)->first();
        if (!$cfg) {
            return response()->json([
                'group_url' => '',
                'auto_invite_enabled' => false,
                'auto_invite_limit' => 0,
                'auto_invite_used' => 0,
                'remaining' => 0,
            ]);
        }
        return response()->json([
            'group_url' => $cfg->group_url,
            'auto_invite_enabled' => (bool) $cfg->auto_invite_enabled,
            'auto_invite_limit' => (int) $cfg->auto_invite_limit,
            'auto_invite_used' => (int) $cfg->auto_invite_used,
            'remaining' => max(0, (int) $cfg->auto_invite_limit - (int) $cfg->auto_invite_used),
        ]);
    }

    public function whatsappGroupUpdate(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $this->requireSuperAdmin($request);
        $data = $request->validate([
            'group_url' => 'nullable|url|max:500',
            'auto_invite_enabled' => 'boolean',
            'auto_invite_limit' => 'integer|min:0|max:9999',
            'reset_used' => 'boolean',
        ]);
        $patch = ['updated_at' => now()];
        if (array_key_exists('group_url', $data)) $patch['group_url'] = $data['group_url'];
        if (array_key_exists('auto_invite_enabled', $data)) $patch['auto_invite_enabled'] = $data['auto_invite_enabled'];
        if (array_key_exists('auto_invite_limit', $data)) $patch['auto_invite_limit'] = $data['auto_invite_limit'];
        if (!empty($data['reset_used'])) $patch['auto_invite_used'] = 0;

        \DB::table('whatsapp_group_configs')->updateOrInsert(['id' => 1], array_merge([
            'group_url' => 'https://whatsapp.com/channel/0029VbAzaW30gcfNCtU7MZ0U',
            'auto_invite_enabled' => false,
            'auto_invite_limit' => 0,
            'auto_invite_used' => 0,
            'created_at' => now(),
        ], $patch));

        return $this->whatsappGroupGet($request);
    }

    public function whatsappInviteToggle(\Illuminate\Http\Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $this->requireSuperAdmin($request);
        $client = \App\Models\Client::findOrFail($id);
        $isMarked = !is_null($client->whatsapp_invited_at);
        $client->whatsapp_invited_at = $isMarked ? null : now();
        $client->save();
        return response()->json([
            'client_id' => $id,
            'whatsapp_invited_at' => $client->whatsapp_invited_at?->toIso8601String(),
            'marked' => !$isMarked,
        ]);
    }

    /**
     * SEL-113 fase 2 — GET /api/v1/admin/whatsapp-config
     * Retorna {whatsapp_group_url, remaining, auto_invite_enabled, used, limit}.
     * Persistencia: tabela whatsapp_group_configs (row id=1, tenant global).
     */
    public function whatsappConfigGet(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $this->requireSuperAdmin($request);
        $cfg = \DB::table('whatsapp_group_configs')->where('id', 1)->first();
        $limit = (int) ($cfg->auto_invite_limit ?? 0);
        $used = (int) ($cfg->auto_invite_used ?? 0);
        return response()->json([
            'whatsapp_group_url'  => $cfg->group_url ?? '',
            'remaining'           => max(0, $limit - $used),
            'auto_invite_enabled' => (bool) ($cfg->auto_invite_enabled ?? false),
            'auto_invite_used'    => $used,
            'auto_invite_limit'   => $limit,
        ]);
    }

    /**
     * SEL-113 fase 2 — PUT /api/v1/admin/whatsapp-config
     * Body: {whatsapp_group_url?, remaining?, auto_invite_enabled?}
     * "remaining" e traduzido pra auto_invite_limit = used + remaining (nunca reseta used).
     */
    public function whatsappConfigUpdate(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $this->requireSuperAdmin($request);
        $data = $request->validate([
            'whatsapp_group_url'  => 'nullable|url|max:500',
            'remaining'           => 'nullable|integer|min:0|max:99999',
            'auto_invite_enabled' => 'nullable|boolean',
        ]);
        $cfg = \DB::table('whatsapp_group_configs')->where('id', 1)->first();
        $used = (int) ($cfg->auto_invite_used ?? 0);
        $patch = ['updated_at' => now()];
        if (array_key_exists('whatsapp_group_url', $data)) {
            $patch['group_url'] = $data['whatsapp_group_url'];
        }
        if (array_key_exists('remaining', $data) && $data['remaining'] !== null) {
            // remaining vira novo limit = used + remaining (assim contador usado
            // continua correto — decrementa cada nova ativacao paga).
            $patch['auto_invite_limit'] = $used + (int) $data['remaining'];
            // Se admin setou remaining>0, ativa auto-invite implicitamente
            if ((int) $data['remaining'] > 0 && !array_key_exists('auto_invite_enabled', $data)) {
                $patch['auto_invite_enabled'] = true;
            }
        }
        if (array_key_exists('auto_invite_enabled', $data) && $data['auto_invite_enabled'] !== null) {
            $patch['auto_invite_enabled'] = (bool) $data['auto_invite_enabled'];
        }
        \DB::table('whatsapp_group_configs')->updateOrInsert(['id' => 1], array_merge([
            'group_url'           => '',
            'auto_invite_enabled' => false,
            'auto_invite_limit'   => 0,
            'auto_invite_used'    => 0,
            'created_at'          => now(),
        ], $patch));
        return $this->whatsappConfigGet($request);
    }

}
