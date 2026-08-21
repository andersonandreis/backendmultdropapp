<?php
// INF-054 R4

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClientProduct;
use App\Models\ClientSupplierBalance;
use App\Models\ClientSupplierTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\OrderEvent;
use App\Models\Supplier;
use App\Services\ClientWalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\Federation\HubProxyHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Traits\FormatsMoneyBR;
use OpenApi\Attributes as OA;

class ManualOrderController extends Controller
{
    use FormatsMoneyBR;

    public function __construct(
        private ClientWalletService $walletService,
    ) {}

    // -------------------------------------------------------------------------
    // POST /api/v1/orders/manual/preview
    // Dry-run: calcula total e verifica saldo sem criar pedido
    // -------------------------------------------------------------------------

    #[OA\Post(
        path: '/api/v1/orders/manual/preview',
        summary: 'Preview de pedido manual (dry-run)',
        description: 'Calcula o total de um pedido manual com base nos itens e verifica o saldo disponivel na carteira do lojista para o fornecedor selecionado. Nenhum pedido e criado.',
        tags: ['Pedidos Manuais'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['supplier_id', 'items'],
                properties: [
                    new OA\Property(property: 'supplier_id', type: 'integer', example: 1, description: 'ID do fornecedor'),
                    new OA\Property(
                        property: 'items',
                        type: 'array',
                        items: new OA\Items(
                            required: ['client_product_id', 'qty'],
                            properties: [
                                new OA\Property(property: 'client_product_id', type: 'integer', example: 42),
                                new OA\Property(property: 'qty', type: 'integer', minimum: 1, example: 2),
                            ]
                        )
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Preview calculado com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'items',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'sku', type: 'string'),
                                    new OA\Property(property: 'name', type: 'string'),
                                    new OA\Property(property: 'qty', type: 'integer'),
                                    new OA\Property(property: 'unit_cost', type: 'number', format: 'float'),
                                    new OA\Property(property: 'subtotal', type: 'number', format: 'float'),
                                ]
                            )
                        ),
                        new OA\Property(property: 'total', type: 'number', format: 'float', example: 16.74),
                        new OA\Property(property: 'wallet_balance', type: 'number', format: 'float', example: 150.00),
                        new OA\Property(property: 'sufficient_balance', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Saldo suficiente para este pedido'),
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Produto nao pertence ao cliente ou plano insuficiente'),
            new OA\Response(response: 422, description: 'Validacao falhou ou custo nao definido'),
        ]
    )]
    public function preview(Request $request): JsonResponse
    {
        if (HubProxyHelper::isWl()) {
            $fwd = $this->buildFederatedManualBody($request);
            if ($fwd instanceof JsonResponse) {
                return $fwd;
            }
            return HubProxyHelper::forwardToHub('post', '/orders/manual/preview', $fwd);
        }
        $user   = $request->user();
        $client = $user->client;

        if (! $client) {
            return response()->json(['error' => 'no_client'], 422);
        }

        // FOR-092: request federada ja passou no gate de plano DO WL (a
        // assinatura do cliente vive no banco do WL, nao no hub).
        if ($request->attributes->get('federation_plan_validated') !== true) {
            $subscription = $client->subscriptions()
                ->whereIn('status', ['active', 'trialing'])
                ->with('plan')
                ->latest()
                ->first();

            $plan = $subscription?->plan;
            if (! $plan || (int) $plan->max_skus <= 30) {
                return response()->json(['error' => 'requires_pro'], 403);
            }
        }

        $validated = $request->validate([
            'supplier_id'               => 'required|integer|exists:suppliers,id',
            'items'                     => 'required|array|min:1',
            'items.*.client_product_id' => 'required|integer',
            'items.*.qty'               => 'required|integer|min:1',
        ]);

        $supplier = Supplier::findOrFail($validated['supplier_id']);

        $total        = 0;
        $previewItems = [];

        foreach ($validated['items'] as $itemInput) {
            $product = ClientProduct::with('product')
                ->where('id', $itemInput['client_product_id'])
                ->where('client_id', $client->id)
                ->first();

            if (! $product) {
                return response()->json([
                    'error'      => 'product_not_owned',
                    'product_id' => $itemInput['client_product_id'],
                ], 403);
            }

            $cost = (float) ($product->supplier_unit_cost
                ?: ($product->product->cost ?? 0));

            if ($cost <= 0) {
                $label = $product->custom_title ?: ($product->product->name ?? 'ID ' . $product->id);
                return response()->json([
                    'error'   => 'no_cost_defined',
                    'product' => $label,
                ], 422);
            }

            $qty      = (int) $itemInput['qty'];
            $subtotal = round($cost * $qty, 2);
            $total   += $subtotal;

            $previewItems[] = [
                'sku'       => $product->custom_sku ?: $product->supplier_product_sku,
                'name'      => $product->custom_title ?: ($product->product->name ?? ''),
                'qty'       => $qty,
                'unit_cost' => round($cost, 2),
                'subtotal'  => $subtotal,
            ];
        }

        $walletBalance     = $this->walletService->getBalance($client->id, $supplier->id);
        $sufficientBalance = $walletBalance >= $total;

        return response()->json([
            'items'              => $previewItems,
            'total'              => round($total, 2),
            'wallet_balance'     => round($walletBalance, 2),
            'sufficient_balance' => $sufficientBalance,
            'message'            => $sufficientBalance
                ? 'Saldo suficiente para este pedido'
                : sprintf(
                    'Saldo insuficiente. Faltam R$ %.2f para completar este pedido',
                    round($total - $walletBalance, 2)
                ),
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/orders/manual
    // Cria pedido manual — INSERT direto na tabela pedidos do legado (canal=13)
    // -------------------------------------------------------------------------

    #[OA\Post(
        path: '/api/v1/orders/manual',
        summary: 'Criar pedido manual',
        description: 'Cria um pedido manual no sistema, inserindo diretamente no legado via canal=13. Use este endpoint para registrar vendas realizadas fora dos marketplaces integrados (Instagram, WhatsApp, site proprio, feiras, etc). O saldo da carteira do lojista e verificado antes da criacao; se insuficiente, o pedido e bloqueado. O legado processa o debito automaticamente ao processar o pedido.',
        tags: ['Pedidos Manuais'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['supplier_id', 'items', 'buyer_name', 'buyer_doc', 'address'],
                properties: [
                    new OA\Property(property: 'supplier_id', type: 'integer', example: 1, description: 'ID do fornecedor (deve ter integracao ativa com o lojista no legado)'),
                    new OA\Property(
                        property: 'items',
                        type: 'array',
                        description: 'Lista de itens do pedido',
                        items: new OA\Items(
                            required: ['client_product_id', 'qty'],
                            properties: [
                                new OA\Property(property: 'client_product_id', type: 'integer', example: 42, description: 'ID do produto na tabela client_products'),
                                new OA\Property(property: 'qty', type: 'integer', minimum: 1, example: 2, description: 'Quantidade'),
                            ]
                        )
                    ),
                    new OA\Property(property: 'buyer_name', type: 'string', maxLength: 100, example: 'Joao Silva', description: 'Nome completo do comprador'),
                    new OA\Property(property: 'buyer_doc', type: 'string', maxLength: 100, example: '123.456.789-00', description: 'CPF ou CNPJ do comprador (aceita formatado ou apenas digitos)'),
                    new OA\Property(property: 'buyer_phone', type: 'string', maxLength: 20, example: '11999999999', description: 'Telefone do comprador (opcional)', nullable: true),
                    new OA\Property(property: 'buyer_email', type: 'string', format: 'email', maxLength: 100, example: 'joao@email.com', description: 'E-mail do comprador (opcional)', nullable: true),
                    new OA\Property(
                        property: 'address',
                        type: 'object',
                        required: ['street', 'number', 'neighborhood', 'city', 'state', 'cep'],
                        properties: [
                            new OA\Property(property: 'street', type: 'string', example: 'Rua das Flores'),
                            new OA\Property(property: 'number', type: 'string', example: '123'),
                            new OA\Property(property: 'complement', type: 'string', example: 'Apto 45', nullable: true),
                            new OA\Property(property: 'neighborhood', type: 'string', example: 'Centro'),
                            new OA\Property(property: 'city', type: 'string', example: 'Sao Paulo'),
                            new OA\Property(property: 'state', type: 'string', minLength: 2, maxLength: 2, example: 'SP'),
                            new OA\Property(property: 'cep', type: 'string', example: '01310-100'),
                        ]
                    ),
                    new OA\Property(property: 'channel_name', type: 'string', maxLength: 100, example: 'Instagram', description: 'Canal de origem da venda (Instagram, WhatsApp, Site, Feira, etc)', nullable: true),
                    new OA\Property(
                        property: 'delivery_type',
                        type: 'string',
                        enum: ['correios', 'transportadora', 'retirada', 'motoboy', 'sedex', 'pac'],
                        example: 'sedex',
                        description: 'Tipo de entrega',
                        nullable: true
                    ),
                    new OA\Property(property: 'notes', type: 'string', maxLength: 500, example: 'Cliente pediu embalagem presenteavel', description: 'Observacoes internas do pedido', nullable: true),
                    new OA\Property(property: 'marketplace', type: 'string', example: 'instagram', description: 'Identificador do marketplace de origem (legado)', nullable: true),
                    new OA\Property(property: 'reason', type: 'string', maxLength: 255, example: 'Venda direta via DM', description: 'Motivo/descricao interna do pedido manual', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Pedido criado com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'order_id', type: 'integer', example: 123, description: 'ID do pedido no HubAI (novo sistema)'),
                        new OA\Property(property: 'legacy_id', type: 'integer', example: 4567, description: 'ID do pedido na tabela pedidos do legado'),
                        new OA\Property(property: 'supplier_total', type: 'number', format: 'float', example: 16.74),
                        new OA\Property(property: 'message', type: 'string', example: 'Pedido criado no legado. Pagamento sera processado automaticamente.'),
                    ]
                )
            ),
            new OA\Response(
                response: 403,
                description: 'Plano insuficiente (requer Pro ou superior) ou produto nao pertence ao lojista',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'requires_pro'),
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Falha de validacao, produto sem custo, integracao nao encontrada, saldo insuficiente ou cliente/fornecedor nao vinculado ao legado',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'insufficient_balance'),
                        new OA\Property(property: 'balance', type: 'number', format: 'float', example: 10.00, nullable: true),
                        new OA\Property(property: 'required', type: 'number', format: 'float', example: 16.74, nullable: true),
                        new OA\Property(property: 'deficit', type: 'number', format: 'float', example: 6.74, nullable: true),
                    ]
                )
            ),
            new OA\Response(
                response: 500,
                description: 'Erro ao inserir pedido no legado',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'legacy_insert_failed'),
                        new OA\Property(property: 'message', type: 'string'),
                    ]
                )
            ),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        if (HubProxyHelper::isWl()) {
            $fwd = $this->buildFederatedManualBody($request);
            if ($fwd instanceof JsonResponse) {
                return $fwd;
            }
            return HubProxyHelper::forwardToHub('post', '/orders/manual', $fwd);
        }
        $user   = $request->user();
        $client = $user->client;

        if (! $client) {
            return response()->json(['error' => 'no_client'], 422);
        }

        // Validacao de plano: Start (max_skus <= 30) bloqueado.
        // FOR-092: request federada ja passou no gate de plano DO WL (a
        // assinatura do cliente vive no banco do WL, nao no hub).
        if ($request->attributes->get('federation_plan_validated') !== true) {
            $subscription = $client->subscriptions()
                ->whereIn('status', ['active', 'trialing'])
                ->with('plan')
                ->latest()
                ->first();

            $plan = $subscription?->plan;
            if (! $plan || (int) $plan->max_skus <= 30) {
                return response()->json(['error' => 'requires_pro'], 403);
            }
        }

        $validated = $request->validate([
            'supplier_id'               => 'required|integer|exists:suppliers,id',
            'items'                     => 'required|array|min:1',
            'items.*.client_product_id' => 'required|integer',
            'items.*.qty'               => 'required|integer|min:1',
            'buyer_name'                => 'required|string|max:100',
            'buyer_doc'                 => 'required|string|max:100',
            'buyer_phone'               => 'nullable|string|max:20',
            'buyer_email'               => 'nullable|email|max:100',
            'address'                   => 'required|array',
            'address.street'            => 'required|string',
            'address.number'            => 'required|string',
            'address.complement'        => 'nullable|string',
            'address.neighborhood'      => 'required|string',
            'address.city'              => 'required|string',
            'address.state'             => 'required|string|size:2',
            'address.cep'               => 'required|string',
            'channel_name'              => 'nullable|string|max:100',
            'delivery_type'             => 'nullable|string|in:correios,transportadora,retirada,motoboy,sedex,pac',
            'notes'                     => 'nullable|string|max:500',
            'marketplace'               => 'nullable|string',
            'reason'                    => 'nullable|string|max:255',
        ]);

        $supplier = Supplier::findOrFail($validated['supplier_id']);

        if (! $supplier->legacy_empresa_id) {
            return response()->json(['error' => 'supplier_not_linked_to_legacy'], 422);
        }

        if (! $client->legacy_id_login) {
            return response()->json(['error' => 'client_not_linked_to_legacy'], 422);
        }

        // Calcular total — custo SEMPRE do banco, nunca do request (tenant isolation)
        $total         = 0;
        $resolvedItems = [];

        foreach ($validated['items'] as $itemInput) {
            $product = ClientProduct::with('product')
                ->where('id', $itemInput['client_product_id'])
                ->where('client_id', $client->id)
                ->first();

            if (! $product) {
                return response()->json([
                    'error'      => 'product_not_owned',
                    'product_id' => $itemInput['client_product_id'],
                ], 403);
            }

            // Custo: tenta direto no ClientProduct (supplier_unit_cost),
            // fallback no Product relacionado (coluna: cost)
            $cost = (float) ($product->supplier_unit_cost
                ?: ($product->product->cost ?? 0));

            if ($cost <= 0) {
                $label = $product->custom_title ?: ($product->product->name ?? 'ID ' . $product->id);
                return response()->json([
                    'error'   => 'no_cost_defined',
                    'product' => $label,
                ], 422);
            }

            $resolvedItems[] = [
                'cp'   => $product,
                'qty'  => (int) $itemInput['qty'],
                'cost' => $cost,
            ];

            $total += $cost * (int) $itemInput['qty'];
        }

        // Verificar saldo da wallet ANTES de criar o pedido
        // OBS: O legado (pedido_manual6.php linha ~1474) ja faz INSERT em conta_corrente
        // com tipo='D' ao processar canal=13. NAO descontar aqui para evitar double-debit.
        $walletBalance = $this->walletService->getBalance($client->id, $supplier->id);
        if ($walletBalance < $total) {
            return response()->json([
                'error'    => 'insufficient_balance',
                'balance'  => round($walletBalance, 2),
                'required' => round($total, 2),
                'deficit'  => round($total - $walletBalance, 2),
            ], 422);
        }

        // Resolver id_integracao no legado — tabela: integracao
        $integracao = DB::connection('legacy')->table('integracao')
            ->where('id_login', $client->legacy_id_login)
            ->where('id_deposito', $supplier->legacy_empresa_id)
            ->where('removida', 0)
            ->orderBy('id', 'desc')
            ->first();

        if (! $integracao) {
            return response()->json([
                'error' => 'integracao_not_found',
                'hint'  => 'Cliente nao tem integracao ativa com esse fornecedor no legado',
            ], 422);
        }

        // Criar Order espelho no novo (rastreio + items)
        $newOrder = Order::create([
            'client_id'                => $client->id,
            'supplier_id'              => $supplier->id,
            'source'                   => 'manual',
            'status'                   => 'pending_payment',
            'supplier_total'           => $total,
            'customer_name'            => $validated['buyer_name'],
            'customer_document_number' => preg_replace('/\D/', '', $validated['buyer_doc']),
            'customer_phone'           => $validated['buyer_phone'] ?? null,
            'customer_email'           => $validated['buyer_email'] ?? null,
            'channel_name'             => $validated['channel_name'] ?? null,
            'delivery_type'            => $validated['delivery_type'] ?? null,
            'notes'                    => $validated['notes'] ?? null,
            'manual_reason'            => $validated['reason'] ?? null,
            'manual_created_by'        => $user->id,
            'currency'                 => 'BRL',
        ]);

        foreach ($resolvedItems as $item) {
            $cp = $item['cp'];
            OrderItem::create([
                'order_id'            => $newOrder->id,
                'client_product_id'   => $cp->id,
                'product_id'          => $cp->product_id,
                'sku'                 => $cp->custom_sku ?: $cp->supplier_product_sku,
                'name'                => $cp->custom_title ?: ($cp->product->name ?? ''),
                'quantity'            => $item['qty'],
                'supplier_unit_cost'  => $item['cost'],
                'supplier_total_cost' => $item['cost'] * $item['qty'],
                'unit_price'          => $item['cost'],
                'total'               => $item['cost'] * $item['qty'],
            ]);
        }

        // INSERT direto no legado
        try {
            $legacyId = DB::connection('legacy')->table('pedidos')->insertGetId([
                'id_canal'                => 13,
                'id_integracao'           => $integracao->id,
                'id_loja'                 => $supplier->legacy_empresa_id,
                'dados'                   => json_encode([
                    'loja'               => $supplier->company_name,
                    'origem'             => 'novohubai_manual',
                    'novo_order_id'      => $newOrder->id,
                    'marketplace_origem' => $validated['marketplace'] ?? null,
                    'channel_name'       => $validated['channel_name'] ?? null,
                    'delivery_type'      => $validated['delivery_type'] ?? null,
                    'notes'              => $validated['notes'] ?? null,
                ]),
                'valor_total'             => $total,
                'valor_pix'               => $total,
                'cliente_nome'            => $validated['buyer_name'],
                'cliente_cpf'             => preg_replace('/\D/', '', $validated['buyer_doc']),
                'endereco_endereco'       => $validated['address']['street'],
                'endereco_numero'         => $validated['address']['number'],
                'endereco_complemento'    => $validated['address']['complement'] ?? null,
                'endereco_bairro'         => $validated['address']['neighborhood'],
                'endereco_cidade'         => $validated['address']['city'],
                'endereco_estado'         => $validated['address']['state'],
                'endereco_cep'            => preg_replace('/\D/', '', $validated['address']['cep']),
                'status'                  => 0,
                'status_marketplace'      => 'APPROVED',
                'data_add'                => now(),
                'data_pedido_canal'       => now(),
                'validado_estoque_zerado' => 'S',
            ]);

            $newOrder->update(['legacy_id' => $legacyId]);

            Log::info('[ManualOrder] Pedido criado no legado', [
                'order_id'      => $newOrder->id,
                'legacy_id'     => $legacyId,
                'supplier_id'   => $supplier->id,
                'integracao_id' => $integracao->id,
                'total'         => $total,
                'channel_name'  => $validated['channel_name'] ?? null,
                'delivery_type' => $validated['delivery_type'] ?? null,
            ]);

            return response()->json([
                'order_id'       => $newOrder->id,
                'legacy_id'      => $legacyId,
                'supplier_total' => round($total, 2),
                'supplier_total_formatted' => $this->formatBRL(round($total, 2)),
                'message'        => 'Pedido criado no legado. Pagamento sera processado automaticamente.',
            ], 201);

        } catch (\Throwable $e) {
            Log::error('[ManualOrder] Erro ao criar pedido no legado', [
                'error'    => $e->getMessage(),
                'order_id' => $newOrder->id,
            ]);

            $newOrder->update(['status' => 'failed']);

            return response()->json([
                'error'   => 'legacy_insert_failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // =========================================================================
    // POST /api/v1/orders/{id}/manual-label
    // Upload de etiqueta manual (PDF) para pedidos do canal manual.
    // =========================================================================

    #[OA\Post(
        path: "/api/v1/orders/{id}/manual-label",
        summary: "Upload de etiqueta manual (PDF)",
        description: "Lojista sobe um PDF de etiqueta para um pedido manual ja pago. Valida ownership por tenant (client_id), mime PDF, tamanho maximo 10MB.",
        tags: ["Pedidos Manuais"],
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(
                name: "id",
                in: "path",
                required: true,
                schema: new OA\Schema(type: "integer"),
                description: "ID do pedido"
            )
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: "multipart/form-data",
                schema: new OA\Schema(
                    required: ["label"],
                    properties: [
                        new OA\Property(
                            property: "label",
                            type: "string",
                            format: "binary",
                            description: "Arquivo PDF da etiqueta (max 10MB)"
                        )
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Etiqueta enviada com sucesso"),
            new OA\Response(response: 403, description: "Pedido nao pertence ao cliente autenticado"),
            new OA\Response(response: 404, description: "Pedido nao encontrado"),
            new OA\Response(response: 422, description: "Validacao falhou (mime, tamanho, sem arquivo)"),
        ]
    )]
    public function uploadLabel(Request $request, int $id): JsonResponse
    {
        $user   = $request->user();
        $client = $user?->client;

        // MUL-426: admin/fornecedor tambem sobe etiqueta manual (a operacao usa o
        // painel admin quando o marketplace nao libera o documento — caso 21/08,
        // 7 pedidos com a Shopee recusando o create e a etiqueta ja no Seller Center).
        $isElevado = in_array($user?->role, ['super_admin', 'admin', 'supplier'], true);

        if (! $client && ! $isElevado) {
            return response()->json(["error" => "no_client"], 422);
        }

        // MUL-426: alem de PDF, aceita imagem — e o formato que o Seller Center e o
        // Bling entregam. 10MB max.
        $request->validate([
            "label"  => "required|file|mimetypes:application/pdf,image/png,image/jpeg|mimes:pdf,png,jpg,jpeg|max:10240",
            "motivo" => "nullable|string|max:200",
        ]);

        $q = Order::where("id", $id);
        if (! $isElevado) {
            $q->where("client_id", $client->id);
        }
        $order = $q->first();

        if (! $order) {
            // Resposta unica para nao revelar existencia de pedido de outro tenant.
            return response()->json(["error" => "order_not_found"], 403);
        }

        try {
            $file = $request->file("label");
            $ext  = strtolower($file->getClientOriginalExtension() ?: 'pdf');

            // MUL-424: etiqueta NUNCA vai pro storage publico — labels_disk (privado),
            // solta em labels/ para o proxy autenticado servir como qualquer outra.
            $nome = sprintf('manual-%d-%s.%s', $order->id, substr(md5(uniqid('', true)), 0, 8), $ext);
            $disk = \Illuminate\Support\Facades\Storage::disk((string) config('filesystems.labels_disk', 'public'));
            $path = $disk->putFileAs('labels', $file, $nome);

            $motivo = trim((string) $request->input('motivo', '')) ?: 'etiqueta via metodo alternativo (upload manual)';

            // MUL-426: o pedido registra o metodo alternativo e PARA de retentar na API:
            // label_url preenchida encerra os loops de fetch (gate padrao), e o motivo
            // fica em manual_reason + anotacao do admin.
            $order->update([
                "manual_label_path"        => $path,
                "manual_label_uploaded_at" => now(),
                "manual_reason"            => $motivo,
                "label_url"                => '/storage/labels/' . $nome,
                "label_status_reason"      => null,
                "label_error_at"           => null,
                "admin_note"               => trim(((string) $order->admin_note) . "\n" .
                    '[' . now()->format('d/m/Y H:i') . '] Etiqueta por metodo alternativo (upload manual por ' . ($user->name ?? $user->email) . '): ' . $motivo),
            ]);

            // Com etiqueta em maos, o pedido avanca pra esteira de despacho (MUL-378).
            if (in_array($order->order_processing_status, [null, '', 'awaiting_label', 'label_failed', 'awaiting_hub'], true)) {
                $order->forceFill(['order_processing_status' => 'awaiting_dispatch'])->saveQuietly();
            }

            // MUL-426: avisa o hub para encerrar a fila de retentativa da etiqueta la.
            if (! empty($order->hubai_order_id)) {
                try {
                    \Illuminate\Support\Facades\Http::timeout(10)->connectTimeout(5)
                        ->withHeaders([
                            'X-Federation-Tenant' => (string) config('app.tenant'),
                            'X-Federation-Secret' => (string) (config('services.hubai_federation.secret') ?: env('FEDERATION_HMAC_SECRET', '')),
                        ])->post(rtrim((string) config('services.hubai_federation.storage_url', 'https://api.hubai.io'), '/')
                            . '/api/federation/orders/' . (int) $order->hubai_order_id . '/label-resolved-manually', [
                            'motivo' => $motivo,
                            'tenant' => (string) config('app.tenant'),
                        ]);
                } catch (\Throwable $e) {
                    Log::warning('[MUL-426] hub nao avisado do manual-label (segue local)', [
                        'order_id' => $order->id, 'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info("[ManualOrder] Etiqueta manual recebida", [
                "order_id"  => $order->id,
                "client_id" => $order->client_id,
                "por"       => $user->id,
                "path"      => $path,
                "size_kb"   => round($file->getSize() / 1024, 1),
            ]);

            return response()->json([
                "ok"        => true,
                "order_id"  => $order->id,
                "path"      => $path,
                "label_url" => $order->label_url,
                "message"   => "Etiqueta manual enviada com sucesso.",
            ], 200);
        } catch (\Throwable $e) {
            Log::error("[ManualOrder] Falha ao salvar etiqueta manual", [
                "order_id" => $order->id,
                "error"    => $e->getMessage(),
            ]);

            return response()->json([
                "error"   => "upload_failed",
                "message" => $e->getMessage(),
            ], 500);
        }
    }
    // =========================================================================
    // POST /api/v1/orders/{id}/manual-payment
    // Pagamento de pedido manual (canal manual). Suporta method=wallet (debita
    // ClientSupplierBalance + cria ClientSupplierTransaction debit + Payment
    // gateway=wallet status=paid + marca order paid atomicamente). PIX fica
    // marcado para implementacao posterior (BUG-4 documentado em T13).
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/orders/{id}/manual-payment',
        summary: 'Pagamento de pedido manual via wallet',
        description: 'Lojista paga um pedido manual ja criado (status pending_payment). method=wallet debita o saldo do ClientSupplierBalance correspondente e marca o pedido como paid em uma unica transacao. Retorna 422 se saldo insuficiente. 403 se order de outro tenant.',
        tags: ['Pedidos Manuais'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                description: 'ID do pedido manual'
            )
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['method'],
                properties: [
                    new OA\Property(property: 'method', type: 'string', enum: ['wallet', 'pix'], description: 'Metodo de pagamento')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Pagamento aprovado (wallet)'),
            new OA\Response(response: 403, description: 'Order de outro tenant'),
            new OA\Response(response: 404, description: 'Order nao encontrada'),
            new OA\Response(response: 422, description: 'Saldo insuficiente, status invalido ou metodo nao suportado'),
        ]
    )]
    public function payManual(Request $request, int $id): JsonResponse
    {
        if (HubProxyHelper::isWl()) {
            $order = Order::find($id);
            $hubId = $order && $order->hubai_order_id ? $order->hubai_order_id : $id;
            $u = $request->user();
            $c = $u ? $u->client : null;
            if (! $c) {
                return response()->json(['error' => 'no_client'], 422);
            }
            $body = $request->only(['method']);
            // FOR-092: identidade canonica, nunca ID local (padrao MUL-236)
            $body['client'] = [
                'legacy_id_login' => $c->legacy_id_login ? (int) $c->legacy_id_login : null,
                'email'           => $u->email,
                'name'            => $u->name,
            ];
            return HubProxyHelper::forwardToHub('post', "/orders/$hubId/manual-payment", $body);
        }
        $user   = $request->user();
        $client = $user?->client;

        if (! $client) {
            return response()->json(['error' => 'no_client'], 422);
        }

        $validated = $request->validate([
            'method' => 'required|string|in:wallet,pix',
        ]);

        // Ownership: order DEVE pertencer ao client autenticado.
        $order = Order::where('id', $id)
            ->where('client_id', $client->id)
            ->first();

        if (! $order) {
            // Resposta unica para nao revelar existencia de pedido de outro tenant.
            return response()->json(['error' => 'order_not_found'], 403);
        }

        if ($order->status !== 'pending_payment') {
            return response()->json([
                'error'   => 'invalid_status',
                'message' => 'Pedido nao esta aguardando pagamento (status=' . $order->status . ').',
            ], 422);
        }

        if ($validated['method'] === 'wallet') {
            return $this->payWithWallet($client, $order);
        }

        // PIX: BUG-4 conhecido (T13 skipped). Retornar 422 ate o fix.
        return response()->json([
            'error'   => 'pix_not_implemented',
            'message' => 'Pagamento via PIX em manutencao (BUG-4).',
        ], 422);
    }

    /**
     * Debita ClientSupplierBalance, cria transaction debit, Payment paid e marca
     * order como paid em uma unica transacao DB. Lock pessimista para evitar
     * double-debit em concorrencia.
     */
    private function payWithWallet($client, Order $order): JsonResponse
    {
        $amount = round((float) $order->supplier_total, 2);

        return DB::transaction(function () use ($client, $order, $amount) {
            $balance = ClientSupplierBalance::where('client_id', $client->id)
                ->where('supplier_id', $order->supplier_id)
                ->lockForUpdate()
                ->first();

            $current = $balance ? (float) $balance->balance : 0.0;

            if ($current < $amount) {
                return response()->json([
                    'error'             => 'insufficient_balance',
                    'message'           => 'Saldo insuficiente na carteira para este fornecedor.',
                    'balance'           => $current,
                    'required'          => $amount,
                    'deficit'           => round($amount - $current, 2),
                ], 422);
            }

            // MUL-363 Fase 3: debito via nucleo canonico (atomico + payment_events)
            $tx = app(\App\Services\Financial\Ledger\WalletLedger::class)->debit(
                $client->id,
                $order->supplier_id,
                $amount,
                new \App\Services\Financial\Ledger\LedgerEntryMeta(
                    type: 'order_debit',
                    description: 'Pagamento manual pedido #' . $order->id,
                    orderId: $order->id,
                    actor: 'user:' . auth()->id(),
                )
            );

            $payment = Payment::create([
                'order_id'    => $order->id,
                'client_id'   => $client->id,
                'supplier_id' => $order->supplier_id,
                'gateway'     => 'wallet',
                'method'      => 'wallet',
                'amount'      => $amount,
                'status'      => 'paid',
                'paid_at'     => now(),
            ]);

            $order->update([
                'status'                => 'paid',
                'paid_at'               => now(),
                'wallet_paid_at'        => now(),
                'wallet_transaction_id' => $tx->id,
            ]);
            // MUL-363: Bling sync dispara SO no evento "pedido pago" (OrderObserver)

            Log::info('[ManualOrder] Pagamento manual via wallet', [
                'order_id'      => $order->id,
                'client_id'     => $client->id,
                'supplier_id'   => $order->supplier_id,
                'amount'        => $amount,
                'transaction_id'=> $tx->id,
                'payment_id'    => $payment->id,
            ]);

            return response()->json([
                'ok'             => true,
                'order_id'       => $order->id,
                'status'         => 'paid',
                'method'         => 'wallet',
                'amount'         => $amount,
                'transaction_id' => $tx->id,
                'payment_id'     => $payment->id,
                'message'        => 'Pedido pago com sucesso via wallet.',
            ], 200);
        });
    }


    /**
     * INF-054 R4: payManual via federation.
     */
    public function payManualFromFederation(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'client'       => ['required', 'array'],
            'client.email' => ['nullable', 'email'],
            'method'       => 'required|string|in:wallet,pix',
        ]);
        $tenantSlug = $request->attributes->get('federation_tenant');
        // FOR-092: client canonico (legacy_id_login → email), nunca ID local do WL
        $client = $this->resolveFederatedClient((array) $request->input('client'), false);
        if (!$client) return response()->json(['error' => 'client_not_found'], 404);
        $order = Order::where('id', $id)->where('client_id', $client->id)->first();
        if (!$order) return response()->json(['error' => 'order_not_found'], 403);
        if (!$this->tenantAuthorizedManual($tenantSlug, $order)) {
            return response()->json(['error' => 'tenant_not_authorized'], 403);
        }
        if ($order->status !== 'pending_payment') {
            return response()->json(['error' => 'invalid_status', 'status' => $order->status], 422);
        }
        if ($request->input('method') === 'wallet') {
            $resp = $this->payWithWallet($client, $order);
            \App\Jobs\FanoutOrderWebhookJob::dispatch($order->id, 'order.updated', ['source_wl' => $tenantSlug, 'action' => 'manual_payment_wallet']);
            return $resp;
        }
        return response()->json(['error' => 'pix_not_implemented'], 422);
    }

    /**
     * INF-054 R4: preview via federation. Só simula — não escreve.
     */
    public function previewFromFederation(Request $request): JsonResponse
    {
        $request->validate([
            'client'       => ['required', 'array'],
            'client.email' => ['nullable', 'email'],
        ]);
        // FOR-092: client canonico + itens por SKU de catalogo — o WL nao
        // conhece os IDs do hub (client_id 895 do fornecefy era OUTRA pessoa
        // aqui; gate devolvia requires_pro pra cliente Pro real).
        $client = $this->resolveFederatedClient((array) $request->input('client'), true);
        if (!$client) return response()->json(['error' => 'client_not_found'], 404);
        $err = $this->resolveFederatedItems($request, $client);
        if ($err) return $err;
        $request->attributes->set('federation_plan_validated', true);
        // Setar user resolver sintético pra reaproveitar lógica original
        $request->setUserResolver(function () use ($client) {
            $user = new \stdClass();
            $user->client = $client;
            $user->id = null;
            $user->role = 'client';
            return $user;
        });
        return $this->preview($request);
    }

    private function tenantAuthorizedManual(?string $tenantSlug, Order $order): bool
    {
        if (!$tenantSlug || !$order->supplier_id) return false;
        $tid = \DB::table('tenants')->where('slug', $tenantSlug)->value('id');
        if (!$tid) return false;
        return \DB::table('tenant_supplier')->where('tenant_id', $tid)->where('supplier_id', $order->supplier_id)->exists();
    }


    /**
     * INF-054 R5: store (create manual order) via federation.
     * Autoriza via client_id + tenant_supplier scope. Reusa lógica original via fake user.
     */
    public function storeFromFederation(Request $request): JsonResponse
    {
        $request->validate([
            'client'       => ['required', 'array'],
            'client.email' => ['nullable', 'email'],
            'supplier_id'  => ['required', 'integer'],
        ]);
        $tenantSlug = $request->attributes->get('federation_tenant');
        // FOR-092: client canonico (legacy_id_login → email, auto-provisao
        // minima) + supplier_id agora e o hub_supplier_id enviado pelo WL.
        $client = $this->resolveFederatedClient((array) $request->input('client'), true);
        if (!$client) return response()->json(['error' => 'client_not_found'], 404);
        // Valida tenant scope no supplier requested
        $tid = \DB::table('tenants')->where('slug', $tenantSlug)->value('id');
        if (!$tid || !\DB::table('tenant_supplier')->where('tenant_id', $tid)
            ->where('supplier_id', $request->input('supplier_id'))->exists()) {
            return response()->json(['error' => 'tenant_not_authorized'], 403);
        }
        $err = $this->resolveFederatedItems($request, $client);
        if ($err) return $err;
        $request->attributes->set('federation_plan_validated', true);
        // Fake user pra reaproveitar store() original
        $request->setUserResolver(function () use ($client) {
            $u = new \stdClass();
            $u->id = null;
            $u->role = 'client';
            $u->client = $client;
            // subscriptions relation acessada em store — client model já resolve
            return $u;
        });
        $resp = $this->store($request);
        // Se sucesso, dispatch fanout do pedido criado
        // FOR-092: store() devolve order_id no topo, nao order.id — o fanout
        // nunca disparava com a chave antiga.
        $body = $resp->getData(true);
        if (isset($body['order_id'])) {
            \App\Jobs\FanoutOrderWebhookJob::dispatch((int) $body['order_id'], 'order.created', ['source_wl' => $tenantSlug, 'action' => 'manual_create']);
        }
        return $resp;
    }

    /**
     * FOR-092: monta o corpo federado com identidade CANONICA — o WL nunca
     * envia IDs locais (client_id/supplier_id/client_product_id) pro hub,
     * porque as tabelas dos WLs tem IDs proprios (padrao MUL-236: client por
     * legacy_id_login/email, produto por SKU de catalogo, supplier por
     * hub_supplier_id). O gate de plano roda AQUI: a assinatura do cliente
     * vive no banco do WL, nao no hub.
     */
    private function buildFederatedManualBody(Request $request): array|JsonResponse
    {
        $u = $request->user();
        $c = $u ? $u->client : null;
        if (! $c) {
            return response()->json(['error' => 'no_client'], 422);
        }

        $subscription = $c->subscriptions()
            ->whereIn('status', ['active', 'trialing'])
            ->with('plan')
            ->latest()
            ->first();
        $plan = $subscription?->plan;
        if (! $plan || (int) $plan->max_skus <= 30) {
            return response()->json(['error' => 'requires_pro'], 403);
        }

        $supplier = Supplier::find((int) $request->input('supplier_id'));
        if (! $supplier || ! $supplier->hub_supplier_id) {
            return response()->json(['error' => 'supplier_not_federated'], 422);
        }

        $items = [];
        foreach ((array) $request->input('items', []) as $it) {
            $cp = ClientProduct::with('product')
                ->where('client_id', $c->id)
                ->find((int) ($it['client_product_id'] ?? 0));
            if (! $cp) {
                return response()->json([
                    'error'      => 'product_not_owned',
                    'product_id' => $it['client_product_id'] ?? null,
                ], 403);
            }
            $items[] = [
                'product_sku'          => $cp->product->sku ?? null,
                'custom_sku'           => $cp->custom_sku,
                'supplier_product_sku' => $cp->supplier_product_sku,
                'custom_title'         => $cp->custom_title,
                'qty'                  => (int) ($it['qty'] ?? 1),
            ];
        }

        $body = $request->all();
        unset($body['client_id']);
        $body['supplier_id'] = (int) $supplier->hub_supplier_id;
        $body['items']       = $items;
        $body['client']      = [
            'legacy_id_login' => $c->legacy_id_login ? (int) $c->legacy_id_login : null,
            'email'           => $u->email,
            'name'            => $u->name,
            'document'        => $c->document,
        ];

        return $body;
    }

    /**
     * FOR-092: resolve o client CANONICO do hub a partir da identidade do WL
     * (legacy_id_login → email). Com $autoProvision cria registro minimo
     * user+client (padrao MUL-236/JT-002: id/email/nome, nunca espelho de
     * cadastro).
     */
    private function resolveFederatedClient(array $c, bool $autoProvision): ?\App\Models\Client
    {
        $legacy = ! empty($c['legacy_id_login']) ? (int) $c['legacy_id_login'] : null;
        if ($legacy) {
            $client = \App\Models\Client::where('legacy_id_login', $legacy)->orderBy('id')->first();
            if ($client) return $client;
        }

        if (empty($c['email'])) return null;

        $user = \App\Models\User::where('email', $c['email'])->orderBy('id')->first();
        if ($user) {
            $client = \App\Models\Client::where('user_id', $user->id)->orderBy('id')->first();
            if ($client) return $client;
        }

        if (! $autoProvision) return null;

        if (! $user) {
            $user = \App\Models\User::create([
                'name'     => $c['name'] ?? $c['email'],
                'email'    => $c['email'],
                'password' => bcrypt(bin2hex(random_bytes(24))),
            ]);
        }

        Log::info('[ManualOrder] FOR-092 client canonico auto-provisionado', [
            'user_id' => $user->id,
            'email'   => $c['email'],
            'legacy'  => $legacy,
        ]);

        return \App\Models\Client::create([
            'user_id'         => $user->id,
            'document'        => $c['document'] ?? null,
            'legacy_id_login' => $legacy,
        ]);
    }

    /**
     * FOR-092: traduz itens canonicos (product_sku/custom_sku) vindos do WL
     * pra client_product_id DO HUB, criando ClientProduct minimo quando o
     * produto de catalogo existe (mesmo padrao do KitController MUL-236 F2).
     * Reescreve $request->items no formato que preview()/store() esperam.
     */
    private function resolveFederatedItems(Request $request, \App\Models\Client $client): ?JsonResponse
    {
        $resolved = [];
        foreach ((array) $request->input('items', []) as $i => $it) {
            if (! empty($it['client_product_id'])) {
                // ID cru de WL era exatamente o bug cross-tenant — rejeitar.
                return response()->json(['error' => 'local_id_not_allowed', 'item' => $i], 422);
            }

            $productSku = $it['product_sku'] ?? null;
            $productId  = $productSku
                ? DB::table('products')->where('sku', $productSku)->value('id')
                : null;

            $cpId = null;
            if ($productId) {
                $cpId = ClientProduct::where('client_id', $client->id)
                    ->where('product_id', $productId)->orderBy('id')->value('id');
            }
            if (! $cpId && ! empty($it['custom_sku'])) {
                $cpId = ClientProduct::where('client_id', $client->id)
                    ->where('custom_sku', $it['custom_sku'])->orderBy('id')->value('id');
            }
            if (! $cpId && ! empty($it['supplier_product_sku'])) {
                $cpId = ClientProduct::where('client_id', $client->id)
                    ->where('supplier_product_sku', $it['supplier_product_sku'])->orderBy('id')->value('id');
            }
            if (! $cpId && $productId) {
                $cpId = ClientProduct::create([
                    'client_id'            => $client->id,
                    'product_id'           => $productId,
                    'supplier_product_sku' => $it['supplier_product_sku'] ?? null,
                    'custom_sku'           => $it['custom_sku'] ?? null,
                    'custom_title'         => $it['custom_title'] ?? null,
                    'is_active'            => 1,
                ])->id;
            }
            if (! $cpId) {
                return response()->json([
                    'error' => 'item_unresolvable',
                    'sku'   => $productSku ?? ($it['custom_sku'] ?? ($it['supplier_product_sku'] ?? null)),
                ], 422);
            }

            $resolved[] = [
                'client_product_id' => $cpId,
                'qty'               => (int) ($it['qty'] ?? 1),
            ];
        }

        $request->merge(['items' => $resolved]);

        return null;
    }


    // =========================================================================
    // NOV-207 Etapa 3 — Confirmar recebimento externo (fornecedor)
    // Rota: POST /api/v1/orders/{id}/confirm-external-payment
    // Auth: usuario supplier do pedido (Sanctum). Filament panel usa esta mesma
    // rota internamente. Registra par credit+debit compensatorios na
    // ClientSupplierTransaction (saldo liquido zero), cria Payment gateway=external,
    // marca order pago com wallet_paid_at, anexa auto-nota em admin_note e
    // registra OrderEvent tipo=external_payment_confirmed.
    // =========================================================================
    public function confirmExternalPayment(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $supplier = $user->supplier_id ? Supplier::find($user->supplier_id) : null;
        if (! $supplier && in_array($user->role, ['super_admin', 'admin'], true)) {
            // MUL-251: admin do painel WL nao carrega supplier_id proprio
            $supplier = Supplier::find((int) config('multdrop.supplier_id', 0));
        }
        if (! $supplier) {
            return response()->json(['error' => 'not_supplier_user'], 403);
        }

        // MUL-251 ajuste Ruan 22/07: checkbox de confirmacao obrigatorio; observacao OPCIONAL
        $validated = $request->validate([
            'confirm'     => 'required|accepted',
            'observacoes' => 'nullable|string|max:2000',
        ]);
        $obs = trim((string) ($validated['observacoes'] ?? ''));

        $order = Order::where('id', $id)->where('supplier_id', $supplier->id)->first();
        if (! $order) {
            return response()->json(['error' => 'order_not_found'], 404);
        }

        if ($order->wallet_paid_at !== null) {
            return response()->json([
                'error'   => 'already_paid',
                'message' => "Pedido #{$order->id} ja consta como pago em {$order->wallet_paid_at}.",
            ], 422);
        }
        if ($order->canonical_status === 'cancelled') {
            return response()->json([
                'error'   => 'cancelled',
                'message' => 'Pedido cancelado nao pode ser confirmado.',
            ], 422);
        }


        $amount = round((float) ($order->supplier_total ?? $order->total ?? 0), 2);
        if ($amount <= 0) {
            return response()->json(['error' => 'invalid_amount', 'message' => 'Valor do pedido invalido.'], 422);
        }

        return DB::transaction(function () use ($user, $supplier, $order, $amount, $obs) {
            $balance = ClientSupplierBalance::firstOrCreate(
                ['client_id' => $order->client_id, 'supplier_id' => $supplier->id],
                ['balance' => 0]
            );
            $balance = ClientSupplierBalance::where('id', $balance->id)->lockForUpdate()->first();

            // MUL-363 Fase 3: par liquido-zero via nucleo, idempotente por pedido
            // (clique duplo do admin nao duplica o par)
            $ledger = app(\App\Services\Financial\Ledger\WalletLedger::class);
            $txCredit = $ledger->credit($order->client_id, $supplier->id, $amount,
                new \App\Services\Financial\Ledger\LedgerEntryMeta(
                    type: 'external_payment_ajuste',
                    description: "Ajuste — recebimento externo confirmado por {$user->email} (user #{$user->id}) em " . now()->format('d/m/Y H:i') . " — pedido #{$order->id}",
                    orderId: $order->id,
                    actor: "user:{$user->id}",
                    idempotencyKey: "extpay:in:order:{$order->id}",
                ));
            $txDebit = $ledger->debit($order->client_id, $supplier->id, $amount,
                new \App\Services\Financial\Ledger\LedgerEntryMeta(
                    type: 'external_payment',
                    description: "Pagamento externo pedido #{$order->id} — confirmado por {$user->email} (user #{$user->id}) em " . now()->format('d/m/Y H:i'),
                    orderId: $order->id,
                    actor: "user:{$user->id}",
                    idempotencyKey: "extpay:out:order:{$order->id}",
                ));

            $payment = Payment::create([
                'order_id'    => $order->id,
                'client_id'   => $order->client_id,
                'supplier_id' => $supplier->id,
                'gateway'     => 'external',
                'method'      => 'external',
                'amount'      => $amount,
                'status'      => 'paid',
                'paid_at'     => now(),
            ]);

            $now = now();
            $ts  = $now->format('d/m/Y H:i');
            $block = "[Pagamento externo confirmado pelo fornecedor]\n"
                   . "Por: {$user->email} (user #{$user->id}) em {$ts}\n"
                   . 'Observacoes: ' . ($obs !== '' ? $obs : '-') . "\n";
            $adminNote = trim(($order->admin_note ? $order->admin_note . "\n\n" : "") . $block);

            $order->update([
                'wallet_paid_at'        => $now,
                'wallet_transaction_id' => $txDebit->id,
                'admin_note'            => $adminNote,
            ]);
            // MUL-363: Bling sync dispara SO no evento "pedido pago" (OrderObserver)

            OrderEvent::create([
                'order_id'    => $order->id,
                'event_type'  => 'external_payment_confirmed',
                'description' => "Pagamento externo confirmado por {$user->email}",
                'user_id'     => $user->id,
                'metadata'    => [
                    'amount'       => $amount,
                    'observacoes'  => $obs,
                    'payment_id'   => $payment->id,
                    'tx_credit_id' => $txCredit->id,
                    'tx_debit_id'  => $txDebit->id,
                ],
            ]);

            Log::info('[NOV-207 E3] External payment confirmed', [
                'order_id'   => $order->id,
                'supplier'   => $supplier->id,
                'confirmer'  => $user->id,
                'amount'     => $amount,
                'payment_id' => $payment->id,
            ]);

            return response()->json([
                'ok'             => true,
                'order_id'       => $order->id,
                'wallet_paid_at' => $now->toIso8601String(),
                'payment_id'     => $payment->id,
                'admin_note'     => $adminNote,
            ], 200);
        });
    }

    // =========================================================================
    // MUL-254 — Forcar cobranca via wallet
    // Rota: POST /api/v1/supplier-admin/orders/{id}/force-charge
    // Body: { confirm: bool required=true, observacoes?: string }
    // Debita o valor do pedido da wallet do seller com debito UNICO real
    // (sem par compensatorio): saldo positivo diminui, saldo zerado NEGATIVA.
    // Cria Payment gateway=wallet method=forced, marca wallet_paid_at,
    // anexa auto-nota em admin_note e registra OrderEvent tipo=forced_charge.
    // =========================================================================
    public function forceCharge(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $supplier = $this->forceChargeSupplier($user);
        if (! $supplier) {
            return response()->json(['error' => 'not_supplier_user'], 403);
        }

        $validated = $request->validate([
            'confirm'     => 'required|accepted',
            'observacoes' => 'nullable|string|max:2000',
        ]);
        $obs = trim((string) ($validated['observacoes'] ?? ''));

        $order = Order::where('id', $id)->where('supplier_id', $supplier->id)->first();
        if (! $order) {
            return response()->json(['error' => 'order_not_found'], 404);
        }

        if ($guard = $this->forceChargeGuard($order)) {
            return response()->json($guard, 422);
        }

        return response()->json($this->forceChargeExecute($user, $supplier, $order, $obs), 200);
    }

    // MUL-277: resolucao de supplier compartilhada (padrao MUL-251)
    private function forceChargeSupplier($user): ?Supplier
    {
        $supplier = $user->supplier_id ? Supplier::find($user->supplier_id) : null;
        if (! $supplier && in_array($user->role, ['super_admin', 'admin'], true)) {
            // admin do painel WL nao carrega supplier_id proprio (mesmo padrao MUL-251)
            $supplier = Supplier::find((int) config('multdrop.supplier_id', 0));
        }
        return $supplier;
    }

    // MUL-277: guards do forceCharge — retorna ['error','message'] ou null se cobravel
    private function forceChargeGuard(Order $order): ?array
    {
        if ($order->wallet_paid_at !== null) {
            return [
                'error'   => 'already_paid',
                'message' => "Pedido #{$order->id} ja consta como pago em {$order->wallet_paid_at}.",
            ];
        }
        if ($order->canonical_status === 'cancelled') {
            return [
                'error'   => 'cancelled',
                'message' => 'Pedido cancelado nao pode ser cobrado.',
            ];
        }


        $amount = round((float) ($order->supplier_total ?? $order->total ?? 0), 2);
        if ($amount <= 0) {
            return ['error' => 'invalid_amount', 'message' => 'Valor do pedido invalido.'];
        }

        return null;
    }

    // MUL-277: nucleo transacional do forceCharge (mesmo comportamento MUL-254).
    // $syncDelaySeconds > 0 = fila lenta de sync Bling (batch).
    private function forceChargeExecute($user, Supplier $supplier, Order $order, string $obs, int $syncDelaySeconds = 0): array
    {
        $amount = round((float) ($order->supplier_total ?? $order->total ?? 0), 2);

        return DB::transaction(function () use ($user, $supplier, $order, $amount, $obs, $syncDelaySeconds) {
            $balance = ClientSupplierBalance::firstOrCreate(
                ['client_id' => $order->client_id, 'supplier_id' => $supplier->id],
                ['balance' => 0]
            );
            $balance = ClientSupplierBalance::where('id', $balance->id)->lockForUpdate()->first();

            // MUL-363 Fase 3: idempotencia pelo LEDGER — pedido com debito liquido que ja
            // cobre o valor nao e cobrado de novo (era o gerador das duplas da MUL-362:
            // autopay pagava, espelho apagava o carimbo, admin forcava em cima).
            $netJaCobrado = (float) ClientSupplierTransaction::where('order_id', $order->id)
                ->selectRaw("COALESCE(SUM(CASE WHEN type='debit' THEN amount ELSE -amount END),0) s")
                ->value('s');
            if ($amount > 0 && $netJaCobrado >= $amount - 0.01) {
                throw new \RuntimeException(
                    "Pedido #{$order->id} ja tem debito liquido de R$ " . number_format($netJaCobrado, 2, ',', '.') .
                    " no ledger — cobranca forcada bloqueada pra nao duplicar. Se a intencao e cobrar de novo, estorne antes."
                );
            }

            // Debito UNICO real via nucleo: saldo pode ficar negativo (decisao Ruan 22/07)
            $txDebit = app(\App\Services\Financial\Ledger\WalletLedger::class)->debit(
                $order->client_id,
                $supplier->id,
                $amount,
                new \App\Services\Financial\Ledger\LedgerEntryMeta(
                    type: 'forced_charge',
                    description: "Cobranca forcada pedido #{$order->id} — por {$user->email} (user #{$user->id}) em " . now()->format('d/m/Y H:i'),
                    orderId: $order->id,
                    actor: "user:{$user->id}",
                ),
                true
            );

            $payment = Payment::create([
                'order_id'    => $order->id,
                'client_id'   => $order->client_id,
                'supplier_id' => $supplier->id,
                'gateway'     => 'wallet',
                'method'      => 'forced',
                'amount'      => $amount,
                'status'      => 'paid',
                'paid_at'     => now(),
            ]);

            $balanceAfter = round((float) $balance->balance, 2);
            $now = now();
            $ts  = $now->format('d/m/Y H:i');
            $saldoFmt = number_format($balanceAfter, 2, ',', '.');
            $block = "[Cobranca forcada na wallet]\n"
                   . "Por: {$user->email} (user #{$user->id}) em {$ts}\n"
                   . "Saldo do seller apos cobranca: R$ {$saldoFmt}\n"
                   . 'Observacoes: ' . ($obs !== '' ? $obs : '-') . "\n";
            $adminNote = trim(($order->admin_note ? $order->admin_note . "\n\n" : "") . $block);

            // MUL-363: Bling sync dispara SO no evento "pedido pago" (OrderObserver),
            // que roda DURANTE o update abaixo — por isso o offset da fila lenta
            // (MUL-277) precisa estar setado ANTES.
            if ($syncDelaySeconds > 0) {
                \App\Observers\OrderObserver::$blingSyncDelaySeconds[$order->id] = $syncDelaySeconds;
            }
            $order->update([
                'wallet_paid_at'        => $now,
                'wallet_transaction_id' => $txDebit->id,
                'admin_note'            => $adminNote,
            ]);

            OrderEvent::create([
                'order_id'    => $order->id,
                'event_type'  => 'forced_charge',
                'description' => "Cobranca forcada na wallet por {$user->email}",
                'user_id'     => $user->id,
                'metadata'    => [
                    'amount'        => $amount,
                    'observacoes'   => $obs,
                    'payment_id'    => $payment->id,
                    'tx_debit_id'   => $txDebit->id,
                    'balance_after' => $balanceAfter,
                ],
            ]);

            Log::info('[MUL-254] Forced wallet charge', [
                'order_id'      => $order->id,
                'supplier'      => $supplier->id,
                'charger'       => $user->id,
                'amount'        => $amount,
                'balance_after' => $balanceAfter,
                'payment_id'    => $payment->id,
            ]);

            return [
                'ok'             => true,
                'order_id'       => $order->id,
                'wallet_paid_at' => $now->toIso8601String(),
                'payment_id'     => $payment->id,
                'balance_after'  => $balanceAfter,
                'admin_note'     => $adminNote,
            ];
        });
    }

    // =========================================================================
    // MUL-277 — Forcar cobranca EM MASSA + fila lenta de sync Bling
    // Rota: POST /api/v1/supplier-admin/orders/force-charge-batch
    // Body: { order_ids: int[] max 10000 (MUL-278), confirm: required, observacoes?: string }
    // Mesmo nucleo do forceCharge; guards viram skip com reason. Cada pedido
    // cobrado despacha SyncOrderToBlingJob com delay escalonado (6s/pedido)
    // pra formar fila LENTA e nao pesar o servidor.
    // =========================================================================
    public function forceChargeBatch(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }
        $supplier = $this->forceChargeSupplier($user);
        if (! $supplier) {
            return response()->json(['error' => 'not_supplier_user'], 403);
        }

        $validated = $request->validate([
            'confirm'     => 'required|accepted',
            'order_ids'   => 'required|array|min:1|max:10000', // MUL-278: fila lenta absorve qualquer quantidade
            'order_ids.*' => 'integer',
            'delay_offset_s' => 'nullable|integer|min:0|max:100000', // MUL-278: fila continua entre chunks
            'observacoes' => 'nullable|string|max:2000',
        ]);
        $obs = trim((string) ($validated['observacoes'] ?? ''));
        $ids = array_values(array_unique(array_map('intval', $validated['order_ids'])));

        $orders = Order::whereIn('id', $ids)->where('supplier_id', $supplier->id)->get()->keyBy('id');

        $charged = [];
        $skipped = [];
        $delay   = (int) ($validated['delay_offset_s'] ?? 0); // MUL-278: continua a fila do chunk anterior
        foreach ($ids as $oid) {
            $order = $orders->get($oid);
            if (! $order) {
                $skipped[] = ['id' => $oid, 'reason' => 'order_not_found', 'message' => 'Pedido nao encontrado.'];
                continue;
            }
            if ($guard = $this->forceChargeGuard($order)) {
                $skipped[] = ['id' => $oid, 'reason' => $guard['error'], 'message' => $guard['message']];
                continue;
            }
            try {
                $delay += 6; // fila lenta: 6s entre cada sync Bling
                $result    = $this->forceChargeExecute($user, $supplier, $order, $obs, $delay);
                $charged[] = [
                    'id'            => $oid,
                    'payment_id'    => $result['payment_id'],
                    'balance_after' => $result['balance_after'],
                    'sync_delay_s'  => $delay,
                ];
            } catch (\Throwable $e) {
                Log::error('[MUL-277] forceChargeBatch falhou num pedido', ['order_id' => $oid, 'err' => $e->getMessage()]);
                $skipped[] = ['id' => $oid, 'reason' => 'exception', 'message' => $e->getMessage()];
            }
        }

        Log::info('[MUL-277] Force charge batch', [
            'supplier' => $supplier->id,
            'user'     => $user->id,
            'charged'  => count($charged),
            'skipped'  => count($skipped),
        ]);

        return response()->json(['ok' => true, 'charged' => $charged, 'skipped' => $skipped], 200);
    }

    // =========================================================================
    // MUL-277 — Status da fila de sync Bling (derivado das colunas bling_*)
    // Rota: GET /api/v1/supplier-admin/orders/bling-sync-status?order_ids=1,2,3
    // Estados: unpaid | queued | synced | failed
    // =========================================================================
    public function blingSyncStatus(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }
        $supplier = $this->forceChargeSupplier($user);
        if (! $supplier) {
            return response()->json(['error' => 'not_supplier_user'], 403);
        }

        // MUL-278: input() le query E body — listas grandes vem via POST body (URL estoura)
        $raw = $request->input('order_ids', '');
        $ids = array_values(array_filter(array_map('intval', is_array($raw) ? $raw : explode(',', (string) $raw))));
        if ($ids === [] || count($ids) > 10000) {
            return response()->json(['error' => 'order_ids obrigatorio (1..10000 ids, csv ou array)'], 422);
        }

        $rows = Order::whereIn('id', $ids)->where('supplier_id', $supplier->id)
            ->get(['id', 'wallet_paid_at', 'bling_pedido_id', 'bling_pedido_url', 'bling_synced_at', 'bling_sync_error', 'bling_sync_attempted_at']);

        $out = $rows->map(function ($o) {
            if ($o->bling_synced_at || $o->bling_pedido_id) {
                $state = 'synced';
            } elseif ($o->bling_sync_error) {
                $state = 'failed';
            } elseif ($o->wallet_paid_at) {
                $state = 'queued';
            } else {
                $state = 'unpaid';
            }
            return [
                'id'               => $o->id,
                'state'            => $state,
                'wallet_paid_at'   => $o->wallet_paid_at ? (string) $o->wallet_paid_at : null,
                'bling_pedido_id'  => $o->bling_pedido_id,
                'bling_pedido_url' => $o->bling_pedido_url,
                'synced_at'        => $o->bling_synced_at ? (string) $o->bling_synced_at : null,
                'error'            => $o->bling_sync_error,
                'attempted_at'     => $o->bling_sync_attempted_at ? (string) $o->bling_sync_attempted_at : null,
            ];
        })->values();

        return response()->json(['data' => $out], 200);
    }

    // =========================================================================
    // NOV-207 Etapa 3 — Estornar confirmacao externa
    // Rota: POST /api/v1/orders/{id}/revert-external-payment
    // Body: { confirm: bool required=true, motivo: string min=10 }
    // Reverte: Payment=cancelled, transaction credit REAL (saldo sobe pro cliente),
    // order.wallet_paid_at=NULL, anexa nota em admin_note, OrderEvent
    // external_payment_reverted. Estorno eh sempre na wallet digital (dinheiro
    // volta pro cliente que pode reusar/sacar).
    // =========================================================================
    public function revertExternalPayment(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $supplier = $user->supplier_id ? Supplier::find($user->supplier_id) : null;
        if (! $supplier && in_array($user->role, ['super_admin', 'admin'], true)) {
            // MUL-251: admin do painel WL nao carrega supplier_id proprio
            $supplier = Supplier::find((int) config('multdrop.supplier_id', 0));
        }
        if (! $supplier) {
            return response()->json(['error' => 'not_supplier_user'], 403);
        }

        $validated = $request->validate([
            'confirm' => 'required|accepted',
            'motivo'  => 'required|string|min:10|max:2000',
        ]);

        $order = Order::where('id', $id)->where('supplier_id', $supplier->id)->first();
        if (! $order) {
            return response()->json(['error' => 'order_not_found'], 404);
        }

        $lastPayment = Payment::where('order_id', $order->id)
            ->where('gateway', 'external')
            ->where('status', 'paid')
            ->orderByDesc('id')
            ->first();

        if (! $lastPayment) {
            return response()->json([
                'error'   => 'no_external_payment',
                'message' => 'Nao ha pagamento externo pra estornar neste pedido.',
            ], 422);
        }

        $amount = round((float) $lastPayment->amount, 2);

        return DB::transaction(function () use ($user, $supplier, $order, $lastPayment, $amount, $validated) {
            $balance = ClientSupplierBalance::firstOrCreate(
                ['client_id' => $order->client_id, 'supplier_id' => $supplier->id],
                ['balance' => 0]
            );
            $balance = ClientSupplierBalance::where('id', $balance->id)->lockForUpdate()->first();

            // MUL-363 Fase 3: credit via nucleo, idempotente por payment estornado
            $tx = app(\App\Services\Financial\Ledger\WalletLedger::class)->credit(
                $order->client_id,
                $supplier->id,
                $amount,
                new \App\Services\Financial\Ledger\LedgerEntryMeta(
                    type: 'external_payment_estorno',
                    description: "Estorno de confirmacao externa pedido #{$order->id}",
                    orderId: $order->id,
                    actor: "user:{$user->id}",
                    idempotencyKey: "extpay:reverse:pay:{$lastPayment->id}",
                )
            );

            $lastPayment->update(['status' => 'cancelled']);

            $now = now();
            $ts  = $now->format('d/m/Y H:i');
            $block = "[Confirmacao externa estornada]\n"
                   . "Por: {$user->email} (user #{$user->id}) em {$ts}\n"
                   . "Motivo: {$validated['motivo']}\n";
            $adminNote = trim(($order->admin_note ? $order->admin_note . "\n\n" : "") . $block);

            $order->update([
                'wallet_paid_at'        => null,
                'wallet_transaction_id' => null,
                'admin_note'            => $adminNote,
            ]);

            OrderEvent::create([
                'order_id'    => $order->id,
                'event_type'  => 'external_payment_reverted',
                'description' => "Confirmacao externa estornada por {$user->email}",
                'user_id'     => $user->id,
                'metadata'    => [
                    'amount'       => $amount,
                    'motivo'       => $validated['motivo'],
                    'payment_id'   => $lastPayment->id,
                    'tx_credit_id' => $tx->id,
                ],
            ]);

            Log::info('[NOV-207 E3] External payment REVERTED', [
                'order_id'   => $order->id,
                'supplier'   => $supplier->id,
                'reverter'   => $user->id,
                'amount'     => $amount,
                'payment_id' => $lastPayment->id,
            ]);

            return response()->json([
                'ok'               => true,
                'order_id'         => $order->id,
                'reverted_amount'  => $amount,
                'balance_credited' => $amount,
                'admin_note'       => $adminNote,
            ], 200);
        });
    }

    // =========================================================================
    // MUL-254B — Estornar cobranca forcada
    // Rota: POST /api/v1/orders/{id}/revert-forced-charge
    // Body: { confirm: bool required=true, motivo: string min=10 }
    // Reverte: Payment wallet/forced=cancelled, credit REAL na wallet (dinheiro
    // volta pro seller), order.wallet_paid_at=NULL, nota em admin_note,
    // OrderEvent forced_charge_reverted.
    // =========================================================================
    public function revertForcedCharge(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $supplier = $user->supplier_id ? Supplier::find($user->supplier_id) : null;
        if (! $supplier && in_array($user->role, ['super_admin', 'admin'], true)) {
            // MUL-251: admin do painel WL nao carrega supplier_id proprio
            $supplier = Supplier::find((int) config('multdrop.supplier_id', 0));
        }
        if (! $supplier) {
            return response()->json(['error' => 'not_supplier_user'], 403);
        }

        $validated = $request->validate([
            'confirm' => 'required|accepted',
            'motivo'  => 'required|string|min:10|max:2000',
        ]);

        $order = Order::where('id', $id)->where('supplier_id', $supplier->id)->first();
        if (! $order) {
            return response()->json(['error' => 'order_not_found'], 404);
        }

        $lastPayment = Payment::where('order_id', $order->id)
            ->where('gateway', 'wallet')
            ->where('method', 'forced')
            ->where('status', 'paid')
            ->orderByDesc('id')
            ->first();

        if (! $lastPayment) {
            return response()->json([
                'error'   => 'no_forced_charge',
                'message' => 'Nao ha cobranca forcada pra estornar neste pedido.',
            ], 422);
        }

        $amount = round((float) $lastPayment->amount, 2);

        return DB::transaction(function () use ($user, $supplier, $order, $lastPayment, $amount, $validated) {
            $balance = ClientSupplierBalance::firstOrCreate(
                ['client_id' => $order->client_id, 'supplier_id' => $supplier->id],
                ['balance' => 0]
            );
            $balance = ClientSupplierBalance::where('id', $balance->id)->lockForUpdate()->first();

            // MUL-363 Fase 3: credit via nucleo, idempotente por payment estornado
            $tx = app(\App\Services\Financial\Ledger\WalletLedger::class)->credit(
                $order->client_id,
                $supplier->id,
                $amount,
                new \App\Services\Financial\Ledger\LedgerEntryMeta(
                    type: 'forced_charge_estorno',
                    description: "Estorno de cobranca forcada pedido #{$order->id} — por {$user->email} (user #{$user->id})",
                    orderId: $order->id,
                    actor: "user:{$user->id}",
                    idempotencyKey: "forced:reverse:pay:{$lastPayment->id}",
                    reversesTransactionId: null,
                )
            );

            $lastPayment->update(['status' => 'cancelled']);

            $balanceAfter = round((float) $balance->balance, 2);
            $now = now();
            $ts  = $now->format('d/m/Y H:i');
            $saldoFmt = number_format($balanceAfter, 2, ',', '.');
            $block = "[Cobranca forcada estornada]\n"
                   . "Por: {$user->email} (user #{$user->id}) em {$ts}\n"
                   . "Saldo do seller apos estorno: R$ {$saldoFmt}\n"
                   . "Motivo: {$validated['motivo']}\n";
            $adminNote = trim(($order->admin_note ? $order->admin_note . "\n\n" : "") . $block);

            $order->update([
                'wallet_paid_at'        => null,
                'wallet_transaction_id' => null,
                'admin_note'            => $adminNote,
            ]);

            OrderEvent::create([
                'order_id'    => $order->id,
                'event_type'  => 'forced_charge_reverted',
                'description' => "Cobranca forcada estornada por {$user->email}",
                'user_id'     => $user->id,
                'metadata'    => [
                    'amount'        => $amount,
                    'motivo'        => $validated['motivo'],
                    'payment_id'    => $lastPayment->id,
                    'tx_credit_id'  => $tx->id,
                    'balance_after' => $balanceAfter,
                ],
            ]);

            Log::info('[MUL-254B] Forced charge REVERTED', [
                'order_id'   => $order->id,
                'supplier'   => $supplier->id,
                'reverter'   => $user->id,
                'amount'     => $amount,
                'payment_id' => $lastPayment->id,
            ]);

            return response()->json([
                'ok'              => true,
                'order_id'        => $order->id,
                'reverted_amount' => $amount,
                'balance_after'   => $balanceAfter,
                'admin_note'      => $adminNote,
            ], 200);
        });
    }


}
