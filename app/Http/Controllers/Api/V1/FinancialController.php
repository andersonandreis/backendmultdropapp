<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Support\Facades\Cache;

use App\Http\Controllers\Controller;
use App\Models\ClientSupplierBalance;
use App\Models\ClientSupplierTransaction;
use App\Traits\FormatsMoneyBR;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class FinancialController extends Controller
{
    use FormatsMoneyBR;

    private function clientOrFail(Request $request)
    {
        $client = $request->user()->client;

        if (! $client) {
            abort(403, 'Usuario nao possui perfil de lojista.');
        }

        return $client;
    }

    #[OA\Get(
        path: '/api/v1/financial/balance',
        summary: 'Saldo financeiro do lojista agrupado por fornecedor',
        description: 'Retorna o saldo disponivel do lojista em cada fornecedor (carteira HubAI). O saldo representa o valor pre-pago depositado pelo lojista para compra de produtos. Tambem retorna o saldo total somado de todos os fornecedores. Um saldo negativo indica divida pendente.',
        tags: ['Financeiro'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Saldos por fornecedor e total consolidado',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            description: 'Saldo por fornecedor',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'supplier_id', type: 'integer', example: 3),
                                    new OA\Property(property: 'supplier_name', type: 'string', example: 'XPTO'),
                                    new OA\Property(property: 'balance', type: 'number', example: 1250.00),
                                    new OA\Property(property: 'balance_formatted', type: 'string', example: 'R$ 1.250,00'),
                                ]
                            )
                        ),
                        new OA\Property(
                            property: 'meta',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'total_balance', type: 'number', example: 3750.50),
                                new OA\Property(property: 'total_balance_formatted', type: 'string', example: 'R$ 3.750,50'),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Token ausente ou invalido'),
            new OA\Response(response: 403, description: 'Usuario nao possui perfil de lojista'),
        ]
    )]
    public function balance(Request $request)
    {
        $client = $this->clientOrFail($request);

        $balances = ClientSupplierBalance::where('client_id', $client->id)
            ->with('supplier:id,company_name,display_name')
            ->get()
            ->map(fn ($b) => [
                'supplier_id'       => $b->supplier_id,
                'supplier_name'     => $b->supplier?->display_name ?? $b->supplier?->company_name ?? 'Saldo migrado',
                'balance'           => (float) $b->balance,
                'balance_formatted' => $this->formatBRL((float) $b->balance),
            ]);

        $totalBalance = (float) $balances->sum('balance');

        return response()->json([
            'data' => $balances->values(),
            'meta' => [
                'total_balance'           => $totalBalance,
                'total_balance_formatted' => $this->formatBRL($totalBalance),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/financial/transactions',
        summary: 'Extrato de transacoes financeiras do lojista',
        description: 'Retorna o historico paginado de transacoes financeiras do lojista (creditos e debitos) em cada fornecedor.',
        tags: ['Financeiro'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'supplier_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['credit', 'debit'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Extrato paginado de transacoes financeiras',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 8001),
                                    new OA\Property(property: 'type', type: 'string', enum: ['credit', 'debit'], example: 'debit'),
                                    new OA\Property(property: 'amount', type: 'number', example: 179.90),
                                    new OA\Property(property: 'amount_formatted', type: 'string', example: 'R$ 179,90'),
                                    new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Debito por pedido #500'),
                                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                                ]
                            )
                        ),
                        new OA\Property(
                            property: 'meta',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'total', type: 'integer', example: 87),
                                new OA\Property(property: 'per_page', type: 'integer', example: 15),
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'last_page', type: 'integer', example: 6),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Token ausente ou invalido'),
            new OA\Response(response: 403, description: 'Usuario nao possui perfil de lojista'),
        ]
    )]
    public function transactions(Request $request)
    {
        $client  = $this->clientOrFail($request);
        $perPage = (int) $request->query('per_page', 15);

        // MUL-362 P6: desempate por id — transacoes do mesmo segundo (lotes) saiam em
        // ordem indefinida, o "saldo apos" parecia saltar (falso sumico de R$ 59) e a
        // paginacao podia cortar o lote no meio de forma instavel.
        $query = ClientSupplierTransaction::where('client_id', $client->id)
            ->with(['supplier:id,company_name,display_name', 'pixTransaction:id,gateway,external_id,paid_at,net_amount,fee_amount'])
            ->latest()
            ->orderByDesc('id');

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', (int) $request->query('supplier_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        // MUL-142-D #9: filtro de busca por descricao/referencia
        if ($request->filled('search')) {
            $s = $request->query('search');
            $query->where(function ($q) use ($s) {
                $q->where('description', 'like', "%{$s}%")
                  ->orWhere('reference', 'like', "%{$s}%");
            });
        }

        $paginator = $query->paginate($perPage);

        // MUL-084: coletar order_ids (direto ou parseados do description "Pedidos: 123, 456, ...") p/ carregar itens
        $paginatorItems = $paginator->items();
        $orderIds = [];
        foreach ($paginatorItems as $tx) {
            if ($tx->order_id) $orderIds[] = (int) $tx->order_id;
            if (preg_match_all('/(\d{5,})/', (string) ($tx->description ?? ''), $m)) {
                foreach ($m[1] as $oid) $orderIds[] = (int) $oid;
            }
        }
        $orderIds = array_values(array_unique(array_filter($orderIds)));
        $orderItemsByOrder = [];
        if (!empty($orderIds)) {
            $rows = \DB::table('order_items as oi')
                ->join('orders as o', 'o.id', '=', 'oi.order_id')
                ->leftJoin('products as p', 'p.id', '=', 'oi.product_id')
                // MUL-405: era leftJoin direto em product_media com is_cover=1. Produto com
                // MAIS DE UMA capa multiplicava a linha do item -- 2.708 linhas fantasma na
                // wallet inteira, concentradas em componente de kit (34% deles usam produto
                // de capa dupla, contra 7% dos itens normais). A subquery devolve UMA capa
                // por produto e resolve sem depender de limpar os 217 produtos afetados.
                ->leftJoin(\DB::raw('(SELECT product_id, MIN(id) AS mid FROM product_media WHERE is_cover = 1 GROUP BY product_id) as pmc'),
                    'pmc.product_id', '=', 'p.id')
                ->leftJoin('product_media as pm', 'pm.id', '=', 'pmc.mid')
                ->where('o.client_id', $client->id)
                ->where(function ($q) use ($orderIds) {
                    $q->whereIn('oi.order_id', $orderIds)
                      ->orWhereIn('o.legacy_id', $orderIds);
                })
                // MUL-405: supplier_unit_cost/supplier_total_cost entram aqui. Sem eles o
                // front so tinha unit_price (preco de VENDA) para mostrar numa tela de
                // COBRANCA -- num pedido de 10 itens os valores nao explicavam o cobrado.
                ->select('oi.order_id','o.legacy_id','oi.name','oi.quantity','oi.unit_price',
                         'oi.supplier_unit_cost','oi.supplier_total_cost',
                         'oi.sku','oi.product_image','pm.url as media_url')
                ->orderBy('oi.order_id')
                ->limit(500)
                ->get();
            foreach ($rows as $r) {
                $item = [
                    'name' => $r->name,
                    'sku' => $r->sku,
                    'quantity' => (int) $r->quantity,
                    'unit_price' => (float) $r->unit_price,
                    // MUL-405: o que o seller PAGA. E este valor que justifica a cobranca.
                    'supplier_unit_cost'  => (float) $r->supplier_unit_cost,
                    'supplier_total_cost' => (float) $r->supplier_total_cost,
                    'image' => $r->media_url ?: $r->product_image,
                ];
                $orderItemsByOrder[$r->order_id][] = $item;
                if ($r->legacy_id) {
                    $orderItemsByOrder[$r->legacy_id][] = $item;
                }
            }
        }

        $items = collect($paginatorItems)->map(function ($tx) use ($orderItemsByOrder) {
            $arr = $tx->toArray();
            $arr['amount_formatted'] = $this->formatBRL((float) $tx->amount);
            $arr['supplier_name'] = $tx->supplier?->display_name ?? $tx->supplier?->company_name ?? 'Saldo legado migrado';
            $arr['description'] = $this->friendlyDescription($tx->description ?? '', $tx->reference ?? '');
            // MUL-084: itens do(s) pedido(s) relacionado(s) a essa transacao
            $linkedOrderIds = [];
            if ($tx->order_id) $linkedOrderIds[] = (int) $tx->order_id;
            if (preg_match_all('/(\d{5,})/', (string) ($tx->description ?? ''), $m)) {
                foreach ($m[1] as $oid) $linkedOrderIds[] = (int) $oid;
            }
            $agg = [];
            // MUL-405: o total era contado DEPOIS do corte de 6, entao um pedido de 10 itens
            // exibia 6 e dizia "Mostrando 6 de 6" -- o aviso de truncamento nunca disparava.
            // Agora conta todos os itens vinculados ANTES de cortar.
            $totalItens = 0;
            foreach (array_unique($linkedOrderIds) as $oid) {
                $totalItens += count($orderItemsByOrder[$oid] ?? []);
            }
            foreach (array_unique($linkedOrderIds) as $oid) {
                if (isset($orderItemsByOrder[$oid])) {
                    foreach ($orderItemsByOrder[$oid] as $it) {
                        $agg[] = $it + ['order_id' => $oid];
                        if (count($agg) >= 6) break 2;
                    }
                }
            }
            $arr['order_items'] = $agg;
            $arr['order_items_total'] = $totalItens;
            if ($tx->pixTransaction) {                $arr["pix_gateway"]     = $tx->pixTransaction->gateway;                $arr["pix_external_id"] = $tx->pixTransaction->external_id;                $arr["pix_paid_at"]     = $tx->pixTransaction->paid_at;                $arr["pix_net_amount"]  = $tx->pixTransaction->net_amount;                $arr["pix_fee_amount"]  = $tx->pixTransaction->fee_amount;            }
            return $arr;
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
     * GET /api/v1/financial/summary
     *
     * Total ALL-TIME de entradas + saidas (Fase 4 — substituir os
     * "Total Entradas/Saidas" que mostravam so a pagina atual).
     */
    public function summary(Request $request)
    {
        $client = $this->clientOrFail($request);

        // FOR-038: filtrar por supplier_id ativo para evitar soma cross-supplier
        $supplierId = (int) ($request->query('supplier_id')
            ?: \App\Models\SupplierPaymentSetting::where('is_active', 1)->value('supplier_id')
            ?: 1);

        // MUL-262: cache-aside 120s (invalidado no ClientSupplierTransactionObserver::created)
        $data = Cache::remember("fin:summary:{$client->id}:{$supplierId}", 120, function () use ($client, $supplierId) {
            $totalEntradas = (float) \App\Models\ClientSupplierTransaction::where('client_id', $client->id)
                ->where('supplier_id', $supplierId)
                ->where('type', 'credit')->sum('amount');
            $totalSaidas   = (float) \App\Models\ClientSupplierTransaction::where('client_id', $client->id)
                ->where('supplier_id', $supplierId)
                ->where('type', 'debit')->sum('amount');
            return [
                'total_entradas'           => round($totalEntradas, 2),
                'total_saidas'             => round($totalSaidas, 2),
                'total_entradas_formatted' => $this->formatBRL($totalEntradas),
                'total_saidas_formatted'   => $this->formatBRL($totalSaidas),
                'movimentacao_total'       => round($totalEntradas + $totalSaidas, 2),
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/v1/financial/balance-history?days=90
     *
     * Pontos diarios do saldo acumulado nos ultimos N dias (default 90).
     */
    public function balanceHistory(Request $request)
    {
        $client = $this->clientOrFail($request);
        $days   = max(1, min(365, (int) $request->query('days', 90)));

        // FOR-038: filtrar por supplier_id ativo para evitar historico cross-supplier
        $supplierId = (int) ($request->query('supplier_id')
            ?: \App\Models\SupplierPaymentSetting::where('is_active', 1)->value('supplier_id')
            ?: 1);

        // MUL-262: cache-aside 120s (chave inclui days)
        $data = Cache::remember("fin:history:{$client->id}:{$supplierId}:{$days}", 120, function () use ($client, $supplierId, $days) {
            $from = now()->subDays($days)->startOfDay();
            $saldoInicial = (float) \App\Models\ClientSupplierTransaction::where('client_id', $client->id)
                ->where('supplier_id', $supplierId)
                ->where('created_at', '<', $from)
                ->selectRaw("SUM(CASE WHEN type = 'credit' THEN amount ELSE -amount END) as bal")
                ->value('bal');

            $movByDay = \App\Models\ClientSupplierTransaction::where('client_id', $client->id)
                ->where('supplier_id', $supplierId)
                ->where('created_at', '>=', $from)
                ->selectRaw("DATE(created_at) as d, SUM(CASE WHEN type='credit' THEN amount ELSE -amount END) as net")
                ->groupBy('d')->orderBy('d')->pluck('net', 'd')->toArray();

            $points = [];
            $running = $saldoInicial;
            for ($i = 0; $i < $days; $i++) {
                $d = now()->subDays($days - 1 - $i)->format('Y-m-d');
                $running += (float) ($movByDay[$d] ?? 0);
                $points[] = ['date' => $d, 'balance' => round($running, 2)];
            }
            return [
                'days'           => $days,
                'saldo_inicial'  => round($saldoInicial, 2),
                'saldo_atual'    => round($running, 2),
                'points'         => $points,
            ];
        });

        return response()->json(['data' => $data]);
    }
    private function friendlyDescription(string $desc, string $ref): string
    {
        if (str_starts_with($desc, 'ajuste_migracao_legado') || str_starts_with($desc, 'migra-v3')) {
            return 'Saldo migrado do sistema anterior';
        }
        if (str_starts_with($desc, 'ajuste_reconciliacao')) {
            return 'Ajuste de saldo (reconciliação)';
        }
        if (str_starts_with($desc, 'importacao_wl_cc')) {
            return 'Crédito importado (conta corrente WL)';
        }
        if (str_starts_with($desc, 'deposito') || str_starts_with($ref, 'dep')) {
            return 'Depósito na carteira';
        }
        if (str_starts_with($desc, 'pagamento_pedido') || str_starts_with($ref, 'order-')) {
            return 'Pagamento de pedido';
        }
        return !empty($desc) ? $desc : 'Lançamento';
    }

}