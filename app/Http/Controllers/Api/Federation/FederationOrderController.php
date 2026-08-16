<?php

namespace App\Http\Controllers\Api\Federation;

use App\Http\Controllers\Controller;
use App\Jobs\DispatchWebhookJob;
use App\Models\FederationSyncLog;
use App\Models\Order;
use App\Models\TenantWebhookEndpoint;
use App\Models\WebhookDelivery;
use App\Observers\ProductObserver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * NOV-171-B — Endpoints de pedidos da Federation API (no hub api.hubai.io).
 *
 * DECISÃO RUAN: WL MANDA — hub aceita status incondicionalmente (last-write-wins).
 * Regra 8 (00-INDEX): retorna 200 imediatamente + processamento assíncrono via job.
 */
class FederationOrderController extends Controller
{
    /**
     * POST /api/federation/orders/{hub_order_id}/status
     *
     * WL informa novo status de um pedido. O hub aceita INCONDICIONALMENTE.
     * Propaga a atualização para outros WLs via webhook (exceto o WL de origem).
     *
     * Regra 8: retorna 200 imediatamente — qualquer processamento pesado via job.
     */
    public function updateStatusFromWl(Request $request, int $hubOrderId): JsonResponse
    {
        $tenant = $request->attributes->get('federation_tenant');

        $validator = Validator::make($request->all(), [
            'status'     => ['required', 'string', 'max:50'],
            'source_wl'  => ['nullable', 'string', 'max:50'],
            'changed_by' => ['nullable', 'string', 'max:100'],
            'notes'      => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Dados inválidos.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $sourceWl = $request->input('source_wl', $tenant);
        $newStatus = $request->input('status');

        // 200 imediato + job assíncrono (regra 8 do 00-INDEX)
        \App\Jobs\FederationUpdateOrderStatusJob::dispatch(
            hubOrderId: $hubOrderId,
            tenantSlug: $tenant,
            newStatus:  $newStatus,
            sourceWl:   $sourceWl,
            changedBy:  $request->input('changed_by'),
            notes:      $request->input('notes'),
        );

        Log::info('[FederationOrder::updateStatus] aceito 200', [
            'hub_order_id' => $hubOrderId,
            'tenant'       => $tenant,
            'new_status'   => $newStatus,
            'source_wl'    => $sourceWl,
        ]);

        return response()->json([
            'message'      => 'Status recebido e será processado.',
            'hub_order_id' => $hubOrderId,
            'status'       => $newStatus,
        ], 200);
    }

    /**
     * GET /api/federation/orders/delta
     *
     * WL busca pedidos novos/atualizados desde ?since= (polling alternativo).
     * Retorna apenas pedidos onde origin_tenant_slug = tenant do token.
     * Paginado: 100 itens por página.
     */
    public function delta(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('federation_tenant');
        $since  = $request->query('since');

        $query = Order::withoutGlobalScopes()
            ->where('origin_tenant_slug', $tenant)
            ->with(['orderItems', 'client']);

        if ($since) {
            try {
                $sinceDate = \Carbon\Carbon::parse($since);
                $query->where('updated_at', '>', $sinceDate);
            } catch (\Throwable) {
                return response()->json(['message' => 'Parâmetro since inválido. Use ISO8601.'], 422);
            }
        }

        $orders = $query
            ->orderBy('updated_at')
            ->paginate(100);

        $items = $orders->map(function (Order $o) {
            return [
                'hub_order_id'       => $o->id,
                'origin_tenant_slug' => $o->origin_tenant_slug,
                'status'             => $o->status,
                'marketplace'        => $o->marketplace ?? null,
                'marketplace_order_id' => $o->marketplace_order_id ?? null,
                'total'              => $o->total ?? null,
                'client_id'          => $o->client_id,
                'supplier_id'        => $o->supplier_id,
                'items_count'        => $o->orderItems?->count() ?? 0,
                'updated_at'         => $o->updated_at?->toIso8601String(),
                'created_at'         => $o->created_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'data'         => $items,
            'current_page' => $orders->currentPage(),
            'last_page'    => $orders->lastPage(),
            'total'        => $orders->total(),
            'tenant'       => $tenant,
            'since'        => $since,
        ]);
    }

    /**
     * WL puxa delta de pedidos dos SEUS suppliers (INF-039).
     * Diferente do delta(): filtra por supplier_id via tenant_supplier,
     * nao por origin_tenant_slug.
     */
    public function deltaSupplier(Request $request): JsonResponse
    {
        $tenantSlug = $request->attributes->get('federation_tenant');
        $tenant = \App\Models\Tenant::where('slug', $tenantSlug)->first();

        if (! $tenant) {
            return response()->json(['message' => 'Tenant do token não encontrado.'], 404);
        }

        $supplierIds = DB::table('tenant_supplier')
            ->where('tenant_id', $tenant->id)
            ->pluck('supplier_id');

        if ($supplierIds->isEmpty()) {
            return response()->json(['data' => [], 'total' => 0, 'suppliers' => []]);
        }

        $since = $request->query('since');
        $query = Order::withoutGlobalScopes()
            ->select(['id', 'supplier_id', 'status', 'canonical_status', 'total',
                      'supplier_total', 'updated_at'])
            ->whereIn('supplier_id', $supplierIds);

        if ($since) {
            try {
                $query->where('updated_at', '>', \Carbon\Carbon::parse($since));
            } catch (\Throwable) {
                return response()->json(['message' => 'Parâmetro since inválido. Use ISO8601.'], 422);
            }
        }

        $orders = $query->orderBy('updated_at')->paginate(200);

        // MUL-339: impressao digital dos itens, uma consulta para a pagina inteira.
        $idsDaPagina = collect($orders->items())->pluck('id');
        $itensPorPedido = DB::table('order_items')
            ->whereIn('order_id', $idsDaPagina)
            ->orderBy('order_id')->orderBy('sku')
            ->get(['order_id', 'sku', 'quantity', 'unit_price', 'supplier_unit_cost'])
            ->groupBy('order_id');

        return response()->json([
            'data'         => collect($orders->items())->map(function (Order $o) use ($itensPorPedido) {
                $itens = $itensPorPedido->get($o->id, collect());

                return [
                    'hub_order_id' => $o->id,
                    'supplier_id'  => $o->supplier_id,
                    'status'       => $o->status,
                    // MUL-339: o canonical_status e o que os dois lados falam igual. O status cru
                    // vem do marketplace (to_confirm_receive, to_return) e a WL guarda o traduzido
                    // (shipped, processed) — comparar os dois nunca converge.
                    'canonical_status' => $o->canonical_status,
                    'total'            => $o->total !== null ? (string) $o->total : null,
                    'supplier_total'   => $o->supplier_total !== null ? (string) $o->supplier_total : null,
                    'items_count'      => $itens->count(),
                    'items_hash'       => self::hashDosItens($itens),
                    'updated_at'       => $o->updated_at?->toIso8601String(),
                ];
            }),
            'current_page' => $orders->currentPage(),
            'last_page'    => $orders->lastPage(),
            'total'        => $orders->total(),
            'suppliers'    => $supplierIds,
        ]);
    }

    /**
     * MUL-339: impressao digital do conteudo do pedido.
     *
     * Os dois lados calculam isto do mesmo jeito — o mesmo metodo existe no
     * FederationPullOrdersFromHub. Qualquer mudanca aqui tem que ir la tambem, senao todo pedido
     * passa a acusar divergencia.
     *
     * Normaliza para nao depender de ordem nem de formatacao: ordena por sku e escreve os valores
     * com 2 casas. Item sem sku entra como string vazia, para nao sumir do hash.
     *
     * @param  \Illuminate\Support\Collection<int,object>  $itens
     */
    public static function hashDosItens($itens): string
    {
        $linhas = collect($itens)
            ->map(fn ($i) => implode('|', [
                (string) ($i->sku ?? ''),
                (int) ($i->quantity ?? 0),
                number_format((float) ($i->unit_price ?? 0), 2, '.', ''),
                number_format((float) ($i->supplier_unit_cost ?? 0), 2, '.', ''),
            ]))
            ->sort()
            ->values()
            ->implode(';');

        return substr(sha1($linhas), 0, 16);
    }

    /**
     * WL pede reenvio de pedidos especificos pro seu proprio endpoint (INF-039).
     * Usa o fanout normal (payload completo) restrito ao tenant do token.
     */
    public function redispatchToTenant(Request $request): JsonResponse
    {
        $tenantSlug = $request->attributes->get('federation_tenant');
        $tenant = \App\Models\Tenant::where('slug', $tenantSlug)->first();

        if (! $tenant) {
            return response()->json(['message' => 'Tenant do token não encontrado.'], 404);
        }

        $ids = $request->input('ids');

        if (! is_array($ids) || count($ids) === 0 || count($ids) > 500) {
            return response()->json(['message' => 'ids deve ser array de 1 a 500 hub_order_ids.'], 422);
        }

        $supplierIds = DB::table('tenant_supplier')
            ->where('tenant_id', $tenant->id)
            ->pluck('supplier_id');

        $validIds = Order::withoutGlobalScopes()
            ->whereIn('id', array_map('intval', $ids))
            ->whereIn('supplier_id', $supplierIds)
            ->pluck('id');

        // MUL-339: um WL pode ter mais de um registro de tenant. O api.multdrop.app tem dois —
        // 'multdrop.app', dono do endpoint de pedidos, e 'multdrop', dono dos de catalogo e kits.
        // O token dele esta sob 'multdrop', entao filtrar pelo tenant do token nao alcancava o
        // endpoint que recebe pedido: o fanout nao achava destino e voltava em silencio, enquanto
        // esta resposta dizia dispatched=N. A rede de seguranca do INF-039 rodou um mes assim.
        //
        // Resolve os tenants irmaos: os que tem endpoint ativo no MESMO host do WL que pediu.
        $tenantsIrmaos = $this->tenantsIrmaos($tenant->id);

        $entregas = 0;
        foreach ($tenantsIrmaos as $tid) {
            foreach ($validIds as $orderId) {
                // order.created fixo: WL desconhecido materializa; conhecido cai no path de update.
                \App\Jobs\FanoutOrderWebhookJob::dispatch((int) $orderId, 'order.created', [], (string) $tid);
                $entregas++;
            }
        }

        Log::info('[FederationOrderController] redispatch para tenant', [
            'tenant'          => $tenantSlug,
            'tenants_irmaos'  => $tenantsIrmaos,
            'pedidos'         => count($ids),
            'validos'         => $validIds->count(),
            'jobs_enfileirados' => $entregas,
        ]);

        return response()->json([
            'dispatched'     => $validIds->count(),
            'skipped'        => count($ids) - $validIds->count(),
            'tenants_alvo'   => count($tenantsIrmaos),
            'jobs'           => $entregas,
        ]);
    }
    /**
     * MUL-339: tenants que atendem o MESMO WL do tenant informado.
     *
     * Um WL pode ter mais de um registro em `tenants` — o api.multdrop.app tem 'multdrop.app'
     * (endpoint de pedidos) e 'multdrop' (catalogo, kits, federation.order.update). Como o token
     * de federacao esta registrado sob um slug so, filtrar o fanout por ele deixa de fora os
     * endpoints do irmao.
     *
     * Casa pelo host da URL dos endpoints ativos. Se o tenant nao tiver endpoint, devolve ele
     * mesmo — comportamento antigo, para nao quebrar quem esta certo.
     *
     * @return array<int,string>
     */
    private function tenantsIrmaos(string $tenantId): array
    {
        $hosts = DB::table('tenant_webhook_endpoints')
            ->where('tenant_id', $tenantId)->where('active', 1)
            ->pluck('url')
            ->map(fn ($u) => parse_url((string) $u, PHP_URL_HOST))
            ->filter()->unique()->values();

        if ($hosts->isEmpty()) {
            return [$tenantId];
        }

        $irmaos = DB::table('tenant_webhook_endpoints')
            ->where('active', 1)
            ->get(['tenant_id', 'url'])
            ->filter(fn ($e) => in_array(parse_url((string) $e->url, PHP_URL_HOST), $hosts->all(), true))
            ->pluck('tenant_id')
            ->unique()
            ->values()
            ->all();

        return $irmaos ?: [$tenantId];
    }
}
