<?php
// INF-054 R1+R4 — caminho 2 proxy WL->hub aplicado

namespace App\Http\Controllers\Api\V1;

use App\Services\Federation\HubProxyHelper;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ProductMedia;
use App\Services\ShippingLabelService;
use App\Services\Financial\OrderPaymentService;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use App\Services\AppLoggerService;

class OrderController extends Controller
{
    use \App\Support\MontaEtapasDeDevolucao;

    private function clientOrFail(Request $request)
    {
        $user = $request->user();
        $client = $user->client;

        if (! $client) {
            // MUL-149: admin/super_admin navegando em tela de seller nao pode tomar 403
            // (o interceptor do frontend derrubava a sessao). Resposta 200 vazia.
            if (in_array($user->role, ['super_admin', 'admin'], true)) {
                abort(response()->json([
                    'data' => [], 'total' => 0, 'summary' => null,
                    'admin_without_client' => true,
                ], 200));
            }
            abort(403, 'Usuario nao possui perfil de lojista.');
        }

        return $client;
    }

    #[OA\Get(
        path: '/api/v1/orders',
        summary: 'Listar pedidos do lojista com filtros e paginacao',
        description: 'Retorna os pedidos do lojista autenticado, ordenados do mais recente para o mais antigo. Inclui os itens de cada pedido. Pode ser filtrado por status (ex: pending, paid, shipped, delivered, cancelled) e por source (origem do pedido: mercadolivre, shopee, manual, etc.). Use per_page para controlar o tamanho da pagina.',
        tags: ['Pedidos'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'per_page',
                in: 'query',
                required: false,
                description: 'Quantidade de pedidos por pagina',
                schema: new OA\Schema(type: 'integer', default: 15, example: 15)
            ),
            new OA\Parameter(
                name: 'status',
                in: 'query',
                required: false,
                description: 'Filtrar por status do pedido',
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['pending', 'paid', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'],
                    example: 'paid'
                )
            ),
            new OA\Parameter(
                name: 'source',
                in: 'query',
                required: false,
                description: 'Filtrar por origem do pedido (plataforma)',
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['mercadolivre', 'shopee', 'shopify', 'manual', 'hubaisimulator'],
                    example: 'mercadolivre'
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista de pedidos paginada com itens incluidos',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 500),
                                    new OA\Property(property: 'client_id', type: 'integer', example: 7),
                                    new OA\Property(
                                        property: 'external_id',
                                        type: 'string',
                                        nullable: true,
                                        description: 'ID do pedido na plataforma de origem',
                                        example: 'MLB-2024050001'
                                    ),
                                    new OA\Property(
                                        property: 'source',
                                        type: 'string',
                                        description: 'Plataforma de origem do pedido',
                                        example: 'mercadolivre'
                                    ),
                                    new OA\Property(
                                        property: 'status',
                                        type: 'string',
                                        description: 'Status atual do pedido',
                                        example: 'paid'
                                    ),
                                    new OA\Property(
                                        property: 'total',
                                        type: 'number',
                                        description: 'Valor total do pedido em R$',
                                        example: 189.90
                                    ),
                                    new OA\Property(
                                        property: 'buyer_name',
                                        type: 'string',
                                        nullable: true,
                                        description: 'Nome do comprador',
                                        example: 'Maria Souza'
                                    ),
                                    new OA\Property(
                                        property: 'items',
                                        type: 'array',
                                        description: 'Itens do pedido',
                                        items: new OA\Items(
                                            properties: [
                                                new OA\Property(property: 'id', type: 'integer', example: 1001),
                                                new OA\Property(property: 'order_id', type: 'integer', example: 500),
                                                new OA\Property(property: 'name', type: 'string', example: 'Camiseta Polo P'),
                                                new OA\Property(property: 'sku', type: 'string', nullable: true, example: 'CAMP-001-P'),
                                                new OA\Property(property: 'quantity', type: 'integer', example: 2),
                                                new OA\Property(property: 'unit_price', type: 'number', example: 89.95),
                                                new OA\Property(property: 'total', type: 'number', example: 179.90),
                                            ]
                                        )
                                    ),
                                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2024-05-01T08:20:00Z'),
                                    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2024-05-01T10:45:00Z'),
                                ]
                            )
                        ),
                        new OA\Property(
                            property: 'meta',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'total', type: 'integer', example: 128),
                                new OA\Property(property: 'per_page', type: 'integer', example: 15),
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'last_page', type: 'integer', example: 9),
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

        // Colunas necessarias para a listagem (exclui campos pesados: invoice_xml,
        // capture_payload, external_refs — usados apenas no detalhe via show()).
        // MUL-161-BE1: listColumns ampliado #7 bling_order_id/number #8/#9 nfe_entrada extra #27 label fields
        $listColumns = [
            'id', 'client_id', 'supplier_id', 'order_number', 'source', 'external_order_id',
            'buyer_nickname', 'customer_name', 'customer_document_number',
            'status', 'order_processing_status',
            'total', 'supplier_total', 'subtotal', 'shipping_cost', 'marketplace_fee',
            'tracking_number', 'tracking_url', 'carrier_name', 'shipping_mode', 'delivery_type',
            'label_url', 'label_status_reason', 'label_error_at', 'label_printed_at', 'manual_label_path',
            'invoice_number', 'invoice_series', 'invoice_access_key',
            'invoice_issued_at', 'invoice_status', 'invoice_url', 'invoice_xml_url',
            'nfe_entrada_status', 'nfe_entrada_received_at', 'nfe_entrada_updated_at',
            'nfe_entrada_access_key', 'nfe_entrada_pdf_url', 'nfe_entrada_xml_url',
            'canonical_status', 'wallet_paid_at', 'wallet_transaction_id', 'marketplace_order_id', 'channel_name',
            // MUL-360 item 3: FK necessaria pro eager load de marketplaceAccount funcionar
            // (sem ela o belongsTo vinha null em toda a listagem — FOR-053-D nunca populou aqui)
            'marketplace_account_id',
            'bling_order_id', 'bling_order_number',
            'seller_notes', 'expedition_note',
            'paid_at', 'shipped_at', 'delivered_at', 'cancelled_at',
            'created_at', 'updated_at',
            // MUL-239: data real da venda (MUL-237) exposta na listagem do seller
            'marketplace_created_at',
        ];

        // MUL-197: is_draft/draft_reason expostos na listagem (front distingue rascunho)
        $listColumns[] = 'is_draft';
        $listColumns[] = 'draft_reason';

        // MUL-201: expor package_number Shopee (OFG...) pra montar URL do Seller Center panel
        $listColumns[] = \DB::raw("JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload), '$.package_list[0].package_number')) AS shopee_package_number");

        $query = Order::where('client_id', $client->id)
            ->select($listColumns)
            ->with([
                'items:id,order_id,name,quantity,unit_price,total,sku,product_id,product_image,legacy_sku_pai_id',
                'items.product:id',
                'items.product.media' => fn ($q) => $q->where('is_cover', 1)->select(['id', 'product_id', 'url', 'original_url', 'local_path', 'is_cover']),
                // MUL-198: registro do pagamento visivel pro seller (metodo, valor, data, transacao)
                'walletTransaction:id,type,transaction_type,amount,description,created_at',
                'pixTransactions' => fn ($q) => $q->where('type', 'order_payment')->where('status', 'paid')
                    ->select(['id', 'order_id', 'gateway', 'amount', 'status', 'paid_at', 'created_at']),
                // FOR-053-D: identification_type (CPF/CNPJ) da conta ML pro front decidir DC-e vs NF-e
                // MUL-360 item 3: account_name (nome renomeado da integracao) pra exibir "LOJA:"
                'marketplaceAccount:id,account_name,identification_type,identification_number',
            ])
            // MUL-239: ordenar pela data real da venda (fallback created_at pro legado sem backfill)
            ->orderByRaw('COALESCE(marketplace_created_at, created_at) DESC');

        // MUL-197: rascunhos FORA da lista padrao; ?draft=1 lista SO rascunhos
        $query->where('is_draft', $request->boolean('draft') ? 1 : 0);

        // INF-036: pedido inexistente na API do marketplace fica oculto em TODAS
        // as superficies do frontend (inclusive aba rascunhos). Auditoria: admin Filament.
        $query->where('status', '!=', \App\Enums\OrderStatus::NOT_FOUND->value);

        if ($request->filled('status')) {
            // MUL-193: abas do painel filtram por status canonico; marketplaces gravam
            // sinonimos (PROCESSED->processing etc.) que ficavam invisiveis em toda aba.
            $statusSynonyms = [
                'paid'      => ['paid', 'pago', 'approved', 'aprovado', 'ready_to_ship', 'processed', 'processing'],
                'pending'   => ['pending', 'pending_payment'],
                'delivered' => ['delivered', 'completed', 'to_confirm_receive'],
            ];
            $statusParam = $request->query('status');

            if ($statusParam === 'pending') {
                // MUL-198: aba "Pendente" = aguardando pagamento do SELLER — inclui pedidos
                // com status de marketplace "pago" (comprador pagou) mas sem lastro
                // (wallet_paid_at NULL). Espelha o card Aguard. Pagamento do summary.
                $query->where(function ($q) use ($statusSynonyms) {
                    $q->whereIn('status', $statusSynonyms['pending'])
                      ->orWhere(function ($q2) use ($statusSynonyms) {
                          $q2->whereIn('status', $statusSynonyms['paid'])
                             ->whereNull('wallet_paid_at');
                      });
                });
            } else {
                $query->whereIn('status', $statusSynonyms[$statusParam] ?? [$statusParam]);

                // MUL-198: aba "Pago" exige lastro real — wallet_paid_at preenchido.
                // Status de marketplace (paid/processing/processed/ready_to_ship) NAO marca pago
                // do seller sem registro de pagamento (PIX confirmado ou debito de wallet).
                if ($statusParam === 'paid') {
                    $query->whereNotNull('wallet_paid_at');
                }
            }
        }

        if ($request->filled('source')) {
            // MUL-214 item 25: filtro por marketplace inclui pedidos importados via
            // Bling cujo channel_name aponta pro mesmo marketplace; 'bling' passa a
            // significar apenas pedidos Bling sem canal mapeavel (sem repetir).
            $src = strtolower((string) $request->query('source'));
            $channelKeywords = [
                'shopee'       => 'shopee',
                'mercadolivre' => 'mercado',
                'amazon'       => 'amazon',
                'tiktok'       => 'tiktok',
                'magalu'       => 'magalu',
            ];
            if ($src === 'bling') {
                $query->where('source', 'bling')->where(function ($q) use ($channelKeywords) {
                    $q->whereNull('channel_name')->orWhere('channel_name', '');
                    // canal preenchido mas nao-mapeavel a marketplace continua em 'bling'
                    $q->orWhere(function ($qq) use ($channelKeywords) {
                        foreach ($channelKeywords as $needle) {
                            $qq->whereRaw('LOWER(channel_name) NOT LIKE ?', ['%' . $needle . '%']);
                        }
                    });
                });
            } elseif (isset($channelKeywords[$src])) {
                $needle = $channelKeywords[$src];
                $query->where(function ($q) use ($src, $needle) {
                    $q->where('source', $src)
                      ->orWhere(function ($qq) use ($needle) {
                          $qq->where('source', 'bling')
                             ->whereRaw('LOWER(channel_name) LIKE ?', ['%' . $needle . '%']);
                      });
                });
            } else {
                $query->where('source', $src);
            }
        }

        if ($request->filled('carrier')) {
            $carrier = $request->query('carrier');
            $query->where(function ($q) use ($carrier) {
                $q->where('carrier_name', 'like', "%{$carrier}%")
                  ->orWhere('shipping_mode', 'like', "%{$carrier}%")
                  ->orWhere('delivery_type', 'like', "%{$carrier}%");
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }

        if ($request->filled('search')) {
            $s = $request->query('search');
            // MUL-092: busca numerica usa match exato em external_order_id e order_number
            // para evitar false positives (ex: "1892" bate em "grabriell1892" e "25189232941")
            $query->where(function($q) use ($s) {
                if (ctype_digit($s)) {
                    $q->where('order_number', $s)
                      ->orWhere('external_order_id', $s)
                      ->orWhere('marketplace_order_id', $s)
                      ->orWhere('id', $s);
                } else {
                    $q->where('order_number', 'like', "%{$s}%")
                      ->orWhere('external_order_id', 'like', "%{$s}%")
                      ->orWhere('marketplace_order_id', 'like', "%{$s}%")
                      ->orWhere('customer_name', 'like', "%{$s}%");
                }
            });
        }

        // NOV-207 Etapa 2: filtro rapido por semaforo de etiqueta
        if ($request->filled('label_semaforo')) {
            $sem = $request->query('label_semaforo');
            if ($sem === 'acao_necessaria') {
                $query->whereNull('label_url')
                      ->whereNull('manual_label_path')
                      ->whereIn('label_status_reason', [
                          'invoice_required','invoice_required_cpf','invoice_required_cnpj',
                          'token_error','fiscal_data_missing','missing_marketplace_account',
                      ]);
            } elseif ($sem === 'aguardando') {
                $query->whereNull('label_url')->whereNull('manual_label_path')
                      ->where(function($q){
                          $q->whereNull('label_status_reason')
                            ->orWhereIn('label_status_reason', ['awaiting_marketplace','payment_pending']);
                      });
            } elseif ($sem === 'ok') {
                $query->where(function($q){
                    $q->whereNotNull('label_url')->orWhereNotNull('manual_label_path');
                });
            }
        }

        // MUL-360 item 8: payable_ids=1 devolve TODOS os ids pagaveis do filtro atual
        // (nao so a pagina) — mesma semantica do front: nao cancelado, sem wallet_paid_at,
        // custo > 0, com etiqueta (ou enviado/entregue, ou Amazon Fulfillment).
        if ($request->boolean('payable_ids')) {
            $payableQ = (clone $query)
                ->whereNull('wallet_paid_at')
                ->where('supplier_total', '>', 0)
                ->whereNotNull('supplier_id')
                ->whereNotIn('status', ['cancelled', 'canceled'])
                ->where(function ($q) {
                    $q->whereNotNull('label_url')
                      ->orWhereNotNull('manual_label_path')
                      ->orWhereIn('status', ['shipped', 'delivered'])
                      ->orWhereRaw("LOWER(CONCAT_WS(' ', COALESCE(carrier_name,''), COALESCE(shipping_mode,''), COALESCE(channel_name,''))) REGEXP 'fulfillment|fba'");
                });
            $rows = $payableQ->get(['id', 'supplier_total']);

            return response()->json([
                'ids'        => $rows->pluck('id'),
                'count'      => $rows->count(),
                'total_cost' => round((float) $rows->sum('supplier_total'), 2),
            ]);
        }

        $paginator = $query->paginate($perPage);

        // MUL-341: estado da devolucao da pagina inteira numa consulta so, para o caminhao de volta
        $devolucoes = \App\Support\EtapaDoPedido::devolucoesDe(
            collect($paginator->items())->pluck('id')->filter()->map(fn ($v) => (int) $v)->all()
        );

        // MUL-161-BE1 #27: adicionar label_status_label ao payload de orders
        $items = collect($paginator->items())->map(function ($order) use ($devolucoes) {
            $arr = is_array($order) ? $order : $order->toArray();

            // MUL-341: a etapa vem resolvida daqui, nunca calculada no front. O painel do seller e
            // o do fornecedor mostravam coisas diferentes do mesmo pedido porque cada um tinha a
            // propria regra — 1.968 pedidos com status 'completed' que so o seller reconhecia.
            $etapa = \App\Support\EtapaDoPedido::resolver(
                (object) $arr,
                $devolucoes[(int) ($arr['id'] ?? 0)] ?? null
            );
            $arr['etapa']        = $etapa['etapa'];
            $arr['etapa_rotulo'] = $etapa['rotulo'];
            $arr['devolucao']    = $etapa['devolucao'];
            $arr['label_status_label']   = $this->resolveLabelStatusLabel($arr);
            $arr['label_semaforo']       = $this->resolveLabelSemaforo($arr);
            $arr['label_status_message'] = $this->resolveLabelStatusMessage($arr);
            $arr['label_action_url']     = $this->resolveLabelActionUrl($arr);
            return $arr;
        })->all();

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
     * MUL-161-BE1 #27 — Resolve label_status_label baseado no status do pedido e etiqueta.
     * Quando etiqueta ja recebida (label_url) e status awaiting_label -> 'Aguardando Separacao'.
     * Mapeia canonical_status e status interno para labels em PT-BR.
     */
    protected function resolveLabelStatusLabel(array $order): ?string
    {
        $status      = $order['status'] ?? null;
        $labelUrl    = $order['label_url'] ?? null;
        $manualLabel = $order['manual_label_path'] ?? null;
        $canonical   = $order['canonical_status'] ?? null;

        // Etiqueta ja recebida + status awaiting_label -> Aguardando Separacao
        if (($labelUrl || $manualLabel) && $status === 'awaiting_label') {
            return 'Aguardando Separacao';
        }

        // Status canonico do marketplace quando disponivel
        if ($canonical) {
            $map = [
                'delivered'      => 'Entregue',
                'cancelled'      => 'Cancelado',
                'returning'      => 'Em Devolucao',
                'returned'       => 'Devolvido',
                'shipped'        => 'Enviado',
                'paid'           => 'Pago',
                'pending'        => 'Aguardando Pagamento',
                'awaiting_label' => 'Aguardando Etiqueta',
                'label_printed'  => 'Etiqueta Impressa',
                'picked'         => 'Separado',
                'packed'         => 'Embalado',
                'processing'     => 'Processando',
            ];
            return $map[$canonical] ?? ucfirst(str_replace('_', ' ', $canonical));
        }

        // Fallback pelo status interno
        if ($status) {
            $internalMap = [
                'pending'        => 'Aguardando Pagamento',
                'paid'           => 'Pago',
                'awaiting_label' => $labelUrl ? 'Aguardando Separacao' : 'Aguardando Etiqueta',
                'label_printed'  => 'Etiqueta Impressa',
                'shipped'        => 'Enviado',
                'delivered'      => 'Entregue',
                'cancelled'      => 'Cancelado',
                'returning'      => 'Em Devolucao',
                'returned'       => 'Devolvido',
            ];
            return $internalMap[$status] ?? null;
        }

        return null;
    }

    /**
     * NOV-207 Etapa 2 - Semaforo simplificado pro frontend baseado no label_status_reason.
     * ok            = etiqueta pronta (label_url preenchido)
     * aguardando    = marketplace ainda processando (nao alarma o seller)
     * acao_necessaria = seller precisa resolver algo (mostrar banner + CTA)
     */
    protected function resolveLabelSemaforo(array $order): string
    {
        $labelUrl = $order['label_url'] ?? null;
        $manual   = $order['manual_label_path'] ?? null;
        if ($labelUrl || $manual) {
            return 'ok';
        }
        $reason = $order['label_status_reason'] ?? null;
        $acao = [
            'invoice_required', 'invoice_required_cpf', 'invoice_required_cnpj',
            'token_error', 'fiscal_data_missing', 'missing_marketplace_account',
            // SEL-413: terminais. Entram como acao_necessaria de proposito — sao os
            // casos em que o seller PRECISA fazer algo, ou ao menos saber que nao ha
            // o que esperar. Classificar como "aguardando" e o que travou 3 pedidos
            // do cliente 1331 por 5 dias sem ninguem perceber.
            'already_shipped', 'tracking_invalid', 'label_unavailable',
        ];
        if (in_array($reason, $acao, true)) {
            return 'acao_necessaria';
        }
        return 'aguardando';
    }

    /**
     * NOV-207 Etapa 2 - Mensagem PT-BR curta pro badge/banner.
     */
    protected function resolveLabelStatusMessage(array $order): ?string
    {
        $labelUrl = $order['label_url'] ?? null;
        $manual   = $order['manual_label_path'] ?? null;
        if ($labelUrl || $manual) {
            return 'Etiqueta pronta';
        }
        $reason = $order['label_status_reason'] ?? null;
        $map = [
            'invoice_required_cpf'         => 'Emita a DC-e no anuncio do Mercado Livre',
            'invoice_required_cnpj'        => 'Emita a NF-e no anuncio do Mercado Livre',
            'invoice_required'             => 'Emita a nota fiscal no anuncio do marketplace',
            'token_error'                  => 'Reconecte sua conta do marketplace',
            'fiscal_data_missing'          => 'Complete seus dados fiscais',
            'missing_marketplace_account'  => 'Conecte a conta do marketplace deste pedido',
            'awaiting_marketplace'         => 'Marketplace ainda esta processando a etiqueta',
            'payment_pending'              => 'Aguardando processamento',
            'already_shipped'              => 'Pedido ja despachado no marketplace — etiqueta nao disponivel',
            'tracking_invalid'             => 'Rastreio invalidado — gere uma nova etiqueta no marketplace',
            'label_unavailable'            => 'O marketplace nao libera a etiqueta deste pedido',
        ];
        return $map[$reason] ?? ($reason ? 'Etiqueta com problema - contate o suporte' : null);
    }

    /**
     * NOV-207 Etapa 2 - URL do CTA (deep-link) quando o seller precisa agir.
     */
    protected function resolveLabelActionUrl(array $order): ?string
    {
        $reason = $order['label_status_reason'] ?? null;
        $source = $order['source'] ?? null;
        switch ($reason) {
            case 'invoice_required':
            case 'invoice_required_cpf':
            case 'invoice_required_cnpj':
                if ($source === 'mercadolivre') {
                    $ext = $order['external_order_id'] ?? null;
                    return $ext ? "https://www.mercadolivre.com.br/vendas/{$ext}/detalhe" : 'https://www.mercadolivre.com.br/vendas';
                }
                return null;
            case 'token_error':
            case 'missing_marketplace_account':
                return '/integracoes';
            case 'fiscal_data_missing':
                return '/configuracoes/fiscal';
            default:
                return null;
        }
    }


    #[OA\Get(
        path: '/api/v1/orders/{id}',
        summary: 'Detalhes completos de um pedido especifico',
        description: 'Retorna todos os dados de um pedido do lojista, incluindo itens, historico de eventos (timeline de status) e dados de entrega. O pedido deve pertencer ao lojista autenticado. Use este endpoint para exibir a pagina de detalhe do pedido no front-end.',
        tags: ['Pedidos'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'ID do pedido',
                schema: new OA\Schema(type: 'integer', example: 500)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Dados completos do pedido com itens e eventos',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 500),
                                new OA\Property(property: 'client_id', type: 'integer', example: 7),
                                new OA\Property(property: 'external_id', type: 'string', nullable: true, example: 'MLB-2024050001'),
                                new OA\Property(property: 'source', type: 'string', example: 'mercadolivre'),
                                new OA\Property(property: 'status', type: 'string', example: 'shipped'),
                                new OA\Property(property: 'total', type: 'number', example: 189.90),
                                new OA\Property(property: 'subtotal', type: 'number', nullable: true, example: 179.90),
                                new OA\Property(property: 'shipping_cost', type: 'number', nullable: true, example: 10.00),
                                new OA\Property(property: 'buyer_name', type: 'string', nullable: true, example: 'Maria Souza'),
                                new OA\Property(property: 'buyer_email', type: 'string', nullable: true, example: 'maria@example.com'),
                                new OA\Property(property: 'shipping_address', type: 'object', nullable: true, example: ['street' => 'Rua das Flores', 'number' => '100', 'city' => 'Sao Paulo', 'state' => 'SP', 'zip_code' => '01310-100']),
                                new OA\Property(property: 'tracking_code', type: 'string', nullable: true, example: 'BR123456789BR'),
                                new OA\Property(
                                    property: 'items',
                                    type: 'array',
                                    items: new OA\Items(
                                        properties: [
                                            new OA\Property(property: 'id', type: 'integer', example: 1001),
                                            new OA\Property(property: 'order_id', type: 'integer', example: 500),
                                            new OA\Property(property: 'name', type: 'string', example: 'Camiseta Polo P'),
                                            new OA\Property(property: 'sku', type: 'string', nullable: true, example: 'CAMP-001-P'),
                                            new OA\Property(property: 'quantity', type: 'integer', example: 2),
                                            new OA\Property(property: 'unit_price', type: 'number', example: 89.95),
                                            new OA\Property(property: 'total', type: 'number', example: 179.90),
                                        ]
                                    )
                                ),
                                new OA\Property(
                                    property: 'events',
                                    type: 'array',
                                    description: 'Historico de eventos do pedido (timeline)',
                                    items: new OA\Items(
                                        properties: [
                                            new OA\Property(property: 'id', type: 'integer', example: 201),
                                            new OA\Property(property: 'order_id', type: 'integer', example: 500),
                                            new OA\Property(property: 'event', type: 'string', example: 'status_changed'),
                                            new OA\Property(property: 'description', type: 'string', example: 'Pedido enviado para transportadora'),
                                            new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2024-05-02T14:00:00Z'),
                                        ]
                                    )
                                ),
                                new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2024-05-01T08:20:00Z'),
                                new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2024-05-02T14:00:00Z'),
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
            new OA\Response(
                response: 404,
                description: 'Pedido nao encontrado ou nao pertence ao lojista',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'No query results for model [Order] 99')]
                )
            ),
        ]
    )]
    public function show(Request $request, int $id)
    {
        $client = $this->clientOrFail($request);
        // MUL-252: admin/super_admin abre detalhe de pedido de qualquer seller
        // (a listagem do painel admin ja e global via endpoint supplier — sem isso o Ver dava 404)
        $isAdminRole = in_array($request->user()->role ?? '', ['super_admin', 'admin'], true);
        $order  = Order::when(! $isAdminRole, fn ($q) => $q->where('client_id', $client->id))
            ->with([
                'items',
                // SEL-210: SKU do anúncio (custom_sku do client_product) + link do listing
                // pra Detalhes do Pedido exibir junto com o SKU do produto atual (swap SKU visível)
                'items.clientProduct:id,custom_sku,external_listing_id,external_listing_url',
                // MUL-230 fix-2: carrega dados do kit pro modal Order Review (nome + sku do anuncio kit)
                'items.clientKit:id,name,sku',
                'events',
                // MUL-198: registro do pagamento no detalhe do pedido
                'walletTransaction:id,type,transaction_type,amount,description,created_at',
                'pixTransactions' => fn ($q) => $q->where('type', 'order_payment')->where('status', 'paid')
                    ->select(['id', 'order_id', 'gateway', 'amount', 'status', 'paid_at', 'created_at']),
                // MUL-360 item 3: nome renomeado da integracao no detalhe do pedido
                'marketplaceAccount:id,account_name,identification_type,identification_number',
            ])
            ->findOrFail($id);

        // MUL-360 item 4: NF-e Entrada = nota que o FORNECEDOR ja emitiu no Bling dele.
        // Read-through no primeiro Ver: busca e espelha chave/PDF/XML/status; resultado
        // negativo cacheia 30min pra nao bater no Bling a cada abertura. Nunca emite nada.
        if (! $order->nfe_entrada_access_key && $order->bling_pedido_id
            && \Illuminate\Support\Facades\Cache::add("nfe_entrada_probe_{$order->id}", 1, 1800)) {
            try {
                $erp = \App\Models\ErpAccount::where('supplier_id', $order->supplier_id)
                    ->where('platform', 'bling')->where('status', 'active')->first();
                if ($erp && app(\App\Services\Integrations\Erps\Bling\BlingNfeService::class)->syncIssuedNfe($erp, $order)) {
                    $order->refresh();
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::info('[Order show] probe NF-e entrada falhou: ' . $e->getMessage(), [
                    'order_id' => $order->id,
                ]);
            }
        }

        // Expõe custo do fornecedor somente para planos Pro/Scale (max_skus > 30).
        // Start e clientes sem plano ativo não recebem supplier_unit_cost nem
        // supplier_total_cost — campos ficam ocultos via OrderItem::$hidden.
        $user = $request->user();
        $isAdmin = in_array($user->role ?? '', ['super_admin', 'admin', 'staff']);
        $canSeeCost = $isAdmin || ! $client->isStartPlan();

        if ($canSeeCost) {
            $order->items->each->makeVisible(['supplier_unit_cost', 'supplier_total_cost']);
        }

        // NOV-207 Etapa 2: expor semaforo/mensagem/CTA de etiqueta no detalhe
        $arr = $order->toArray();

        // MUL-340: as etapas da devolucao, ja resolvidas. Vazio em pedido normal.
        $arr['devolucoes'] = $this->etapasDeDevolucao($order->id, $order->external_order_id);

        // MUL-341: a mesma etapa da listagem, da mesma funcao
        $etapa = \App\Support\EtapaDoPedido::resolver(
            $order,
            \App\Support\EtapaDoPedido::devolucaoDe($order->id, $order->external_order_id)
        );
        $arr['etapa']        = $etapa['etapa'];
        $arr['etapa_rotulo'] = $etapa['rotulo'];
        $arr['devolucao']    = $etapa['devolucao'];
        $arr['label_status_label']   = $this->resolveLabelStatusLabel($arr);
        $arr['label_semaforo']       = $this->resolveLabelSemaforo($arr);
        $arr['label_status_message'] = $this->resolveLabelStatusMessage($arr);
        $arr['label_action_url']     = $this->resolveLabelActionUrl($arr);
        // MUL-230: expoe marketplace_sku on-demand. Prioridade: (1) raw_payload propagado pelo fanout; (2) fallback lookup legado.produtos por external_item_id. Zero coluna guardada — fonte unica.
        if (!empty($arr['items'])) {
            $rawStr = $arr['raw_payload'] ?? $order->raw_payload ?? null;
            $rawItems = is_string($rawStr) ? (data_get(json_decode($rawStr, true), 'data.order.items') ?: []) : (data_get($rawStr, 'data.order.items') ?: []);
            foreach ($arr['items'] as $k => $item) {
                $eid = $item['external_item_id'] ?? null;
                $mkt = null;
                // MUL-230: item eh componente de kit → SKU do anuncio = client_kits.sku (fonte unica)
                if (!empty($item['client_kit_id'])) {
                    $mkt = \DB::table('client_kits')->where('id', $item['client_kit_id'])->value('sku');
                    if ($mkt) { $arr['items'][$k]['marketplace_sku'] = $mkt; continue; }
                }
                // MUL-252: componente explodido chega sem external_item_id — casa tambem por sku interno
                foreach ($rawItems as $ri) {
                    if (empty($ri['marketplace_sku'])) continue;
                    if (($eid && ($ri['external_item_id'] ?? null) == $eid)
                        || (!empty($item['sku']) && ($ri['sku'] ?? null) === $item['sku'])) { $mkt = $ri['marketplace_sku']; break; }
                }
                if (!$mkt && $eid) {
                    try { $mkt = \DB::connection('legacy')->table('produtos')->where('item_id', $eid)->value('sku'); } catch (\Throwable $e) {}
                }
                if ($mkt) $arr['items'][$k]['marketplace_sku'] = $mkt;
            }
        }
        return response()->json(['data' => $arr]);
    }

    #[OA\Post(
        path: '/api/v1/orders/{id}/label',
        summary: 'Gerar ou buscar etiqueta de envio do pedido',
        description: 'Verifica se o pedido ja possui etiqueta salva e a retorna diretamente. Caso contrario, solicita a etiqueta ao marketplace (ex: Mercado Livre) e armazena a URL localmente. Retorna label_url, tracking_number e carrier_name quando disponivel. O campo ready indica se a etiqueta esta pronta — se false, o campo retry_in_minutes indica quando tentar novamente.',
        tags: ['Pedidos'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'ID do pedido',
                schema: new OA\Schema(type: 'integer', example: 500)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Status da etiqueta de envio',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'ready', type: 'boolean', description: 'Indica se a etiqueta esta disponivel para download', example: true),
                        new OA\Property(property: 'label_url', type: 'string', nullable: true, description: 'URL do PDF da etiqueta quando ready=true', example: '/storage/labels/order-500-12345678.pdf'),
                        new OA\Property(property: 'tracking_number', type: 'string', nullable: true, description: 'Codigo de rastreio do envio', example: 'BR123456789BR'),
                        new OA\Property(property: 'carrier_name', type: 'string', nullable: true, description: 'Nome da transportadora', example: 'Correios'),
                        new OA\Property(property: 'reason', type: 'string', nullable: true, description: 'Motivo quando ready=false', example: 'O envio ainda nao foi criado pelo Mercado Livre.'),
                        new OA\Property(property: 'retry_in_minutes', type: 'integer', nullable: true, description: 'Minutos sugeridos para nova tentativa', example: 5),
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
            new OA\Response(
                response: 404,
                description: 'Pedido nao encontrado ou nao pertence ao lojista',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'No query results for model [Order] 99')]
                )
            ),
        ]
    )]
    public function generateLabel(Request $request, int $id, ShippingLabelService $labelService)
    {
        // SEL-413: so proxya pro hub quando o pedido REALMENTE vive no hub.
        // O fallback antigo ("hubai_order_id ?: id") mandava o id LOCAL do WL, e o hub
        // respondia order_not_found — ou, pior, encontrava um pedido alheio de mesmo id.
        // E o mesmo defeito que o MUL-298 corrigiu no OrderItemsController.
        // Diferenca importante: la a resposta certa e RECUSAR (editar pedido errado no hub
        // e destrutivo). Aqui a resposta certa e RESOLVER LOCAL: a conta de marketplace
        // vive no banco do proprio WL e o FetchShippingLabelJob sempre rodou localmente
        // em cada WL — e assim que o multdrop obtem etiqueta. Sem vinculo, segue o fluxo local.
        // Impacto: o seller.global tem 100% dos pedidos nativos, entao este endpoint
        // NUNCA funcionou la — todo clique voltava order_not_found.
        $hubLinkId = HubProxyHelper::isWl() ? Order::where('id', $id)->value('hubai_order_id') : null;
        if ($hubLinkId) {
            $u = $request->user();
            $c = $u ? $u->client : null;
            return HubProxyHelper::forwardToHub('post', "/orders/$hubLinkId/label", ['client_id' => $c ? ($c->hubai_id ?? $c->id) : null]);
        }
        $client = $this->clientOrFail($request);
        $order  = Order::where('client_id', $client->id)->findOrFail($id);

        // Guard financeiro: bloqueia etiqueta se custo do fornecedor nao foi pago.
        $supplierTotal = (float) ($order->supplier_total ?? 0);
        if ($order->supplier_id && $supplierTotal <= 0) {
            // MUL-192: custo zero/indefinido = dado quebrado, nao item gratis — sem lastro nao emite etiqueta.
            return response()->json([
                'error'   => 'supplier_cost_missing',
                'message' => 'Custo do fornecedor indefinido para este pedido. Aguarde a correcao do custo antes de emitir a etiqueta.',
            ], 402);
        }
        // SEL-413: guard 'supplier_unpaid' REMOVIDO daqui.
        // O MUL-242 (974924d, 20/07) ja havia tirado os gates de pagamento do
        // FetchShippingLabelJob/CheckLabelAvailabilityJob por causa do deadlock com o
        // NOV-207 — a etiqueta e PRE-requisito do pagamento (OrderPaymentService guard 4),
        // entao exigir pagamento pra buscar etiqueta trava os dois lados. Aquele commit
        // nao tocou neste controller, e o caminho MANUAL ficou com o gate antigo: o seller
        // sem etiqueta nao conseguia nem pagar nem pedir a etiqueta de novo.
        // Quem protege o fornecedor continua sendo assertPaymentConfirmed (MUL-255):
        // sem wallet_paid_at ninguem bipa envio. Impressao segue exigindo pagamento.

        // SEL-413: roda o proprio job de forma sincrona em vez de chamar o service solto.
        // O job persiste label_url, grava label_status_reason padronizado, atualiza
        // order_processing_status, limpa o erro no sucesso, faz o fanout pros WLs e
        // agenda o retry automatico. Nada disso acontecia com checkLabelStatus() direto —
        // o clique do usuario nao deixava rastro nenhum no pedido.
        \App\Jobs\FetchShippingLabelJob::dispatchSync($order->id, 'manual');

        $order->refresh();

        $arr   = $order->toArray();
        $ready = ! empty($order->label_url) || ! empty($order->manual_label_path);

        return response()->json([
            'ready'               => $ready,
            'label_url'           => $order->label_url,
            // compat: consumidores antigos leem 'reason'
            'reason'              => $ready ? null : ($order->label_status_reason ?: 'awaiting_marketplace'),
            'label_status_reason' => $order->label_status_reason,
            'message'             => $this->resolveLabelStatusMessage($arr),
            'semaforo'            => $this->resolveLabelSemaforo($arr),
            'tracking_number'     => $order->tracking_number,
            'carrier_name'        => $order->carrier_name,
        ]);
    }

    #[OA\Post(
        path: '/api/v1/orders/{id}/invoice',
        summary: 'Registrar NF-e de um pedido',
        description: 'Salva os dados da Nota Fiscal Eletronica no pedido: numero, serie, chave de acesso e URLs do PDF e XML quando disponiveis. Todos os campos sao opcionais individualmente, mas pelo menos um deve ser enviado. Sobrescreve dados anteriores se o pedido ja tiver NF-e registrada.',
        tags: ['Pedidos'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'ID do pedido',
                schema: new OA\Schema(type: 'integer', example: 500)
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'invoice_number', type: 'string', nullable: true, description: 'Numero da NF-e', example: '000123'),
                    new OA\Property(property: 'invoice_series', type: 'string', nullable: true, description: 'Serie da NF-e', example: '001'),
                    new OA\Property(property: 'invoice_access_key', type: 'string', nullable: true, description: 'Chave de acesso de 44 digitos da NF-e', example: '35260512345678000199550010001234560000000001'),
                    new OA\Property(property: 'invoice_url', type: 'string', nullable: true, description: 'URL do PDF da NF-e (DANFE)', example: 'https://cdn.example.com/nfe/123.pdf'),
                    new OA\Property(property: 'invoice_xml_url', type: 'string', nullable: true, description: 'URL do XML da NF-e', example: 'https://cdn.example.com/nfe/123.xml'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'NF-e registrada com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'invoice_number', type: 'string', nullable: true, example: '000123'),
                        new OA\Property(property: 'invoice_series', type: 'string', nullable: true, example: '001'),
                        new OA\Property(property: 'invoice_access_key', type: 'string', nullable: true, example: '35260512345678000199550010001234560000000001'),
                        new OA\Property(property: 'invoice_url', type: 'string', nullable: true, example: 'https://cdn.example.com/nfe/123.pdf'),
                        new OA\Property(property: 'invoice_xml_url', type: 'string', nullable: true, example: 'https://cdn.example.com/nfe/123.xml'),
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
            new OA\Response(
                response: 404,
                description: 'Pedido nao encontrado ou nao pertence ao lojista',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'No query results for model [Order] 99')]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Erro de validacao',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'The invoice access key must be 44 characters.'),
                        new OA\Property(property: 'errors', type: 'object', example: ['invoice_access_key' => ['The invoice access key must be 44 characters.']]),
                    ]
                )
            ),
        ]
    )]
    public function addInvoice(Request $request, int $id)
    {
        if (HubProxyHelper::isWl()) {
            $order = Order::find($id);
            $hubId = $order && $order->hubai_order_id ? $order->hubai_order_id : $id;
            $u = $request->user();
            $c = $u ? $u->client : null;
            $body = $request->only(['invoice_number','invoice_series','invoice_access_key','invoice_url','invoice_xml_url']);
            $body['client_id'] = $c ? ($c->hubai_id ?? $c->id) : null;
            return HubProxyHelper::forwardToHub('post', "/orders/$hubId/invoice", $body);
        }
        $client = $this->clientOrFail($request);
        $order  = Order::where('client_id', $client->id)->findOrFail($id);

        $validated = $request->validate([
            'invoice_number'     => ['sometimes', 'nullable', 'string', 'max:20'],
            'invoice_series'     => ['sometimes', 'nullable', 'string', 'max:10'],
            'invoice_access_key' => ['sometimes', 'nullable', 'string', 'size:44'],
            'invoice_url'        => ['sometimes', 'nullable', 'url', 'max:500'],
            'invoice_xml_url'    => ['sometimes', 'nullable', 'url', 'max:500'],
        ]);

        $validated['invoice_issued_at'] = now();

        $order->update($validated);

        return response()->json([
            'success'            => true,
            'invoice_number'     => $order->invoice_number,
            'invoice_series'     => $order->invoice_series,
            'invoice_access_key' => $order->invoice_access_key,
            'invoice_url'        => $order->invoice_url,
            'invoice_xml_url'    => $order->invoice_xml_url,
        ]);
    }

    #[OA\Post(
        path: '/api/v1/orders/{id}/ship',
        summary: 'Marcar pedido como enviado',
        description: 'Atualiza o status de processamento do pedido para "shipped" e registra a data/hora do envio. Se o pedido ja possui tracking_number salvo, ele e mantido — envie tracking_number no body somente se quiser sobrescrever ou adicionar um novo codigo de rastreio.',
        tags: ['Pedidos'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'ID do pedido',
                schema: new OA\Schema(type: 'integer', example: 500)
            ),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'tracking_number', type: 'string', nullable: true, description: 'Codigo de rastreio (opcional se ja existir no pedido)', example: 'BR123456789BR'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Pedido marcado como enviado',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'shipped_at', type: 'string', format: 'date-time', example: '2026-05-12T14:30:00Z'),
                        new OA\Property(property: 'tracking_number', type: 'string', nullable: true, example: 'BR123456789BR'),
                        new OA\Property(property: 'order_processing_status', type: 'string', example: 'shipped'),
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
            new OA\Response(
                response: 404,
                description: 'Pedido nao encontrado ou nao pertence ao lojista',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'No query results for model [Order] 99')]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Erro de validacao',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'The tracking number field must be a string.'),
                        new OA\Property(property: 'errors', type: 'object', example: ['tracking_number' => ['The tracking number field must be a string.']]),
                    ]
                )
            ),
        ]
    )]
    public function markShipped(Request $request, int $id)
    {
        // INF-054 R2: se WL, proxy pro hub
        if (HubProxyHelper::isWl()) {
            $order = Order::find($id);
            $hubId = $order && $order->hubai_order_id ? $order->hubai_order_id : $id;
            return HubProxyHelper::forwardToHub('post', "/orders/$hubId/ship", $request->only(['tracking_number']));
        }
        $client = $this->clientOrFail($request);
        $order  = Order::where('client_id', $client->id)->findOrFail($id);

        $validated = $request->validate([
            'tracking_number' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $updateData = [
            'order_processing_status' => 'shipped',
            'shipped_at'              => now(),
        ];

        if (array_key_exists('tracking_number', $validated) && $validated['tracking_number'] !== null) {
            $updateData['tracking_number'] = $validated['tracking_number'];
        }

        $order->update($updateData);
        AppLoggerService::info('api', 'order.marked_shipped', 'Order marked as shipped', ['order_id' => $order->id, 'tracking' => $order->tracking_number ?? null]);

        return response()->json([
            'success'                  => true,
            'shipped_at'               => $order->shipped_at,
            'tracking_number'          => $order->tracking_number,
            'order_processing_status'  => $order->order_processing_status,
        ]);
    }

    /**
     * GET /api/v1/orders/summary
     * Contagens de pedidos do cliente por status, sem paginar.
     */
    public function summary(Request $request)
    {
        $client = $this->clientOrFail($request);

        // MUL-197: contadores das abas nao contam rascunho
        $query = Order::where('client_id', $client->id)->where('is_draft', 0);
        if ($request->filled('source')) {
            $query->where('source', $request->query('source'));
        }
        if ($request->filled('start') && $request->filled('end')) {
            $query->whereBetween('created_at', [
                \Carbon\Carbon::parse($request->query('start'))->startOfDay(),
                \Carbon\Carbon::parse($request->query('end'))->endOfDay(),
            ]);
        }

        $byStatus = (clone $query)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        // MUL-198: cards contam IGUAL as abas — mesmos sinonimos do filtro do index
        $syn = [
            'paid'      => ['paid', 'pago', 'approved', 'aprovado', 'ready_to_ship', 'processed', 'processing'],
            'pending'   => ['pending', 'pending_payment'],
            'delivered' => ['delivered', 'completed', 'to_confirm_receive'],
        ];
        $sumSyn = fn (array $keys) => array_sum(array_map(fn ($k) => (int) ($byStatus[$k] ?? 0), $keys));

        // card "Pagos" = status pago E lastro real (wallet_paid_at)
        $paidComLastro = (int) (clone $query)
            ->whereIn('status', $syn['paid'])
            ->whereNotNull('wallet_paid_at')
            ->count();

        // card "Aguard. Pagamento" = status pendente + status pago SEM lastro (aba Pendente)
        $aguardandoPagamento = $sumSyn($syn['pending']) + (int) (clone $query)
            ->whereIn('status', $syn['paid'])
            ->whereNull('wallet_paid_at')
            ->count();

        // MUL-083: total de produtos (soma de order_items.quantity)
        $totalItems = (int) \DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.client_id', $client->id)
            ->where('orders.is_draft', 0) // MUL-197
            ->when($request->filled('source'), fn($q) => $q->where('orders.source', $request->query('source')))
            ->when($request->filled('start') && $request->filled('end'), fn($q) => $q->whereBetween('orders.created_at', [
                \Carbon\Carbon::parse($request->query('start'))->startOfDay(),
                \Carbon\Carbon::parse($request->query('end'))->endOfDay(),
            ]))
            ->sum('order_items.quantity');

        return response()->json([
            'data' => [
                'total'       => (int) $query->count(),
                'total_items' => $totalItems,
                'today'       => (int) (clone $query)->whereDate('created_at', today())->count(),
                'by_status' => [
                    'pending_payment'      => (int) ($byStatus['pending_payment'] ?? 0),
                    'pending'              => (int) ($byStatus['pending'] ?? 0),
                    'aguardando_pagamento' => $aguardandoPagamento,
                    'paid'                 => (int) ($byStatus['paid'] ?? 0),
                    'paid_com_lastro'      => $paidComLastro,
                    'processing'           => (int) ($byStatus['processing'] ?? 0),
                    'shipped'              => (int) ($byStatus['shipped'] ?? 0),
                    'delivered'            => $sumSyn($syn['delivered']),
                    'cancelled'            => (int) ($byStatus['cancelled'] ?? 0),
                ],
            ],
        ]);
    }

    /**
     * GET /api/v1/orders/revenue?period=1D|1S|1M|1A|MAX
     * Faturamento agregado: total + serie temporal + receita por marketplace.
     */
    public function revenue(Request $request)
    {
        $client  = $this->clientOrFail($request);
        $hasCustomRange = $request->filled('start') && $request->filled('end');

        $base = Order::where('client_id', $client->id)->where('is_draft', 0); // MUL-197

        if ($hasCustomRange) {
            $startDate = \Carbon\Carbon::parse($request->query('start'))->startOfDay();
            $endDate   = \Carbon\Carbon::parse($request->query('end'))->endOfDay();
            $base->whereBetween('created_at', [$startDate, $endDate]);
            $period = 'CUSTOM';
            $days   = (int) $startDate->diffInDays($endDate) + 1;
        } else {
            $period  = strtoupper((string) $request->query('period', 'MAX'));
            $daysMap = ['1D' => 1, '1S' => 7, '1M' => 30, '1A' => 365, 'MAX' => 9999];
            $days    = $daysMap[$period] ?? 9999;
            if ($days < 9999) {
                $base->where('created_at', '>=', now()->subDays($days));
            }
            $startDate = $days < 9999 ? now()->subDays($days) : null;
            $endDate   = now();
        }

        $total = (float) (clone $base)->sum('total');
        $ordersCount = (int) (clone $base)->count();
        $itemsSold = (int) \DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.client_id', $client->id)
            ->where('orders.is_draft', 0) // MUL-197
            ->when($hasCustomRange, fn ($q) => $q->whereBetween('orders.created_at', [$startDate, $endDate]))
            ->when(!$hasCustomRange && $days < 9999, fn ($q) => $q->where('orders.created_at', '>=', now()->subDays($days)))
            ->sum('order_items.quantity');

        $byMarketplace = (clone $base)
            ->selectRaw("COALESCE(NULLIF(source, ''), 'outros') as src, SUM(total) as revenue")
            ->groupBy('src')->orderByDesc('revenue')->get()
            ->map(fn ($r) => ['source' => $r->src, 'revenue' => (float) $r->revenue])->all();

        $series = [];
        if ($days <= 1) {
            $rows = (clone $base)->selectRaw('HOUR(created_at) as b, SUM(total) as v')->groupBy('b')->pluck('v', 'b');
            for ($h = 0; $h < 24; $h++) {
                $series[] = ['date' => sprintf('%02dh', $h), 'value' => (float) ($rows[$h] ?? 0)];
            }
        } elseif ($days <= 62) {
            $rows = (clone $base)->selectRaw('DATE(created_at) as b, SUM(total) as v')->groupBy('b')->pluck('v', 'b');
            $rangeStart = $hasCustomRange ? $startDate->copy() : now()->subDays((int)$days - 1)->startOfDay();
            for ($i = 0; $i < (int)$days; $i++) {
                $d = $rangeStart->copy()->addDays($i);
                $series[] = ['date' => $d->format('d/m'), 'value' => (float) ($rows[$d->format('Y-m-d')] ?? 0)];
            }
        } else {
            $rows = (clone $base)->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as b, SUM(total) as v")->groupBy('b')->pluck('v', 'b');
            $months = min((int)ceil($days / 30), 24);
            for ($i = $months - 1; $i >= 0; $i--) {
                $d = now()->subMonths($i);
                $series[] = ['date' => $d->format('m/Y'), 'value' => (float) ($rows[$d->format('Y-m')] ?? 0)];
            }
        }

        return response()->json([
            'data' => [
                'period'         => $period,
                'total'          => $total,
                'orders_count'   => $ordersCount,
                'items_sold'     => $itemsSold,
                'series'         => $series,
                'by_marketplace' => $byMarketplace,
            ],
        ]);
    }

    /**
     * GET /api/v1/orders/top-products?limit=10
     * Produtos mais vendidos do cliente (agregado por item de pedido).
     */
    public function topProducts(Request $request)
    {
        $client = $this->clientOrFail($request);
        $limit  = min(max((int) $request->query('limit', 10), 1), 50);

        // MUL-227 item 41-2: top 10 do CATALOGO do fornecedor vinculado (nao do proprio seller).
        // Query param scope=own restaura comportamento antigo pra quem quiser.
        $scope = $request->query('scope', 'supplier');
        $q = \DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.is_draft', 0); // MUL-197
        if ($scope === 'supplier') {
            // Considera pedidos de todos os sellers cujo supplier eh o mesmo do meu client.
            // Fix bug-tenant-supplier-client-id: tenant_supplier liga tenant×supplier, NAO client×supplier.
            // Uso o Client::suppliers() (belongsToMany client_supplier) que e o modelo correto.
            $supplierIds = $client->suppliers()->pluck('suppliers.id');
            if ($supplierIds->isEmpty()) {
                // fallback: sem client_supplier vinculado, usa o supplier_id do proprio client
                $supplierIds = $client->supplier_id ? collect([$client->supplier_id]) : collect();
            }
            if ($supplierIds->isEmpty()) {
                $q->where('orders.client_id', $client->id); // sem fornecedor -> fallback proprio
            } else {
                $q->whereIn('orders.supplier_id', $supplierIds);
            }
        } else {
            $q->where('orders.client_id', $client->id);
        }

        if ($request->filled('start') && $request->filled('end')) {
            $q->whereBetween('orders.created_at', [
                \Carbon\Carbon::parse($request->query('start'))->startOfDay(),
                \Carbon\Carbon::parse($request->query('end'))->endOfDay(),
            ]);
        }

        // Use MAX product_id to resolve catalog cover image per product group
        $rows = $q->selectRaw('order_items.name as name,
                     MAX(order_items.product_id) as product_id,
                     MAX(order_items.product_image) as legacy_image,
                     SUM(order_items.quantity) as qty,
                     SUM(order_items.total) as revenue,
                     COUNT(DISTINCT order_items.order_id) as orders_count')
            ->groupBy('order_items.name')
            ->orderByDesc('qty')
            ->limit($limit)->get();

        $productIds = $rows->pluck('product_id')->filter()->unique()->values();
        $coverImages = [];
        if ($productIds->isNotEmpty()) {
            // MUL-054: usa Model pra disparar accessor ProductMedia::url
            // (fallback Bunny -> original_url enquanto backfill nao termina).
            $coverImages = ProductMedia::whereIn('product_id', $productIds)
                ->where('is_cover', 1)
                ->get(['id', 'product_id', 'url', 'original_url', 'local_path'])
                ->mapWithKeys(fn ($m) => [$m->product_id => $m->url])
                ->all();
        }

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'name'          => $r->name,
                'image'         => $coverImages[$r->product_id ?? 0] ?? $r->legacy_image,
                'quantity_sold' => (int) $r->qty,
                'revenue'       => (float) $r->revenue,
                'orders'        => (int) $r->orders_count,
            ])->all(),
        ]);
    }

    /**
     * POST /api/v1/orders/{id}/pay
     * Gera PIX para pagamento do pedido (wallet + gateway).
     */
    // MUL-252: status de pagamento p/ polling do modal PIX do seller.
    public function payStatus(Request $request): \Illuminate\Http\JsonResponse
    {
        $client = $this->clientOrFail($request);

        $ids = collect(explode(',', (string) $request->query('ids', '')))
            ->map(fn ($v) => (int) trim($v))
            ->filter()
            ->unique()
            ->take(50)
            ->values();

        if ($ids->isEmpty()) {
            return response()->json(['error' => 'ids_required'], 422);
        }

        $orders = Order::where('client_id', $client->id)
            ->whereIn('id', $ids)
            ->get(['id', 'status', 'wallet_paid_at', 'paid_at']);

        $data = $orders->map(fn ($o) => [
            'id' => $o->id,
            'paid' => $o->wallet_paid_at !== null,
            'wallet_paid_at' => $o->wallet_paid_at?->toIso8601String(),
            'status' => $o->status,
        ])->values();

        return response()->json([
            'data' => $data,
            'all_paid' => $data->isNotEmpty() && $data->every(fn ($r) => $r['paid']),
        ]);
    }

    public function pay(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        // MUL-366: valida method ANTES do proxy WL→hub — o endpoint de federacao
        // nao revalida, e method desconhecido cairia no fluxo automatico do hub.
        $method = $request->input('method');
        if ($method !== null && ! in_array($method, ['balance', 'pix'], true)) {
            return response()->json([
                'error'   => 'method_invalid',
                'message' => "Método de pagamento inválido: use 'balance' ou 'pix'.",
            ], 422);
        }

        if (HubProxyHelper::isWl()) {
            $u = $request->user();
            $c = $u ? $u->client : null;
            if (! $c) {
                return response()->json(['error' => 'unauthenticated'], 401);
            }
            $wlOrder = Order::where('id', $id)->where('client_id', $c->id)->first();
            if (! $wlOrder) {
                return response()->json(['error' => 'order_not_found'], 404);
            }
            // MUL-366: pagamento com SALDO e LOCAL — a wallet do seller vive neste
            // backend (regra 33; NOV-214 zerou os bolsos do hub, entao proxyar
            // 'balance' pro hub acharia saldo R$ 0 e geraria PIX indevido).
            // Debito local via nucleo = mesmo caminho do autopay: carimbo local
            // dispara o evento "pedido pago" e o Bling sync daqui.
            if ($wlOrder->hubai_order_id && $method !== 'balance') {
                $body = $request->only(['document', 'method']); // MUL-366: escolha pix segue pro hub
                // MUL-251: hub pode nao ter o document deste client — manda o local junto
                if (empty($body['document']) && !empty($c->document)) {
                    $body['document'] = $c->document;
                }
                $body['client_id'] = $c->id; // informativo — hub resolve o client pelo proprio pedido (MUL-251)

                $respHub = HubProxyHelper::forwardToHub('post', "/orders/{$wlOrder->hubai_order_id}/pay", $body);

                // FOR-129: o hub so paga pedido cujo cliente exista LA (payFromFederation
                // faz $order->client e devolve client_not_found). Seller desta WL nao existe
                // no hub -- 1.028 de 1.028 medidos em 14/08 -- entao o PIX morria aqui.
                // Nesse caso o pagamento segue LOCAL: o caminho ja roda (372 PIX em 30 dias),
                // usa as credenciais do fornecedor deste backend e mantem o ledger aqui, que
                // e o dono dele (regra 33 e 35 do CLAUDE.md; NOV-214 zerou os bolsos do hub).
                $erroHub = json_decode($respHub->getContent(), true)['error'] ?? null;
                if ($erroHub !== 'client_not_found') {
                    return $respHub;
                }

                \Illuminate\Support\Facades\Log::info('[FOR-129] hub sem cliente para o pedido; PIX gerado localmente', [
                    'order_id'       => $wlOrder->id,
                    'hubai_order_id' => $wlOrder->hubai_order_id,
                    'client_id'      => $c->id,
                ]);
            }
            // MUL-251: pedido legado sem espelho no hub — segue o fluxo local (pre-INF-054)
        }
        $client = $this->clientOrFail($request);

        $order = Order::where('id', $id)
            ->where('client_id', $client->id)
            ->whereNull('wallet_paid_at') // FOR-039: paid_at ML ja preenchido; filtrar por custo fornecedor
            ->firstOrFail();

        $supplier = $order->supplier;
        if (! $supplier) {
            return response()->json(['error' => 'Fornecedor não encontrado para este pedido.'], 422);
        }

        // FOR-042: aceita document no body para destravar lojistas migrados sem CPF/CNPJ valido
        $docFromBody = preg_replace('/\D/', '', (string) $request->input('document', ''));
        if ($docFromBody) {
            if (!\App\Helpers\DocumentValidator::isValid($docFromBody)) {
                return response()->json([
                    'error'   => 'document_invalid',
                    'message' => 'CPF ou CNPJ informado e invalido. Verifique os digitos e tente novamente.',
                ], 422);
            }
            $client->document = $docFromBody;
            $client->save();
        }

        // FOR-042: pre-valida document do cliente para evitar erro tardio do gateway.
        // MUL-366: CPF/CNPJ so e necessario quando PIX pode ser gerado — pagamento
        // 100% saldo (method=balance) nao passa por gateway e nao exige documento.
        if ($method !== 'balance') {
            $currentDoc = preg_replace('/\D/', '', (string) ($client->document ?? ''));
            if (empty($currentDoc)) {
                return response()->json([
                    'error'   => 'document_required',
                    'message' => 'Informe seu CPF/CNPJ para gerar PIX.',
                ], 422);
            }
            if (!\App\Helpers\DocumentValidator::isValid($currentDoc)) {
                return response()->json([
                    'error'   => 'document_invalid',
                    'message' => 'CPF ou CNPJ cadastrado e invalido. Informe um novo para gerar PIX.',
                ], 422);
            }
        }

        try {
            $paymentService = app(OrderPaymentService::class);
            $result = $paymentService->payOrder($order, $supplier, $method);

            if ($result['status'] === 'paid') {
                return response()->json([
                    'status'      => 'paid',
                    'message'     => 'Pedido pago integralmente com saldo da carteira.',
                    'wallet_used' => $result['wallet_used'],
                ]);
            }

            $pixTx = $result['pix_transaction'];
            return response()->json([
                'status'       => 'pix_required',
                'pix_needed'   => $result['pix_needed'],
                'wallet_used'  => $result['wallet_used'],
                'qr_code'      => $pixTx->qr_code      ?? '',
                'qr_code_url'  => $pixTx->qr_code_text ?? '',
                'expires_at'   => $pixTx->expires_at,
                'pix_id'       => $pixTx->id,
            ]);
        } catch (\Exception $e) {
            // NOV-207 Etapa 3: mensagem com marcador [label_required] retorna
            // 422 estruturado com label_status_reason + label_action_url pra
            // frontend mostrar CTA (integracao com Etapa 2c ja no ar).
            if (str_contains($e->getMessage(), '[label_required]')) {
                $arr = $order->toArray();
                return response()->json([
                    'error'                => 'label_required',
                    'message'              => str_replace('[label_required] ', '', $e->getMessage()),
                    'label_status_reason'  => $order->label_status_reason,
                    'label_status_message' => $this->resolveLabelStatusMessage($arr),
                    'label_action_url'     => $this->resolveLabelActionUrl($arr),
                ], 422);
            }
            // MUL-366: saldo insuficiente na escolha explicita 'balance' — 422 estruturado
            if (str_contains($e->getMessage(), '[insufficient_balance]')) {
                return response()->json([
                    'error'   => 'insufficient_balance',
                    'message' => str_replace('[insufficient_balance] ', '', $e->getMessage()),
                ], 422);
            }
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * PATCH /api/v1/orders/{id}/notes
     * MUL-112 — Atualiza anotacoes internas do lojista (seller_notes).
     * Diferente de buyer_notes (comprador) — este campo e do LOJISTA para uso interno.
     * Auth: apenas o dono do pedido (client) OU super_admin.
     */
    public function updateNotes(Request $request, int $id)
    {
        // INF-054 R1: se WL, encaminha pro hub
        if (HubProxyHelper::isWl()) {
            $order = Order::find($id);
            $hubId = $order?->hubai_order_id ?? $id;
            return HubProxyHelper::forwardToHub('patch', "/orders/$hubId/notes", $request->only(['notes','expedition_note']));
        }
        // MUL-161-BE1 #15: aceitar 'expedition_note' como alias de 'notes' (seller_notes).
        // O frontend (Integrations.tsx) envia expedition_note; o campo de BD e seller_notes.
        // Comportamento original de 'notes' mantido intacto.
        $hasNotes          = $request->has('notes');
        $hasExpeditionNote = $request->has('expedition_note');

        if (! $hasNotes && ! $hasExpeditionNote) {
            return response()->json([
                'message' => 'The notes field is required.',
                'errors'  => ['notes' => ['The notes field is required.']],
            ], 422);
        }

        $request->validate([
            'notes'          => ['sometimes', 'nullable', 'string', 'max:5000'],
            'expedition_note' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $order = Order::findOrFail($id);

        $user = $request->user();

        // Verifica autorizacao: super_admin pode tudo; client so pode ver o proprio pedido
        if ($user->role !== 'super_admin') {
            $client = $user->client;
            if (! $client || $order->client_id !== $client->id) {
                return response()->json(['error' => 'Nao autorizado.'], 403);
            }
        }

        if ($hasExpeditionNote) {
            // expedition_note vai para a coluna orders.expedition_note (max 100)
            $expeditionNote = trim((string) $request->input('expedition_note'));
            $order->update(['expedition_note' => $expeditionNote]);

            return response()->json([
                'success'        => true,
                'expedition_note' => $order->expedition_note,
            ]);
        }

        // Comportamento original: 'notes' -> seller_notes
        $order->update(['seller_notes' => $request->input('notes')]);

        return response()->json([
            'success'      => true,
            'seller_notes' => $order->seller_notes,
        ]);
    }

    /**
     * PATCH /api/v1/orders/{id}/expedition-note
     * MUL-142-E #15-back — Salva nota de expedicao do lojista (max 100 chars).
     * Bloqueado apos pedido ser enviado/cancelado/entregue.
     */
    public function updateExpeditionNote(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        if (HubProxyHelper::isWl()) {
            $order = Order::find($id);
            $hubId = $order?->hubai_order_id ?? $id;
            return HubProxyHelper::forwardToHub("patch", "/orders/$hubId/expedition-note", $request->only(["note"]));
        }
        $request->validate([
            "note" => ["required", "string", "max:100"],
        ]);

        $order = Order::findOrFail($id);
        $user  = $request->user();

        if ($user->role !== "super_admin") {
            $client = $user->client;
            if (! $client || $order->client_id !== $client->id) {
                return response()->json(["error" => "Nao autorizado."], 403);
            }
        }

        if (in_array($order->status, ["shipped", "cancelled", "delivered"], true)) {
            return response()->json([
                "error"  => "Observacao nao pode ser editada apos envio/cancelamento.",
                "status" => $order->status,
            ], 422);
        }

        $order->update(["expedition_note" => trim($request->input("note"))]);

        return response()->json([
            "success"         => true,
            "expedition_note" => $order->expedition_note,
        ]);
    }

    /**
     * POST /api/v1/orders/{id}/expedition-note/read
     * MUL-142-E #15-back — Operador picking confirma leitura da nota de expedicao.
     * Registra quem/quando em order_status_history (origin=picking_note).
     * Notifica o seller via canal de notificacoes existente.
     */
    public function markExpeditionNoteRead(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        if (HubProxyHelper::isWl()) {
            $order = Order::find($id);
            $hubId = $order?->hubai_order_id ?? $id;
            $user = $request->user();
            return HubProxyHelper::forwardToHub("post", "/orders/$hubId/expedition-note/read", ["actor_user_id" => $user?->id]);
        }
        $order = Order::findOrFail($id);
        $user  = $request->user();

        if (! in_array($user->role, ["super_admin", "supplier"], true)) {
            return response()->json(["error" => "Acesso restrito ao fornecedor."], 403);
        }

        if ($order->expedition_note_read_at) {
            return response()->json([
                "success"                  => true,
                "already_read"             => true,
                "expedition_note_read_at"  => $order->expedition_note_read_at,
                "expedition_note_read_by"  => $order->expedition_note_read_by,
            ]);
        }

        $readBy = $user->name ?? $user->email ?? (string) $user->id;

        $order->update([
            "expedition_note_read_at" => now(),
            "expedition_note_read_by" => $readBy,
        ]);

        \Illuminate\Support\Facades\DB::table("order_status_history")->insert([
            "order_id"   => $order->id,
            "field"      => "expedition_note_read",
            "actor_type" => "supplier",
            "actor_id"   => (string) $user->id,
            "origin"     => "picking_note",
            "metadata"   => json_encode(["note" => $order->expedition_note, "read_by" => $readBy]),
            "created_at" => now(),
        ]);

        // Notificar o seller (notificacao de banco — sem canal externo neste momento)
        try {
            $clientUser = \App\Models\Client::find($order->client_id)?->user;
            if ($clientUser) {
                $clientUser->notify(new \App\Notifications\ExpeditionNoteReadNotification($order, $readBy));
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("[ExpeditionNote] Notificacao ao seller falhou", [
                "order_id" => $order->id,
                "error"    => $e->getMessage(),
            ]);
        }

        return response()->json([
            "success"                  => true,
            "already_read"             => false,
            "expedition_note_read_at"  => $order->expedition_note_read_at,
            "expedition_note_read_by"  => $order->expedition_note_read_by,
        ]);
    }

    /**
     * MUL-161-BE1 #29 — GET /api/v1/orders/filters
     * Retorna canais e canais de envio distintos dos pedidos do client autenticado.
     * Usado pelo frontend para popular dropdowns de filtro em /pedidos.
     *
     * Resposta: {channels:[{value,label}], shipping_channels:[{value,label}]}
     */
    public function filters(Request $request): \Illuminate\Http\JsonResponse
    {
        $client = $this->clientOrFail($request);

        // MUL-214 itens 25/26: marketplaces efetivos SEM repetir — pedido importado
        // via Bling com channel_name de marketplace (Shopee/Amazon/TikTok/ML) conta
        // como o marketplace real; 'bling' fica so pros pedidos sem canal mapeavel.
        // Fix de contrato: resposta agora vem em 'data' com arrays de strings
        // (o front sempre caiu no fallback hardcoded porque lia response.data).
        $rows = Order::where('client_id', $client->id)
            ->where('is_draft', 0) // MUL-197
            ->whereNotNull('source')
            ->selectRaw("DISTINCT source, COALESCE(channel_name, '') AS channel_name")
            ->get();

        $channelKeywords = [
            'shopee'  => 'shopee',
            'mercado' => 'mercadolivre',
            'amazon'  => 'amazon',
            'tiktok'  => 'tiktok',
            'magalu'  => 'magalu',
        ];

        $channelSet = [];
        foreach ($rows as $row) {
            $src = strtolower(trim((string) $row->source));
            if ($src !== 'bling') {
                $channelSet[$src] = true;
                continue;
            }
            $ch = strtolower(trim((string) $row->channel_name));
            $mapped = null;
            foreach ($channelKeywords as $needle => $canonical) {
                if ($ch !== '' && str_contains($ch, $needle)) {
                    $mapped = $canonical;
                    break;
                }
            }
            $channelSet[$mapped ?? 'bling'] = true;
        }
        $channels = array_values(array_keys($channelSet));

        // Canais de envio SEM repetir (ex: 'Amazon DBA' vs 'Logistica Amazon Dba'
        // do Bling viram um so). Uniao carrier_name + delivery_type, dedup por
        // chave normalizada (lowercase, sem prefixo 'logistica '), exibindo o
        // valor mais curto/limpo. O filtro do index usa LIKE nas 3 colunas.
        $shippingRaw = Order::where('client_id', $client->id)
            ->where('is_draft', 0)
            ->whereNotNull('carrier_name')->where('carrier_name', '!=', '')
            ->distinct()->pluck('carrier_name')
            ->merge(
                Order::where('client_id', $client->id)
                    ->where('is_draft', 0)
                    ->whereNotNull('delivery_type')->where('delivery_type', '!=', '')
                    ->distinct()->pluck('delivery_type')
            );

        $shippingSet = [];
        foreach ($shippingRaw as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $key = strtolower(preg_replace('/\s+/', ' ', $value));
            $key = preg_replace('/^log[ií]stica\s+/u', '', $key);
            if (! isset($shippingSet[$key]) || mb_strlen($value) < mb_strlen($shippingSet[$key])) {
                $shippingSet[$key] = $value;
            }
        }
        sort($shippingSet);
        $shippingChannels = array_values($shippingSet);

        return response()->json([
            'data' => [
                'channels'          => $channels,
                'shipping_channels' => $shippingChannels,
            ],
        ]);
    }


    /**
     * INF-054 R1: notes/expedition_note via federation (WL->hub).
     */
    public function updateNotesFromFederation(Request $request, int $id)
    {
        $request->validate([
            'notes'           => ['sometimes', 'nullable', 'string', 'max:5000'],
            'expedition_note' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        $order = Order::findOrFail($id);
        $tenantSlug = $request->attributes->get('federation_tenant');
        if (! $this->tenantAuthorizedForOrder($tenantSlug, $order)) {
            return response()->json(['error' => 'tenant_not_authorized'], 403);
        }
        $updates = [];
        if ($request->has('notes'))           $updates['seller_notes']    = $request->input('notes');
        if ($request->has('expedition_note')) $updates['expedition_note'] = $request->input('expedition_note');
        if (!empty($updates)) {
            $order->fill($updates)->save();
            \App\Jobs\FanoutOrderWebhookJob::dispatch($order->id, 'order.updated', ['source_wl' => $tenantSlug]);
        }
        return response()->json(['success' => true, 'order_id' => $order->id, 'updated' => array_keys($updates)]);
    }

    public function updateExpeditionNoteFromFederation(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $request->validate(['note' => ['required', 'string', 'max:100']]);
        $order = Order::findOrFail($id);
        $tenantSlug = $request->attributes->get('federation_tenant');
        if (! $this->tenantAuthorizedForOrder($tenantSlug, $order)) {
            return response()->json(['error' => 'tenant_not_authorized'], 403);
        }
        $order->expedition_note = $request->input('note');
        $order->expedition_note_read_at = null;
        $order->expedition_note_read_by = null;
        $order->save();
        \App\Jobs\FanoutOrderWebhookJob::dispatch($order->id, 'order.updated', ['source_wl' => $tenantSlug]);
        return response()->json(['success' => true, 'order_id' => $order->id]);
    }

    public function markExpeditionNoteReadFromFederation(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $order = Order::findOrFail($id);
        $tenantSlug = $request->attributes->get('federation_tenant');
        if (! $this->tenantAuthorizedForOrder($tenantSlug, $order)) {
            return response()->json(['error' => 'tenant_not_authorized'], 403);
        }
        if ($order->expedition_note_read_at) {
            return response()->json(['success' => true, 'already_read' => true]);
        }
        $order->expedition_note_read_at = now();
        $order->expedition_note_read_by = (int) ($request->input('actor_user_id') ?? 0);
        $order->save();
        \App\Jobs\FanoutOrderWebhookJob::dispatch($order->id, 'order.updated', ['source_wl' => $tenantSlug]);
        return response()->json(['success' => true, 'order_id' => $order->id]);
    }

    private function tenantAuthorizedForOrder(?string $tenantSlug, Order $order): bool
    {
        if (!$tenantSlug || !$order->supplier_id) return false;
        $tenantId = \DB::table('tenants')->where('slug', $tenantSlug)->value('id');
        if (!$tenantId) return false;
        return \DB::table('tenant_supplier')
            ->where('tenant_id', $tenantId)
            ->where('supplier_id', $order->supplier_id)
            ->exists();
    }


    /**
     * INF-054 R2: markShipped via federation (WL->hub).
     */
    public function markShippedFromFederation(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'tracking_number' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        $order = Order::findOrFail($id);
        $tenantSlug = $request->attributes->get('federation_tenant');
        if (! $this->tenantAuthorizedForOrder($tenantSlug, $order)) {
            return response()->json(['error' => 'tenant_not_authorized'], 403);
        }
        $updateData = [
            'order_processing_status' => 'shipped',
            'shipped_at'              => now(),
        ];
        if (array_key_exists('tracking_number', $validated) && $validated['tracking_number'] !== null) {
            $updateData['tracking_number'] = $validated['tracking_number'];
        }
        $order->update($updateData);
        \App\Jobs\FanoutOrderWebhookJob::dispatch($order->id, 'order.updated', ['source_wl' => $tenantSlug]);
        return response()->json([
            'success' => true,
            'shipped_at' => $order->shipped_at,
            'tracking_number' => $order->tracking_number,
            'order_processing_status' => $order->order_processing_status,
        ]);
    }


    /**
     * INF-054 R4: pay via federation (WL->hub).
     * Autoriza via tenant_supplier + client_id do payload.
     */
    public function payFromFederation(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'client_id' => ['sometimes', 'nullable', 'integer'],
            'document'  => ['sometimes', 'nullable', 'string'],
            'method'    => ['sometimes', 'nullable', 'in:balance,pix'], // MUL-366
        ]);
        $tenantSlug = $request->attributes->get('federation_tenant');
        // MUL-251: WLs nao tem mapeamento de client hub — resolver pelo proprio pedido.
        // Seguranca: tenantAuthorizedForOrder garante que a WL e dona do pedido, e a WL
        // ja validou que o seller autenticado e dono do pedido local antes de encaminhar.
        $order = Order::find($id);
        if (!$order) return response()->json(['error' => 'order_not_found'], 404);
        if (!$this->tenantAuthorizedForOrder($tenantSlug, $order)) {
            return response()->json(['error' => 'tenant_not_authorized'], 403);
        }
        $client = $order->client;
        if (!$client) return response()->json(['error' => 'client_not_found'], 404);
        if ($order->wallet_paid_at !== null) {
            return response()->json(['error' => 'already_paid'], 422);
        }
        $supplier = $order->supplier;
        if (!$supplier) return response()->json(['error' => 'supplier_not_found'], 422);

        $docFromBody = preg_replace('/\D/', '', (string) $request->input('document', ''));
        if ($docFromBody) {
            if (!\App\Helpers\DocumentValidator::isValid($docFromBody)) {
                return response()->json(['error' => 'document_invalid'], 422);
            }
            $client->document = $docFromBody;
            $client->save();
        }
        $currentDoc = preg_replace('/\D/', '', (string) ($client->document ?? ''));
        if (empty($currentDoc) || !\App\Helpers\DocumentValidator::isValid($currentDoc)) {
            return response()->json(['error' => 'document_required'], 422);
        }

        try {
            $svc = app(\App\Services\Financial\OrderPaymentService::class);
            $result = $svc->payOrder($order, $supplier, $request->input('method'));
            \App\Jobs\FanoutOrderWebhookJob::dispatch($order->id, 'order.updated', ['source_wl' => $tenantSlug, 'action' => 'pay']);
            if ($result['status'] === 'paid') {
                return response()->json(['status' => 'paid', 'wallet_used' => $result['wallet_used']]);
            }
            $pixTx = $result['pix_transaction'];
            return response()->json([
                'status'          => 'pix_required',
                'pix_needed'      => $result['pix_needed'] ?? null,
                'wallet_used'     => $result['wallet_used'] ?? 0,
                'qr_code'         => $pixTx->qr_code      ?? '',
                'qr_code_url'     => $pixTx->qr_code_text ?? '',
                'expires_at'      => $pixTx->expires_at,
                'pix_id'          => $pixTx->id,
                'pix_transaction' => $pixTx,
            ]);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), '[label_required]')) {
                $arr = $order->toArray();
                return response()->json([
                    'error'                => 'label_required',
                    'message'              => str_replace('[label_required] ', '', $e->getMessage()),
                    'label_status_reason'  => $order->label_status_reason,
                    'label_status_message' => $this->resolveLabelStatusMessage($arr),
                    'label_action_url'     => $this->resolveLabelActionUrl($arr),
                ], 422);
            }
            \Illuminate\Support\Facades\Log::error('[INF-054 R4] payFromFederation error', ['order_id' => $id, 'err' => $e->getMessage()]);
            return response()->json(['error' => 'payment_failed', 'detail' => $e->getMessage()], 500);
        }
    }

    /**
     * INF-054 R4: generateLabel via federation.
     */
    public function generateLabelFromFederation(Request $request, int $id, ShippingLabelService $labelService): \Illuminate\Http\JsonResponse
    {
        $request->validate(['client_id' => ['required', 'integer']]);
        $tenantSlug = $request->attributes->get('federation_tenant');
        $client = \App\Models\Client::find($request->input('client_id'));
        if (!$client) return response()->json(['error' => 'client_not_found'], 404);
        $order = Order::where('id', $id)->where('client_id', $client->id)->first();
        if (!$order) return response()->json(['error' => 'order_not_found'], 404);
        if (!$this->tenantAuthorizedForOrder($tenantSlug, $order)) {
            return response()->json(['error' => 'tenant_not_authorized'], 403);
        }
        $supplierTotal = (float) ($order->supplier_total ?? 0);
        if ($order->supplier_id && $supplierTotal <= 0) {
            return response()->json(['error' => 'supplier_cost_missing'], 402);
        }
        // SEL-413: guard 'supplier_unpaid' removido — mesma razao do generateLabel acima.
        // Este e o caminho que os WLs usam pra pedir etiqueta ao hub; mantinha o mesmo
        // deadlock pro seller de qualquer WL.
        \App\Jobs\FetchShippingLabelJob::dispatchSync($order->id, 'manual_federation');
        $order->refresh();
        $result = [
            'ready'     => ! empty($order->label_url) || ! empty($order->manual_label_path),
            'label_url' => $order->label_url,
            'reason'    => $order->label_status_reason,
        ];
        \App\Jobs\FanoutOrderWebhookJob::dispatch($order->id, 'order.updated', ['source_wl' => $tenantSlug, 'action' => 'generate_label']);
        return response()->json(array_merge($result, [
            'tracking_number' => $order->tracking_number,
            'carrier_name' => $order->carrier_name,
        ]));
    }


    /**
     * INF-054 R4 F2: addInvoice via federation. Grava campos NF-e (dropshipper manual).
     */
    public function addInvoiceFromFederation(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'client_id'          => ['required', 'integer'],
            'invoice_number'     => ['sometimes', 'nullable', 'string', 'max:20'],
            'invoice_series'     => ['sometimes', 'nullable', 'string', 'max:10'],
            'invoice_access_key' => ['sometimes', 'nullable', 'string', 'size:44'],
            'invoice_url'        => ['sometimes', 'nullable', 'url', 'max:500'],
            'invoice_xml_url'    => ['sometimes', 'nullable', 'url', 'max:500'],
        ]);
        $tenantSlug = $request->attributes->get('federation_tenant');
        $client = \App\Models\Client::find($request->input('client_id'));
        if (!$client) return response()->json(['error' => 'client_not_found'], 404);
        $order = Order::where('id', $id)->where('client_id', $client->id)->first();
        if (!$order) return response()->json(['error' => 'order_not_found'], 404);
        if (!$this->tenantAuthorizedForOrder($tenantSlug, $order)) {
            return response()->json(['error' => 'tenant_not_authorized'], 403);
        }
        $data = $request->only(['invoice_number','invoice_series','invoice_access_key','invoice_url','invoice_xml_url']);
        $data = array_filter($data, fn($v) => $v !== null);
        if (empty($data)) return response()->json(['error' => 'no_invoice_fields'], 422);
        $data['invoice_issued_at'] = now();
        $order->update($data);
        \App\Jobs\FanoutOrderWebhookJob::dispatch($order->id, 'order.updated', ['source_wl' => $tenantSlug, 'action' => 'add_invoice']);
        return response()->json([
            'success' => true,
            'invoice_number' => $order->invoice_number,
            'invoice_series' => $order->invoice_series,
            'invoice_access_key' => $order->invoice_access_key,
            'invoice_url' => $order->invoice_url,
            'invoice_xml_url' => $order->invoice_xml_url,
        ]);
    }


    /**
     * MUL-227 item 29: seller bloqueia próprio pedido.
     * Se pedido já foi pago pela carteira (wallet_paid_at), reembolsa: zera wallet_paid_at
     * e devolve o valor ao ClientSupplierBalance / wallet_balance do client.
     */
    public function blockOrder(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $client = $request->user()->client;
        if (! $client) {
            return response()->json(['error' => 'sem perfil de client'], 403);
        }

        $data = $request->validate([
            'motivo' => 'nullable|string|max:500',
        ]);

        /** @var \App\Models\Order $order */
        $order = \App\Models\Order::where('id', $id)->where('client_id', $client->id)->first();
        if (! $order) {
            return response()->json(['error' => 'pedido nao encontrado'], 404);
        }
        if ($order->status === 'shipped' || $order->status === 'delivered') {
            return response()->json(['error' => 'pedido ja enviado, nao pode ser bloqueado pelo seller'], 422);
        }
        if ($order->blocked_at) {
            return response()->json(['data' => $order->fresh(), 'already_blocked' => true]);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($order, $data, $client) {
            $order->update([
                'blocked_at'   => now(),
                'blocked_by'   => $client->id,
                'block_reason' => $data['motivo'] ?? 'Bloqueado pelo seller',
            ]);

            // Reembolso automático se pago pela carteira
            if ($order->wallet_paid_at && (float) ($order->supplier_total ?? 0) > 0) {
                $amount = (float) $order->supplier_total;

                // Credita de volta na carteira do client (ledger)
                if (\Illuminate\Support\Facades\Schema::hasTable('client_supplier_balances')) {
                    \Illuminate\Support\Facades\DB::table('client_supplier_balances')->updateOrInsert(
                        ['client_id' => $client->id, 'supplier_id' => $order->supplier_id],
                        ['balance' => \Illuminate\Support\Facades\DB::raw("balance + {$amount}"), 'updated_at' => now()]
                    );
                }

                // Log da transação
                if (\Illuminate\Support\Facades\Schema::hasTable('wallet_transactions')) {
                    \Illuminate\Support\Facades\DB::table('wallet_transactions')->insert([
                        'client_id'   => $client->id,
                        'supplier_id' => $order->supplier_id,
                        'order_id'    => $order->id,
                        'type'        => 'refund',
                        'amount'      => $amount,
                        'note'        => 'Reembolso auto por bloqueio de pedido (MUL-227 #29)',
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);
                }

                $order->update(['wallet_paid_at' => null]);
            }
        });

        \Illuminate\Support\Facades\Log::info('[OrderController::blockOrder] pedido bloqueado', [
            'order_id' => $order->id, 'client_id' => $client->id,
        ]);

        return response()->json(['data' => $order->fresh(), 'refunded' => $order->wallet_paid_at === null]);
    }

    /**
     * MUL-227 item 29: seller desbloqueia próprio pedido.
     */
    public function unblockOrder(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $client = $request->user()->client;
        if (! $client) {
            return response()->json(['error' => 'sem perfil de client'], 403);
        }

        $order = \App\Models\Order::where('id', $id)->where('client_id', $client->id)->first();
        if (! $order) return response()->json(['error' => 'pedido nao encontrado'], 404);

        $order->update([
            'blocked_at'   => null,
            'blocked_by'   => null,
            'block_reason' => null,
        ]);

        return response()->json(['data' => $order->fresh()]);
    }
}
