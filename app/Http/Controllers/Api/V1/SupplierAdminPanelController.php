<?php
// INF-054 R1+R3 + INF-060 — caminho 2 proxy WL->hub aplicado

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientSupplierTransaction;
use App\Models\LabelPrintLog;
use App\Models\Order;
use App\Services\GoolhubBridgeService;
use App\Services\Labels\CombinedLabelService;
use Illuminate\Http\JsonResponse;
use App\Services\Federation\HubProxyHelper;
use Illuminate\Http\Request;
use App\Jobs\FetchShippingLabelJob;
use Illuminate\Support\Facades\DB;
use App\Models\PixTransaction;
use App\Models\Supplier;
use App\Services\Integrations\Factories\PaymentGatewayFactory;
use App\Services\Integrations\Payments\ShipayService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\OrderStatusHistory;

/**
 * Painel admin do FORNECEDOR (MultDrop etc.) — `/admin/*` em
 * fornecedorshop.online.
 *
 * Substitui o painel "super_admin sistema" que estava errado em /admin
 * (ver `Plano - Painel Admin Fornecedor MultDrop` em Obsidian).
 *
 * MultDrop comprou whitelabel; internamente e um fornecedor — esse painel
 * e o equivalente Lovable do `dashboard-loja.php` do legado.
 *
 * Estrategia (Caminho A/C): bridge le do banco do legado, novo renderiza.
 */
class SupplierAdminPanelController extends Controller
{
    use \App\Support\MontaEtapasDeDevolucao;

    /**
     * Fallbacks para retro-compat.
     */
    private const DEFAULT_LEGACY_LOJA_ID          = 565;
    private const DEFAULT_SUPPLIER_ID              = 30;
    private const DEFAULT_LEGACY_DEPOSITO_ID_CONST = 498;

    /**
     * Supplier resolvido na requisicao atual.
     * Populado por requireSupplierAdmin() e reutilizado em supplierId() / legacyLojaId().
     */
    private ?\App\Models\Supplier $_resolvedSupplier = null;

    public function __construct(private GoolhubBridgeService $bridge)
    {
    }

    /**
     * Retorna o supplier_id do fornecedor autenticado.
     *
     * Usa $_resolvedSupplier populado por requireSupplierAdmin().
     * Para super_admin sem supplier proprio, retorna o ID de config.
     */
    private function supplierId(): int
    {
        if ($this->_resolvedSupplier) {
            return (int) $this->_resolvedSupplier->id;
        }

        return (int) config('multdrop.supplier_id', self::DEFAULT_SUPPLIER_ID);
    }

    /**
     * Retorna o supplier model resolvido. Alias de $_resolvedSupplier.
     */
    private function resolveSupplier(?Request $request = null): ?\App\Models\Supplier
    {
        if ($this->_resolvedSupplier) {
            return $this->_resolvedSupplier;
        }

        if ($request) {
            $user = $request->user();
            if ($user && in_array($user->role, ['supplier', 'admin'])) {
                return $user->supplier;
            }
        }

        return \App\Models\Supplier::find(
            (int) config('multdrop.supplier_id', self::DEFAULT_SUPPLIER_ID)
        );
    }

    /**
     * Verifica autenticacao e resolve o supplier do usuario.
     *
     * Popula $this->_resolvedSupplier para reutilizacao no mesmo request.
     * Para super_admin: resolve primeiro pelo X-Tenant-Slug (via tenant_supplier),
     * depois pelo config multdrop.supplier_id como fallback.
     */
    private function requireSupplierAdmin(Request $request): void
    {
        $user = $request->user();
        if (!$user || !in_array($user->role, ['super_admin', 'admin', 'supplier'])) {
            abort(403, 'Apenas admin do fornecedor.');
        }

        // Resolve e armazena o supplier para o request atual
        if ($user->role === 'super_admin') {
            // MES-028: super_admin no painel de tenant especifico deve ver
            // o supplier daquele tenant, nao o supplier padrao do config.
            $tenantSlug = $request->header('X-Tenant-Slug');
            if ($tenantSlug) {
                $supplierId = \DB::table('tenant_supplier')
                    ->join('tenants', 'tenants.id', '=', 'tenant_supplier.tenant_id')
                    ->where('tenants.slug', $tenantSlug)
                    ->orderBy('tenant_supplier.supplier_id')
                    ->value('tenant_supplier.supplier_id');
                if ($supplierId) {
                    $this->_resolvedSupplier = \App\Models\Supplier::find((int) $supplierId);
                }
            }
            // Fallback: config (comportamento legado para Multdrop sem header)
            if (!$this->_resolvedSupplier) {
                $this->_resolvedSupplier = \App\Models\Supplier::find(
                    (int) config('multdrop.supplier_id', self::DEFAULT_SUPPLIER_ID)
                );
            }
        } else {
            $this->_resolvedSupplier = $user->supplier;
        }
    }

    /**
     * Guard FOR-037: bloqueia envio de pedido sem pagamento PIX confirmado.
     *
     * So aplica quando supplier.pix_only_orders = true.
     * NAO bloqueia pedidos legados: se order.status IN (paid, shipped, delivered),
     * o pagamento ja foi confirmado no sistema anterior — deixa passar.
     * Para pedidos novos (pending): verifica payments.status=paid OU pix_transactions.status=paid.
     */
    private function assertPaymentConfirmed(Order $order): void
    {
        // MUL-255: regra universal (decisao Ruan 22/07) - so pedido marcado como pago
        // ao fornecedor (wallet / PIX / pag. externo / cobranca forcada => wallet_paid_at)
        // pode ser bipado. Fornecedor em modelo manual marca via Confirmar Pag. Externo.
        if ($order->wallet_paid_at === null) {
            \Illuminate\Support\Facades\Log::warning('[ShipGuard][MUL-255] Bip bloqueado: pedido nao pago ao fornecedor', [
                'order_id' => $order->id,
                'status'   => $order->status,
            ]);
            abort(response()->json([
                'error'   => 'payment_not_confirmed',
                'message' => 'Pedido ainda nao foi marcado como pago. Confirme o pagamento (wallet, PIX ou pagamento externo) antes de bipar o envio.',
            ], 422));
        }

        $supplier = $this->_resolvedSupplier;
        if (!$supplier) {
            return;
        }

        $setting = $supplier->paymentSetting;

        // Sem configuracao de pagamento = modelo manual -> deixa passar
        if (!$setting || !$setting->pix_only_orders) {
            return;
        }

        // Pedidos com status confirmado (no legado ou novo sistema) -> passa
        // paid/shipped/delivered indicam pagamento ja confirmado
        if (in_array($order->status, ["paid", "shipped", "delivered"])) {
            return;
        }

        // Para pedidos ainda pending: verificar pagamento confirmado no novo sistema

        // 1. Verificar tabela payments (cobre wallet + PIX confirmado pelo webhook)
        $hasPaidPayment = \Illuminate\Support\Facades\DB::table("payments")
            ->where("order_id", $order->id)
            ->where("status", "paid")
            ->exists();

        if ($hasPaidPayment) {
            return;
        }

        // 2. Fallback: pix_transactions diretamente
        // (edge case: webhook confirmou PIX mas Payment ainda nao foi atualizado)
        $hasPaidPix = \Illuminate\Support\Facades\DB::table("pix_transactions")
            ->where("order_id", $order->id)
            ->where("supplier_id", $supplier->id)
            ->where("status", "paid")
            ->exists();

        if ($hasPaidPix) {
            return;
        }

        \Illuminate\Support\Facades\Log::warning("[ShipGuard] Tentativa de envio sem pagamento confirmado", [
            "order_id"    => $order->id,
            "supplier_id" => $supplier->id,
            "legacy_id"   => $order->legacy_id,
            "order_status"=> $order->status,
        ]);

        abort(response()->json([
            "error"   => "payment_not_confirmed",
            "message" => "O pagamento deste pedido ainda nao foi confirmado. Aguarde a confirmacao do PIX antes de enviar o pedido.",
        ], 422));
    }

    /**
     * Define o estoque (consolidado) de um produto do supplier atual.
     * Garante UM unico registro inventory com producer_id=supplier_id.
     */
    private function upsertStock(int $productId, int $quantity): int
    {
        $sid = $this->supplierId();
        $row = \DB::table('inventory')
            ->where('product_id', $productId)
            ->where('producer_id', $sid)
            ->first();

        if ($row) {
            \DB::table('inventory')->where('id', $row->id)->update([
                'quantity'   => max(0, $quantity),
                'updated_at' => now(),
            ]);
            return (int) $row->id;
        }

        return (int) \DB::table('inventory')->insertGetId([
            'product_id'   => $productId,
            'producer_id'  => $sid,
            'warehouse_id' => $sid,
            'quantity'     => max(0, $quantity),
            'reserved'     => 0,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    /**
     * Resolve o id da loja no legado (tabela loja.id) para o supplier autenticado.
     *
     * Mapeamento (migration 2026_06_25_120000):
     *   loja.id WHERE loja.id_deposito = suppliers.legacy_empresa_id
     *
     * Exemplos:
     *   Drop Auto Pecas (supplier_id=10, legacy_empresa_id=20)  -> legacy_loja_id=93
     *   MStore          (supplier_id=25, legacy_empresa_id=447) -> legacy_loja_id=515
     *   Multdrop        (supplier_id=30, legacy_empresa_id=498) -> legacy_loja_id=565
     */
    /**
     * Resolve o id da loja no legado (tabela loja.id) para o supplier autenticado.
     *
     * Mapeamento (migration 2026_06_25_120000):
     *   loja.id WHERE loja.id_deposito = suppliers.legacy_empresa_id
     *
     * Exemplos:
     *   Drop Auto Pecas (supplier_id=10, legacy_empresa_id=20)  -> legacy_loja_id=93
     *   MStore          (supplier_id=25, legacy_empresa_id=447) -> legacy_loja_id=515
     *   Multdrop        (supplier_id=30, legacy_empresa_id=498) -> legacy_loja_id=565
     */
    private function legacyLojaId(Request $request): int
    {
        $supplier = $this->_resolvedSupplier ?? $this->resolveSupplier($request);

        if ($supplier && $supplier->legacy_loja_id) {
            return (int) $supplier->legacy_loja_id;
        }

        return (int) config('multdrop.legacy_loja_id', self::DEFAULT_LEGACY_LOJA_ID);
    }

    /**
     * GET /api/v1/supplier-admin/dashboard
     * Le do bridge legado supplier_dashboard.php.
     */
    /**
     * MUL-417 — dashboard do painel admin, lido do BANCO LOCAL.
     *
     * Era 100% legado: uma unica chamada a goolhub.io/api/bridge/supplier_dashboard.php.
     * Com o legado fora do ar a chamada morre em `cURL error 7: No route to host`, o
     * metodo devolvia 502, o Cloudflare trocava por pagina de erro SEM cabecalho de CORS
     * e o navegador reportava "failed to fetch" — a tela ficava presa em "Carregando
     * dashboard do fornecedor...". Reproduzido e medido em 18/08/2026.
     *
     * O contrato de resposta e exatamente o que o front ja consome
     * (SupplierAdminDashboard), so que agora cada numero sai de uma consulta local.
     * Nenhuma mudanca no front foi necessaria.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $supplierId = $this->supplierId();
        $supplier   = $this->_resolvedSupplier;

        $desde15 = now()->subDays(15)->startOfDay();
        $desde30 = now()->subDays(30)->startOfDay();

        // --- catalogo -------------------------------------------------------
        $skus = DB::table('products')
            ->where('supplier_id', $supplierId)
            ->selectRaw("
                COUNT(*)                                        AS vinculados,
                SUM(COALESCE(virtual_stock_qty, 0) <= 0)        AS sem_estoque,
                SUM(is_active = 1)                              AS ativos,
                SUM(is_active = 0)                              AS inativos,
                SUM(category_id IS NULL)                        AS sem_categoria
            ")
            ->first();

        // --- pedidos dos ultimos 15 dias ------------------------------------
        // "pago" aqui e o pagamento AO FORNECEDOR (wallet_paid_at): e o que este painel
        // precisa saber. paid_at e do marketplace e responde outra pergunta (regra 33).
        // Cancelado fica de fora: nao e trabalho nem cobranca.
        $ped = DB::table('orders')
            ->where('supplier_id', $supplierId)
            ->where('is_draft', 0)
            ->where('created_at', '>=', $desde15)
            ->where('canonical_status', '<>', 'cancelled')
            ->selectRaw("
                SUM(wallet_paid_at IS NULL)                                                          AS nao_pagos,
                SUM(wallet_paid_at IS NULL AND (label_url IS NULL OR label_url = ''))                AS nao_pagos_sem_etiq,
                SUM(wallet_paid_at IS NOT NULL AND label_url IS NOT NULL AND label_url <> '')        AS pagos_com_etiq,
                SUM(wallet_paid_at IS NOT NULL AND (label_url IS NULL OR label_url = ''))            AS pagos_sem_etiq
            ")
            ->first();

        // --- etiquetas ------------------------------------------------------
        // em_aberto = tem etiqueta, ninguem imprimiu e o pedido nao saiu (trabalho parado).
        $etq = DB::table('orders')
            ->where('supplier_id', $supplierId)
            ->where('is_draft', 0)
            ->selectRaw("
                SUM(label_url IS NOT NULL AND label_url <> '' AND label_printed_at IS NULL AND shipped_at IS NULL) AS em_aberto,
                SUM(label_printed_at IS NOT NULL)                                                                  AS impressas,
                SUM(shipped_at IS NOT NULL)                                                                        AS enviadas
            ")
            ->first();

        // --- SAC ------------------------------------------------------------
        $tickets = DB::table('support_tickets')
            ->where('supplier_id', $supplierId)
            ->selectRaw('status, COUNT(*) AS n')
            ->groupBy('status')
            ->pluck('n', 'status');
        $sacDe = fn (array $chaves) => (int) collect($chaves)->sum(fn ($k) => (int) ($tickets[$k] ?? 0));

        // --- integracoes ----------------------------------------------------
        // O front rotula por id_canal do legado (3 Shopee, 6 ML, 20 Bling, 10 Amazon,
        // 7 Magalu, 13 TikTok). mercado_livre e mercadolivre convivem no banco (as duas
        // grafias existem) e caem no mesmo canal.
        $idCanal = [
            'shopee'        => 3,
            'mercadolivre'  => 6,
            'mercado_livre' => 6,
            'bling'         => 20,
            'amazon'        => 10,
            'magalu'        => 7,
            'tiktok'        => 13,
        ];
        $porCanal = [];
        foreach (DB::table('marketplace_accounts')->selectRaw('platform, COUNT(*) AS n')->groupBy('platform')->get() as $linha) {
            $canal = $idCanal[strtolower((string) $linha->platform)] ?? 0;
            $porCanal[$canal] = ($porCanal[$canal] ?? 0) + (int) $linha->n;
        }
        $integracoesTotal = array_sum($porCanal);
        $porCanalLista    = [];
        foreach ($porCanal as $canal => $qtd) {
            $porCanalLista[] = ['id_canal' => (int) $canal, 'qtd' => (int) $qtd];
        }

        // --- vendas dos ultimos 30 dias -------------------------------------
        // Data da VENDA no marketplace quando existe (MUL-237); senao a de entrada.
        $vendas = DB::table('orders')
            ->where('supplier_id', $supplierId)
            ->where('is_draft', 0)
            ->where('canonical_status', '<>', 'cancelled')
            ->whereRaw('COALESCE(marketplace_created_at, created_at) >= ?', [$desde30])
            ->selectRaw('DATE(COALESCE(marketplace_created_at, created_at)) AS dia, COUNT(*) AS qtd, SUM(COALESCE(total,0)) AS total')
            ->groupBy('dia')
            ->orderBy('dia')
            ->get();

        return response()->json(['data' => [
            'loja' => [
                'id'           => (int) $supplierId,
                'nome'         => $supplier->display_name ?? $supplier->trade_name ?? $supplier->company_name ?? null,
                'razao_social' => $supplier->company_name ?? null,
                'cnpj'         => $supplier->document ?? null,
                'endereco'     => trim(implode(', ', array_filter([
                    $supplier->address ?? null,
                    $supplier->address_number ?? null,
                    $supplier->address_complement ?? null,
                ]))) ?: null,
                'email'        => $supplier->email ?? null,
            ],
            'skus' => [
                'vinculados'    => (int) ($skus->vinculados ?? 0),
                'sem_estoque'   => (int) ($skus->sem_estoque ?? 0),
                'ativos'        => (int) ($skus->ativos ?? 0),
                'inativos'      => (int) ($skus->inativos ?? 0),
                'sem_categoria' => (int) ($skus->sem_categoria ?? 0),
            ],
            'pedidos_15d' => [
                'nao_pagos'          => (int) ($ped->nao_pagos ?? 0),
                'nao_pagos_sem_etiq' => (int) ($ped->nao_pagos_sem_etiq ?? 0),
                'pagos_com_etiq'     => (int) ($ped->pagos_com_etiq ?? 0),
                'pagos_sem_etiq'     => (int) ($ped->pagos_sem_etiq ?? 0),
            ],
            'etiquetas' => [
                'em_aberto' => (int) ($etq->em_aberto ?? 0),
                'impressas' => (int) ($etq->impressas ?? 0),
                'enviadas'  => (int) ($etq->enviadas ?? 0),
            ],
            'sac' => [
                'abertos'         => $sacDe(['open', 'new', 'aberto']),
                'novamente'       => $sacDe(['reopened', 'novamente']),
                'respondidos'     => $sacDe(['answered', 'responded', 'respondido']),
                'em_tratamento'   => $sacDe(['in_progress', 'em_tratamento', 'pending']),
                'fechados'        => $sacDe(['closed', 'resolved', 'fechado']),
                'em_analise'      => $sacDe(['analysis', 'in_analysis', 'em_analise']),
                'prazo_expirado'  => $sacDe(['expired', 'overdue', 'prazo_expirado']),
            ],
            'integracoes' => [
                'total'     => (int) $integracoesTotal,
                'por_canal' => $porCanalLista,
            ],
            'vendas_30d' => [
                'total_brl' => round((float) $vendas->sum('total'), 2),
                'por_dia'   => $vendas->map(fn ($l) => [
                    'date'  => (string) $l->dia,
                    'qtd'   => (int) $l->qtd,
                    'total' => round((float) $l->total, 2),
                ])->values()->all(),
            ],
        ]]);
    }

    /**
     * GET /api/v1/supplier-admin/orders
     * Pedidos do fornecedor (reusa orders novo — supplier_id = 30).
     */
    public function orders(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        // MUL-378: paginacao dinamica do painel — teto 200 protege de per_page abusivo
        $perPage = max(5, min(200, (int) $request->query('per_page', 20)));

        // Isolamento de seller: clientes com role nao-admin so veem pedidos do proprio client_id.
        // Fornecedores (role=supplier) e admins veem todos os pedidos do supplier_id deles.
        $role     = $request->user()->role;
        $isSupplierOrAdmin = in_array($role, ['super_admin', 'admin', 'supplier']);
        $clientId = $request->user()->client?->id ?? null;

        // Client sem client_id e sem role elevado: retorna vazio (sem acesso)
        if (!$isSupplierOrAdmin && !$clientId) {
            return response()->json(['data' => [], 'meta' => ['total' => 0, 'per_page' => $perPage, 'current_page' => 1, 'last_page' => 1]]);
        }

        $q = Order::where('supplier_id', $this->supplierId())
            // MUL-269 fase 2: nome do seller vem do user (accessor client->company_name).
            ->with(['client:id,user_id,phone', 'client.user:id,name,full_name', 'client.marketplaceAccounts:id,client_id,account_name,platform', 'marketplaceAccount:id,account_name,wl_client_name,wl_client_email', 'items:id,order_id,name,sku,quantity,unit_price,total,product_image,supplier_unit_cost,supplier_total_cost,legacy_sku_pai_id,external_item_id'])
            // JT-023: ordenar pela data do PEDIDO, nao pela da importacao.
            // ->latest() ordena por created_at, que e quando a linha nasceu NESTE banco.
            // Pedido espelhado do hub entra com a data de hoje: o 137266 foi feito em
            // 01/08 e aparecia no topo como se fosse de 13/08. Medido em 13/08 no jtdrop:
            // atraso medio de 0,4 dia, pior caso 20 dias, 53 pedidos com mais de um dia.
            //
            // COALESCE porque 498 dos 1.416 pedidos nao tem marketplace_created_at
            // (pedido nativo do WL nao passa pelo marketplace) — nesses o created_at
            // continua sendo a melhor aproximacao.
            ->orderByRaw('COALESCE(marketplace_created_at, created_at) DESC')
            ->orderBy('id', 'desc');

        // MUL-197: rascunhos (is_draft=1) ficam INVISIVEIS por padrao em todas as
        // superficies de listagem. Somente ?draft=1 exibe (e ai exibe SO rascunhos).
        $q->where('is_draft', $request->boolean('draft') ? 1 : 0);

        // INF-036: pedido inexistente na API do marketplace fica oculto sempre.
        $q->where('status', '!=', \App\Enums\OrderStatus::NOT_FOUND->value);

        // Filtrar por client_id apenas para sellers (role=client) que nao sao admin/supplier
        if (!$isSupplierOrAdmin && $clientId) {
            $q->where('client_id', $clientId);
        }

        if (($status = $request->query('status')) && $status !== 'all') $q->where('status', $status);
        // periodo: date_from/date_to (YYYY-MM-DD) tem prioridade; fallback para days relativo
        $dateFrom = $request->query("date_from");
        $dateTo   = $request->query("date_to");
        if ($dateFrom) {
            $q->whereDate("created_at", ">=", $dateFrom);
        }
        if ($dateTo) {
            $q->whereDate("created_at", "<=", $dateTo);
        }
        // dias (relativos a created_at): 0=hoje, -1=ontem, N>0=ultimos N dias (ignorado se date_from/date_to presentes)
        if (!($dateFrom || $dateTo)) {
            $daysRaw = $request->query("days");
            if ($daysRaw !== null && $daysRaw !== "") {
                $days = (int) $daysRaw;
                if ($days === 0) {
                    $q->where("created_at", ">=", now()->startOfDay());
                } elseif ($days === -1) {
                    $q->whereBetween("created_at", [
                        now()->subDay()->startOfDay(),
                        now()->subDay()->endOfDay(),
                    ]);
                } elseif ($days > 0) {
                    $q->where("created_at", ">=", now()->subDays($days));
                }
            }
        }
        // canal de venda: marketplace real = COALESCE(channel_name, source) — pedidos
        // Amazon/Shopee/TikTok via Bling tem source=bling e o marketplace em channel_name (MUL-213 #2)
        if ($channel = $request->query("channel") ?: $request->query("source")) {
            $norm = mb_strtolower(str_replace(' ', '', $channel));
            $q->whereRaw("LOWER(REPLACE(COALESCE(NULLIF(channel_name, ''), source), ' ', '')) = ?", [$norm]);
        }
        // filtro etiqueta — JT-006 semantica separada:
        //   none      = sem label_url
        //   available = tem label_url mas ainda nao foi impressa (fila de impressao do fornecedor)
        //   printed   = label_printed_at preenchido, ainda nao enviada
        //   sent      = shipped_at preenchido
        $label = $request->query('label');
        if ($label === 'none') {
            $q->where(function ($w) {
                $w->whereNull('label_url')->orWhere('label_url', '');
            });
        } elseif ($label === 'available') {
            // JT-022: "disponivel pra imprimir" e trabalho que o fornecedor pode fazer
            // AGORA. Pedido cancelado nao e — a etiqueta dele nao serve mais, e o
            // marketplace nao aceita o envio. Medido em 13/08 no jtdrop: dos 171 que o
            // filtro devolvia, 81 estavam cancelados (47%), enchendo a tela de trabalho
            // que nao existe.
            $q->whereNotNull('label_url')->where('label_url', '<>', '')
              ->whereNull('label_printed_at')->whereNull('shipped_at')
              ->where(function ($w) {
                  $w->whereNull('canonical_status')
                    ->orWhere('canonical_status', '<>', 'cancelled');
              })
              ->where('status', '<>', 'cancelled');
        } elseif ($label === 'printed') {
            $q->whereNotNull('label_printed_at')->whereNull('shipped_at');
        } elseif ($label === 'sent') {
            $q->whereNotNull('shipped_at');
        }
        // filtro canal de envio: casa delivery_type (Shopee) OU carrier_name (Bling: Amazon DBA etc.) — MUL-213 #1
        if ($dt = $request->query('delivery_type')) {
            $q->where(function ($w) use ($dt) {
                $w->where('delivery_type', $dt)->orWhere('carrier_name', $dt);
            });
        }
        if ($search = $request->query('search')) {
            $s = '%' . $search . '%';
            // MUL-271: numerico = match exato (id interno incluso) — LIKE parcial achava
            // pedido errado (ex: 90451 batia dentro de external_order_id 15904516082).
            // Nao-numerico inclui SKU dos itens (paridade com painel seller).
            $q->where(function ($w) use ($s, $search) {
                if (ctype_digit($search)) {
                    $w->where('id', $search)
                      ->orWhere('order_number', $search)
                      ->orWhere('external_order_id', $search)
                      ->orWhere('tracking_number', $search)
                      ->orWhereHas('items', fn ($iq) => $iq->where('sku', $search)->orWhere('variation_sku', $search));
                } else {
                    $w->where('external_order_id', 'like', $s)
                      ->orWhere('order_number', 'like', $s)
                      ->orWhere('tracking_number', 'like', $s)
                      ->orWhere('customer_name', 'like', $s)
                      ->orWhereHas('items', fn ($iq) => $iq->where('sku', 'like', $s)->orWhere('variation_sku', 'like', $s));
                }
            });
        }

        // Filtro por client (admin pode filtrar por qualquer cliente)
        if (($clientIdParam = $request->query('client_id')) && in_array($request->user()->role, ['super_admin', 'admin'])) {
            $q->where('client_id', (int) $clientIdParam);
        }

        // Filtro por pagamento: paid / pending / no_cost / all (INF-047)
        $paymentFilter = $request->query('payment', 'all');
        if ($paymentFilter === 'paid') {
            $q->whereNotNull('wallet_paid_at');
        } elseif ($paymentFilter === 'pending') {
            $q->whereNull('wallet_paid_at')->where('status', '!=', 'cancelled');
        } elseif ($paymentFilter === 'no_cost') {
            $q->whereExists(function ($sq) {
                $sq->select(\Illuminate\Support\Facades\DB::raw(1))
                   ->from('order_items')
                   ->whereColumn('order_items.order_id', 'orders.id')
                   ->where(function ($ww) {
                       $ww->whereNull('supplier_unit_cost')
                          ->orWhere('supplier_unit_cost', 0);
                   });
            });
        }

        // MUL-278: ids_only=1 devolve TODOS os ids do filtro atual (selecao total
        // do batch de cobranca forcada — a fila lenta absorve qualquer quantidade)
        if ($request->boolean('ids_only')) {
            $ids = (clone $q)->reorder()->pluck('orders.id');
            return response()->json(['ids' => $ids, 'total' => $ids->count()], 200);
        }

        // Sum para header de totais
        $totalSum = (clone $q)->sum('total');

        $paginator = $q->paginate($perPage);

        // Batch-load listing URLs: legacy_sku_pai_id -> {platform, external_listing_id, shop_id}
        $allItems   = collect($paginator->items())->flatMap(fn ($o) => $o->items)->unique('legacy_sku_pai_id')->pluck('legacy_sku_pai_id')->filter()->values()->all();
        $listingMap = [];
        if (!empty($allItems)) {
            $rows = DB::select("
                SELECT p.legacy_sku_pai_id,
                    (SELECT ma2.platform FROM client_products cp2 JOIN marketplace_accounts ma2 ON ma2.id=cp2.marketplace_account_id WHERE cp2.product_id=p.id AND cp2.external_listing_id IS NOT NULL AND cp2.external_listing_id!='' LIMIT 1) as platform,
                    (SELECT cp2.external_listing_id FROM client_products cp2 WHERE cp2.product_id=p.id AND cp2.external_listing_id IS NOT NULL AND cp2.external_listing_id!='' LIMIT 1) as external_listing_id,
                    (SELECT ma2.shop_id FROM client_products cp2 JOIN marketplace_accounts ma2 ON ma2.id=cp2.marketplace_account_id WHERE cp2.product_id=p.id AND cp2.external_listing_id IS NOT NULL AND cp2.external_listing_id!='' LIMIT 1) as shop_id
                FROM products p
                WHERE p.legacy_sku_pai_id IN (" . implode(',', array_map('intval', $allItems)) . ")
                  AND EXISTS (SELECT 1 FROM client_products cp3 WHERE cp3.product_id=p.id AND cp3.external_listing_id IS NOT NULL AND cp3.external_listing_id!='')
            ");
            foreach ($rows as $r) {
                $url = null;
                if ($r->platform === 'shopee' && $r->shop_id) {
                    $url = "https://shopee.com.br/product/{$r->shop_id}/{$r->external_listing_id}";
                } elseif (in_array($r->platform, ['mercadolivre', 'ml'])) {
                    $url = "https://produto.mercadolivre.com.br/MLB-{$r->external_listing_id}";
                }
                $listingMap[(int)$r->legacy_sku_pai_id] = $url;
            }
        }

        // MUL-341: estado da devolucao da pagina inteira numa consulta so — o caminhao de
        // volta precisa disso, e uma consulta por linha derrubaria a listagem.
        $devolucoesDaPagina = \App\Support\EtapaDoPedido::devolucoesDe(
            collect($paginator->items())->pluck('id')->filter()->map(fn ($v) => (int) $v)->all()
        );

        return response()->json([
            'data' => collect($paginator->items())->map(function ($o) use ($devolucoesDaPagina, $listingMap) {
                $etapaLista = \App\Support\EtapaDoPedido::resolver(
                    $o,
                    $devolucoesDaPagina[(int) $o->id] ?? null
                );

                return [
                'id'                     => $o->id,
                'legacy_id'              => $o->legacy_id,
                'order_number'           => $o->order_number,
                'external_order_id'      => $o->external_order_id,
                // FOR-127: pedido vindo de WL nao tem client no hub (cliente e local da WL).
                // O nome do seller vem de marketplace_accounts.wl_client_name.
                'client'                 => $o->client?->user?->name
                    ?? $o->client?->company_name
                    ?? $o->wl_seller_name
                    ?? $o->marketplaceAccount?->wl_client_name
                    ?? $o->marketplaceAccount?->account_name,
                'client_email'           => $o->marketplaceAccount?->wl_client_email,
                'client_company'         => $o->client?->company_name,
                'client_id'              => $o->client_id,
                'client_phone'           => $o->client?->phone,
                'client_store_name'      => $o->client?->marketplaceAccounts?->first()?->account_name,
                'source'                 => $o->source,
                'status'                 => $o->status,
                // MUL-341: etapa resolvida no backend, igual a do painel do seller
                'etapa'                  => $etapaLista['etapa'],
                'etapa_rotulo'           => $etapaLista['rotulo'],
                'devolucao'              => $etapaLista['devolucao'],
                'is_draft'               => (bool) $o->is_draft,
                'draft_reason'           => $o->draft_reason,
                'order_processing_status'=> $o->order_processing_status,
                'customer_name'          => $o->customer_name,
                'customer_document_type'  => $o->customer_document_type,
                'customer_document_number'=> $o->customer_document_number,
                'total'                  => (float) $o->total,
                'supplier_total'         => $o->supplier_total ? (float) $o->supplier_total : null,
                'paid_at'                => $o->paid_at,
                'wallet_paid_at'         => $o->wallet_paid_at,
                // FOR-130: o fornecedor confere o recebimento no extrato do gateway.
                // Pagamento por saldo nao tem transacao la -- vem method='saldo' e id nulo.
                'payment_external_id'    => $o->payment_external_id,
                'payment_method'         => $o->payment_method,
                'payment_gateway'        => $o->payment_gateway,
                'shipped_at'             => $o->shipped_at,
                'delivered_at'           => $o->delivered_at,
                'tracking_number'        => $o->tracking_number,
                'label_url'              => $this->absoluteLabelUrl($o->label_url),
                // JT-022: o motivo da etiqueta indisponivel na LISTA, nao so no modal.
                // O fornecedor abre o painel, ve 'sem etiqueta' em dezenas de pedidos e
                // nao sabe se falta DC-e, se o marketplace nao liberou ou se a conta caiu.
                // Medido em 13/08: invoice_required_cpf 222 · awaiting_marketplace 108.
                'label_status_reason'    => $o->label_status_reason,
                'invoice_number'         => $o->invoice_number,
                'bling_pedido_id'        => $o->bling_pedido_id,
                'bling_pedido_url'       => $o->bling_pedido_url,
                'bling_synced_at'        => $o->bling_synced_at,
                'bling_sync_error'       => $o->bling_sync_error,
                'bling_sync_attempted_at'=> $o->bling_sync_attempted_at,
                'carrier_name'           => $o->carrier_name,
                'created_at'             => $o->created_at,
                // FOR-119: a data do PEDIDO no marketplace. O painel mostrava created_at,
                // que e quando a linha nasceu neste banco — pedido espelhado do hub aparecia
                // com a data da importacao (pior caso medido: 20 dias de diferenca).
                'marketplace_created_at'  => $o->marketplace_created_at,
                'items_count'            => $o->items->count(),
                'items_preview'          => $o->items->take(3)->map(fn($i) => ['name'=>$i->name,'sku'=>$i->sku,'quantity'=>$i->quantity,'product_image'=>$i->product_image,'supplier_unit_cost'=>$i->supplier_unit_cost ? (float)$i->supplier_unit_cost : null,'supplier_total_cost'=>$i->supplier_total_cost ? (float)$i->supplier_total_cost : null,'listing_url'=>$listingMap[(int)($i->legacy_sku_pai_id ?? 0)] ?? ($o->source === 'shopee' && $o->shop_id && $i->external_item_id ? "https://shopee.com.br/product/{$o->shop_id}/{$i->external_item_id}" : null)])->values(),
                ];
            })->values(),
            'meta' => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total_sum'    => round($totalSum, 2),
            ],
        ]);
    }


    // === showOrder() — GET /api/v1/supplier-admin/orders/{id} ===
    public function showOrder(Request $request, int $id): JsonResponse
    {
        $this->requireSupplierAdmin($request);

        $order = Order::where('supplier_id', $this->supplierId())
            ->with([
                // MUL-269 fase 2: nome do seller vem do user (accessor client->company_name).
                'client:id,user_id,phone',
                'client.user:id,name,full_name',
                'items:id,order_id,name,sku,quantity,unit_price,total,supplier_unit_cost,supplier_total_cost,product_image,legacy_sku_pai_id,external_item_id,external_variation_id',
            ])
            ->findOrFail($id);

        $order->items->each->makeVisible(['supplier_unit_cost', 'supplier_total_cost']);

        $etapaResolvida = \App\Support\EtapaDoPedido::resolver(
            $order,
            \App\Support\EtapaDoPedido::devolucaoDe($order->id, $order->external_order_id)
        );

        return response()->json([
            'data' => [
                // MUL-340: as etapas da devolucao, ja resolvidas para exibicao. Vazio em pedido
                // normal. O front nao precisa conhecer os codigos da Shopee nem calcular prazo.
                'devolucoes'              => $this->etapasDeDevolucao($order->id, $order->external_order_id),
                // MUL-341: etapa resolvida no backend — a mesma que o painel do seller recebe
                'etapa'                   => $etapaResolvida['etapa'],
                'etapa_rotulo'            => $etapaResolvida['rotulo'],
                'devolucao'               => $etapaResolvida['devolucao'],
                'id'                      => $order->id,
                'legacy_id'               => $order->legacy_id,
                'order_number'            => $order->order_number,
                'external_order_id'       => $order->external_order_id,
                'marketplace_order_id'    => $order->marketplace_order_id,
                'shop_id'                 => $order->shop_id,
                // FOR-127: mesmo fallback da lista
                'client'                  => $order->client?->company_name
                    ?? $order->wl_seller_name
                    ?? $order->marketplaceAccount?->wl_client_name
                    ?? $order->marketplaceAccount?->account_name,
                'source'                  => $order->source,
                'status'                  => $order->status,
                'is_draft'                => (bool) $order->is_draft,
                'draft_reason'            => $order->draft_reason,
                'enrich_attempts'         => (int) ($order->enrich_attempts ?? 0),
                'last_enriched_at'        => $order->last_enriched_at,
                'order_processing_status' => $order->order_processing_status,
                'customer_name'           => $order->customer_name,
                'customer_document_number'=> $order->customer_document_number,
                'customer_phone'          => $order->customer_phone,
                'customer_address'        => $order->customer_address,
                'total'                   => (float) $order->total,
                'subtotal'                => (float) $order->subtotal,
                'shipping_cost'           => (float) $order->shipping_cost,
                'marketplace_fee'         => (float) $order->marketplace_fee,
                'supplier_total'          => $order->supplier_total ? (float) $order->supplier_total : null,
                'tracking_number'         => $order->tracking_number,
                'label_url'               => $this->absoluteLabelUrl($order->label_url),
                'carrier_name'            => $order->carrier_name,
                'invoice_number'          => $order->invoice_number,
                'invoice_series'          => $order->invoice_series,
                'invoice_access_key'      => $order->invoice_access_key,
                'invoice_status'          => $order->invoice_status,
                'paid_at'                 => $order->paid_at,
                'wallet_paid_at'          => $order->wallet_paid_at,
                // FOR-130
                'payment_external_id'     => $order->payment_external_id,
                'payment_method'          => $order->payment_method,
                'payment_gateway'         => $order->payment_gateway,
                // MUL-251: pagamento externo confirmado (NOV-207 E3) — UI alterna Confirmar/Estornar
                'external_payment'        => (function () use ($order) {
                    $ep = \App\Models\Payment::where('order_id', $order->id)
                        ->where('gateway', 'external')->where('status', 'paid')
                        ->orderByDesc('id')->first();
                    return $ep ? ['id' => $ep->id, 'amount' => (float) $ep->amount, 'paid_at' => $ep->paid_at] : null;
                })(),
                // MUL-254B: cobranca forcada ativa — UI alterna Forcar/Estornar Cobranca Forcada
                'forced_charge'           => (function () use ($order) {
                    $fc = \App\Models\Payment::where('order_id', $order->id)
                        ->where('gateway', 'wallet')->where('method', 'forced')->where('status', 'paid')
                        ->orderByDesc('id')->first();
                    return $fc ? ['id' => $fc->id, 'amount' => (float) $fc->amount, 'paid_at' => $fc->paid_at] : null;
                })(),
                'shipped_at'              => $order->shipped_at,
                'delivered_at'            => $order->delivered_at,
                'cancelled_at'            => $order->cancelled_at,
                'created_at'              => $order->created_at,
                // FOR-119: a data do PEDIDO no marketplace. O painel mostrava created_at,
                // que e quando a linha nasceu neste banco — pedido espelhado do hub aparecia
                // com a data da importacao (pior caso medido: 20 dias de diferenca).
                'marketplace_created_at'  => $order->marketplace_created_at,
                'notes'                   => $order->notes,
                // MUL-222 item 13: espelhar dialog seller (Obs Expedicao + NF-e links + Shopee package)
                'expedition_note'         => $order->expedition_note ?? null,
                'invoice_url'             => $order->invoice_url ?? null,
                'invoice_xml_url'         => $order->invoice_xml_url ?? null,
                'nfe_entrada_pdf_url'     => $order->nfe_entrada_pdf_url ?? null,
                'nfe_entrada_xml_url'     => $order->nfe_entrada_xml_url ?? null,
                'nfe_entrada_number'      => $order->nfe_entrada_number ?? null,
                'bling_pedido_id'         => $order->bling_pedido_id ?? null,
                'nfe_entrada_status'      => $order->nfe_entrada_status ?? null,
                'shopee_package_number'   => $order->shopee_package_number ?? null,
                'external_pack_id'        => $order->external_pack_id ?? null,
                'items'                   => (function () use ($order) {
                    // MUL-252F: espelha cascata marketplace_sku do OrderController@show (MUL-230/252-D) no detalhe admin
                    $rawStr = $order->raw_payload ?? null;
                    $rawItems = is_string($rawStr) ? (data_get(json_decode($rawStr, true), 'data.order.items') ?: []) : (data_get($rawStr, 'data.order.items') ?: []);
                    return $order->items->map(function ($i) use ($rawItems) {
                        $mkt = null;
                        if (!empty($i->client_kit_id)) {
                            $mkt = \DB::table('client_kits')->where('id', $i->client_kit_id)->value('sku');
                        }
                        if (!$mkt) {
                            foreach ($rawItems as $ri) {
                                if (empty($ri['marketplace_sku'])) continue;
                                if (($i->external_item_id && ($ri['external_item_id'] ?? null) == $i->external_item_id)
                                    || (!empty($i->sku) && ($ri['sku'] ?? null) === $i->sku)) { $mkt = $ri['marketplace_sku']; break; }
                            }
                        }
                        if (!$mkt && $i->external_item_id) {
                            try { $mkt = \DB::connection('legacy')->table('produtos')->where('item_id', $i->external_item_id)->value('sku'); } catch (\Throwable $e) {}
                        }
                        return [
                            'id'                 => $i->id,
                            'name'               => $i->name,
                            'sku'                => $i->sku,
                            'marketplace_sku'    => $mkt,
                            'quantity'           => $i->quantity,
                            'unit_price'         => (float) $i->unit_price,
                            'total'              => (float) $i->total,
                            'supplier_unit_cost' => $i->supplier_unit_cost ? (float) $i->supplier_unit_cost : null,
                            'supplier_total_cost'=> $i->supplier_total_cost ? (float) $i->supplier_total_cost : null,
                            'product_image'      => $i->product_image,
                            'legacy_sku_pai_id'  => $i->legacy_sku_pai_id,
                            // MUL-229: id do anúncio no marketplace (útil quando SKU foi trocado ou seller usou SKU errado)
                            'external_item_id'   => $i->external_item_id,
                            'external_variation_id' => $i->external_variation_id,
                        ];
                    })->values();
                })(),
                // INF-060: payment_details cross-DB do fornecefy (ShiPay)
                'payment_details'         => $this->getPaymentDetails($order->id, $order->tenant_slug),
            ],
        ]);
    }

    // === updateInvoice() === PATCH /api/v1/supplier-admin/orders/{id}/invoice ===
    /**
     * Permite ao fornecedor atualizar dados de NF-e de um pedido.
     * Campos: invoice_number, invoice_series, invoice_access_key, invoice_status,
     *         invoice_url, invoice_xml_url, customer_document_number, customer_document_type
     */
    public function updateInvoice(Request $request, int $id): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $order = Order::where('supplier_id', $this->supplierId())->findOrFail($id);
        $validated = $request->validate([
            'invoice_number'          => 'nullable|string|max:20',
            'invoice_series'          => 'nullable|string|max:5',
            'invoice_access_key'      => 'nullable|string|size:44',
            'invoice_status'          => 'nullable|string|max:50',
            'invoice_url'             => 'nullable|url|max:500',
            'invoice_xml_url'         => 'nullable|url|max:500',
            'customer_document_number'=> 'nullable|string|max:20',
            'customer_document_type'  => 'nullable|in:CPF,CNPJ',
        ]);
        $updates = array_filter($validated, fn($v) => $v !== null);
        if (empty($updates)) {
            return response()->json(['message' => 'Nenhum campo para atualizar.'], 422);
        }
        $order->fill($updates)->save();
        \Illuminate\Support\Facades\Log::info('[SupplierAdmin] NF-e atualizada', [
            'order_id'    => $id,
            'supplier_id' => $this->supplierId(),
            'fields'      => array_keys($updates),
        ]);
        return response()->json([
            'message' => 'Nota fiscal atualizada com sucesso.',
            'data'    => [
                'id'                      => $order->id,
                'invoice_number'          => $order->invoice_number,
                'invoice_series'          => $order->invoice_series,
                'invoice_access_key'      => $order->invoice_access_key,
                'invoice_status'          => $order->invoice_status,
                'invoice_url'             => $order->invoice_url,
                'invoice_xml_url'         => $order->invoice_xml_url,
                'customer_document_number'=> $order->customer_document_number,
                'customer_document_type'  => $order->customer_document_type,
            ],
        ]);
    }

    // === buyerDocument() === GET /api/v1/supplier-admin/orders/{id}/buyer-document ===
    /**
     * Resolve o CPF/CNPJ do comprador de um pedido.
     *
     * Logica:
     *  1. CPF ja preenchido sem mascara -> retorna do banco.
     *  2. Shopee com token -> busca buyer_tax_info via API Shopee e salva.
     *  3. ML com token -> busca /orders/{id}/billing_info e salva.
     *  4. Sem token/impossivel -> retorna resolved=false com motivo.
     */
    public function buyerDocument(Request $request, int $id): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $order = Order::where('supplier_id', $this->supplierId())
            ->with(['marketplaceAccount'])
            ->findOrFail($id);
        $existing = $order->customer_document_number;
        // 1. Ja tem CPF real (sem mascara)
        if ($existing && !str_contains($existing, '*')) {
            return response()->json([
                'resolved'                => true,
                'source'                  => 'database',
                'customer_document_number'=> $existing,
                'customer_document_type'  => $order->customer_document_type,
            ]);
        }
        $source  = strtolower($order->source ?? '');
        $account = $order->marketplaceAccount;
        // 2. Shopee com token
        if ($source === 'shopee' && $account && $account->access_token) {
            try {
                $shopeeService = app(\App\Services\Integrations\Marketplaces\ShopeeService::class);
                $detail = $shopeeService->getOrderDetail(
                    (int) $account->shop_id,
                    decrypt($account->access_token),
                    [$order->external_order_id]
                );
                $orderData = $detail['response']['order_list'][0] ?? null;
                $taxInfo   = $orderData['buyer_tax_info'] ?? null;
                if ($taxInfo && !empty($taxInfo['cpf'])) {
                    $cpf = preg_replace('/[^0-9]/', '', $taxInfo['cpf']);
                    if (strlen($cpf) === 11 || strlen($cpf) === 14) {
                        $docType = strlen($cpf) === 11 ? 'CPF' : 'CNPJ';
                        $order->customer_document_number = $cpf;
                        $order->customer_document_type   = $docType;
                        $order->save();
                        return response()->json([
                            'resolved'                => true,
                            'source'                  => 'shopee_api',
                            'customer_document_number'=> $cpf,
                            'customer_document_type'  => $docType,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[buyerDocument] Shopee API falhou', [
                    'order_id' => $id, 'error' => $e->getMessage(),
                ]);
            }
        }
        // 3. ML com token
        if (in_array($source, ['ml', 'mercadolivre']) && $account && $account->access_token) {
            try {
                $mlService = app(\App\Services\MercadoLivreService::class);
                $token     = $mlService->getValidToken($account);
                $response  = \Illuminate\Support\Facades\Http::withToken($token)
                    ->get("https://api.mercadolibre.com/orders/{$order->external_order_id}/billing_info");
                if ($response->successful()) {
                    $data      = $response->json();
                    $docNumber = $data['buyer']['doc_number'] ?? null;
                    $docType   = $data['buyer']['doc_type']   ?? null;
                    if ($docNumber && !str_contains((string)$docNumber, '*')) {
                        $clean = preg_replace('/[^0-9]/', '', $docNumber);
                        if (strlen($clean) >= 11) {
                            $resolvedType = $docType === 'CNPJ' ? 'CNPJ' : 'CPF';
                            $order->customer_document_number = $clean;
                            $order->customer_document_type   = $resolvedType;
                            $order->save();
                            return response()->json([
                                'resolved'                => true,
                                'source'                  => 'mercadolivre_api',
                                'customer_document_number'=> $clean,
                                'customer_document_type'  => $resolvedType,
                            ]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[buyerDocument] ML API falhou', [
                    'order_id' => $id, 'error' => $e->getMessage(),
                ]);
            }
        }
        // 4. Nao conseguiu resolver
        $reason = "Sem conta de marketplace conectada com token valido para este pedido ($source).";
        if ($existing && str_contains($existing, '*')) {
            $reason = 'CPF mascarado pelo marketplace. ' . $reason;
        }
        return response()->json([
            'resolved'                => false,
            'source'                  => null,
            'customer_document_number'=> null,
            'customer_document_type'  => null,
            'reason'                  => $reason,
        ]);
    }

        // === products() reformado ===
    /** GET /api/v1/supplier-admin/products — inclui media (cover) + estoque inventory */
    /**
     * MUL-281: retorna [primary, filial?] pra listagem de catalogo do admin.
     * NAO usar em outros contextos (pedidos, wallet, financeiro) — esses continuam
     * escopados por supplierId() (primary). So catalogo abre pros 2.
     */
    private function supplierIdsForCatalog(): array
    {
        $primary = $this->supplierId();
        $filial  = (int) config('multdrop.filial_supplier_id', 0);
        return $filial > 0 && $filial !== $primary ? [$primary, $filial] : [$primary];
    }

    public function products(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $perPage = (int) $request->query('per_page', 24);

        // MUL-281: filtro opcional ?supplier_id=X (aba Multdrop vs Filial).
        // Se ausente, mostra os 2 (comportamento legado + filial no mesmo painel).
        $requestedSupplier = (int) $request->query('supplier_id', 0);
        $catalogSupplierIds = $this->supplierIdsForCatalog();
        $filterSupplierIds  = $requestedSupplier > 0 && in_array($requestedSupplier, $catalogSupplierIds, true)
            ? [$requestedSupplier]
            : $catalogSupplierIds;

        $q = \App\Models\Product::whereIn('supplier_id', $filterSupplierIds)
            ->where('is_active', 1)
            ->with(['media:id,product_id,url,type,is_cover,position'])
            ->orderByDesc('id');

        if ($search = $request->query('search')) {
            $s = '%' . $search . '%';
            $q->where(function ($w) use ($s) {
                $w->where('name', 'like', $s)
                  ->orWhere('sku', 'like', $s)
                  ->orWhere('ean', 'like', $s);
            });
        }
        if (($active = $request->query('active')) !== null && $active !== '') {
            $q->where('is_active', (int) $active === 1);
        }
        if ($categoryId = (int) $request->query('category_id')) {
            $q->where('category_id', $categoryId);
        }
        if ($request->query('no_category') === '1') {
            $q->where(function($w){ $w->whereNull('category_id')->orWhere('category_id', 0); });
        }
        // MUL-222 item 15: filtro por marketplace_platform (produto tem >=1 client_products na plataforma)
        if ($mp = $request->query('marketplace_platform')) {
            $q->whereHas('clientProducts', function($w) use ($mp) {
                $w->whereHas('marketplaceAccount', fn($w2) => $w2->where('platform', $mp));
            });
        }
        // MUL-222 item 15: filtro cadastrados (listed=1) / sem cadastrar (listed=0) — >=1 client_products ou 0
        $listed = $request->query('listed');
        if ($listed === '1') {
            $q->has('clientProducts');
        } elseif ($listed === '0') {
            $q->doesntHave('clientProducts');
        }
        $stockFlt = $request->query('stock'); // in | out
        if ($stockFlt === 'in') {
            $q->whereHas('inventory', fn($w) => $w->where('quantity', '>', 0));
        } elseif ($stockFlt === 'out') {
            $q->where(function ($w) {
                $w->whereDoesntHave('inventory')
                  ->orWhereHas('inventory', function ($w2) {
                      $w2->select(\DB::raw('1'))->groupBy('product_id')
                         ->havingRaw('SUM(quantity) <= 0');
                  });
            });
        }

        $paginator = $q->paginate($perPage);

        $ids = collect($paginator->items())->pluck('id')->all();
        $stockMap = \DB::table('inventory')
            ->whereIn('product_id', $ids)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as total')
            ->pluck('total', 'product_id');

        // MUL-281: map de supplier pra badge/nome/prefixo na UI
        $supplierIds = collect($paginator->items())->pluck('supplier_id')->unique()->all();
        $supplierMap = \DB::table('suppliers')->whereIn('id', $supplierIds)
            ->get(['id','company_name','prefix'])->keyBy('id');

        $items = collect($paginator->items())->map(function ($p) use ($stockMap, $supplierMap) {
            $cover = $p->media->firstWhere('is_cover', 1) ?? $p->media->sortBy('position')->first();
            $sup   = $supplierMap->get($p->supplier_id);
            return [
                'id'                => $p->id,
                'name'              => $p->name,
                'sku'               => $p->sku,
                'gtin'              => $p->gtin,
                'ean'               => $p->ean,
                'brand'             => $p->brand,
                'price'             => (float) $p->price,
                'cost'              => $p->cost !== null ? (float) $p->cost : null,
                // MUL-281:
                'supplier_id'       => (int) $p->supplier_id,
                'supplier_name'     => $sup->company_name ?? null,
                'supplier_prefix'   => $sup->prefix ?? null,
                'description'       => $p->description,
                'is_active'         => (bool) $p->is_active,
                'stock'             => (int) ($stockMap[$p->id] ?? 0),
                'virtual_stock_qty' => (int) ($p->virtual_stock_qty ?? 0),
                'image_url'         => $cover?->url,
                'media'             => $p->media->map(fn($m) => ['id'=>$m->id,'url'=>$m->url,'is_cover'=>(bool)$m->is_cover])->values(),
                'model'             => $p->model,
                'weight_kg'         => $p->weight_kg !== null ? (float) $p->weight_kg : null,
                'height_cm'         => $p->height_cm !== null ? (float) $p->height_cm : null,
                'width_cm'          => $p->width_cm  !== null ? (float) $p->width_cm  : null,
                'length_cm'         => $p->length_cm !== null ? (float) $p->length_cm : null,
                'warranty_months'   => $p->warranty_months,
                'video_url'         => $p->video_url,
                'category_id'       => $p->category_id,
                'condition'         => $p->condition,
                'attributes'        => $p->attributes ?: null,
                'inmetro'             => $p->inmetro,
                'homologation_number' => $p->homologation_number,
                'manufacturer'        => $p->manufacturer,
                'warehouse_location' => $p->warehouse_location,
            ];
        })->values();

        $totalProducts = \DB::table('products')->where('supplier_id', $this->supplierId())->where('is_active', 1)->count();
        $semEstoque = \DB::table('products as p')
            ->leftJoin(\DB::raw('(SELECT product_id, SUM(quantity) AS total FROM inventory GROUP BY product_id) i'),
                'i.product_id', '=', 'p.id')
            ->where('p.supplier_id', $this->supplierId())
            ->where('p.is_active', 1)
            ->where(function($w) { $w->whereNull('i.total')->orWhere('i.total', '<=', 0); })
            ->count();

        return response()->json([
            'data'     => $items,
            'counters' => ['total' => $totalProducts, 'sem_estoque' => $semEstoque],
            'meta'     => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    /** DELETE /api/v1/supplier-admin/products/{id} */
    public function deleteProduct(Request $request, int $id): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $p = \App\Models\Product::whereIn('supplier_id', $this->supplierIdsForCatalog())->find($id);
        if (!$p) return response()->json(['error' => 'Produto nao encontrado'], 404);

        // Bloqueia se ja tem historico de pedido ou envio (preserva auditoria)
        $hasOrders = \DB::table('order_items')->where('product_id', $p->id)->exists();
        $hasShipments = \DB::table('shipment_items')->where('product_id', $p->id)->exists();
        if ($hasOrders || $hasShipments) {
            return response()->json([
                'error' => 'Produto tem pedidos vinculados, nao pode ser excluido. Desative em vez de excluir.',
            ], 422);
        }

        // Limpa dependentes em cascata (FKs sem ON DELETE CASCADE)
        \DB::transaction(function () use ($p) {
            \DB::table('inventory')->where('product_id', $p->id)->delete();
            \DB::table('product_media')->where('product_id', $p->id)->delete();
            \DB::table('product_variations')->where('product_id', $p->id)->delete();
            \DB::table('product_kits')
                ->where('product_id', $p->id)
                ->orWhere('child_product_id', $p->id)
                ->delete();
            \DB::table('client_products')->where('product_id', $p->id)->delete();
            \DB::table('auto_listing_queue_items')->where('product_id', $p->id)->delete();
            // ProductObserver::deleted dispara SyncProductToLegacy com action=delete
            $p->delete();
        });

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** POST /api/v1/supplier-admin/products */
    public function createProduct(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $data = $request->validate([
            'name'            => 'required|string|max:300',
            'sku'             => 'required|string|max:120',
            'price'           => 'required|numeric|min:0',
            'cost'            => 'nullable|numeric|min:0',
            'brand'           => 'nullable|string|max:120',
            'model'           => 'nullable|string|max:120',
            'description'     => 'nullable|string',
            'gtin'            => 'nullable|string|max:50',
            'ean'             => 'nullable|string|max:50',
            'condition'       => 'nullable|string|in:new,used,refurbished',
            'category_id'     => 'nullable|integer',
            'warehouse_location' => 'nullable|string|max:255',
            'weight_kg'       => 'nullable|numeric|min:0',
            'height_cm'       => 'nullable|numeric|min:0',
            'width_cm'        => 'nullable|numeric|min:0',
            'length_cm'       => 'nullable|numeric|min:0',
            'warranty_months' => 'nullable|integer|min:0',
            'video_url'       => 'nullable|url',
            'ncm'             => 'nullable|string|max:20',
            'origem'          => 'nullable|string|max:10',
            'inmetro'         => 'nullable|string|max:100',
            'homologation_number' => 'nullable|string|max:100',
            'manufacturer'    => 'nullable|string|max:150',
            'image_url'       => 'nullable|url',
            'images'          => 'nullable|array',
            'images.*.url'    => 'required_with:images|url',
            'images.*.is_cover' => 'nullable|boolean',
            'images.*.position' => 'nullable|integer',
            'stock'           => 'nullable|integer|min:0',
        ]);

        if (\App\Models\Product::where('supplier_id', $this->supplierId())
            ->where('sku', $data['sku'])->exists()) {
            return response()->json(['error' => 'SKU ja cadastrado para este fornecedor'], 409);
        }

        $ncm    = $data['ncm']    ?? null; unset($data['ncm']);
        $origem = $data['origem'] ?? null; unset($data['origem']);
        $images = $data['images'] ?? null; unset($data['images']);
        $legacyImg = $data['image_url'] ?? null; unset($data['image_url']);

        $attrs = [];
        if ($ncm !== null && $ncm !== '')    $attrs['ncm'] = $ncm;
        if ($origem !== null && $origem !== '') $attrs['origem'] = $origem;
        if ($attrs) $data['attributes'] = $attrs;

        $stockQty = $data['stock'] ?? null;
        unset($data['stock']);

        $p = \App\Models\Product::create(array_merge($data, [
            'supplier_id' => $this->supplierId(),
            'is_active'   => true,
        ]));

        if ($stockQty !== null) {
            $this->upsertStock($p->id, (int) $stockQty);
        }

        $this->syncProductImages($p->id, $images, $legacyImg, /* replace */ false);

        return response()->json(['data' => ['id' => $p->id, 'sku' => $p->sku]]);
    }

    /** PUT /api/v1/supplier-admin/products/{id} */
    public function updateProduct(Request $request, int $id): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $p = \App\Models\Product::whereIn('supplier_id', $this->supplierIdsForCatalog())->find($id);
        if (!$p) return response()->json(['error' => 'Produto nao encontrado'], 404);

        $data = $request->validate([
            'name'            => 'sometimes|string|max:300',
            'sku'             => 'sometimes|string|max:120',
            'service_sku'     => 'sometimes|nullable|string|max:100',
            'price'           => 'sometimes|numeric|min:0',
            'cost'            => 'sometimes|nullable|numeric|min:0',
            'brand'           => 'sometimes|nullable|string|max:120',
            'model'           => 'sometimes|nullable|string|max:120',
            'description'     => 'sometimes|nullable|string',
            'gtin'            => 'sometimes|nullable|string|max:50',
            'ean'             => 'sometimes|nullable|string|max:50',
            'condition'       => 'sometimes|nullable|string|in:new,used,refurbished',
            'category_id'     => 'sometimes|nullable|integer',
            'warehouse_location' => 'sometimes|nullable|string|max:255',
            'weight_kg'       => 'sometimes|nullable|numeric|min:0',
            'height_cm'       => 'sometimes|nullable|numeric|min:0',
            'width_cm'        => 'sometimes|nullable|numeric|min:0',
            'length_cm'       => 'sometimes|nullable|numeric|min:0',
            'warranty_months' => 'sometimes|nullable|integer|min:0',
            'video_url'       => 'sometimes|nullable|url',
            'ncm'             => 'sometimes|nullable|string|max:20',
            'origem'          => 'sometimes|nullable|string|max:10',
            'inmetro'         => 'sometimes|nullable|string|max:100',
            'homologation_number' => 'sometimes|nullable|string|max:100',
            'manufacturer'    => 'sometimes|nullable|string|max:150',
            'is_active'       => 'sometimes|boolean',
            'image_url'       => 'sometimes|nullable|url',
            'images'          => 'sometimes|nullable|array',
            'images.*.url'    => 'required_with:images|url',
            'images.*.is_cover' => 'nullable|boolean',
            'images.*.position' => 'nullable|integer',
            'stock'           => 'sometimes|nullable|integer|min:0',
        ]);

        $hasNcm    = array_key_exists('ncm', $data);
        $hasOrigem = array_key_exists('origem', $data);
        $ncm    = $data['ncm']    ?? null; unset($data['ncm']);
        $origem = $data['origem'] ?? null; unset($data['origem']);
        $hasImages = array_key_exists('images', $data);
        $images = $data['images'] ?? null; unset($data['images']);
        $legacyImg = $data['image_url'] ?? null; unset($data['image_url']);

        if ($hasNcm || $hasOrigem) {
            $attrs = is_array($p->attributes) ? $p->attributes : [];
            if ($hasNcm) {
                if ($ncm !== null && $ncm !== '') $attrs['ncm'] = $ncm;
                else unset($attrs['ncm']);
            }
            if ($hasOrigem) {
                if ($origem !== null && $origem !== '') $attrs['origem'] = $origem;
                else unset($attrs['origem']);
            }
            $data['attributes'] = $attrs ?: null;
        }

        $hasStock = array_key_exists('stock', $data);
        $stockQty = $data['stock'] ?? null;
        unset($data['stock']);

        $p->fill($data)->save();

        if ($hasStock && $stockQty !== null) {
            $this->upsertStock($p->id, (int) $stockQty);
        }

        if ($hasImages) {
            $this->syncProductImages($p->id, $images, $legacyImg, /* replace */ true);
        } elseif ($legacyImg) {
            $this->syncProductImages($p->id, null, $legacyImg, /* replace */ false);
        }

        return response()->json(['data' => ['id' => $p->id]]);
    }

    /**
     * Sincroniza imagens do produto.
     *  - $replace=true: apaga todas as anteriores e regrava
     *  - $replace=false: apenda
     *  - $legacyImg: aceita campo image_url legado (1 url) caso $images seja vazio
     */
    private function syncProductImages(int $productId, ?array $images, ?string $legacyImg, bool $replace): void
    {
        if ($replace) {
            \DB::table('product_media')->where('product_id', $productId)->delete();
        }

        $list = is_array($images) ? $images : [];
        if (!$list && $legacyImg) {
            $list = [['url' => $legacyImg, 'is_cover' => 1, 'position' => 0]];
        }
        if (!$list) return;

        // FOR-007: baixa pro storage local antes de gravar
        $supplierId = (int) (\DB::table('products')->where('id', $productId)->value('supplier_id') ?? 0);
        $imageSvc   = app(\App\Services\ImageDownloadService::class);

        $rows = [];
        foreach ($list as $idx => $img) {
            if (empty($img['url'])) continue;
            $mat = $imageSvc->ensureLocal((string) $img['url'], $supplierId, $productId);
            $rows[] = [
                'product_id'   => $productId,
                'url'          => $mat['url'],
                'original_url' => $mat['local'] ? (string) $img['url'] : null,
                'local_path'   => $mat['path'],
                'type'         => 'image',
                'is_cover'     => !empty($img['is_cover']) ? 1 : 0,
                'position'     => $img['position'] ?? $idx,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }
        if (!$rows) return;

        // garantir 1 cover (se ninguem marcou, primeira da lista vira cover)
        $hasCover = collect($rows)->contains(fn($r) => $r['is_cover'] === 1);
        if (!$hasCover) $rows[0]['is_cover'] = 1;

        // se replace=false e ja tinha cover, desmarcar cover das novas e manter a existente
        if (!$replace) {
            $existingCoverCount = \DB::table('product_media')
                ->where('product_id', $productId)->where('is_cover', 1)->count();
            if ($existingCoverCount > 0) {
                foreach ($rows as &$r) $r['is_cover'] = 0;
                unset($r);
            }
        }

        \DB::table('product_media')->insert($rows);
    }

    /** POST /api/v1/supplier-admin/products/import  multipart {file} */
    public function importProducts(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $request->validate(['file' => 'required|file|mimes:csv,xlsx,xls,txt']);
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());

        $rows = [];
        if (in_array($ext, ['csv', 'txt'])) {
            if (($h = fopen($file->getRealPath(), 'r')) !== false) {
                $header = null;
                while (($row = fgetcsv($h, 0, ',')) !== false) {
                    if (!$header) { $header = array_map('trim', $row); continue; }
                    $rows[] = array_combine($header, array_pad($row, count($header), null));
                }
                fclose($h);
            }
        } else {
            try {
                $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file->getRealPath());
                $reader->setReadDataOnly(true);
                $sheet = $reader->load($file->getRealPath())->getActiveSheet()->toArray();
                $header = array_map('trim', array_shift($sheet) ?: []);
                foreach ($sheet as $row) $rows[] = array_combine($header, array_pad($row, count($header), null));
            } catch (\Throwable $e) {
                return response()->json(['error' => 'Falha ao ler XLSX: ' . $e->getMessage()], 422);
            }
        }

        $created = 0; $updated = 0; $skipped = 0; $errors = [];
        foreach ($rows as $i => $r) {
            $sku  = trim((string) ($r['sku']   ?? $r['SKU']   ?? ''));
            $name = trim((string) ($r['name']  ?? $r['nome']  ?? $r['NAME'] ?? ''));
            if ($sku === '' || $name === '') { $skipped++; continue; }
            $payload = [
                'name'        => $name,
                'price'       => (float) str_replace(',', '.', (string) ($r['price'] ?? $r['preco'] ?? 0)),
                'cost'        => $r['cost'] ?? $r['custo'] ?? null,
                'brand'       => $r['brand'] ?? $r['marca'] ?? null,
                'description' => $r['description'] ?? $r['descricao'] ?? null,
                'gtin'        => $r['gtin'] ?? null,
                'ean'         => $r['ean'] ?? null,
            ];
            if ($payload['cost'] !== null) $payload['cost'] = (float) str_replace(',', '.', (string) $payload['cost']);

            $existing = \App\Models\Product::where('supplier_id', $this->supplierId())->where('sku', $sku)->first();
            try {
                if ($existing) {
                    $existing->fill($payload)->save();
                    $updated++;
                } else {
                    \App\Models\Product::create(array_merge($payload, [
                        'supplier_id' => $this->supplierId(), 'sku' => $sku, 'is_active' => true,
                    ]));
                    $created++;
                }
                $imageUrl = trim((string) ($r['image_url'] ?? $r['imagem'] ?? $r['foto'] ?? ''));
                if ($imageUrl) {
                    $p = $existing ?: \App\Models\Product::where('supplier_id', $this->supplierId())->where('sku', $sku)->first();
                    if ($p) {
                        // FOR-007: baixa pro storage local
                        $mat = app(\App\Services\ImageDownloadService::class)
                            ->ensureLocal($imageUrl, (int) $this->supplierId(), (int) $p->id);
                        \DB::table('product_media')->where('product_id', $p->id)->update(['is_cover' => 0]);
                        \DB::table('product_media')->insert([
                            'product_id'   => $p->id,
                            'url'          => $mat['url'],
                            'original_url' => $mat['local'] ? $imageUrl : null,
                            'local_path'   => $mat['path'],
                            'type'         => 'image',
                            'is_cover'     => 1,
                            'position'     => 0,
                            'created_at'   => now(),
                            'updated_at'   => now(),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = "linha " . ($i+2) . " ($sku): " . $e->getMessage();
            }
        }

        return response()->json(['data' => [
            'created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors,
        ]]);
    }


    // =========================================================================
    // PICKING / PACKING (scanner por rastreio)
    // =========================================================================

    /** GET /api/v1/supplier-admin/picking/queue */
    // MUL-245 — picking 100% nativo (tabela orders). Legado/bridge removidos das
    // leituras: fora do ar no incidente K3s e inexistente pros WLs novos (JT-Drop).
    private function nativeCanalId(?string $source): int
    {
        return match (strtolower((string) $source)) {
            'shopee'                                     => 3,
            'ml', 'meli', 'mercadolivre', 'mercado_livre' => 6,
            'magalu', 'magazineluiza'                    => 7,
            'tiktok', 'tiktok_shop'                      => 13,
            'amazon'                                     => 10,
            'bling'                                      => 20,
            default                                      => 0,
        };
    }

    private function nativeCanalLabel(?string $source): string
    {
        return match ($this->nativeCanalId($source)) {
            3       => 'Shopee',
            6       => 'ML',
            7       => 'Magalu',
            13      => 'TikTok',
            10      => 'Amazon',
            20      => 'Bling',
            default => $source ? ucfirst($source) : 'Outro',
        };
    }

    /** Query base dos pedidos elegíveis pra fila de picking (nativo). */
    private function nativePickingEligibleQuery(int $supplierId)
    {
        // MUL-378: mesma definicao das telas do admin (Order::scopeReadyToShip), que
        // ainda acrescenta whereNull('blocked_at'). Aqui somam-se dois cortes proprios
        // do painel do fornecedor: a janela de 2026 (MUL-245) e o pago ao fornecedor.
        return Order::query()
            ->readyToShip()
            ->where('orders.supplier_id', $supplierId)
            ->where('orders.paid_at', '>=', '2026-01-01')
            // MUL-255: picking so libera pedido ja pago ao fornecedor (wallet/PIX/externo)
            ->whereNotNull('orders.wallet_paid_at');
    }

    /** Itens por pedido em batch: [order_id => [{descricao,foto,sku,qtd}...]] */
    private function nativePickingItems(array $orderIds): array
    {
        if (empty($orderIds)) return [];
        $rows = DB::table('order_items')
            ->whereIn('order_id', $orderIds)
            ->orderBy('id')
            ->get(['order_id', 'sku', 'name', 'quantity', 'product_image']);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->order_id][] = [
                'descricao' => $r->name,
                'foto'      => $r->product_image,
                'sku'       => $r->sku,
                'qtd'       => (int) ($r->quantity ?? 1),
            ];
        }
        return $out;
    }

    /** Primeiro EAN por pedido em batch: [order_id => ean] */
    private function nativePickingEans(array $orderIds): array
    {
        if (empty($orderIds)) return [];
        $rows = DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->whereIn('oi.order_id', $orderIds)
            ->whereNotNull('p.ean')->where('p.ean', '!=', '')
            ->orderBy('oi.id')
            ->get(['oi.order_id', 'p.ean']);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->order_id] ??= $r->ean;
        }
        return $out;
    }

    /** "Rua X, 45, compl, Cidade, Estado, 20771445" → partes do endereço. */
    private function parseCustomerAddress(?string $addr): array
    {
        $addr = trim((string) $addr);
        if ($addr === '') {
            return ['endereco_completo' => null, 'cidade' => null, 'estado' => null, 'cep' => null];
        }
        $parts  = array_map('trim', explode(',', $addr));
        $cep    = null;
        $estado = null;
        $cidade = null;
        $last   = preg_replace('/\D/', '', (string) end($parts));
        if (strlen($last) >= 7 && strlen($last) <= 8) {
            $cep = $last;
            array_pop($parts);
        }
        if (count($parts) >= 2) {
            $estado = array_pop($parts) ?: null;
            $cidade = array_pop($parts) ?: null;
        }
        return ['endereco_completo' => $addr, 'cidade' => $cidade, 'estado' => $estado, 'cep' => $cep];
    }

    /** Monta o item de picking (shape idêntico ao antigo legado+supplement). */
    private function nativePickingRow($o, array $itens, ?string $ean = null): array
    {
        $first = $itens[0] ?? null;
        $qtd   = 0;
        foreach ($itens as $it) $qtd += (int) ($it['qtd'] ?? 1);
        return [
            'id'                => (int) $o->id,
            'order_id'          => (int) $o->id,
            'legacy_id'         => $o->legacy_id ? (int) $o->legacy_id : null,
            'codigo_pedido'     => $o->order_number,
            'tracking_number'   => $o->tracking_number,
            'id_canal'          => $this->nativeCanalId($o->source),
            'cliente_nome'      => $o->customer_name,
            'valor_total'       => $o->total,
            'paid_at'           => $o->paid_at ? (string) $o->paid_at : null,
            'label_printed_at'  => $o->label_printed_at ? (string) $o->label_printed_at : null,
            'shipped_at'        => $o->shipped_at ? (string) $o->shipped_at : null,
            'label_url'         => $this->absoluteLabelUrl($o->label_url),
            'data_pedido'       => $o->created_at ? (string) $o->created_at : null,
            'data_pedido_canal' => $o->marketplace_created_at ? (string) $o->marketplace_created_at : null,
            'qtd'               => $qtd > 0 ? $qtd : 1,
            'nome_produto'      => $first['descricao'] ?? null,
            'url_foto'          => $first['foto'] ?? null,
            'itens'             => $itens,
            'carrier_name'      => $o->carrier_name,
            'external_order_id' => $o->external_order_id,
            'fulfillment_type'  => $o->delivery_type,
            'channel_name'      => $o->channel_name ?: $o->source,
            'store_name'        => $o->store_name_join ?? null,
            'seller_name'       => $o->seller_name_join ?? null,
            // MUL-378: o painel do fornecedor traduz este campo por um mapa proprio, e o
            // vocabulario dele nao tem 'awaiting_dispatch' — cairia no fallback e a tela
            // mostraria "awaiting dispatch" em ingles cru. 'awaiting_shipment' existe la
            // como "Aguardando Envio", que e exatamente o estado: etiqueta pronta,
            // esperando despacho. Quando o front aprender awaiting_dispatch, isto sai.
            'canonical_status'  => $o->order_processing_status === 'awaiting_dispatch'
                ? 'awaiting_shipment'
                : $o->order_processing_status,
            'ean'               => $ean,
        ];
    }

    public function pickingQueue(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $limit = min((int) $request->query('limit', 50), 200);
        $filterCanal   = $request->query('canal');
        $filterSku     = $request->query('sku');
        $filterStore   = $request->query('store');
        $filterUrgency = $request->query('urgency');

        // MUL-245: fila 100% nativa (tabela orders) — sem banco legado
        // MUL-269 fase 2: seller_name vem do user conectado (clients.company_name removido).
        $orders = $this->nativePickingEligibleQuery($this->supplierId())
            ->leftJoin('tenants as t', 't.slug', '=', 'orders.tenant_slug')
            ->leftJoin('clients as c', 'c.id', '=', 'orders.client_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->orderByDesc('orders.id')
            ->limit($limit)
            ->get(['orders.*', 't.name as store_name_join', DB::raw("COALESCE(NULLIF(u.full_name,''),u.name) as seller_name_join")]);

        $orderIds     = $orders->pluck('id')->all();
        $itemsByOrder = $this->nativePickingItems($orderIds);
        $eansByOrder  = $this->nativePickingEans($orderIds);

        $queue = [];
        foreach ($orders as $o) {
            $queue[] = $this->nativePickingRow(
                $o,
                $itemsByOrder[(int) $o->id] ?? [],
                $eansByOrder[(int) $o->id] ?? null
            );
        }

        // Etiqueta ainda hospedada no legado → baixar em background pro storage nativo
        foreach ($queue as &$item) {
            if (!empty($item['label_url']) && str_contains($item['label_url'], 'sistemagrupoonline')) {
                FetchShippingLabelJob::dispatch((int) $item['id'], 'picking')->onQueue('default');
                $item['label_downloading'] = true;
            }
        }
        unset($item);

        // MUL-044: calcular dispatch_deadline e urgency por marketplace
        foreach ($queue as &$item) {
            $paidAt  = $item['paid_at'] ?? null;
            $idCanal = (int)($item['id_canal'] ?? 0);
            if ($paidAt) {
                $paidDate = \Carbon\Carbon::parse($paidAt);
                $slaDays  = match($idCanal) {
                    3       => 2,  // Shopee
                    6, 1    => 3,  // Mercado Livre
                    default => 3,
                };
                $deadline = $paidDate->copy()->addDays($slaDays);
                $item['dispatch_deadline'] = $deadline->toIso8601String();
                $now = \Carbon\Carbon::now();
                if ($deadline->isPast()) {
                    $item['urgency'] = 'overdue';
                } elseif ($deadline->isToday()) {
                    $item['urgency'] = 'today';
                } elseif ($deadline->isTomorrow()) {
                    $item['urgency'] = 'tomorrow';
                } else {
                    $item['urgency'] = 'ok';
                }
            } else {
                $item['dispatch_deadline'] = null;
                $item['urgency'] = 'ok';
            }
        }
        unset($item);

        // MUL-044: filtros em PHP pos-processamento
        if ($filterCanal) {
            $queue = array_filter($queue, fn($i) => (string)($i['id_canal'] ?? '') === (string)$filterCanal);
        }
        if ($filterStore) {
            $queue = array_filter($queue, fn($i) => stripos($i['store_name'] ?? '', $filterStore) !== false);
        }
        if ($filterUrgency) {
            $queue = array_filter($queue, fn($i) => ($i['urgency'] ?? '') === $filterUrgency);
        }
        if ($filterSku) {
            $queue = array_filter($queue, fn($i) =>
                stripos($i['nome_produto'] ?? '', $filterSku) !== false ||
                stripos($i['codigo_pedido'] ?? '', $filterSku) !== false
            );
        }
        $queue = array_values($queue);

        // MUL-379: 'count' sempre foi o tamanho DESTA pagina (apos limit e filtros),
        // e a tela mostrava esse numero como se fosse o total — dai a divergencia que o
        // Ruan viu: painel do fornecedor 57 x telas do admin 86. Os 29 de diferenca sao
        // pedidos com etiqueta que o lojista ainda nao pagou, barrados pelo corte da
        // MUL-255 (fornecedor nao despacha antes de receber). Agora a fila diz os tres
        // numeros e a tela pode explicar a diferenca em vez de esconde-la.
        $baseFila = fn () => Order::query()->readyToShip()
            ->where('orders.supplier_id', $this->supplierId())
            ->where('orders.paid_at', '>=', '2026-01-01');

        return response()->json(['data' => [
            'queue' => $queue,
            'count' => count($queue),
            // total elegivel (sem o limit da pagina nem os filtros de tela)
            'total' => (clone $baseFila())->whereNotNull('orders.wallet_paid_at')->count(),
            // trabalho que existe mas esta travado esperando o lojista pagar
            'aguardando_pagamento' => (clone $baseFila())->whereNull('orders.wallet_paid_at')->count(),
            'err' => null,
        ]]);
    }

    /** GET /api/v1/supplier-admin/picking/separacao — Lista de separacao agrupada por SKU */
    public function pickingSeparacao(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);

        // MUL-245: nativo (tabela orders), sem legado.
        $orders = $this->nativePickingEligibleQuery($this->supplierId())
            ->orderByDesc('orders.id')
            ->get(['orders.id', 'orders.order_number', 'orders.source', 'orders.label_url']);

        if ($orders->isEmpty()) {
            return response()->json(['data' => [
                'items'         => [],
                'total_pedidos' => 0,
                'total_itens'   => 0,
                'gerado_em'     => now()->toIso8601String(),
            ]]);
        }

        $orderMap = [];
        foreach ($orders as $o) {
            $orderMap[(int)$o->id] = $o;
        }

        $itemsByOrder = $this->nativePickingItems(array_keys($orderMap));

        $bySkus = [];
        foreach ($itemsByOrder as $orderId => $itens) {
            $o = $orderMap[$orderId] ?? null;
            if (!$o) continue;
            foreach ($itens as $item) {
                $sku = $item['sku'] ?? '';
                $key = $sku !== '' && $sku !== null ? $sku : ('NOSKU_' . md5((string)($item['descricao'] ?? '')));
                if (!isset($bySkus[$key])) {
                    $bySkus[$key] = [
                        'sku'          => $sku ?? '',
                        'nome_produto' => $item['descricao'] ?? 'Sem nome',
                        'nome'         => $item['descricao'] ?? 'Sem nome',  // NOV-112 B3
                        'url_foto'     => $item['foto'] ?? null,
                        'imagem'       => $item['foto'] ?? null,             // NOV-112 B3
                        'qtd_total'    => 0,
                        'pedidos'      => [],
                    ];
                }
                $qtd = (int)($item['qtd'] ?? 1);
                $bySkus[$key]['qtd_total'] += $qtd;
                $bySkus[$key]['pedidos'][] = [
                    'id'            => $orderId,
                    'codigo_pedido' => $o->order_number,
                    'canal_label'   => $this->nativeCanalLabel($o->source),
                    'qtd'           => $qtd,
                    'label_url'     => $o->label_url ? $this->absoluteLabelUrl($o->label_url) : null,
                ];
            }
        }

        usort($bySkus, fn($a, $b) => $b['qtd_total'] <=> $a['qtd_total']);

        return response()->json(['data' => [
            'items'         => array_values($bySkus),
            'total_pedidos' => count($orderMap),
            'total_itens'   => array_sum(array_column(array_values($bySkus), 'qtd_total')),
            'gerado_em'     => now()->toIso8601String(),
        ]]);
    }

    /** GET /api/v1/supplier-admin/picking/lookup?tracking=... */
    public function pickingLookup(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $tracking = trim((string) $request->query('tracking', ''));
        if ($tracking === '') return response()->json(['error' => 'tracking obrigatorio'], 422);

        // MUL-245: lookup nativo (tabela orders), sem bridge/legado.
        $supplierId = $this->supplierId();

        $order = Order::query()
            ->where('orders.supplier_id', $supplierId)
            ->where('orders.is_draft', false)
            ->where(function ($q) use ($tracking) {
                $q->where('orders.tracking_number', $tracking)
                  ->orWhere('orders.order_number', $tracking)
                  ->orWhere('orders.external_order_id', $tracking);
            })
            // MUL-269 fase 2: seller_name vem do user conectado (clients.company_name removido).
            ->leftJoin('tenants as t', 't.slug', '=', 'orders.tenant_slug')
            ->leftJoin('clients as c', 'c.id', '=', 'orders.client_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->orderByDesc('orders.id')
            ->first(['orders.*', 't.name as store_name_join', DB::raw("COALESCE(NULLIF(u.full_name,''),u.name) as seller_name_join")]);

        if (!$order) {
            $devolucao = Order::query()
                ->where('supplier_id', $supplierId)
                ->where('return_code', $tracking)
                ->orderByDesc('id')
                ->first();
            if ($devolucao) {
                return response()->json(['data' => [
                    'mode'      => 'devolucao',
                    'devolucao' => [
                        'id'               => (int) $devolucao->id,
                        'motivo'           => $devolucao->return_reason,
                        'status'           => $devolucao->return_status,
                        'valor_pedido'     => $devolucao->total,
                        'data_solicitacao' => $devolucao->returned_at,
                    ],
                ]]);
            }
            return response()->json(['error' => 'Pedido nao encontrado'], 404);
        }

        $orderId = (int) $order->id;
        $itens   = $this->nativePickingItems([$orderId]);
        $eans    = $this->nativePickingEans([$orderId]);
        $pedido  = $this->nativePickingRow($order, $itens[$orderId] ?? [], $eans[$orderId] ?? null);
        $pedido  = array_merge($pedido, $this->parseCustomerAddress($order->customer_address));

        $mode = $order->shipped_at ? 'ja_enviado' : 'despacho';

        return response()->json(['data' => ['mode' => $mode, 'pedido' => $pedido]]);
    }

    /** GET /api/v1/supplier-admin/picking/lookup_cep?cep=NNNNNNN[&limit=N] */
    public function pickingLookupCep(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $cep   = preg_replace('/\D/', '', (string) $request->query('cep', ''));
        $limit = max(1, min(50, (int) $request->query('limit', 20)));
        if (strlen($cep) < 5) return response()->json(['error' => 'cep invalido'], 422);

        // MUL-245: busca nativa por CEP no customer_address (tabela orders), sem bridge/legado.
        // MUL-269 fase 2: seller_name vem do user conectado (clients.company_name removido).
        $orders = $this->nativePickingEligibleQuery($this->supplierId())
            ->where('orders.customer_address', 'like', '%' . $cep . '%')
            ->leftJoin('tenants as t', 't.slug', '=', 'orders.tenant_slug')
            ->leftJoin('clients as c', 'c.id', '=', 'orders.client_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->orderByDesc('orders.id')
            ->limit($limit)
            ->get(['orders.*', 't.name as store_name_join', DB::raw("COALESCE(NULLIF(u.full_name,''),u.name) as seller_name_join")]);

        if ($orders->isEmpty()) {
            return response()->json(['error' => 'Nenhum pedido encontrado para este CEP'], 404);
        }

        $orderIds = $orders->pluck('id')->map(fn($v) => (int) $v)->all();
        $itemsMap = $this->nativePickingItems($orderIds);
        $eansMap  = $this->nativePickingEans($orderIds);

        $pedidos = [];
        foreach ($orders as $o) {
            $oid = (int) $o->id;
            $row = $this->nativePickingRow($o, $itemsMap[$oid] ?? [], $eansMap[$oid] ?? null);
            $pedidos[] = array_merge($row, $this->parseCustomerAddress($o->customer_address));
        }

        return response()->json(['data' => ['pedidos' => $pedidos]]);
    }

    /** POST /api/v1/supplier-admin/picking/ship  { id_pedido } */
    public function pickingShip(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $idPedido = (int) $request->input('id_pedido');
        if ($idPedido <= 0) return response()->json(['error' => 'id_pedido obrigatorio'], 422);

        // MES-046-B: busca o pedido localmente (por legacy_id OU por id direto)
        // para atualizar o banco novo independente do legado.
        $supplierId = $this->supplierId();
        $order = Order::where('supplier_id', $supplierId)
            ->where(function ($q) use ($idPedido) {
                $q->where('legacy_id', $idPedido)
                  ->orWhere('id', $idPedido);
            })
            ->first();

        // FOR-037: guard de pagamento - bloqueia envio sem PIX confirmado
        if ($order) {
            $this->assertPaymentConfirmed($order);
        }

        // MES-046-B: atualiza o banco local PRIMEIRO (nativo, sem depender do legado)
        $fromStatus = null;
        if ($order) {
            $fromStatus = $order->order_processing_status;
            $order->update([
                'order_processing_status' => 'shipped',
                'shipped_at'              => now()->toDateTimeString(),
            ]);

            // Gravar historico auditavel (MES-046-B)
            OrderStatusHistory::record(
                $order,
                'order_processing_status',
                $fromStatus,
                'shipped',
                'bip',
                ['legacy_id' => $idPedido, 'trigger' => 'pickingShip'],
                $request->user() ? (string) $request->user()->id : null,
                'supplier'
            );
        }

        // MES-046-B: relay para o legado como best-effort (nao bloqueia se falhar).
        // Se LEGACY_SYNC_ENABLED=false, pula relay (legado em desligamento).
        $legacyRelayed = false;
        if (env('LEGACY_SYNC_ENABLED', true)) {
            try {
                $res = $this->bridge->pickingAction('ship', $idPedido);
                $legacyRelayed = (bool) ($res['success'] ?? false);
                if (!$legacyRelayed) {
                    Log::warning('[MES-046-B] Relay bip para legado falhou (best-effort)', [
                        'id_pedido' => $idPedido,
                        'error'     => $res['error'] ?? 'unknown',
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('[MES-046-B] Excecao no relay bip para legado (best-effort)', [
                    'id_pedido' => $idPedido,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        // Se encontramos o pedido no banco novo, resposta vem do banco novo
        if ($order) {
            return response()->json([
                'data' => [
                    'order_id'      => $order->id,
                    'legacy_id'     => $order->legacy_id,
                    'status'        => $order->order_processing_status,
                    'shipped_at'    => $order->shipped_at,
                    'legacy_synced' => $legacyRelayed,
                ],
            ]);
        }

        // Fallback: pedido so existe no legado (pre-migracao) - usa bridge
        if (env('LEGACY_SYNC_ENABLED', true)) {
            try {
                $res = $this->bridge->pickingAction('ship', $idPedido);
                return response()->json($res['success'] ? ['data' => $res['data']] : ['error' => $res['error']], $res['success'] ? 200 : 502);
            } catch (\Throwable $e) {
                return response()->json(['error' => 'Pedido nao encontrado no novo sistema e legado indisponivel'], 502);
            }
        }

        return response()->json(['error' => 'Pedido nao encontrado no novo sistema'], 404);
    }

    /** POST /api/v1/supplier-admin/picking/skip */
    public function pickingSkip(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $idPedido = (int) $request->input('id_pedido');
        if ($idPedido <= 0) return response()->json(['error' => 'id_pedido obrigatorio'], 422);
        $res = $this->bridge->pickingAction('skip', $idPedido);
        return response()->json($res['success'] ? ['data' => $res['data']] : ['error' => $res['error']], $res['success'] ? 200 : 502);
    }

    /** POST /api/v1/supplier-admin/picking/problema  { id_pedido, motivo } */
    public function pickingProblema(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $idPedido = (int) $request->input('id_pedido');
        $motivo   = trim((string) $request->input('motivo', ''));
        if ($idPedido <= 0 || $motivo === '') {
            return response()->json(['error' => 'id_pedido e motivo obrigatorios'], 422);
        }
        $res = $this->bridge->pickingAction('problema', $idPedido, ['motivo' => $motivo]);
        if (!$res['success']) return response()->json(['error' => $res['error']], 502);

        // Cria ticket SAC para o seller dono do pedido
        try {
            $data = $res['data'] ?? [];
            // tentar achar order do banco novo pelo legacy_id
            $order = Order::where('legacy_id', $idPedido)->first();
            if ($order && $order->client_id) {
                \App\Models\SupportTicket::create([
                    'user_id'  => $order->client_id,
                    'subject'  => 'Problema no despacho do pedido #' . ($data['codigo'] ?? $idPedido),
                    'message'  => "O fornecedor reportou um problema ao bipar este pedido:\n\n" . $motivo,
                    'status'   => 'new',
                    'priority' => 'high',
                    'category' => 'order_problem',
                    'metadata' => json_encode([
                        'order_id'    => $order->id,
                        'legacy_id'   => $idPedido,
                        'problema_id' => $data['problema_id'] ?? null,
                    ]),
                ]);
            }
        } catch (\Throwable $e) {
            \Log::warning("Falhou criar ticket pra problema picking: " . $e->getMessage());
        }

        return response()->json(['data' => $res['data']]);
    }

    // =========================================================================
    // DEVOLUCOES (estorno conta_corrente_white)
    // =========================================================================

    /** GET /api/v1/supplier-admin/returns/queue */
    public function returnsQueue(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $limit = (int) $request->query('limit', 20);
        $res = $this->bridge->getDevolucoes($this->legacyLojaId($request), $limit);
        if (!$res['success']) return response()->json(['error' => $res['error']], 502);
        return response()->json(['data' => $res['data']]);
    }

    /** POST /api/v1/supplier-admin/returns/{id}/approve  { obs } */
    public function approveReturn(Request $request, int $id): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $obs = trim((string) $request->input('obs', ''));
        $res = $this->bridge->respondDevolucao('aprovar', $id, $obs);
        if (!$res['success']) return response()->json(['error' => $res['error']], 502);
        return response()->json(['data' => $res['data']]);
    }

    /** POST /api/v1/supplier-admin/returns/{id}/reject  { obs } */
    public function rejectReturn(Request $request, int $id): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $obs = trim((string) $request->input('obs', ''));
        $res = $this->bridge->respondDevolucao('reprovar', $id, $obs);
        if (!$res['success']) return response()->json(['error' => $res['error']], 502);
        return response()->json(['data' => $res['data']]);
    }

    // =========================================================================
    // INTEGRACOES (lista + disconnect)
    // =========================================================================

    // DEFAULT_LEGACY_DEPOSITO_ID_CONST definida acima (498)

    /** GET /api/v1/supplier-admin/integrations */
    public function integracoes(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $supplier   = $this->_resolvedSupplier ?? $this->resolveSupplier($request);
        $depositoId = ($supplier && $supplier->legacy_empresa_id)
            ? (int) $supplier->legacy_empresa_id
            : (int) config('multdrop.deposito_id', self::DEFAULT_LEGACY_DEPOSITO_ID_CONST);
        $useLegacy  = (bool) config('app.legacy_admin_integrations', true);

        if ($useLegacy && $depositoId > 0) {
            $res = $this->bridge->getIntegracoes($depositoId);
            if (!$res['success']) return response()->json(['error' => $res['error']], 502);
            return response()->json(['data' => $res['data']]);
        }

        // Sem deposito legado (Fornecefy): busca do banco local
        $platformCanal = [
            'mercadolivre' => 6, 'shopee' => 3, 'bling' => 20,
            'magalu' => 7, 'tiktok' => 13, 'amazon' => 10,
        ];
        $accounts = \App\Models\MarketplaceAccount::where('status', '!=', 'disconnected')
            ->orderByDesc('created_at')
            ->get();
        $integracoes = $accounts->map(fn($a) => [
            'id'          => $a->id,
            'id_canal'    => $platformCanal[$a->platform] ?? 0,
            'nome_loja'   => $a->account_name,
            'shop_id'     => $a->shop_id,
            'data_add'    => $a->created_at?->toIso8601String(),
            'token_expira' => $a->token_expires_at?->toIso8601String(),
            'ativa'       => in_array($a->status, ['active', 'connected']),
        ])->values();
        return response()->json(['data' => [
            'integracoes' => $integracoes,
            'count'       => $integracoes->count(),
        ]]);
    }

    /** POST /api/v1/supplier-admin/integrations/{id}/disconnect */
    public function disconnectIntegracao(Request $request, int $id): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $res = $this->bridge->disconnectIntegracao($id);
        if (!$res['success']) return response()->json(['error' => $res['error']], 502);
        return response()->json(['data' => $res['data']]);
    }

    // =========================================================================
    // IA — chave OpenAI por fornecedor + geracao de conteudo
    // =========================================================================

    private function currentSupplier(?Request $request = null): ?\App\Models\Supplier
    {
        return $this->_resolvedSupplier ?? $this->resolveSupplier($request);
    }

    /** GET /api/v1/supplier-admin/ai-settings */
    public function aiSettings(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $s = $this->currentSupplier();
        if (!$s) return response()->json(['error' => 'supplier nao encontrado'], 404);
        $key = $s->openai_api_key;
        $masked = $key ? substr($key, 0, 7) . str_repeat('•', 12) . substr($key, -4) : '';
        return response()->json([
            'data' => [
                'has_key'   => (bool) $key,
                'key_mask'  => $masked,
                'model'     => $s->openai_model ?: 'gpt-4o-mini',
                'available_models' => ['gpt-4o-mini', 'gpt-4o', 'gpt-4-turbo'],
            ],
        ]);
    }

    /** PUT /api/v1/supplier-admin/ai-settings  { openai_api_key, openai_model, test? } */
    public function updateAiSettings(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $data = $request->validate([
            'openai_api_key' => 'nullable|string|min:20|max:200',
            'openai_model'   => 'nullable|string|in:gpt-4o-mini,gpt-4o,gpt-4-turbo',
            'test'           => 'nullable|boolean',
        ]);
        $s = $this->currentSupplier();
        if (!$s) return response()->json(['error' => 'supplier nao encontrado'], 404);

        if (array_key_exists('openai_api_key', $data)) {
            $s->openai_api_key = $data['openai_api_key'] ?: null;
        }
        if (array_key_exists('openai_model', $data)) {
            $s->openai_model = $data['openai_model'] ?: null;
        }
        $s->save();

        $tested = null;
        if (!empty($data['test']) && $s->openai_api_key) {
            try {
                $resp = \Illuminate\Support\Facades\Http::withToken($s->openai_api_key)
                    ->timeout(10)
                    ->get('https://api.openai.com/v1/models');
                $tested = ['ok' => $resp->successful(), 'status' => $resp->status()];
            } catch (\Throwable $e) {
                $tested = ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        return response()->json(['data' => ['saved' => true, 'tested' => $tested]]);
    }

    /** POST /api/v1/supplier-admin/products/{id}/ai-generate  { field: title|description } */
    public function aiGenerate(Request $request, int $id): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $data = $request->validate(['field' => 'required|string|in:title,description']);

        $p = \App\Models\Product::whereIn('supplier_id', $this->supplierIdsForCatalog())->find($id);
        if (!$p) return response()->json(['error' => 'produto nao encontrado'], 404);

        $s = $this->currentSupplier();
        $svc = app(\App\Services\AIProductContentService::class);
        if ($s) $svc->setSupplier($s);
        if (!$svc->hasApiKey()) {
            return response()->json(['error' => 'Configure a chave OpenAI em Configuracoes → IA primeiro.'], 422);
        }

        try {
            if ($data['field'] === 'title') {
                $out = $svc->generateTitle($p);
            } else {
                $out = $svc->generateDescription($p);
            }
            return response()->json(['data' => ['field' => $data['field'], 'value' => $out]]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Falha IA: ' . $e->getMessage()], 502);
        }
    }


    // =========================================================================
    // ACOES DE PEDIDO (cancelar / cancelar etiqueta / reembolso / bloquear / swap sku)
    // =========================================================================

    /**
     * POST /api/v1/supplier-admin/orders/{id}/cancel
     * Body: { motivo: string }
     * id = legacy_id do pedido (tabela pedidos do legado)
     */
    public function orderCancel(Request $request, int $id): JsonResponse
    {
        if (HubProxyHelper::isWl()) {
            $order = \App\Models\Order::find($id);
            $hubId = $order && $order->hubai_order_id ? $order->hubai_order_id : $id;
            return HubProxyHelper::forwardToHub('post', "/orders/$hubId/cancel", $request->only(['motivo']));
        }
        $this->requireSupplierAdmin($request);
        $data = $request->validate(['motivo' => 'required|string|max:500']);
        $res  = $this->bridge->orderCancel($id, $data['motivo']);
        if (!$res['success']) return response()->json(['error' => $res['error']], 502);
        return response()->json(['data' => $res['data']]);
    }

    /**
     * POST /api/v1/supplier-admin/orders/{id}/cancel-label
     * Body: { motivo?: string }
     */
    public function orderCancelLabel(Request $request, int $id): JsonResponse
    {
        if (HubProxyHelper::isWl()) {
            $order = \App\Models\Order::find($id);
            $hubId = $order && $order->hubai_order_id ? $order->hubai_order_id : $id;
            return HubProxyHelper::forwardToHub('post', "/orders/$hubId/cancel-label", $request->only(['motivo']));
        }
        $this->requireSupplierAdmin($request);
        $motivo = trim((string) $request->input('motivo', 'Cancelamento solicitado pelo painel'));
        $res    = $this->bridge->orderCancelLabel($id, $motivo);
        if (!$res['success']) return response()->json(['error' => $res['error']], 502);
        return response()->json(['data' => $res['data']]);
    }

    /**
     * POST /api/v1/supplier-admin/orders/{id}/refund
     * Body: { motivo: string, valor?: float }
     */
    public function orderRefund(Request $request, int $id): JsonResponse
    {
        if (HubProxyHelper::isWl()) {
            $order = \App\Models\Order::find($id);
            $hubId = $order && $order->hubai_order_id ? $order->hubai_order_id : $id;
            return HubProxyHelper::forwardToHub('post', "/orders/$hubId/refund", $request->only(['motivo','valor']));
        }
        $this->requireSupplierAdmin($request);
        $data  = $request->validate([
            'motivo' => 'required|string|max:500',
            'valor'  => 'nullable|numeric|min:0',
        ]);
        $res = $this->bridge->orderRefund($id, $data['motivo'], $data['valor'] ?? null);
        if (!$res['success']) return response()->json(['error' => $res['error']], 502);
        return response()->json(['data' => $res['data']]);
    }

    /**
     * POST /api/v1/supplier-admin/orders/{id}/block   Body: { motivo: string }
     * DELETE /api/v1/supplier-admin/orders/{id}/block  — desbloqueia
     */
    public function orderBlock(Request $request, int $id): JsonResponse
    {
        if (HubProxyHelper::isWl()) {
            $order = \App\Models\Order::find($id);
            $hubId = $order && $order->hubai_order_id ? $order->hubai_order_id : $id;
            $method = $request->isMethod('delete') ? 'delete' : 'post';
            return HubProxyHelper::forwardToHub($method, "/orders/$hubId/block", $request->only(['motivo']));
        }
        $this->requireSupplierAdmin($request);
        $desbloquear = $request->isMethod('delete');
        $motivo      = '';
        if (!$desbloquear) {
            $data   = $request->validate(['motivo' => 'required|string|max:500']);
            $motivo = $data['motivo'];
        }
        $res = $this->bridge->orderBlock($id, $motivo, $desbloquear);
        if (!$res['success']) return response()->json(['error' => $res['error']], 502);
        return response()->json(['data' => $res['data']]);
    }

    /**
     * POST /api/v1/supplier-admin/orders/{id}/swap-sku
     * Body: { itens: [{id_sku_pai, quantidade, nome_produto?, preco_unit?}], motivo?: string }
     */
    public function orderSwapSku(Request $request, int $id): JsonResponse
    {
        if (HubProxyHelper::isWl()) {
            $order = \App\Models\Order::find($id);
            $hubId = $order && $order->hubai_order_id ? $order->hubai_order_id : $id;
            return HubProxyHelper::forwardToHub('post', "/orders/$hubId/swap-sku", $request->only(['itens','motivo']));
        }
        $this->requireSupplierAdmin($request);
        $data = $request->validate([
            'itens'                => 'required|array|min:1',
            'itens.*.id_sku_pai'  => 'required|integer|min:1',
            'itens.*.quantidade'  => 'required|integer|min:1',
            'itens.*.nome_produto'=> 'nullable|string|max:300',
            'itens.*.preco_unit'  => 'nullable|numeric|min:0',
            'motivo'              => 'nullable|string|max:500',
        ]);
        $res = $this->bridge->orderSwapSku(
            $id,
            $data['itens'],
            $data['motivo'] ?? 'Troca de SKU solicitada pelo painel'
        );
        if (!$res['success']) return response()->json(['error' => $res['error']], 502);
        return response()->json(['data' => $res['data']]);
    }



    /**
     * POST /api/v1/supplier-admin/orders/{id}/sync-bling
     * MUL-264: cria ou atualiza o pedido no Bling do fornecedor (ERP account).
     * Se ainda não existe (orders.bling_pedido_id NULL): cria via BlingOrderSync::exportOrder.
     * Se existe: por enquanto retorna o link existente (update ainda não implementado).
     */
    public function syncBling(Request $request, int $id): JsonResponse
    {
        // MUL-264: roda DIRETO na WL (Bling do fornecedor mora no banco local, nao no hub).
        $this->requireSupplierAdmin($request);
        $order = \App\Models\Order::findOrFail($id);
        $erp = \App\Models\ErpAccount::where('supplier_id', $order->supplier_id)
            ->where('platform','bling')->where('status','active')->first();
        if (!$erp) return response()->json(['error'=>'Fornecedor sem conta Bling ativa (erp_accounts).'], 422);
        // MUL-264 (24/07): sync sempre re-envia — se ja tem bling_pedido_id, faz UPDATE (PUT); senao CREATE.
        $wasSynced = (bool) $order->bling_pedido_id;
        try {
            $svc = app(\App\Services\Integrations\Erps\Bling\BlingOrderSync::class);
            $res = $svc->exportSupplierOrder($erp, $order);
            if (!$res) return response()->json(['error'=>'Falha ao exportar pedido (ver logs).'], 502);
            $blingId = $res['data']['id'] ?? $order->bling_pedido_id;
            if ($blingId) {
                \Illuminate\Support\Facades\DB::table('orders')->where('id',$order->id)->update([
                    'bling_pedido_id' => $blingId,
                    'bling_pedido_url' => "https://www.bling.com.br/vendas.php#/venda/$blingId",
                    'bling_synced_at' => now(),
                    'bling_sync_error' => null,
                    'bling_sync_attempted_at' => now(),
                ]);

                // MUL-339: propaga o carimbo do Bling para a WL, igual faz a rota que entra pela
                // federacao (syncBlingFromFederation). As duas fazem a mesma coisa; so aquela
                // avisava, e quem usava o painel direto deixava a WL sem saber.
                \App\Jobs\FanoutOrderWebhookJob::dispatch($order->id, 'order.updated', [
                    'action'          => 'sync_bling',
                    'bling_pedido_id' => $blingId,
                ]);
            }
            return response()->json(['data'=>[
                'action'=> $wasSynced ? 'updated' : 'created',
                'bling_pedido_id'=>$blingId,
                'bling_pedido_url'=>$blingId ? "https://www.bling.com.br/vendas.php#/venda/$blingId" : null,
            ]]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[syncBling] '.$e->getMessage(), ['order_id'=>$order->id]);
            return response()->json(['error'=>'sync_exception', 'message'=>$e->getMessage()], 422);
        }
    }

    /**
     * POST /api/v1/supplier-admin/orders/{id}/emit-nfe
     * MUL-265: emite UMA NF-e no Bling do fornecedor. Ela e SAIDA no Bling dele + ENTRADA no lado do seller
     * (mesma nota, dois angulos). Grava no pedido do seller em orders.nfe_entrada_*.
     *
     * Pre-requisito: pedido ja sincronizado com Bling (orders.bling_pedido_id != null).
     * Se nao sincronizado, dispara sync primeiro.
     *
     * MUL-275: emissao real via BlingNfeService (gerar-nfe + enviar SEFAZ + GET nfe).
     */
    public function emitNfe(Request $request, int $id): JsonResponse
    {
        // MUL-265: roda DIRETO na WL.
        $this->requireSupplierAdmin($request);
        $order = \App\Models\Order::findOrFail($id);
        if (!$order->bling_pedido_id) {
            return response()->json(['error'=>'not_synced','message'=>'Pedido ainda nao foi sincronizado com o Bling do fornecedor. Clique em Sincronizar Bling primeiro.'], 422);
        }
        if ($order->nfe_entrada_number && $order->nfe_entrada_status === 'authorized') {
            return response()->json(['data'=>[
                'action'=>'already_emitted',
                'nfe_number'=>$order->nfe_entrada_number,
                'nfe_access_key'=>$order->nfe_entrada_access_key,
            ]]);
        }
        $erp = \App\Models\ErpAccount::where('supplier_id', $this->supplierId())
            ->where('platform', 'bling')->where('status', 'active')->first();
        if (!$erp) {
            return response()->json(['error'=>'no_bling_account','message'=>'Fornecedor sem conta Bling ativa (erp_accounts).'], 422);
        }
        try {
            $res = app(\App\Services\Integrations\Erps\Bling\BlingNfeService::class)->emitForOrder($erp, $order);
            return response()->json(['data'=>$res]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[emitNfe] '.$e->getMessage(), ['order_id'=>$order->id]);
            return response()->json(['error'=>'nfe_error','message'=>$e->getMessage()], 422);
        }
    }

    // =========================================================================
    // =========================================================================
    // PAGAMENTO AO FORNECEDOR (PIX ShiPay)
    // =========================================================================

    /**
     * POST /api/v1/supplier-admin/orders/{id}/pay-supplier
     *
     * Gera QR code PIX para pagamento do dropshipper ao fornecedor.
     * Usa o gateway configurado em supplier_payment_settings (ex: ShiPay).
     * Idempotente: se ja existe PIX pendente para o pedido, retorna o existente.
     */
    public function paySupplier(Request $request, int $id): JsonResponse
    {
        $this->requireSupplierAdmin($request);

        $order = Order::where("supplier_id", $this->supplierId())->findOrFail($id);

        $supplier = \App\Models\Supplier::with("paymentSetting")->find($order->supplier_id);

        if (! $supplier || ! $supplier->paymentSetting || ! $supplier->paymentSetting->is_active) {
            return response()->json([
                "error" => "Este fornecedor nao possui gateway de pagamento configurado.",
            ], 422);
        }

        $amount = $order->supplier_total
            ? (float) $order->supplier_total
            : (float) $order->total;

        if ($amount <= 0) {
            return response()->json(["error" => "Valor do pedido invalido."], 422);
        }

        $order->load('client');

        $buyerDoc = null;
        if ($request->filled('buyer_document')) {
            $rawBD = preg_replace('/\D/', '', $request->input('buyer_document'));
            if (!\App\Helpers\DocumentValidator::isValid($rawBD)) {
                return response()->json(['error' => 'document_invalid', 'message' => 'CPF/CNPJ informado é inválido.'], 422);
            }
            $buyerDoc = $rawBD;
            if ($order->client) {
                $order->client->update(['document' => $buyerDoc]);
            }
        } else {
            $rawDoc = preg_replace('/\D/', '', $order->client->document ?? '');
            if (empty($rawDoc)) {
                return response()->json(['error' => 'document_required', 'message' => 'O lojista não possui CPF/CNPJ cadastrado. Informe para prosseguir com o pagamento PIX.'], 422);
            }
            if (!\App\Helpers\DocumentValidator::isValid($rawDoc)) {
                return response()->json(['error' => 'document_invalid', 'message' => 'CPF/CNPJ do lojista é inválido. Informe um novo para prosseguir.'], 422);
            }
        }

        try {
            $pixTx = app(\App\Services\Financial\PixService::class)
                ->createOrderPix($order, $supplier, $amount, $buyerDoc);

            return response()->json([
                "data" => [
                    "pix_transaction_id" => $pixTx->id,
                    "qr_code"            => $pixTx->qr_code,
                    "qr_code_text"       => $pixTx->qr_code_text,
                    "amount"             => (float) $pixTx->amount,
                    "expires_at"         => $pixTx->expires_at?->toIso8601String(),
                    "status"             => $pixTx->status,
                    "gateway"            => $pixTx->gateway,
                ],
            ]);
        } catch (\RuntimeException $e) {
            \Illuminate\Support\Facades\Log::error("[paySupplier] Falha ao gerar PIX", [
                "order_id" => $id,
                "error"    => $e->getMessage(),
            ]);
            return response()->json(["error" => "Falha ao gerar PIX: " . $e->getMessage()], 502);
        }
    }

    /**
     * GET /api/v1/supplier-admin/orders/{id}/payment-status
     *
     * Retorna o status atual do pagamento ao fornecedor para um pedido.
     */
    public function paymentStatus(Request $request, int $id): JsonResponse
    {
        $this->requireSupplierAdmin($request);

        $order = Order::where("supplier_id", $this->supplierId())->findOrFail($id);

        $pixTx = \App\Models\PixTransaction::where("order_id", $order->id)
            ->where("type", "order_payment")
            ->orderByDesc("created_at")
            ->first();

        if (! $pixTx) {
            return response()->json([
                "data" => [
                    "status"  => "no_payment",
                    "message" => "Nenhum pagamento gerado para este pedido.",
                ],
            ]);
        }

        if ($pixTx->isExpired()) {
            $pixTx->markAsExpired();
        }

        return response()->json([
            "data" => [
                "pix_transaction_id" => $pixTx->id,
                "status"             => $pixTx->status,
                "amount"             => (float) $pixTx->amount,
                "gateway"            => $pixTx->gateway,
                "external_id"        => $pixTx->external_id,
                "qr_code"            => $pixTx->qr_code,
                "qr_code_text"       => $pixTx->qr_code_text,
                "expires_at"         => $pixTx->expires_at?->toIso8601String(),
                "paid_at"            => $pixTx->paid_at?->toIso8601String(),
                "created_at"         => $pixTx->created_at?->toIso8601String(),
            ],
        ]);
    }

    // =========================================================================
    // VARIACOES DE PRODUTO
    // =========================================================================

    /**
     * GET /api/v1/supplier-admin/products/{id}/variations
     */
    public function productVariations(Request $request, int $id): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $p = \App\Models\Product::whereIn('supplier_id', $this->supplierIdsForCatalog())->find($id);
        if (!$p) return response()->json(['error' => 'Produto nao encontrado'], 404);

        $vars = \DB::table('product_variations')
            ->where('product_id', $id)
            ->orderBy('position')
            ->get();

        return response()->json(['data' => $vars->map(fn($v) => [
            'id'               => $v->id,
            'sku'              => $v->sku,
            'name'             => $v->name,
            'price'            => (float) $v->price,
            'cost'             => (float) $v->cost,
            'gtin'             => $v->gtin ?? null,
            'ean'              => $v->ean  ?? null,
            'virtual_stock_qty'=> (int) ($v->virtual_stock_qty ?? 0),
            'attributes'       => is_string($v->attributes) ? json_decode($v->attributes, true) : $v->attributes,
            'position'         => (int) $v->position,
            'is_active'        => (bool) $v->is_active,
        ])->values()]);
    }

    /**
     * POST /api/v1/supplier-admin/products/{id}/variations
     * Body: { sku, name, price, cost, gtin?, ean?, virtual_stock_qty?, attributes?, position? }
     */
    public function createProductVariation(Request $request, int $id): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $p = \App\Models\Product::whereIn('supplier_id', $this->supplierIdsForCatalog())->find($id);
        if (!$p) return response()->json(['error' => 'Produto nao encontrado'], 404);

        $data = $request->validate([
            'sku'              => 'required|string|max:120|unique:product_variations,sku',
            'name'             => 'required|string|max:300',
            'price'            => 'required|numeric|min:0',
            'cost'             => 'required|numeric|min:0',
            'gtin'             => 'nullable|string|max:50',
            'ean'              => 'nullable|string|max:50',
            'virtual_stock_qty'=> 'nullable|integer|min:0',
            'attributes'       => 'nullable|array',
            'position'         => 'nullable|integer|min:0',
            'is_active'        => 'nullable|boolean',
        ]);

        $varId = \DB::table('product_variations')->insertGetId(array_merge($data, [
            'product_id'        => $id,
            'position'          => $data['position'] ?? 0,
            'is_active'         => $data['is_active'] ?? true,
            'virtual_stock_qty' => $data['virtual_stock_qty'] ?? 0,
            'attributes'        => isset($data['attributes']) ? json_encode($data['attributes']) : null,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]));

        return response()->json(['data' => ['id' => $varId, 'sku' => $data['sku']]], 201);
    }

    /**
     * PUT /api/v1/supplier-admin/products/{id}/variations/{varId}
     */
    public function updateProductVariation(Request $request, int $id, int $varId): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $p = \App\Models\Product::whereIn('supplier_id', $this->supplierIdsForCatalog())->find($id);
        if (!$p) return response()->json(['error' => 'Produto nao encontrado'], 404);

        $var = \DB::table('product_variations')->where('id', $varId)->where('product_id', $id)->first();
        if (!$var) return response()->json(['error' => 'Variacao nao encontrada'], 404);

        $data = $request->validate([
            'name'             => 'sometimes|string|max:300',
            'price'            => 'sometimes|numeric|min:0',
            'cost'             => 'sometimes|numeric|min:0',
            'gtin'             => 'sometimes|nullable|string|max:50',
            'ean'              => 'sometimes|nullable|string|max:50',
            'virtual_stock_qty'=> 'sometimes|nullable|integer|min:0',
            'attributes'       => 'sometimes|nullable|array',
            'position'         => 'sometimes|integer|min:0',
            'is_active'        => 'sometimes|boolean',
        ]);

        if (isset($data['attributes'])) {
            $data['attributes'] = json_encode($data['attributes']);
        }
        $data['updated_at'] = now();

        \DB::table('product_variations')->where('id', $varId)->update($data);
        return response()->json(['data' => ['id' => $varId, 'updated' => true]]);
    }

    /**
     * DELETE /api/v1/supplier-admin/products/{id}/variations/{varId}
     */
    public function deleteProductVariation(Request $request, int $id, int $varId): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $p = \App\Models\Product::whereIn('supplier_id', $this->supplierIdsForCatalog())->find($id);
        if (!$p) return response()->json(['error' => 'Produto nao encontrado'], 404);

        $deleted = \DB::table('product_variations')
            ->where('id', $varId)
            ->where('product_id', $id)
            ->delete();

        if (!$deleted) return response()->json(['error' => 'Variacao nao encontrada'], 404);
        return response()->json(['data' => ['deleted' => true]]);
    }


    /** GET /api/v1/supplier-admin/categories — lista categorias do catalogo interno */
    public function categoriesInternal(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $supplierId = $this->supplierId();
        $categories = \DB::table('categories')
            ->whereIn('supplier_id', [$supplierId, null])
            ->orWhereNull('supplier_id')
            ->select('id', 'name', 'slug')
            ->orderBy('name')
            ->get();
        // incluir apenas categorias que tem ao menos 1 produto deste supplier
        $usedIds = \DB::table('products')
            ->where('supplier_id', $supplierId)
            ->whereNotNull('category_id')
            ->distinct()
            ->pluck('category_id')
            ->toArray();
        $filtered = $categories->filter(fn($c) => in_array($c->id, $usedIds))->values();
        return response()->json(['data' => $filtered]);
    }

    /** GET /api/v1/supplier-admin/categories/shopee?q=fone */
    public function categoriesShopee(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $q = trim((string) $request->query('q', ''));
        $limit = max(1, min(200, (int) $request->query('limit', 50)));
        if ($q === '') return response()->json(['data' => [], 'returned' => 0]);
        $bridge = app(\App\Services\GoolhubBridgeService::class);
        $res = $bridge->searchShopeeCategories($q, $limit);
        if (!$res['success']) return response()->json(['error' => $res['error'] ?? 'bridge falhou'], 502);
        return response()->json(['data' => $res['data'] ?? [], 'returned' => count($res['data'] ?? [])]);
    }

    /** GET /api/v1/supplier-admin/categories/meli?q=fone */
    public function categoriesMeli(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $q = trim((string) $request->query('q', ''));
        $limit = max(1, min(200, (int) $request->query('limit', 50)));
        if ($q === '') return response()->json(['data' => [], 'returned' => 0]);
        $bridge = app(\App\Services\GoolhubBridgeService::class);
        $res = $bridge->searchMeliCategories($q, $limit);
        if (!$res['success']) return response()->json(['error' => $res['error'] ?? 'bridge falhou'], 502);
        return response()->json(['data' => $res['data'] ?? [], 'returned' => count($res['data'] ?? [])]);
    }

    /** GET /api/v1/supplier-admin/categories/meli/attributes?cat=MLB123 */
    public function categoryMeliAttributes(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $cat = trim((string) $request->query('cat', ''));
        if ($cat === '' || !preg_match('/^MLB\d+$/i', $cat)) {
            return response()->json(['error' => 'cat invalida'], 422);
        }
        $bridge = app(\App\Services\GoolhubBridgeService::class);
        $res = $bridge->getMeliAttributes($cat);
        if (!$res['success']) return response()->json(['error' => $res['error'] ?? 'bridge falhou'], 502);
        return response()->json(['data' => $res['data'] ?? []]);
    }

    // =========================================================================
    // Sprint G — Clientes do fornecedor (marketplaces + extrato)
    // =========================================================================

    /**
     * GET /api/v1/supplier-admin/clients
     *
     * Lista os clientes que tem relacao com ESTE fornecedor — significa
     * clientes que ja fizeram pedidos via supplier local ou possuem
     * marketplace_accounts apontando pra ele.
     *
     * Resposta inclui contadores agregados (marketplaces conectados, total
     * gasto, qtd de pedidos) usados no AdminClients pra montar os badges.
     */
    public function listClients(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $perPage    = (int) $request->query('per_page', 20);
        $supplierId = $this->supplierId();

        // ---- parametros novos ----
        $sortBy  = $request->query('sort_by', 'created_at');   // orders_count|total_spent|is_active|created_at
        $status  = $request->query('status',  'all');           // active|inactive|all
        $channel = $request->query('channel', '');              // shopee|mercadolivre|tiktok|shopify|""

        $clientIds = collect()
            ->merge(Order::where('supplier_id', $supplierId)->distinct()->pluck('client_id'))
            ->merge(
                DB::table('marketplace_accounts')
                    ->where('supplier_id', $supplierId)
                    ->distinct()
                    ->pluck('client_id')
            )
            ->filter()
            ->unique()
            ->values();

        $q = Client::query()
            ->whereIn('id', $clientIds)
            ->with([
                // MUL-336: full_name e obrigatorio aqui — o accessor company_name e
                // full_name ?: name, e sem a coluna carregada ele cai no name e o
                // nome que o seller edita no painel dele nunca aparece no admin.
                'user:id,name,full_name,email',
                'marketplaceAccounts' => function ($w) use ($supplierId) {
                    $w->where('supplier_id', $supplierId)
                      ->select('id', 'client_id', 'platform', 'account_name', 'seller_nickname', 'status', 'last_sync_at', 'shop_id', 'ml_user_id', 'seller_id');
                },
            ])
            ->withCount([
                'orders as orders_count' => fn ($w) => $w->where('supplier_id', $supplierId),
                'marketplaceAccounts as marketplace_accounts_count' => fn ($w) => $w->where('supplier_id', $supplierId),
            ])
            ->withSum([
                'orders as total_spent' => fn ($w) => $w->where('supplier_id', $supplierId)->whereIn('status', ['paid', 'shipped', 'delivered']),
            ], 'supplier_total');

        // ---- filtro status ----
        if ($status === 'active')        { $q->where('is_active', 1)->whereNull('blocked_at'); }
        elseif ($status === 'inactive')  { $q->where('is_active', 0)->whereNull('blocked_at'); }
        elseif ($status === 'blocked')   { $q->whereNotNull('blocked_at'); }

        // ---- filtro canal/marketplace ----
        if ($channel !== '') {
            $platforms = $channel === 'mercadolivre' ? ['mercadolivre', 'mercado_livre'] : [$channel];
            $q->whereHas('marketplaceAccounts', function ($w) use ($supplierId, $platforms) {
                $w->where('supplier_id', $supplierId)
                  ->whereIn('platform', $platforms);
            });
        }

        // ---- busca textual ----
        // MUL-269 fase 2: nome do seller vem do user (clients.company_name removido).
        if ($search = $request->query('search')) {
            $s = '%' . $search . '%';
            $q->where(function ($w) use ($s) {
                $w->where('document', 'like', $s)
                  ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $s)
                      ->orWhere('full_name', 'like', $s)
                      ->orWhere('email', 'like', $s));
            });
        }

        // ---- ordenacao backend ANTES do paginate ----
        $allowedSorts = ['orders_count', 'total_spent', 'is_active', 'created_at'];
        $sortBy = in_array($sortBy, $allowedSorts, true) ? $sortBy : 'created_at';

        if ($sortBy === 'total_spent') {
            $q->orderByRaw(
                '(SELECT COALESCE(SUM(o.supplier_total),0) FROM orders o WHERE o.client_id = clients.id AND o.supplier_id = ? AND o.status IN ("paid","shipped","delivered")) DESC',
                [$supplierId]
            );
        } elseif ($sortBy === 'orders_count') {
            $q->orderByRaw(
                '(SELECT COUNT(*) FROM orders o WHERE o.client_id = clients.id AND o.supplier_id = ?) DESC',
                [$supplierId]
            );
        } elseif ($sortBy === 'is_active') {
            $q->orderByDesc('is_active');
        } else {
            $q->latest('id');
        }

        $paginator = $q->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(function (Client $c) use ($supplierId) {
                $marketplaces = $c->marketplaceAccounts->map(function ($m) {
                    // MUL-213 item 14: link publico da loja no marketplace
                    $storeUrl = null;
                    if ($m->platform === 'shopee' && $m->shop_id) {
                        $storeUrl = "https://shopee.com.br/shop/{$m->shop_id}";
                    } elseif (in_array($m->platform, ['mercadolivre', 'mercado_livre'])) {
                        $mlRef = $m->seller_nickname ?: ($m->ml_user_id ?: $m->shop_id);
                        if ($mlRef) $storeUrl = 'http://perfil.mercadolivre.com.br/' . rawurlencode($mlRef);
                    } elseif ($m->platform === 'amazon' && ($m->seller_id ?: $m->shop_id)) {
                        $storeUrl = 'https://www.amazon.com.br/sp?ie=UTF8&seller=' . ($m->seller_id ?: $m->shop_id);
                    }
                    return [
                        'id'              => $m->id,
                        'platform'        => $m->platform,
                        'account_name'    => $m->account_name,
                        'seller_nickname' => $m->seller_nickname,
                        'status'          => $m->status,
                        'last_sync_at'    => $m->last_sync_at,
                        'store_url'       => $storeUrl,
                    ];
                })->values();

                // saldo: ultimo running_balance nas transacoes deste fornecedor
                $balance = DB::table('client_supplier_transactions')
                    ->where('client_id', $c->id)
                    ->where('supplier_id', $supplierId)
                    ->latest('id')
                    ->value('running_balance') ?? 0.0;

                // total de produtos (soma das quantidades) nos pedidos deste fornecedor
                $productsQty = (int) DB::table('order_items')
                    ->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->where('orders.client_id', $c->id)
                    ->where('orders.supplier_id', $supplierId)
                    ->sum('order_items.quantity');

                // plano ativo via subscriptions + plans
                $planName = DB::table('subscriptions')
                    ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
                    ->where('subscriptions.client_id', $c->id)
                    ->whereIn('subscriptions.status', ['active', 'trialing'])
                    ->orderByDesc('subscriptions.id')
                    ->value('plans.name');

                // nome da loja: primeiro account_name dos marketplaces
                $storeName = $c->marketplaceAccounts->first()?->account_name
                          ?? $c->marketplaceAccounts->first()?->seller_nickname;

                return [
                    'id'                           => $c->id,
                    'company_name'                 => $c->company_name,
                    'document'                     => $c->document,
                    'phone'                        => $c->phone,
                    'is_active'                    => (bool) $c->is_active,
                    'status'                       => $c->blocked_at ? 'blocked' : ($c->is_active ? 'active' : 'inactive'),
                    'legacy_id_login'              => $c->legacy_id_login,
                    'created_at'                   => $c->created_at,
                    'user'                         => $c->user ? [
                        'id'    => $c->user->id,
                        'name'  => $c->user->name,
                        'email' => $c->user->email,
                    ] : null,
                    'orders_count'                 => (int) $c->orders_count,
                    'products_count'               => $productsQty,
                    'marketplace_accounts_count'   => (int) $c->marketplace_accounts_count,
                    'total_spent'                  => $c->total_spent ? (float) $c->total_spent : 0.0,
                    'marketplaces'                 => $marketplaces,
                    'plan_name'                    => $planName,
                    'balance'                      => (float) $balance,
                    'store_name'                   => $storeName,
                ];
            })->values(),
            'meta' => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }
    /**
     * GET /api/v1/supplier-admin/clients/{id}
     *
     * Detalhe de UM cliente sob a otica deste fornecedor: dados pessoais,
     * marketplaces conectados, KPIs (qtd pedidos, total gasto, ultimo pedido).
     */
    public function showClient(Request $request, int $id): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $supplierId = $this->supplierId();

        $client = Client::with([
            // MUL-336: idem — sem full_name o detalhe mostra o nome errado e o bloco
            // 'personal' devolve full_name null mesmo com a coluna preenchida.
            'user:id,name,full_name,cpf,email',
            'marketplaceAccounts' => function ($w) use ($supplierId) {
                $w->where('supplier_id', $supplierId);
            },
        ])->findOrFail($id);

        $ordersAgg = Order::where('client_id', $client->id)
            ->where('supplier_id', $supplierId)
            ->selectRaw('COUNT(*) AS qtd, SUM(supplier_total) AS total, MAX(created_at) AS last_order_at')
            ->first();

        // MUL-309: plano atual no detalhe -- o select da tela de edicao precisa vir
        // marcado no plano vigente, senao o admin nao sabe o que esta trocando.
        $subAtiva = DB::table('subscriptions')
            ->leftJoin('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('subscriptions.client_id', $client->id)
            ->where('subscriptions.status', 'active')
            ->orderByDesc('subscriptions.id')
            ->first(['subscriptions.plan_id', 'plans.name as plan_name']);

        // MUL-215: saldo da carteira do cliente neste fornecedor
        $walletBalance = (float) (DB::table('client_supplier_balances')
            ->where('client_id', $client->id)
            ->where('supplier_id', $supplierId)
            ->value('balance') ?? 0);

        return response()->json([
            'data' => [
                'id'              => $client->id,
                'company_name'    => $client->company_name,
                'document'        => $client->document,
                'phone'           => $client->phone,
                'is_active'       => (bool) $client->is_active,
                'status'          => $client->blocked_at ? 'blocked' : ($client->is_active ? 'active' : 'inactive'),
                'legacy_id_login' => $client->legacy_id_login,
                'created_at'      => $client->created_at,
                'wallet_balance'  => $walletBalance,
                'plan_id'         => $subAtiva->plan_id ?? null,
                'plan_name'       => $subAtiva->plan_name ?? null,

                // MUL-309: campos de edicao -- a tela de detalhe era so leitura e nao
                // tinha com que preencher um formulario. Todos aceitos de volta pelo
                // PATCH /supplier-admin/clients/{id}.
                'person_type'            => $client->person_type,
                'legal_name'             => $client->legal_name,
                'trade_name'             => $client->trade_name,
                'state_registration'     => $client->state_registration,
                'ie_indicator'           => $client->ie_indicator,
                'municipal_registration' => $client->municipal_registration,
                'nfe_email'              => $client->nfe_email,
                'address' => [
                    'cep'          => $client->address_cep,
                    'street'       => $client->address_street,
                    'number'       => $client->address_number,
                    'complement'   => $client->address_complement,
                    'neighborhood' => $client->address_neighborhood,
                    'city'         => $client->address_city,
                    'state'        => $client->address_state,
                ],
                'personal' => [
                    'full_name'   => $client->user->full_name ?? null,
                    // MUL-338: o CPF do responsavel e do user, nao do client — clients.document
                    // e o CNPJ da empresa. Os dois cadastros coexistem.
                    'cpf'         => $client->user->cpf ?? null,
                    'birth_date'  => $client->user->birth_date ?? null,
                    'rg'          => $client->user->rg ?? null,
                    'mother_name' => $client->user->mother_name ?? null,
                    'father_name' => $client->user->father_name ?? null,
                ],
                'user'            => $client->user ? [
                    'id'    => $client->user->id,
                    'name'  => $client->user->name,
                    'email' => $client->user->email,
                ] : null,
                'kpis' => [
                    'orders_count'  => (int) ($ordersAgg->qtd ?? 0),
                    'total_spent'   => (float) ($ordersAgg->total ?? 0),
                    'last_order_at' => $ordersAgg->last_order_at ?? null,
                ],
                'marketplaces' => $client->marketplaceAccounts->map(fn ($m) => [
                    'id'              => $m->id,
                    'platform'        => $m->platform,
                    'account_name'    => $m->account_name,
                    'seller_nickname' => $m->seller_nickname,
                    'status'          => $m->status,
                    'last_sync_at'    => $m->last_sync_at,
                ])->values(),
            ],
        ]);
    }

    /**
     * GET /api/v1/supplier-admin/clients/{id}/transactions
     *
     * Extrato financeiro do cliente neste fornecedor (debitos por pedido,
     * creditos por wallet topup, estornos). Filtra por client_supplier_transactions
     * onde supplier_id = supplier local.
     */
    public function clientTransactions(Request $request, int $id): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $perPage = (int) $request->query('per_page', 50);
        $supplierId = $this->supplierId();

        $client = Client::findOrFail($id);

        $q = ClientSupplierTransaction::where('client_id', $client->id)
            ->where('supplier_id', $supplierId)
            ->with('order:id,external_order_id,order_number')
            ->latest('id');

        if ($type = $request->query('type')) {
            $q->where('type', $type);
        }

        $paginator = $q->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($t) => [
                'id'                => $t->id,
                'type'              => $t->type,
                'amount'            => (float) $t->amount,
                'description'       => $t->description,
                'reference'         => $t->reference,
                'transaction_type'  => $t->transaction_type,
                'running_balance'   => $t->running_balance !== null ? (float) $t->running_balance : null,
                'order'             => $t->order ? [
                    'id'                => $t->order->id,
                    'external_order_id' => $t->order->external_order_id,
                    'order_number'      => $t->order->order_number,
                ] : null,
                'created_at'        => $t->created_at,
            ])->values(),
            'meta' => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }


    // =========================================================================
    // MUL-213 item 18 — Financeiro admin: movimentacoes dos sellers
    // =========================================================================

    public function financeTransactions(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $supplierId = $this->supplierId();
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));

        $q = ClientSupplierTransaction::where('supplier_id', $supplierId)
            ->with([
                // MUL-269 fase 2: nome do seller vem do user (accessor client->company_name).
                'client:id,user_id',
                'client.user:id,name,full_name,email',
                'order:id,external_order_id,order_number',
            ])
            ->latest('id');

        if ($type = $request->query('type')) {
            $q->where('type', $type);
        }
        if ($txType = $request->query('transaction_type')) {
            $q->where('transaction_type', $txType);
        }
        if ($clientId = (int) $request->query('client_id', 0)) {
            $q->where('client_id', $clientId);
        }
        if ($from = $request->query('date_from')) {
            $q->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $q->whereDate('created_at', '<=', $to);
        }
        // MUL-222 item 9: filtro por gateway (shipay|pagarme|mercadopago). Usa JOIN em pix_transactions via pix_transaction_id.
        if ($gw = $request->query('gateway')) {
            $gwList = $gw === 'mercadopago'
                ? ['pagarme', 'mercadopago']
                : [$gw];
            $q->whereIn('pix_transaction_id', \DB::table('pix_transactions')->whereIn('gateway', $gwList)->pluck('id'));
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $s = '%' . $search . '%';
            $q->where(function ($w) use ($s) {
                $w->where('description', 'like', $s)
                  ->orWhere('reference', 'like', $s)
                  // MUL-269 fase 2: nome do seller vem do user (clients.company_name removido).
                  ->orWhereHas('client', function ($c) use ($s) {
                      $c->whereHas('user', fn ($u) => $u->where('name', 'like', $s)
                          ->orWhere('full_name', 'like', $s)
                          ->orWhere('email', 'like', $s));
                  });
            });
        }

        $paginator = $q->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($t) => [
                'id'               => $t->id,
                'type'             => $t->type,
                'transaction_type' => $t->transaction_type,
                'amount'           => (float) $t->amount,
                'description'      => $t->description,
                'reference'        => $t->reference,
                'running_balance'  => $t->running_balance !== null ? (float) $t->running_balance : null,
                'client'           => $t->client ? [
                    'id'           => $t->client->id,
                    'company_name' => $t->client->company_name,
                    'name'         => $t->client->user?->name,
                    'email'        => $t->client->user?->email,
                ] : null,
                'order'            => $t->order ? [
                    'id'                => $t->order->id,
                    'external_order_id' => $t->order->external_order_id,
                    'order_number'      => $t->order->order_number,
                ] : null,
                'created_at'       => $t->created_at,
            ])->values(),
            'meta' => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    public function financeSummary(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $supplierId = $this->supplierId();
        // MUL-222 item 9b: expõe filtro gateway pro summary (contagens/somatórios por Shipay|MP)
        $gatewayIds = null;
        if ($gw = $request->query('gateway')) {
            $gwList = $gw === 'mercadopago' ? ['pagarme', 'mercadopago'] : [$gw];
            $gatewayIds = \DB::table('pix_transactions')->whereIn('gateway', $gwList)->pluck('id')->all();
        }

        $base = ClientSupplierTransaction::where('supplier_id', $supplierId)->when($gatewayIds !== null, fn($w) => $w->whereIn('pix_transaction_id', $gatewayIds));
        if ($from = $request->query('date_from')) {
            $base->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $base->whereDate('created_at', '<=', $to);
        }

        $rows = $base->selectRaw("type, COALESCE(transaction_type, 'outros') as tx, COUNT(*) as c, SUM(amount) as total")
            ->groupBy('type', 'tx')
            ->get();

        $credits  = (float) $rows->where('type', 'credit')->sum('total');
        $debits   = (float) $rows->where('type', 'debit')->sum('total');
        $deposits = (float) $rows->where('type', 'credit')->where('tx', 'deposit')->sum('total');

        return response()->json([
            'total_credits'  => $credits,
            'total_debits'   => $debits,
            'net'            => $credits - $debits,
            'deposits_total' => $deposits,
            'count'          => (int) $rows->sum('c'),
            'by_type'        => $rows->map(fn ($r) => [
                'type'             => $r->type,
                'transaction_type' => $r->tx,
                'count'            => (int) $r->c,
                'total'            => (float) $r->total,
            ])->values(),
        ]);
    }

    // =========================================================================
    // PACKING STATS - Feature 1: Painel embalados hoje
    // =========================================================================
    public function packingStats(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $supplierId = $this->supplierId();
        $todayStart = now()->startOfDay()->toDateTimeString();
        $todayEnd   = now()->endOfDay()->toDateTimeString();

        $canalMap = [
            'shopee'       => ['label' => 'Shopee',        'color' => '#EE4D2D'],
            'mercadolivre' => ['label' => 'Mercado Livre', 'color' => '#FFE600'],
            'amazon'       => ['label' => 'Amazon',        'color' => '#FF9900'],
            'bling'        => ['label' => 'Bling',         'color' => '#1AAC4A'],
            'outros'       => ['label' => 'Outros',        'color' => '#6b7a99'],
        ];

        $rows = DB::table('orders')
            ->select('id', 'order_number', 'external_order_id', 'channel_name', 'source', 'shipped_at', 'customer_name')
            ->where('supplier_id', $supplierId)
            ->where('is_draft', 0) // MUL-197: rascunho fora do painel de embalados
            ->where('order_processing_status', 'shipped')
            ->whereBetween('shipped_at', [$todayStart, $todayEnd])
            ->orderByDesc('shipped_at')
            ->limit(200)
            ->get();

        $byCanal     = [];
        $totalByCanal = [];
        foreach ($rows as $o) {
            $k = $this->normalizeCanalName(strtolower($o->channel_name ?? $o->source ?? 'outros'));
            $totalByCanal[$k] = ($totalByCanal[$k] ?? 0) + 1;
            if (count($byCanal[$k] ?? []) < 5) {
                $byCanal[$k][] = [
                    'id'                => $o->id,
                    'order_number'      => $o->order_number,
                    'external_order_id' => $o->external_order_id,
                    'customer_name'     => $o->customer_name,
                    'shipped_at'        => $o->shipped_at,
                ];
            }
        }

        $result = [];
        foreach ($canalMap as $k => $info) {
            $result[] = [
                'canal'       => $k,
                'label'       => $info['label'],
                'color'       => $info['color'],
                'total'       => $totalByCanal[$k] ?? 0,
                'last_orders' => $byCanal[$k] ?? [],
            ];
        }

        return response()->json(['data' => [
            'total'      => array_sum(array_column($result, 'total')),
            'canais'     => $result,
            'updated_at' => now()->toIso8601String(),
        ]]);
    }

    private function normalizeCanalName(string $raw): string
    {
        if (str_contains($raw, 'shopee')) return 'shopee';
        if (str_contains($raw, 'mercado') || str_contains($raw, 'meli') || str_contains($raw, 'ml')) return 'mercadolivre';
        if (str_contains($raw, 'amazon')) return 'amazon';
        if (str_contains($raw, 'bling'))  return 'bling';
        return 'outros';
    }

    // =========================================================================
    // VERIFY SUPERVISOR - Feature 3: Trava reimpressao etiqueta
    // =========================================================================
    public function verifySupervisor(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => 'required|string|max:255']);

        $matched = null;
        foreach (\App\Models\User::whereIn('role', ['super_admin', 'admin'])->where('is_active', 1)->get(['id', 'password', 'name', 'role']) as $sup) {
            if (\Illuminate\Support\Facades\Hash::check($data['password'], $sup->password)) {
                $matched = $sup;
                break;
            }
        }

        \Illuminate\Support\Facades\Log::info('[PickingPacking] verify-supervisor', [
            'ip' => $request->ip(), 'user_id' => $request->user()?->id,
            'success' => $matched !== null, 'supervisor' => $matched?->id,
        ]);

        if (!$matched) return response()->json(['error' => 'Senha incorreta. Acesso negado.'], 401);
        return response()->json(['data' => ['ok' => true, 'supervisor_name' => $matched->name, 'supervisor_role' => $matched->role]]);
    }

    // =========================================================================
    // PACKING COMPLETE - Feature 4: Completar embalagem + NF-e Bling
    // =========================================================================
    public function packingComplete(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $data = $request->validate([
            'order_id'          => 'required|integer|min:1',
            'partial'           => 'sometimes|boolean', // MUL-046 Item 5: checkout parcial
            // MUL-046: itens bipados no packing (opcional — retro-compat)
            'scanned_items'              => 'nullable|array',
            'scanned_items.*.sku'        => 'required_with:scanned_items|string|max:255',
            'scanned_items.*.ean'        => 'nullable|string|max:100',
            'scanned_items.*.scanned_at' => 'nullable|date',
        ]);
        $isPartial = !empty($data['partial']);

        // NOV-112 B1: order_id aceita legacy_id OU id do banco novo.
        // Para pedidos legacy-only (nao importados ainda), opera direto no legado.
        $order = Order::where('supplier_id', $this->supplierId())
            ->where('legacy_id', $data['order_id'])
            ->first()
            ?? Order::where('supplier_id', $this->supplierId())
                ->where('id', $data['order_id'])
                ->first();

        if (!$order) {
            // Pedido existe so no legado (legacy_id > range importado) - despacha via bridge.
            $legacyId = (int) $data['order_id'];
            $isPartial = !empty($data['partial']);
            try {
                $shipRes = $this->bridge->pickingAction('ship', $legacyId);
                if (!($shipRes['success'] ?? false)) {
                    DB::connection('legacy')->statement('UPDATE pedidos SET dt_enviado_tranpostadora_flex = NOW() WHERE id = ?', [$legacyId]);
                }
            } catch (\Throwable $e) {
                try {
                    DB::connection('legacy')->statement('UPDATE pedidos SET dt_enviado_tranpostadora_flex = NOW() WHERE id = ?', [$legacyId]);
                } catch (\Throwable $e2) {}
            }
            return response()->json(['data' => [
                'order_id'                => $legacyId,
                'order_processing_status' => $isPartial ? 'partially_packed' : 'shipped',
                'shipped_at'              => now()->toDateTimeString(),
                'nfe_status'              => 'skipped',
                'already_shipped'         => false,
                'partial'                 => $isPartial,
                'legacy_only'             => true,
            ]]);
        }
        // FOR-037: guard de pagamento — bloqueia envio sem PIX confirmado
        if (!in_array($order->order_processing_status, ['shipped', 'partially_packed'])) {
            $this->assertPaymentConfirmed($order);
        }

        $alreadyShipped = in_array($order->order_processing_status, ['shipped', 'partially_packed']);

        if (!$alreadyShipped) {
            // MUL-046 Item 5: partial=true => status partially_packed; caso contrario => shipped
            $newStatus = $isPartial ? 'partially_packed' : 'shipped';
            $updateData = ['order_processing_status' => $newStatus];
            if (!$isPartial) {
                $updateData['shipped_at'] = now();
                // MUL-093: atualizar canonical_status e status para refletir envio
                // sem isso, filtro "Enviado" do lojista nao encontra o pedido
                $updateData['canonical_status'] = 'shipped';
                $updateData['status']           = 'shipped';
            }
            $order->update($updateData);

            // FIX 4: chamar bridge op=ship para atualizar legado via picking_packing.php.
            try {
                $shipRes = $this->bridge->pickingAction('ship', (int) $data['order_id']);
                if (!($shipRes['success'] ?? false)) {
                    \Illuminate\Support\Facades\Log::warning('[PickingPacking] Bridge ship falhou: ' . ($shipRes['error'] ?? 'unknown'));
                    DB::connection('legacy')->statement('UPDATE pedidos SET dt_enviado_tranpostadora_flex = NOW() WHERE id = ?', [$data['order_id']]);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[PickingPacking] Bridge ship exception: ' . $e->getMessage());
                try { DB::connection('legacy')->statement('UPDATE pedidos SET dt_enviado_tranpostadora_flex = NOW() WHERE id = ?', [$data['order_id']]); } catch (\Throwable $e2) {}
            }

            // FIX 3: persistir bipe em order_beeps para audit trail do operador.
            try {
                DB::table('order_beeps')->updateOrInsert(
                    ['order_id' => $order->id],
                    ['order_id' => $order->id, 'beeped_at' => now(), 'beeped_by' => $request->user()?->id, 'updated_at' => now(), 'created_at' => now()]
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[PickingPacking] Falhou gravar order_beeps: ' . $e->getMessage());
            }
        }

        // MUL-046: persistir scanned_at por item bipado no packing
        if (!empty($data['scanned_items'])) {
            foreach ($data['scanned_items'] as $scanned) {
                $sku       = (string) ($scanned['sku'] ?? '');
                $scannedAt = $scanned['scanned_at'] ?? now()->toDateTimeString();
                if ($sku === '') continue;
                try {
                    DB::table('order_items')
                        ->where('order_id', $order->id)
                        ->where('sku', $sku)
                        ->whereNull('scanned_at')
                        ->update(['scanned_at' => $scannedAt, 'updated_at' => now()]);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[PickingPacking] Falhou gravar scanned_at item: ' . $e->getMessage());
                }
            }
        }

        // MUL-275: EmitBlingNfeJob nunca existiu (dispatch caia no catch em todo bip).
        // Emissao de NF-e e manual via botao Emitir NF-e (BlingNfeService).
        $nfeStatus = 'manual';

        $order->refresh();
        return response()->json(['data' => [
            'order_id'                => $order->id,
            'order_processing_status' => $order->order_processing_status,
            'shipped_at'              => $order->shipped_at,
            'nfe_status'              => $nfeStatus,
            'already_shipped'         => $alreadyShipped,
            'partial'                 => $isPartial, // MUL-046 Item 5
        ]]);
    }

    public function updateClientPhone(Request $request, int $id): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $sup = $this->supplierId();
        $clientIds = Order::where("supplier_id", $sup)->distinct()->pluck("client_id")
            ->merge(DB::table("marketplace_accounts")->where("supplier_id", $sup)->distinct()->pluck("client_id"));
        if (!$clientIds->contains($id)) abort(403, "Cliente nao pertence a este fornecedor.");
        $data = $request->validate(["phone" => "required|string|min:8|max:20"]);
        $phone = preg_replace("/\D/", "", $data["phone"]);
        \App\Models\Client::where("id", $id)->update(["phone" => $phone]);
        return response()->json(["data" => ["id" => $id, "phone" => $phone]]);
    }

    /**
     * POST /api/v1/supplier-admin/clients/{id}/wallet-adjust — MUL-215
     *
     * Ajuste manual da carteira do cliente pelo admin do fornecedor
     * (modelo do legado conta_corrente: ledger C/D com motivo e autor).
     * type: credit (adicionar saldo) | bonus (bonificacao) | debit (remover saldo).
     * Saldo negativo E permitido no ajuste do admin (fluxos de pagamento
     * continuam bloqueando saldo insuficiente).
     */
    public function walletAdjust(Request $request, int $id): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $sup = $this->supplierId();
        $clientIds = Order::where("supplier_id", $sup)->distinct()->pluck("client_id")
            ->merge(DB::table("marketplace_accounts")->where("supplier_id", $sup)->distinct()->pluck("client_id"));
        if (!$clientIds->contains($id)) abort(403, "Cliente nao pertence a este fornecedor.");

        $data = $request->validate([
            'type'        => 'required|in:credit,debit,bonus',
            'amount'      => 'required|numeric|min:0.01|max:1000000',
            'description' => 'required|string|min:3|max:180',
        ]);

        $admin  = $request->user();
        $amount = round((float) $data['amount'], 2);
        $isDebit = $data['type'] === 'debit';

        $result = DB::transaction(function () use ($id, $sup, $data, $admin, $amount, $isDebit) {
            // MUL-363 Fase 3: ajuste admin via nucleo canonico (remocao pode negativar)
            $labels = ['credit' => 'Ajuste de saldo (admin)', 'bonus' => 'Bonus (admin)', 'debit' => 'Remocao de saldo (admin)'];
            $meta = new \App\Services\Financial\Ledger\LedgerEntryMeta(
                type: $data['type'] === 'bonus' ? 'wallet_bonus' : 'wallet_adjust',
                description: sprintf('%s por %s: %s', $labels[$data['type']], $admin?->email ?? 'admin', $data['description']),
                actor: 'user:' . ($admin?->id ?? 0),
                reference: 'admin:' . ($admin?->id ?? 0),
            );
            $ledger = app(\App\Services\Financial\Ledger\WalletLedger::class);
            $tx = $isDebit
                ? $ledger->debit($id, $sup, $amount, $meta, true)
                : $ledger->credit($id, $sup, $amount, $meta);

            return ['balance' => (float) $tx->running_balance, 'transaction_id' => $tx->id];
        });

        \Illuminate\Support\Facades\Log::channel('single')->info('[MUL-215] walletAdjust', [
            'admin_id' => $admin?->id, 'client_id' => $id, 'supplier_id' => $sup,
            'type' => $data['type'], 'amount' => $amount, 'new_balance' => $result['balance'],
        ]);

        return response()->json([
            'success'        => true,
            'balance'        => $result['balance'],
            'transaction_id' => $result['transaction_id'],
            'message'        => 'Ajuste registrado.',
        ]);
    }

    /**
     * PATCH /api/v1/supplier-admin/clients/{id}/blocked
     * Bloqueia/desbloqueia o cliente. Body: {"blocked": bool}
     */
    /**
     * PATCH /api/v1/supplier-admin/clients/{id}
     * MUL: admin edita dados completos do seller (PJ/PF/endereco/filiacao).
     */
    public function updateClientFull(Request $request, int $id): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $sup = $this->supplierId();
        $clientIds = Order::where("supplier_id", $sup)->distinct()->pluck("client_id")
            ->merge(DB::table("marketplace_accounts")->where("supplier_id", $sup)->distinct()->pluck("client_id"));
        if (!$clientIds->contains($id)) abort(403, "Cliente nao pertence a este fornecedor.");
        // MUL-269 fase 2: campos pessoais (full_name/birth_date/rg/mother_name/
        // father_name) e o antigo company_name foram removidos de clients — os
        // pessoais passam a atualizar o USER conectado; company_name deixou de
        // existir (nome vem do user via accessor).
        $data = $request->validate([
            "person_type" => "nullable|in:PF,PJ",
            "document" => "nullable|string|max:20",
            "phone" => "nullable|string|max:30",
            "legal_name" => "nullable|string|max:255",
            "trade_name" => "nullable|string|max:255",
            "state_registration" => "nullable|string|max:30",
            "full_name" => "nullable|string|max:255",
            // MUL-338: CPF do responsavel. So digito, 11 posicoes — a coluna e varchar(11).
            "cpf" => "nullable|string|max:14",
            "birth_date" => "nullable|date",
            "mother_name" => "nullable|string|max:255",
            "father_name" => "nullable|string|max:255",
            "rg" => "nullable|string|max:30",
            "address_cep" => "nullable|string|max:10",
            "address_street" => "nullable|string|max:255",
            "address_number" => "nullable|string|max:20",
            "address_complement" => "nullable|string|max:100",
            "address_neighborhood" => "nullable|string|max:100",
            "address_city" => "nullable|string|max:100",
            "address_state" => "nullable|string|max:2",
            // MUL-309: campos que faltavam para a tela de edicao unica do cliente.
            "name" => "nullable|string|max:255",
            "email" => "nullable|email|max:255",
            "is_active" => "nullable|boolean",
            "nfe_email" => "nullable|email|max:255",
            "ie_indicator" => "nullable|string|max:5",
            "municipal_registration" => "nullable|string|max:30",
            // MUL-309: plano no mesmo save -- a tela unica troca plano junto com o resto.
            "plan_id" => "nullable|integer|exists:plans,id",
            // MUL-312: status do perfil manda no acesso. Mesmos tres valores que o
            // filtro da lista e que o showClient ja devolvem.
            "status" => "nullable|in:active,inactive,blocked",
        ]);
        if (isset($data["document"])) {
            $data["document"] = preg_replace("/[^0-9]/", "", $data["document"]) ?: null;
            if (!isset($data["person_type"]) && $data["document"]) {
                $data["person_type"] = strlen($data["document"]) === 14 ? "PJ" : (strlen($data["document"]) === 11 ? "PF" : null);
            }
        }
        if (isset($data["address_cep"])) $data["address_cep"] = preg_replace("/[^0-9]/", "", $data["address_cep"]) ?: null;
        if (isset($data["cpf"])) $data["cpf"] = preg_replace("/[^0-9]/", "", $data["cpf"]) ?: null;

        // Separar campos pessoais (users) dos campos do client.
        // MUL-309: name e email tambem sao do user (login), nao do client.
        $userFields = ['full_name', 'birth_date', 'mother_name', 'father_name', 'rg', 'name', 'email', 'cpf'];
        $userData = [];
        foreach ($userFields as $f) {
            if (array_key_exists($f, $data)) {
                $userData[$f] = $data[$f];
                unset($data[$f]);
            }
        }

        // MUL-312: a tela manda UM campo de status; aqui ele vira as tres colunas que
        // existem no banco. blocked_at NAO esta no $fillable de Client (proposital),
        // entao nao pode ir junto no $data -- vai por query builder mais abaixo.
        $blockedAtNovo = 'nao-mexer';
        if (array_key_exists('status', $data)) {
            $st = $data['status'];
            unset($data['status']);
            if ($st === 'active')       { $data['is_active'] = 1; $blockedAtNovo = null; }
            elseif ($st === 'inactive') { $data['is_active'] = 0; $blockedAtNovo = null; }
            elseif ($st === 'blocked')  { $blockedAtNovo = now(); }
        }

        // MUL-310: "Ativo" e UM campo na tela, mas o acesso depende de DUAS colunas --
        // users.is_active (AuthController::login e middleware CheckUserActive) e
        // clients.is_active. Escrever so a de clients deixava o cliente aparecendo
        // "Ativo" na lista e recebendo 403 "Conta bloqueada" no login. Espelha nas duas.
        if (array_key_exists('is_active', $data)) {
            $userData['is_active'] = (bool) $data['is_active'];
        }

        $client = \App\Models\Client::findOrFail($id);

        // MUL-309: plano nao e coluna de clients -- sai do $data e vira troca de assinatura.
        $planoNovo = null;
        if (array_key_exists('plan_id', $data)) {
            $planoNovo = $data['plan_id'];
            unset($data['plan_id']);
        }

        // MUL-309: e-mail e login. Duplicar derruba o acesso do outro cliente.
        if (!empty($userData['email'])) {
            $emailEmUso = \App\Models\User::where('email', $userData['email'])
                ->where('id', '!=', $client->user_id)
                ->exists();
            if ($emailEmUso) {
                return response()->json([
                    'error' => 'email_em_uso: ja existe outro usuario com este e-mail.',
                ], 422);
            }
        }

        if (!empty($data)) {
            $client->update($data);
        }
        if (!empty($userData) && $client->user_id && $client->user) {
            $client->user->update($userData);
        }

        // MUL-312: blocked_at fora do fillable -- query builder, igual updateClientBlocked.
        if ($blockedAtNovo !== 'nao-mexer') {
            \App\Models\Client::where('id', $client->id)->update(['blocked_at' => $blockedAtNovo]);
        }

        // MUL-309: so mexe na assinatura se o plano REALMENTE mudou. Trocar plano
        // cancela a sub ativa e cria outra -- nao pode acontecer por um save distraido.
        if ($planoNovo !== null) {
            $planoAtual = \App\Models\Subscription::where('client_id', $client->id)
                ->where('status', 'active')->value('plan_id');

            if ((int) $planoAtual !== (int) $planoNovo) {
                $plano = \App\Models\Plan::find($planoNovo);
                if (!$plano || !$plano->is_active) {
                    return response()->json(['error' => 'plan_inactive', 'message' => 'Plano nao esta ativo'], 422);
                }

                DB::transaction(function () use ($client, $planoNovo) {
                    \App\Models\Subscription::where('client_id', $client->id)
                        ->where('status', 'active')
                        ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'updated_at' => now()]);

                    // MUL-314: started_at nao existe na tabela nem no fillable -- o campo
                    // era descartado em silencio pelo mass assignment e a assinatura
                    // nascia sem periodo. Mesmas colunas que o Filament usa.
                    \App\Models\Subscription::create([
                        'client_id'            => $client->id,
                        'plan_id'              => $planoNovo,
                        'status'               => 'active',
                        'payment_method'       => 'manual',
                        'current_period_start' => now(),
                        'current_period_end'   => now()->addMonth(),
                    ]);
                });

                \Illuminate\Support\Facades\Log::info('[MUL-309] plano trocado pela tela de edicao', [
                    'client_id' => $client->id, 'de' => $planoAtual, 'para' => $planoNovo,
                ]);
            }
        }

        return response()->json(["data" => \App\Models\Client::with('user:id,name,full_name,birth_date,mother_name,father_name,rg')->find($id)]);
    }

    public function updateClientBlocked(Request $request, int $id): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $sup = $this->supplierId();
        $clientIds = Order::where("supplier_id", $sup)->distinct()->pluck("client_id")
            ->merge(DB::table("marketplace_accounts")->where("supplier_id", $sup)->distinct()->pluck("client_id"));
        if (!$clientIds->contains($id)) abort(403, "Cliente nao pertence a este fornecedor.");
        $data = $request->validate(["blocked" => "required|boolean"]);
        \App\Models\Client::where("id", $id)->update(["blocked_at" => $data["blocked"] ? now() : null]);
        return response()->json(["data" => ["id" => $id, "blocked" => (bool) $data["blocked"]]]);
    }

    /**
     * POST /api/v1/supplier-admin/clients/{id}/change-plan
     * Body: { plan_id: int }
     * MUL: admin troca plano do seller — cancela sub ativa, cria nova.
     */
    public function changePlan(Request $request, int $id): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $sup = $this->supplierId();
        $clientIds = Order::where("supplier_id", $sup)->distinct()->pluck("client_id")
            ->merge(DB::table("marketplace_accounts")->where("supplier_id", $sup)->distinct()->pluck("client_id"));
        if (!$clientIds->contains($id)) abort(403, "Cliente nao pertence a este fornecedor.");
        $data = $request->validate([
            "plan_id" => "required|integer|exists:plans,id",
        ]);
        $plan = \App\Models\Plan::find($data["plan_id"]);
        if (!$plan || !$plan->is_active) return response()->json(["error" => "plan_inactive", "message" => "Plano nao esta ativo"], 422);

        DB::transaction(function () use ($id, $data, $plan) {
            // Cancela subs ativas do cliente
            \App\Models\Subscription::where("client_id", $id)
                ->where("status", "active")
                ->update([
                    "status" => "cancelled",
                    "cancelled_at" => now(),
                    "updated_at" => now(),
                ]);
            // Cria nova sub ativa
            \App\Models\Subscription::create([
                "client_id" => $id,
                "plan_id" => $data["plan_id"],
                "status" => "active",
                "current_period_start" => now(),
                "current_period_end" => now()->addMonth(),
                "is_grandfathered" => 0,
            ]);
        });
        return response()->json(["data" => [
            "id" => $id,
            "plan_id" => $plan->id,
            "plan_name" => $plan->name,
            "plan_slug" => $plan->slug,
        ]]);
    }

    /**
     * GET /api/v1/supplier-admin/plans/available
     * Lista planos ativos pra dropdown do admin.
     */
    public function plansAvailable(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $plans = \App\Models\Plan::where("is_active", 1)
            ->orderBy("category")->orderBy("price_monthly")
            ->get(["id","name","slug","category","price_monthly","price_yearly","trial_days","description"]);
        return response()->json(["data" => $plans]);
    }

    /**
     * PATCH /api/v1/supplier-admin/products/{id}/toggle-active
     * Alterna is_active. Aceita opcionalmente {"is_active": bool} para SETAR explicitamente.
     */
    public function toggleActive(Request $request, int $id): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $p = \App\Models\Product::whereIn('supplier_id', $this->supplierIdsForCatalog())->find($id);
        if (!$p) return response()->json(['error' => 'Produto nao encontrado'], 404);

        $data = $request->validate([
            'is_active' => 'sometimes|boolean',
        ]);

        $p->is_active = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : !$p->is_active;
        $p->save();

        return response()->json(['data' => ['id' => $p->id, 'is_active' => (bool) $p->is_active]]);
    }

    /**
     * PUT /api/v1/supplier-admin/products/{id}/stock
     * Define o estoque consolidado do produto (substitui, nao soma).
     */
    public function updateStock(Request $request, int $id): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $p = \App\Models\Product::whereIn('supplier_id', $this->supplierIdsForCatalog())->find($id);
        if (!$p) return response()->json(['error' => 'Produto nao encontrado'], 404);

        $data = $request->validate([
            'quantity' => 'required|integer|min:0',
        ]);

        $this->upsertStock($p->id, (int) $data['quantity']);

        return response()->json([
            'message'  => 'Estoque atualizado com sucesso.',
            'quantity' => (int) $data['quantity'],
        ]);
    }

    // =========================================================================
    // MUL-152 — Ajuste manual de estoque com auditoria (inventory_movements)
    // =========================================================================

    /**
     * POST /api/v1/supplier-admin/products/{id}/stock-adjust
     *
     * Body:
     *   quantity_change  (int, pode ser negativo) — delta a aplicar, OU
     *   new_quantity     (int >= 0) — define valor absoluto
     *   reason           (string, obrigatorio) — motivo do ajuste
     *
     * Grava inventory_movement via InventoryMovementService::record/recordManualAdjust
     * (mesmo caminho que o Filament usa — sem bypass de coluna direto).
     * Retorna: estoque atual por deposito + dados do movimento criado.
     */
    public function stockAdjust(Request $request, int $id): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $supplierId = $this->supplierId();

        // MUL-296: stock segue o fornecedor do produto (Multdrop 30 ou Filial 31),
        // nao o fornecedor primario da instalacao.
        $p = \App\Models\Product::whereIn('supplier_id', $this->supplierIdsForCatalog())->find($id);
        if (!$p) return response()->json(['error' => 'Produto nao encontrado'], 404);
        $supplierId = (int) $p->supplier_id;

        $data = $request->validate([
            'quantity_change' => 'nullable|integer',
            'new_quantity'    => 'nullable|integer|min:0',
            'reason'          => 'required|string|max:500',
        ]);

        $hasChange = array_key_exists('quantity_change', $data) && $data['quantity_change'] !== null;
        $hasNew    = array_key_exists('new_quantity', $data)    && $data['new_quantity'] !== null;

        if (!$hasChange && !$hasNew) {
            return response()->json(['error' => 'Informe quantity_change ou new_quantity'], 422);
        }
        if ($hasChange && $hasNew) {
            return response()->json(['error' => 'Envie apenas quantity_change OU new_quantity, nao os dois'], 422);
        }

        // Busca ou cria registro de inventory para este supplier/produto
        $inventory = \App\Models\Inventory::firstOrCreate(
            ['product_id' => $p->id, 'producer_id' => $supplierId],
            ['warehouse_id' => $supplierId, 'quantity' => 0, 'reserved' => 0]
        );

        /** @var \App\Services\Inventory\InventoryMovementService $svc */
        $svc = app(\App\Services\Inventory\InventoryMovementService::class);

        if ($hasNew) {
            // Ajuste absoluto — recordManualAdjust calcula o delta
            $movement = $svc->recordManualAdjust(
                $inventory,
                (int) $data['new_quantity'],
                'ajuste',
                $data['reason'],
                $request->user()?->id
            );
        } else {
            // Delta relativo
            $delta    = (int) $data['quantity_change'];
            $type     = $delta >= 0 ? 'entrada' : 'saida';
            $movement = $svc->record($inventory, $type, $delta, [
                'notes'          => $data['reason'],
                'user_id'        => $request->user()?->id,
                'reference_type' => 'manual',
            ]);
        }

        $inventory->refresh();

        return response()->json([
            'message'     => 'Estoque ajustado com sucesso.',
            'movement_id' => $movement->id,
            'qty_before'  => $movement->qty_before,
            'qty_after'   => $movement->qty_after,
            'qty_change'  => $movement->qty_change,
            'current_stock_by_warehouse' => $this->currentStockByWarehouse($p->id, $supplierId),
        ]);
    }

    /**
     * GET /api/v1/supplier-admin/products/{id}/stock-info
     *
     * Retorna estoque atual por deposito + ultimos 5 movimentos do produto.
     * Usado pela aba Estoque no frontend ao abrir o modal de edicao.
     */
    public function stockInfo(Request $request, int $id): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $supplierId = $this->supplierId();

        // MUL-296: stock segue o fornecedor do produto (Multdrop 30 ou Filial 31),
        // nao o fornecedor primario da instalacao.
        $p = \App\Models\Product::whereIn('supplier_id', $this->supplierIdsForCatalog())->find($id);
        if (!$p) return response()->json(['error' => 'Produto nao encontrado'], 404);
        $supplierId = (int) $p->supplier_id;

        $stockByWarehouse = $this->currentStockByWarehouse($p->id, $supplierId);

        $recentMovements = \App\Models\InventoryMovement::query()
            ->withoutGlobalScopes()
            ->where('product_id', $p->id)
            ->where('supplier_id', $supplierId)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($m) => [
                'id'         => $m->id,
                'type'       => $m->type,
                'qty_before' => $m->qty_before,
                'qty_change' => $m->qty_change,
                'qty_after'  => $m->qty_after,
                'notes'      => $m->notes,
                'user'       => $m->user?->name,
                'created_at' => $m->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'product_id'          => $p->id,
            'current_stock_total' => (int) collect($stockByWarehouse)->sum('quantity'),
            'stock_by_warehouse'  => $stockByWarehouse,
            'recent_movements'    => $recentMovements,
        ]);
    }

    /**
     * Helper: estoque atual por deposito para um produto/supplier.
     */
    private function currentStockByWarehouse(int $productId, int $supplierId): array
    {
        return \App\Models\Inventory::query()
            ->withoutGlobalScopes()
            ->where('product_id', $productId)
            ->where('producer_id', $supplierId)
            ->get()
            ->map(fn ($inv) => [
                'inventory_id' => $inv->id,
                'warehouse_id' => $inv->warehouse_id,
                'quantity'     => (int) $inv->quantity,
                'reserved'     => (int) $inv->reserved,
                'available'    => max(0, (int) $inv->quantity - (int) $inv->reserved),
            ])
            ->all();
    }
    // =========================================================================
    // Localizacao de produto no armazem -- NOV-110
    // =========================================================================

    /** GET /api/v1/supplier-admin/products/{id}/location */
    public function getProductLocation(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $p = \App\Models\Product::whereIn('supplier_id', $this->supplierIdsForCatalog())->find($id);
        if (!$p) return response()->json(['error' => 'Produto nao encontrado'], 404);

        return response()->json([
            'data' => [
                'id'                 => $p->id,
                'sku'                => $p->sku,
                'name'               => $p->name,
                'warehouse_location' => $p->warehouse_location,
            ],
        ]);
    }

    /** PATCH /api/v1/supplier-admin/products/{id}/location */
    public function updateProductLocation(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $p = \App\Models\Product::whereIn('supplier_id', $this->supplierIdsForCatalog())->find($id);
        if (!$p) return response()->json(['error' => 'Produto nao encontrado'], 404);

        $data = $request->validate([
            'warehouse_location' => 'nullable|string|max:100',
        ]);

        $p->warehouse_location = $data['warehouse_location'] ?? null;
        $p->save();

        return response()->json([
            'message'             => 'Localizacao atualizada com sucesso.',
            'warehouse_location' => $p->warehouse_location,
        ]);
    }


    // =========================================================================
    // Relatorio de separacao com filtros -- NOV-110
    // =========================================================================

    /**
     * GET /api/v1/supplier-admin/picking/separation-report
     *
     * Filtros:
     *   ?date_from=YYYY-MM-DD  filtro data pagamento (inicio)
     *   ?date_to=YYYY-MM-DD    filtro data pagamento (fim)
     *   ?group_by=product      agrupa por SKU (padrao)
     *   ?with_stock=true       inclui quantidade em estoque no resultado
     */
    public function pickingSeparationReport(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $idLoja    = $this->legacyLojaId($request);
        $dateFrom  = $request->query('date_from');
        $dateTo    = $request->query('date_to');
        $withStock = filter_var($request->query('with_stock', 'false'), FILTER_VALIDATE_BOOLEAN);

        // Construir filtros de data
        $dateFilter = '';
        $dateParams = [$idLoja];
        if ($dateFrom) {
            $dateFilter .= ' AND p.curso_pago >= ?';
            $dateParams[] = $dateFrom . ' 00:00:00';
        } else {
            $dateFilter .= " AND p.curso_pago >= '2026-01-01'";
        }
        if ($dateTo) {
            $dateFilter .= ' AND p.curso_pago <= ?';
            $dateParams[] = $dateTo . ' 23:59:59';
        }

        $rows = DB::connection('legacy')->select("
            SELECT p.id, p.nr_canal AS codigo_pedido, p.id_canal
            FROM pedidos p
            WHERE p.id_loja = ?
              AND p.url_img IS NOT NULL AND p.url_img != ''
              AND p.dt_enviado_tranpostadora_flex IS NULL
              AND (p.status_marketplace IS NULL OR p.status_marketplace IN ('READY_TO_SHIP','PROCESSED'))
              {$dateFilter}
            ORDER BY p.id DESC
        ", $dateParams);

        $legacyIds = array_map(fn($r) => (int)$r->id, $rows);

        if (empty($legacyIds)) {
            return response()->json(['data' => ['items' => [], 'total_pedidos' => 0, 'total_itens' => 0, 'gerado_em' => now()->toIso8601String()]]);
        }

        $pedidoMap = [];
        foreach ($rows as $r) {
            $pedidoMap[(int)$r->id] = ['codigo_pedido' => $r->codigo_pedido, 'id_canal' => (int)$r->id_canal];
        }

        $placeholders = implode(',', array_fill(0, count($legacyIds), '?'));
        $products = DB::connection('legacy')->select("
            SELECT pp.id_pedido, pp.descricao, pp.sku, pp.foto, pp.qtd
            FROM pedidos_produtos pp
            WHERE pp.id_pedido IN ({$placeholders})
            ORDER BY pp.id_pedido ASC
        ", $legacyIds);

        // Se with_stock=true, buscar estoque dos SKUs no novo banco
        $stockMap = [];
        if ($withStock) {
            $allSkus = array_values(array_filter(array_unique(
                array_map(fn($r) => $r->sku ?? null, $products)
            )));
            if (!empty($allSkus)) {
                // NOV-110: estoque via inventory JOIN (products nao tem coluna quantity)
                $stockRows = DB::table('products')
                    ->where('products.supplier_id', $this->supplierId())
                    ->whereIn('products.sku', $allSkus)
                    ->leftJoin(
                        DB::raw('(SELECT product_id, SUM(quantity) AS total_qty FROM inventory GROUP BY product_id) AS inv'),
                        'inv.product_id', '=', 'products.id'
                    )
                    ->select('products.sku', DB::raw('COALESCE(inv.total_qty, 0) AS effective_stock'))
                    ->get();
                foreach ($stockRows as $sr) {
                    $stockMap[(string)$sr->sku] = (int)$sr->effective_stock;
                }
            }
        }

        $canalNames = [3 => 'Shopee', 6 => 'ML', 1 => 'ML', 7 => 'Magalu', 13 => 'TikTok', 10 => 'Amazon'];

        $bySkus = [];
        foreach ($products as $prod) {
            $sku = !empty($prod->sku) ? $prod->sku : ('NOSKU_' . md5((string)($prod->descricao ?? '')));
            if (!isset($bySkus[$sku])) {
                $bySkus[$sku] = [
                    'sku'              => $prod->sku ?? '',
                    'name'             => $prod->descricao ?? 'Sem nome',
                    'photo'            => $prod->foto ?? null,
                    'quantity_needed'  => 0,
                    'quantity_in_stock' => $withStock ? ($stockMap[$prod->sku ?? ''] ?? 0) : null,
                    'orders'           => [],
                ];
            }
            $qtd      = (int)($prod->qtd ?? 1);
            $idPedido = (int)$prod->id_pedido;
            $canal    = $canalNames[$pedidoMap[$idPedido]['id_canal'] ?? 0] ?? ('Canal ' . ($pedidoMap[$idPedido]['id_canal'] ?? '?'));
            $bySkus[$sku]['quantity_needed'] += $qtd;
            $bySkus[$sku]['orders'][] = [
                'id'            => $idPedido,
                'codigo_pedido' => $pedidoMap[$idPedido]['codigo_pedido'] ?? null,
                'canal'         => $canal,
                'qtd'           => $qtd,
            ];
        }

        usort($bySkus, fn($a, $b) => $b['quantity_needed'] <=> $a['quantity_needed']);

        return response()->json(['data' => [
            'items'         => array_values($bySkus),
            'total_pedidos' => count($legacyIds),
            'total_itens'   => array_sum(array_column(array_values($bySkus), 'quantity_needed')),
            'gerado_em'     => now()->toIso8601String(),
            'filters'       => [
                'date_from'  => $dateFrom,
                'date_to'    => $dateTo,
                'with_stock' => $withStock,
            ],
        ]]);
    }


    // =========================================================================
    // Impressao em lote de etiquetas -- MUL-043 / NOV-075
    // =========================================================================

    /**
     * POST /api/v1/supplier-admin/picking/print-batch
     *
     * Recebe um lote de order_ids, marca label_printed_at nos pedidos
     * que ainda nao foram impressos, registra o lote em label_print_logs
     * e retorna as URLs de etiqueta para o frontend abrir/imprimir.
     */
    /**
     * JT-008: POST /api/v1/supplier-admin/orders/mark-labels-printed
     *
     * Marca N pedidos como impressos (label_printed_at = now) apos o usuario
     * confirmar no popup de impressao. Escopo restrito ao supplier do user.
     */
    public function markLabelsPrinted(Request $request)
    {
        // INF-054 R1: se WL, encaminha pro hub. Precisa traduzir order_ids locais -> hubai_order_ids
        if (HubProxyHelper::isWl()) {
            $localIds = (array) $request->input("order_ids", []);
            if (empty($localIds)) return response()->json(["error" => "order_ids required"], 422);
            $hubIds = \App\Models\Order::whereIn("id", $localIds)->pluck("hubai_order_id", "id")->filter()->values()->all();
            if (empty($hubIds)) return response()->json(["error" => "no_hub_ids_mapped"], 422);
            return HubProxyHelper::forwardToHub("post", "/orders/mark-labels-printed", ["order_ids" => $hubIds]);
        }

        $this->requireSupplierAdmin($request);

        $validated = $request->validate([
            'order_ids'   => 'required|array|min:1|max:200',
            'order_ids.*' => 'required|integer',
        ]);

        $supplierId = $this->supplierId();
        $now        = now();

        $ids = collect($validated['order_ids'])->unique()->values();

        $affected = Order::whereIn('id', $ids->all())
            ->where('supplier_id', $supplierId)
            ->whereNotNull('label_url')
            ->where('label_url', '<>', '')
            ->whereNull('label_printed_at')
            ->update([
                'label_printed_at' => $now,
                'updated_at'       => $now,
            ]);

        if ($affected > 0) {
            $affectedIds = Order::whereIn('id', $ids->all())
                ->where('supplier_id', $supplierId)
                ->where('label_printed_at', $now)
                ->pluck('id')->toArray();

            \App\Jobs\EmitBlingNfeJob::dispatchIfTrigger($affectedIds, 'label_printed'); // MUL-276

            LabelPrintLog::create([
                'supplier_id'  => $supplierId,
                'user_id'      => auth()->id(),
                'order_ids'    => $affectedIds,
                'batch_size'   => $affected,
                'marketplace'  => null,
                'printer_type' => null,
                'printed_at'   => $now,
            ]);
        }

        return response()->json([
            'success'    => true,
            'count'      => $affected,
            'printed_at' => $now->toIso8601String(),
        ]);
    }

    public function printBatch(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);

        // Aceita formato novo: orders=[{legacy_id, label_url, tracking_number}]
        // O frontend envia diretamente os dados que ja tem da fila.
        $validated = $request->validate([
            'orders'                    => 'required|array|min:1|max:100',
            'orders.*.legacy_id'        => 'required|integer',
            'orders.*.label_url'        => 'required|string',
            'orders.*.tracking_number'  => 'nullable|string',
            'printer_type'              => 'nullable|string|in:zebra,a4',
        ]);

        $supplierId = $this->supplierId();
        $now = now();

        $legacyIds = collect($validated['orders'])->pluck('legacy_id');

        // Marca como impressos os pedidos MultDrop correspondentes
        Order::whereIn('legacy_id', $legacyIds)
            ->where('supplier_id', $supplierId)
            ->whereNull('label_printed_at')
            ->update(['label_printed_at' => $now]);

        // IDs MultDrop para o log
        $multdropIds = Order::whereIn('legacy_id', $legacyIds)
            ->where('supplier_id', $supplierId)
            ->pluck('id')->toArray();

        \App\Jobs\EmitBlingNfeJob::dispatchIfTrigger($multdropIds, 'label_printed'); // MUL-276

        $log = LabelPrintLog::create([
            'supplier_id'  => $supplierId,
            'user_id'      => auth()->id(),
            'order_ids'    => $multdropIds,
            'batch_size'   => count($validated['orders']),
            'marketplace'  => null,
            'printer_type' => $validated['printer_type'] ?? null,
            'printed_at'   => $now,
        ]);

        $labels = collect($validated['orders'])->map(fn($o) => [
            'order_id'        => $o['legacy_id'],
            'label_url'       => $this->absoluteLabelUrl($o['label_url']),
            'tracking_number' => $o['tracking_number'] ?? null,
            'marketplace'     => '',
        ])->values();

        return response()->json([
            'success'    => true,
            'batch_id'   => $log->id,
            'count'      => count($validated['orders']),
            'printed_at' => $now->toIso8601String(),
            'labels'     => $labels,
        ]);
    }

    /**
     * GET /api/v1/supplier-admin/picking/print-history
     *
     * Retorna os ultimos 50 lotes de impressao do fornecedor,
     * ordenados do mais recente para o mais antigo.
     */
    public function printHistory(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);

        $supplierId = $this->supplierId();

        $logs = LabelPrintLog::where('supplier_id', $supplierId)
            ->orderByDesc('printed_at')
            ->limit(50)
            ->get(['id', 'order_ids', 'batch_size', 'marketplace', 'printer_type', 'printed_at', 'user_id']);

        return response()->json(['data' => $logs]);
    }


    /**
     * Converte URL relativa de etiqueta em URL absoluta do backend atual.
     * MUL-137: usa config(app.url) em vez de hardcode api.multdrop.app
     */
    private function absoluteLabelUrl(?string $url): ?string
    {
        if (empty($url)) {
            return $url;
        }
        if (str_starts_with($url, 'http')) {
            return $url;
        }
        // JT-008: URL vai pelo proxy do JT (mesma origem = sem CORS).
        // Backend server-side busca no hub central e streama, sem duplicar arquivo.
        $appUrl = rtrim(config('app.url'), '/');
        $path   = str_starts_with($url, '/') ? $url : '/' . $url;
        return $appUrl . '/api/v1/proxy' . $path;
    }

    /**
     * JT-008: GET /api/v1/proxy/storage/labels/{filename}
     *
     * Proxy do arquivo de etiqueta do hub central (api.hubai.io).
     * Frontend chama api.jtdrop.com.br (mesma origem = sem CORS).
     * Backend server-side busca no hub e streama. Zero duplicacao de arquivo.
     */
    public function proxyStorageLabel(Request $request, string $filename)
    {
        $this->requireSupplierAdmin($request);
        // Guard basico: filename so pode ter caracteres seguros
        if (preg_match('/[^a-zA-Z0-9._\-]/', $filename)) {
            return response('invalid filename', 400);
        }
        $ext  = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'pdf'          => 'application/pdf',
            'jpg', 'jpeg'  => 'image/jpeg',
            'png'          => 'image/png',
            'gif'          => 'image/gif',
            'webp'         => 'image/webp',
            default        => 'application/octet-stream',
        };
        // MUL-244: etiqueta pode ter sido baixada por ESTE backend (ShippingLabelService
        // salva em storage local) — servir direto; hub central so como fallback.
        $local = storage_path('app/public/labels/' . $filename);
        if (is_file($local)) {
            return response()->file($local, [
                'Content-Type'  => $mime,
                'Cache-Control' => 'private, max-age=300',
            ]);
        }
        // MUL-359: etiqueta antiga movida pro privado — proxy e autenticado, serve.
        $localPriv = storage_path('app/private/labels/' . $filename);
        if (is_file($localPriv)) {
            return response()->file($localPriv, [
                'Content-Type'  => $mime,
                'Cache-Control' => 'private, max-age=300',
            ]);
        }
        $hubUrl = rtrim(config('services.hubai_federation.storage_url', 'https://api.hubai.io'), '/');
        $path = '/storage/labels/' . $filename;
        try {
            $res = \Illuminate\Support\Facades\Http::timeout(30)->connectTimeout(10)
                ->withHeaders([
                    'X-Federation-Tenant' => (string) config('app.tenant'),
                    'X-Federation-Secret' => (string) (config('services.hubai_federation.secret') ?: env('FEDERATION_HMAC_SECRET', '')),
                ])->get($hubUrl . $path); // MUL-359: alcanca privado do hub
        } catch (\Throwable $e) {
            return response('label unavailable: ' . $e->getMessage(), 502);
        }
        if (!$res->successful()) {
            return response('label not found in hub', 404);
        }
        return response($res->body(), 200)
            ->header('Content-Type', $mime)
            ->header('Cache-Control', 'private, max-age=300');
    }

    // =========================================================================
    // Etiqueta combinada (cabecalho HubAI + etiqueta marketplace) -- NOV-096
    // =========================================================================

    /**
     * NOV-208: layout de impressao combinada salvo do fornecedor.
     * Persistido na tabela settings (key-value) — sem migration.
     */
    private function supplierPrintLayout(): string
    {
        $v = \App\Models\Setting::where('key', 'supplier:' . $this->supplierId() . ':print_layout')->value('value');
        return in_array($v, ['sequence', 'side', 'footer'], true) ? $v : 'sequence';
    }

    /** GET /api/v1/supplier-admin/print-settings */
    public function printSettings(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);

        $user = $request->user();

        return response()->json(['data' => [
            'combined_label_layout' => $this->supplierPrintLayout(),
            'available_layouts'     => [
                ['value' => 'sequence', 'label' => 'Sequencia — etiqueta e nota em folhas seguidas (Zebra 100x150mm)'],
                ['value' => 'side',     'label' => 'Lado a lado — folha unica paisagem (etiqueta + nota)'],
                ['value' => 'footer',   'label' => 'Rodape — nota compacta no rodape da etiqueta (padrao Shopee)'],
            ],
            'default_printer_name'  => $user->default_printer_name,
            'qz_print_trigger'      => $user->qz_print_trigger ?: 'second_beep',
            'available_qz_triggers' => [
                ['value' => 'disabled',    'label' => 'Desativado'],
                ['value' => 'first_beep',  'label' => '1º bip (separacao)'],
                ['value' => 'second_beep', 'label' => '2º bip (expedicao)'],
                ['value' => 'both',        'label' => 'Ambos os bips'],
            ],
        ]]);
    }

    /** PUT /api/v1/supplier-admin/print-settings  { combined_label_layout: sequence|side } */
    public function updatePrintSettings(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);

        $data = $request->validate([
            'combined_label_layout' => 'sometimes|string|in:sequence,side,footer',
            'default_printer_name'  => 'sometimes|nullable|string|max:191',
            'qz_print_trigger'      => 'sometimes|string|in:disabled,first_beep,second_beep,both',
        ]);

        if (isset($data['combined_label_layout'])) {
            \App\Models\Setting::updateOrCreate(
                ['key' => 'supplier:' . $this->supplierId() . ':print_layout'],
                ['group' => 'printing', 'value' => $data['combined_label_layout']]
            );
        }

        $user = $request->user();
        $qzDirty = false;
        if (array_key_exists('default_printer_name', $data)) {
            $user->forceFill(['default_printer_name' => $data['default_printer_name'] ?: null]);
            $qzDirty = true;
        }
        if (isset($data['qz_print_trigger'])) {
            $user->forceFill(['qz_print_trigger' => $data['qz_print_trigger']]);
            $qzDirty = true;
        }
        if ($qzDirty) {
            $user->save();
        }

        return response()->json(['data' => [
            'saved'                 => true,
            'combined_label_layout' => $this->supplierPrintLayout(),
            'default_printer_name'  => $user->default_printer_name,
            'qz_print_trigger'      => $user->qz_print_trigger ?: 'second_beep',
        ]]);
    }

    /** GET /api/v1/supplier-admin/qz/certificate — cert publico QZ Tray (via token) */
    public function qzCertificate(Request $request)
    {
        $this->requireSupplierAdmin($request);

        return app(\App\Http\Controllers\Admin\QzTrayController::class)->certificate($request);
    }

    /** GET /api/v1/supplier-admin/qz/sign?request=... — assinatura SHA512 (via token) */
    public function qzSign(Request $request)
    {
        $this->requireSupplierAdmin($request);

        return app(\App\Http\Controllers\Admin\QzTrayController::class)->sign($request);
    }

    /** POST /api/v1/supplier-admin/qz/mark-printed — marca label_printed_at, isolado por supplier */
    public function qzMarkPrinted(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);

        $data = $request->validate([
            'order_id'     => 'nullable|integer',
            'order_number' => 'nullable|string|max:64',
            'printer'      => 'nullable|string|max:191',
        ]);

        $query = Order::where('supplier_id', $this->supplierId());
        $order = null;
        if (! empty($data['order_id'])) {
            $order = (clone $query)->where('id', $data['order_id'])->first();
        }
        if (! $order && ! empty($data['order_number'])) {
            $order = (clone $query)->where('order_number', $data['order_number'])->first();
        }

        if (! $order) {
            return response()->json(['ok' => false, 'reason' => 'order_not_found'], 404);
        }

        $already = (bool) $order->label_printed_at;
        if (! $already) {
            $order->update(['label_printed_at' => now()]);
            \App\Jobs\EmitBlingNfeJob::dispatchIfTrigger($order->id, 'label_printed'); // MUL-276
        }

        \Illuminate\Support\Facades\Log::info('[QzTray] etiqueta impressa via QZ (api token)', [
            'order_id'     => $order->id,
            'order_number' => $order->order_number,
            'printer'      => $data['printer'] ?? null,
            'user_id'      => $request->user()?->id,
            'already'      => $already,
        ]);

        return response()->json([
            'ok'         => true,
            'already'    => $already,
            'printed_at' => $order->fresh()->label_printed_at?->toIso8601String(),
        ]);
    }

    /**
     * GET /api/v1/supplier-admin/orders/{orderId}/combined-label
     *
     * Retorna HTML pronto pra iframe imprimir, com UMA etiqueta combinada
     * do pedido informado. Layout fixo Zebra 100x150mm.
     *
     * Isola por supplier_id do fornecedor autenticado.
     */
    public function combinedLabel(Request $request, int $orderId, CombinedLabelService $service): \Illuminate\Http\Response
    {
        $this->requireSupplierAdmin($request);

        $order = Order::where('supplier_id', $this->supplierId())
            ->where('id', $orderId)
            ->firstOrFail();

        // NOV-208: ?layout=side -> folha unica paisagem (etiqueta + nota)
        $layout = $request->query('layout') ?: $this->supplierPrintLayout();

        $html = $service->generate($order, $layout);

        return response($html, 200, [
            'Content-Type'  => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, max-age=60',
        ]);
    }

    /**
     * POST /api/v1/supplier-admin/picking/print-batch-combined
     *
     * Recebe order_ids[], retorna HTML unico com N etiquetas combinadas
     * (page-break-after entre elas). Pra impressao em lote via iframe.
     *
     * Tambem marca label_printed_at e registra LabelPrintLog igual ao
     * print-batch atual.
     */
    public function printBatchCombined(Request $request, CombinedLabelService $service): \Illuminate\Http\Response
    {
        $this->requireSupplierAdmin($request);

        $validated = $request->validate([
            'order_ids'    => 'required_without:legacy_ids|array|min:1|max:100',
            'order_ids.*'  => 'integer',
            'legacy_ids'   => 'required_without:order_ids|array|min:1|max:100', // painel WL usa id legado
            'legacy_ids.*' => 'integer',
            'printer_type' => 'nullable|string|in:zebra,a4',
            'layout'       => 'nullable|string|in:sequence,side,footer', // NOV-208
            'dry'          => 'nullable|boolean', // preview: gera HTML sem marcar impresso
        ]);

        $supplierId = $this->supplierId();

        if (!empty($validated['legacy_ids'])) {
            // MUL-245: fila do picking é nativa — "legacy_ids" traz orders.id (ou
            // legacy_id em painéis antigos). Resolve pelos dois, sem tocar o legado
            // (mesmo padrão MES-046-B do pickingShip).
            $ids = $validated['legacy_ids'];
            $orders = Order::where('supplier_id', $supplierId)
                ->where(function ($q) use ($ids) {
                    $q->whereIn('legacy_id', $ids)->orWhereIn('id', $ids);
                })->get();
        } else {
            $orders = Order::where('supplier_id', $supplierId)
                ->whereIn('id', $validated['order_ids'])->get();
        }

        if ($orders->isEmpty()) {
            abort(404, 'Pedidos ainda nao sincronizados no sistema novo.');
        }

        $now = now();

        if (!$request->boolean('dry')) {
        Order::whereIn('id', $orders->pluck('id'))
            ->whereNull('label_printed_at')
            ->update(['label_printed_at' => $now]);

        \App\Jobs\EmitBlingNfeJob::dispatchIfTrigger($orders->pluck('id')->all(), 'label_printed'); // MUL-276

        LabelPrintLog::create([
            'supplier_id'  => $supplierId,
            'user_id'      => auth()->id(),
            'order_ids'    => $orders->pluck('id')->toArray(),
            'batch_size'   => $orders->count(),
            'marketplace'  => null,
            'printer_type' => $validated['printer_type'] ?? 'zebra',
            'printed_at'   => $now,
        ]);
        }

        $html = $service->generateBatch($orders, $validated['layout'] ?? $this->supplierPrintLayout());

        return response($html, 200, [
            'Content-Type'  => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }



    /**
     * MUL-213 #1/#2 — GET /api/v1/supplier-admin/orders/filters
     * Opções dinâmicas dos filtros de pedidos do painel admin, derivadas dos
     * pedidos reais (que carregam os nomes dos canais das integrações Bling).
     * shipping_channels = união distinct de carrier_name + delivery_type (sem repetir);
     * channels = marketplaces distintos via COALESCE(channel_name, source), sem duplicar
     * (Amazon via Bling aparece como Amazon, não como Bling).
     */
    public function orderFilters(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);

        $base = Order::where('supplier_id', $this->supplierId())->where('is_draft', 0);

        // Mesmo isolamento do orders(): seller nao-admin so enxerga os proprios pedidos
        $role = $request->user()->role;
        if (! in_array($role, ['super_admin', 'admin', 'supplier'])) {
            $clientId = $request->user()->client?->id;
            if (! $clientId) {
                return response()->json(['channels' => [], 'shipping_channels' => []]);
            }
            $base->where('client_id', $clientId);
        }

        $shippingChannels = (clone $base)
            ->whereNotNull('carrier_name')->where('carrier_name', '!=', '')
            ->distinct()->pluck('carrier_name')
            ->merge(
                (clone $base)
                    ->whereNotNull('delivery_type')->where('delivery_type', '!=', '')
                    ->distinct()->pluck('delivery_type')
            )
            ->map(fn ($v) => trim($v))
            ->unique(fn ($v) => mb_strtolower($v))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->map(fn ($v) => ['value' => $v, 'label' => $v]);

        $sourceMap = [
            'mercadolivre' => 'Mercado Livre',
            'shopee'       => 'Shopee',
            'bling'        => 'Bling',
            'manual'       => 'Manual',
            'amazon'       => 'Amazon',
            'magalu'       => 'Magazine Luiza',
            'tiktok'       => 'TikTok Shop',
        ];
        $channels = (clone $base)
            ->whereNotNull('source')->where('source', '!=', '')
            ->selectRaw("DISTINCT LOWER(REPLACE(COALESCE(NULLIF(channel_name, ''), source), ' ', '')) AS mk")
            ->pluck('mk')
            ->sort()->values()
            ->map(fn ($s) => ['value' => $s, 'label' => $sourceMap[$s] ?? ucfirst($s)]);

        // MUL-264: flag pra front decidir se mostra icone de sync bling na listagem
        $hasBlingSync = \App\Models\ErpAccount::where('supplier_id', $this->supplierId())
            ->where('platform', 'bling')
            ->where('status', 'active')
            ->where('auto_sync_orders', 1)
            ->exists();

        return response()->json([
            'channels'             => $channels,
            'shipping_channels'    => $shippingChannels,
            'has_bling_sync'       => $hasBlingSync,
        ]);
    }

    // NOV-112 B4: endpoints de summary/revenue/top-products para role=supplier.
    // Os endpoints GET /orders/summary|revenue|top-products requerem role=client.
    // Para role=supplier estes endpoints equivalentes buscam dados pelo supplier_id.

    public function ordersSummary(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $sid = $this->supplierId();
        $base = Order::where('supplier_id', $sid)->where('is_draft', 0); // MUL-197: rascunho fora de stats
        if ($request->filled('start') && $request->filled('end')) {
            $base->whereBetween('created_at', [
                \Carbon\Carbon::parse($request->query('start'))->startOfDay(),
                \Carbon\Carbon::parse($request->query('end'))->endOfDay(),
            ]);
        }
        $byStatus = (clone $base)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');
        return response()->json(['data' => [
            'total'     => (int) $base->count(),
            'today'     => (int) (clone $base)->whereDate('created_at', today())->count(),
            'by_status' => [
                'pending_payment' => (int) ($byStatus['pending_payment'] ?? 0),
                'paid'            => (int) ($byStatus['paid'] ?? 0),
                'processing'      => (int) ($byStatus['processing'] ?? 0),
                'shipped'         => (int) ($byStatus['shipped'] ?? 0),
                'delivered'       => (int) ($byStatus['delivered'] ?? 0),
                'cancelled'       => (int) ($byStatus['cancelled'] ?? 0),
            ],
        ]]);
    }

    public function ordersRevenue(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $sid  = $this->supplierId();
        $base = Order::where('supplier_id', $sid)->where('is_draft', 0); // MUL-197: rascunho fora de revenue
        $hasCustomRange = $request->filled('start') && $request->filled('end');
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
        $total       = (float) (clone $base)->sum('total');
        $ordersCount = (int) (clone $base)->count();
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
            $rs = $hasCustomRange ? $startDate->copy() : now()->subDays((int)$days - 1)->startOfDay();
            for ($i = 0; $i < (int)$days; $i++) {
                $d = $rs->copy()->addDays($i);
                $series[] = ['date' => $d->format('d/m'), 'value' => (float) ($rows[$d->format('Y-m-d')] ?? 0)];
            }
        } else {
            $rows = (clone $base)->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as b, SUM(total) as v")->groupBy('b')->pluck('v', 'b');
            $months = min((int) ceil($days / 30), 24);
            for ($i = $months - 1; $i >= 0; $i--) {
                $d = now()->subMonths($i);
                $series[] = ['date' => $d->format('m/Y'), 'value' => (float) ($rows[$d->format('Y-m')] ?? 0)];
            }
        }
        return response()->json(['data' => [
            'period'         => $period,
            'total'          => $total,
            'orders_count'   => $ordersCount,
            'series'         => $series,
            'by_marketplace' => $byMarketplace,
        ]]);
    }

    // MUL-222 item 3: 2FA TOTP (setup + confirm + disable + verify no login)
    public function twoFactorSetup(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $user = $request->user();
        // Se já confirmado, precisa desabilitar antes
        if ($user->two_factor_confirmed_at) {
            return response()->json(['error' => '2FA já habilitado. Desabilite primeiro.'], 422);
        }
        $secret = \App\Services\TotpService::generateSecret(20);
        $uri = \App\Services\TotpService::otpauthUri('MultDrop Admin', $user->email, $secret);
        // Guarda temporariamente (não confirmado)
        \DB::table('users')->where('id', $user->id)->update([
            'two_factor_secret' => encrypt($secret),
            'updated_at' => now(),
        ]);
        return response()->json([
            'secret'       => $secret,
            'otpauth_uri'  => $uri,
            'qr_code_url'  => 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($uri),
        ]);
    }

    public function twoFactorConfirm(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $validated = $request->validate(['code' => 'required|string|size:6']);
        $user = $request->user();
        if (! $user->two_factor_secret) return response()->json(['error' => 'Setup não iniciado.'], 422);
        $secret = decrypt($user->two_factor_secret);
        if (! \App\Services\TotpService::verifyCode($secret, $validated['code'])) {
            return response()->json(['error' => 'Código inválido.'], 422);
        }
        $backupCodes = \App\Services\TotpService::generateBackupCodes(8);
        \DB::table('users')->where('id', $user->id)->update([
            'two_factor_backup_codes' => encrypt(json_encode($backupCodes)),
            'two_factor_confirmed_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['enabled' => true, 'backup_codes' => $backupCodes]);
    }

    public function twoFactorDisable(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $validated = $request->validate(['password' => 'required|string']);
        $user = $request->user();
        if (! \Illuminate\Support\Facades\Hash::check($validated['password'], $user->password)) {
            return response()->json(['error' => 'Senha inválida.'], 422);
        }
        \DB::table('users')->where('id', $user->id)->update([
            'two_factor_secret' => null,
            'two_factor_backup_codes' => null,
            'two_factor_confirmed_at' => null,
            'updated_at' => now(),
        ]);
        return response()->json(['enabled' => false]);
    }

    public function twoFactorStatus(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $user = $request->user();
        $codes = null;
        if ($user->two_factor_confirmed_at && $user->two_factor_backup_codes) {
            try { $codes = json_decode(decrypt($user->two_factor_backup_codes), true); }
            catch (\Throwable $e) { $codes = null; }
        }
        return response()->json([
            'enabled'      => (bool) $user->two_factor_confirmed_at,
            'confirmed_at' => $user->two_factor_confirmed_at,
            'backup_codes_remaining' => is_array($codes) ? count($codes) : null,
        ]);
    }

    // MUL-222 item 5: Central de Notificações MVP (CRUD + feed pra clientes)
    public function notificationsList(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $sid = $this->supplierId();
        $q = \DB::table('admin_notifications')->where('supplier_id', $sid)->orderByDesc('id');
        if ($cat = $request->query('category'))    $q->where('category', $cat);
        if ($target = $request->query('target_role')) $q->where('target_role', $target);
        return response()->json(['data' => $q->get()]);
    }

    public function notificationsStore(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $sid = $this->supplierId();
        $validated = $request->validate([
            'title'        => 'required|string|max:255',
            'body'         => 'nullable|string|max:5000',
            'category'     => 'required|in:system,marketing,oncall',
            'target_role'  => 'required|in:admin,client,all',
            'image_url'    => 'nullable|url|max:500',
            'video_url'    => 'nullable|url|max:500',
            'scheduled_at' => 'nullable|date',
            'expires_at'   => 'nullable|date|after_or_equal:scheduled_at',
            'active'       => 'sometimes|boolean',
        ]);
        $validated['supplier_id'] = $sid;
        $validated['created_by']  = $request->user()->id;
        $validated['created_at']  = now();
        $validated['updated_at']  = now();
        $validated['active']      = $validated['active'] ?? true;
        $id = \DB::table('admin_notifications')->insertGetId($validated);
        return response()->json(['data' => \DB::table('admin_notifications')->where('id', $id)->first()], 201);
    }

    public function notificationsUpdate(Request $request, int $id): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $sid = $this->supplierId();
        $row = \DB::table('admin_notifications')->where('id', $id)->where('supplier_id', $sid)->first();
        if (!$row) return response()->json(['error' => 'Não encontrado'], 404);
        $validated = $request->validate([
            'title'        => 'sometimes|string|max:255',
            'body'         => 'sometimes|nullable|string|max:5000',
            'category'     => 'sometimes|in:system,marketing,oncall',
            'target_role'  => 'sometimes|in:admin,client,all',
            'image_url'    => 'sometimes|nullable|url|max:500',
            'video_url'    => 'sometimes|nullable|url|max:500',
            'scheduled_at' => 'sometimes|nullable|date',
            'expires_at'   => 'sometimes|nullable|date',
            'active'       => 'sometimes|boolean',
        ]);
        $validated['updated_at'] = now();
        \DB::table('admin_notifications')->where('id', $id)->update($validated);
        return response()->json(['data' => \DB::table('admin_notifications')->where('id', $id)->first()]);
    }

    public function notificationsDelete(Request $request, int $id): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $sid = $this->supplierId();
        $deleted = \DB::table('admin_notifications')->where('id', $id)->where('supplier_id', $sid)->delete();
        return response()->json(['deleted' => (bool) $deleted]);
    }

    public function notificationsFeed(Request $request): JsonResponse
    {
        // Endpoint público-autenticado pra clientes: retorna notificações ativas + no ar agora + do supplier deles
        $user = $request->user();
        $sid  = null;
        if ($user && $user->client) {
            $firstOrder = \DB::table('orders')->where('client_id', $user->client->id)->latest('id')->value('supplier_id');
            $sid = $firstOrder;
        }
        if (!$sid) return response()->json(['data' => []]);
        $now = now();
        $q = \DB::table('admin_notifications')
            ->where('supplier_id', $sid)
            ->where('active', 1)
            ->where(function($w) { $w->whereIn('target_role', ['client', 'all']); })
            ->where(function($w) use ($now) { $w->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', $now); })
            ->where(function($w) use ($now) { $w->whereNull('expires_at')->orWhere('expires_at', '>=', $now); })
            ->orderByDesc('id')
            ->limit(20);
        return response()->json(['data' => $q->get()]);
    }

    // MUL-222 itens 6+7: relatórios Top Sellers + Top Produtos com filtros completos
    public function reportTopSellers(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $sid = $this->supplierId();
        $limit = min(max((int) $request->query('limit', 50), 1), 200);
        // MUL-269 fase 2: nome do seller vem do user (clients.company_name removido);
        // usa COALESCE(NULLIF(u.full_name,''),u.name) via join em users.
        $q = \DB::table('orders as o')
            ->join('clients as c', 'c.id', '=', 'o.client_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->leftJoin('marketplace_accounts as ma', 'ma.id', '=', 'o.marketplace_account_id')
            ->where('o.supplier_id', $sid)
            ->where('o.is_draft', 0);
        if ($request->filled('start')) $q->where('o.created_at', '>=', \Carbon\Carbon::parse($request->query('start'))->startOfDay());
        if ($request->filled('end'))   $q->where('o.created_at', '<=', \Carbon\Carbon::parse($request->query('end'))->endOfDay());
        if ($mp  = $request->query('marketplace'))     $q->where('o.source', $mp);
        if ($min = $request->query('min_orders'))      $q->having('orders_count', '>=', (int) $min);
        if ($minR = $request->query('min_revenue'))    $q->having('revenue', '>=', (float) $minR);
        if ($status = $request->query('status'))       $q->where('o.status', $status);
        $rows = $q->selectRaw(
            "c.id, COALESCE(NULLIF(u.full_name,''),u.name) as company_name, COALESCE(ma.account_name, ma.seller_nickname) as store_name,
             COUNT(DISTINCT o.id) as orders_count,
             SUM(o.total) as revenue,
             SUM(o.supplier_total) as supplier_revenue,
             AVG(o.total) as ticket_avg,
             MAX(o.created_at) as last_order_at"
        )->groupBy('c.id', 'c.user_id', 'u.full_name', 'u.name', 'store_name')
         ->orderByDesc('orders_count')
         ->limit($limit)
         ->get();
        return response()->json(['data' => $rows->map(fn($r) => [
            'client_id'         => (int) $r->id,
            'company_name'      => $r->company_name,
            'store_name'        => $r->store_name,
            'orders_count'      => (int) $r->orders_count,
            'revenue'           => (float) $r->revenue,
            'supplier_revenue'  => (float) $r->supplier_revenue,
            'ticket_avg'        => (float) $r->ticket_avg,
            'last_order_at'     => $r->last_order_at,
        ])]);
    }

    public function reportTopProducts(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $sid = $this->supplierId();
        $limit = min(max((int) $request->query('limit', 50), 1), 200);
        $q = \DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->leftJoin('marketplace_accounts as ma', 'ma.id', '=', 'o.marketplace_account_id')
            ->leftJoin('clients as cl', 'cl.id', '=', 'o.client_id')
            ->where('o.supplier_id', $sid)
            ->where('o.is_draft', 0);
        if ($request->filled('start')) $q->where('o.created_at', '>=', \Carbon\Carbon::parse($request->query('start'))->startOfDay());
        if ($request->filled('end'))   $q->where('o.created_at', '<=', \Carbon\Carbon::parse($request->query('end'))->endOfDay());
        if ($mp  = $request->query('marketplace'))    $q->where('o.source', $mp);
        if ($store = $request->query('store_name'))   $q->where('ma.account_name', 'like', "%$store%");
        if ($cid = (int) $request->query('client_id')) $q->where('cl.id', $cid);
        if ($minV = $request->query('min_value'))     $q->having('revenue', '>=', (float) $minV);
        $rows = $q->selectRaw(
            'oi.name as name, MAX(oi.product_image) as image, oi.sku,
             SUM(oi.quantity) as qty,
             SUM(oi.total) as revenue,
             COUNT(DISTINCT oi.order_id) as orders_count,
             AVG(oi.unit_price) as unit_price_avg,
             GROUP_CONCAT(DISTINCT o.source) as marketplaces'
        )->groupBy('oi.name', 'oi.sku')
         ->orderByDesc('qty')
         ->limit($limit)
         ->get();
        return response()->json(['data' => $rows->map(fn($r) => [
            'name'             => $r->name,
            'sku'              => $r->sku,
            'image'            => $r->image,
            'quantity_sold'    => (int) $r->qty,
            'revenue'          => (float) $r->revenue,
            'orders_count'     => (int) $r->orders_count,
            'unit_price_avg'   => (float) $r->unit_price_avg,
            'marketplaces'     => $r->marketplaces ? explode(',', $r->marketplaces) : [],
        ])]);
    }

    // MUL-222 item 12: gerenciamento de disputas ADMIN (espelho seller)
    public function adminListDisputes(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $sid = $this->supplierId();
        $perPage = min((int) $request->query('per_page', 25), 100);
        // MUL-269 fase 2: nome do seller vem do user (clients.company_name removido).
        $q = \DB::table('order_disputes as d')
            ->join('orders as o', 'o.id', '=', 'd.order_id')
            ->leftJoin('clients as cl', 'cl.id', '=', 'o.client_id')
            ->leftJoin('users as u', 'u.id', '=', 'cl.user_id')
            ->where('o.supplier_id', $sid)
            ->orderByDesc('d.id')
            ->select('d.*', 'o.order_number', 'o.status as order_status', 'o.customer_name', 'o.total as order_total',
                     \DB::raw("COALESCE(NULLIF(u.full_name,''),u.name) as client_name"));
        if ($status = $request->query('status')) $q->where('d.status', $status);
        if ($s = $request->query('search')) {
            $q->where(function($w) use ($s) {
                $w->where('o.order_number', 'like', "%$s%")
                  ->orWhere('o.customer_name', 'like', "%$s%")
                  ->orWhere('d.reason', 'like', "%$s%");
            });
        }
        $paginator = $q->paginate($perPage);
        $counters = [
            'open'      => \DB::table('order_disputes as d')->join('orders as o', 'o.id', '=', 'd.order_id')->where('o.supplier_id', $sid)->where('d.status', 'open')->count(),
            'in_review' => \DB::table('order_disputes as d')->join('orders as o', 'o.id', '=', 'd.order_id')->where('o.supplier_id', $sid)->where('d.status', 'in_review')->count(),
            'resolved'  => \DB::table('order_disputes as d')->join('orders as o', 'o.id', '=', 'd.order_id')->where('o.supplier_id', $sid)->where('d.status', 'resolved')->count(),
            'rejected'  => \DB::table('order_disputes as d')->join('orders as o', 'o.id', '=', 'd.order_id')->where('o.supplier_id', $sid)->where('d.status', 'rejected')->count(),
        ];
        return response()->json([
            'data' => $paginator->items(),
            'counters' => $counters,
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function adminUpdateDispute(Request $request, int $id): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $sid = $this->supplierId();
        $validated = $request->validate([
            'status'           => 'required|in:open,in_review,resolved,rejected',
            'resolution_notes' => 'nullable|string|max:5000',
        ]);
        $dispute = \DB::table('order_disputes as d')
            ->join('orders as o', 'o.id', '=', 'd.order_id')
            ->where('d.id', $id)
            ->where('o.supplier_id', $sid)
            ->select('d.*')
            ->first();
        if (!$dispute) return response()->json(['error' => 'Disputa não encontrada'], 404);
        $update = [
            'status'           => $validated['status'],
            'resolution_notes' => $validated['resolution_notes'] ?? $dispute->resolution_notes,
            'updated_at'       => now(),
        ];
        if (in_array($validated['status'], ['resolved', 'rejected'], true)) {
            $update['resolved_at']         = now();
            $update['resolved_by_user_id'] = $request->user()->id;
        }
        \DB::table('order_disputes')->where('id', $id)->update($update);
        \Illuminate\Support\Facades\Log::info('[MUL-222 item 12] dispute updated', [
            'dispute_id' => $id, 'status' => $validated['status'], 'user' => $request->user()->id,
        ]);
        return response()->json(['data' => \DB::table('order_disputes')->where('id', $id)->first()]);
    }

    // MUL-222 item 4: relatório de exportação Bling com filtros/busca/reenvio
    public function blingExportReport(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $perPage = min((int) $request->query('per_page', 25), 100);
        $q = \DB::table('sync_logs')
            ->where('platform', 'bling')
            ->where('direction', 'outbound')
            ->orderByDesc('id');
        if ($status = $request->query('status')) $q->where('status', $status);
        if ($from = $request->query('from')) $q->where('created_at', '>=', $from . ' 00:00:00');
        if ($to = $request->query('to')) $q->where('created_at', '<=', $to . ' 23:59:59');
        if ($s = $request->query('search')) {
            $q->where(function($w) use ($s) {
                $w->where('error_message', 'like', "%$s%")
                  ->orWhere('action', 'like', "%$s%")
                  ->orWhere('syncable_id', $s);
            });
        }
        $paginator = $q->paginate($perPage);
        $items = collect($paginator->items())->map(fn($r) => [
            'id'             => $r->id,
            'syncable_type'  => $r->syncable_type,
            'syncable_id'    => $r->syncable_id,
            'action'         => $r->action,
            'status'         => $r->status,
            'error_message'  => $r->error_message,
            'created_at'     => $r->created_at,
            'client_product_id' => $r->client_product_id,
        ])->values();
        return response()->json([
            'data' => $items,
            'counters' => [
                'success' => \DB::table('sync_logs')->where('platform','bling')->where('direction','outbound')->where('status','success')->count(),
                'error'   => \DB::table('sync_logs')->where('platform','bling')->where('direction','outbound')->where('status','error')->count(),
                'skipped' => \DB::table('sync_logs')->where('platform','bling')->where('direction','outbound')->where('status','skipped')->count(),
            ],
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function resendBlingExport(Request $request, int $logId): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $log = \DB::table('sync_logs')->where('id', $logId)->where('platform','bling')->first();
        if (! $log) return response()->json(['error' => 'Log não encontrado.'], 404);
        $type = strtolower($log->syncable_type ?? '');
        if (str_contains($type, 'order')) {
            \App\Jobs\SyncErpOrdersJob::dispatch((int) $log->syncable_id);
            return response()->json(['queued' => true, 'target' => 'order', 'id' => $log->syncable_id]);
        }
        // Fallback: só re-loga a intenção (produto)
        \Illuminate\Support\Facades\Log::info('[MUL-222 item 4] resend não-order tentado', [
            'log_id' => $logId, 'syncable' => $log->syncable_type, 'target' => $log->syncable_id,
        ]);
        return response()->json(['queued' => false, 'reason' => 'Reenvio automático só para pedidos por enquanto.'], 200);
    }

    // MUL-222 item 16: inflar estoque virtual + reservar estoque disponível
    public function inflateStock(Request $request, int $productId): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $sid = $this->supplierId();
        $validated = $request->validate([
            'qty'  => 'required|integer|min:1',
            'note' => 'nullable|string|max:255',
        ]);
        $product = \App\Models\Product::where('supplier_id', $sid)->where('id', $productId)->firstOrFail();
        $prev = (int) ($product->virtual_stock_qty ?? 0);
        $product->virtual_stock_qty = $prev + $validated['qty'];
        $product->save();
        \Illuminate\Support\Facades\Log::info('[MUL-222 item 16] inflate', [
            'product_id' => $productId, 'delta' => $validated['qty'], 'from' => $prev, 'to' => $product->virtual_stock_qty, 'user' => $request->user()->id, 'note' => $validated['note'] ?? null,
        ]);
        return response()->json(['virtual_stock_qty' => (int) $product->virtual_stock_qty]);
    }

    public function reserveStock(Request $request, int $productId): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $sid = $this->supplierId();
        $validated = $request->validate([
            'qty'  => 'required|integer|min:1',
            'note' => 'nullable|string|max:255',
        ]);
        $product = \App\Models\Product::where('supplier_id', $sid)->where('id', $productId)->firstOrFail();
        // Reserva no primeiro inventory encontrado (evita spread cross-warehouse)
        $inv = \DB::table('inventory')->where('product_id', $productId)
            ->orderByRaw('(quantity - reserved) DESC')->first();
        if (!$inv) {
            return response()->json(['error' => 'Sem inventory pra este produto.'], 422);
        }
        $avail = (int) $inv->quantity - (int) $inv->reserved;
        if ($avail < $validated['qty']) {
            return response()->json(['error' => "Estoque disponível insuficiente ($avail)."], 422);
        }
        \DB::table('inventory')->where('id', $inv->id)->increment('reserved', $validated['qty']);
        \DB::table('inventory')->where('id', $inv->id)->update(['updated_at' => now()]);
        \Illuminate\Support\Facades\Log::info('[MUL-222 item 16] reserve', [
            'product_id' => $productId, 'qty' => $validated['qty'], 'inv_id' => $inv->id, 'user' => $request->user()->id, 'note' => $validated['note'] ?? null,
        ]);
        $new = \DB::table('inventory')->where('id', $inv->id)->first();
        return response()->json(['reserved' => (int) $new->reserved, 'available' => (int) $new->quantity - (int) $new->reserved]);
    }

    // MUL-214 pos-sprint: config de chaves API via painel admin (grava cifrado em settings)
    private const API_KEY_SETTINGS = [
        'ark_api_key'      => 'BytePlus ModelArk (Seedance 2.0)',
        'kling_api_key'    => 'Kling AI',
        'google_client_id' => 'Google OAuth (client_id publico, apps.googleusercontent.com)',
    ];

    public function apiKeysStatus(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $out = [];
        foreach (self::API_KEY_SETTINGS as $key => $label) {
            $row = \DB::table('settings')->where('key', $key)->first();
            $has = false; $preview = null;
            if ($row && $row->value) {
                try {
                    $dec = decrypt($row->value);
                    $has = strlen($dec) > 0;
                    // Preview: primeiro 4 + ... + ultimos 4
                    if ($has && strlen($dec) > 12) {
                        $preview = substr($dec, 0, 4) . '...' . substr($dec, -4);
                    } elseif ($has) {
                        $preview = str_repeat('*', strlen($dec));
                    }
                } catch (\Throwable $e) { $has = false; }
            }
            $out[$key] = [
                'label'      => $label,
                'configured' => $has,
                'preview'    => $preview,
                'updated_at' => $row->updated_at ?? null,
            ];
        }
        return response()->json(['data' => $out]);
    }

    public function apiKeysSave(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $validated = $request->validate([
            'key'   => 'required|string|in:' . implode(',', array_keys(self::API_KEY_SETTINGS)),
            'value' => 'nullable|string|max:2000',
        ]);
        if (empty($validated['value'])) {
            \DB::table('settings')->where('key', $validated['key'])->delete();
            \Illuminate\Support\Facades\Log::info('[MUL-214 apiKeys] apagada', ['key' => $validated['key'], 'user' => $request->user()->id]);
            return response()->json(['configured' => false, 'message' => 'Chave removida.']);
        }
        \DB::table('settings')->updateOrInsert(
            ['key' => $validated['key']],
            ['group' => 'api_keys', 'value' => encrypt($validated['value']), 'updated_at' => now(), 'created_at' => now()]
        );
        \Illuminate\Support\Facades\Log::info('[MUL-214 apiKeys] salva', ['key' => $validated['key'], 'user' => $request->user()->id, 'len' => strlen($validated['value'])]);
        return response()->json(['configured' => true, 'message' => 'Chave salva com segurança.']);
    }

    // MUL-222 item 1: kill switch fila Bling (congela push de NF pro Bling em balanço de estoque)
    public function blingQueueStatus(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $frozen = \DB::table('settings')->where('key', 'bling_queue_frozen')->value('value');
        return response()->json([
            'frozen'     => $frozen === '1' || $frozen === 'true',
            'updated_at' => \DB::table('settings')->where('key', 'bling_queue_frozen')->value('updated_at'),
        ]);
    }

    public function setBlingQueueStatus(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $validated = $request->validate(['frozen' => 'required|boolean']);
        \DB::table('settings')->updateOrInsert(
            ['key' => 'bling_queue_frozen'],
            ['group' => 'bling', 'value' => $validated['frozen'] ? '1' : '0', 'updated_at' => now(), 'created_at' => now()]
        );
        \Illuminate\Support\Facades\Log::info('[MUL-222] Bling queue toggled', [
            'frozen' => $validated['frozen'],
            'user_id' => $request->user()->id,
        ]);
        return response()->json(['frozen' => (bool) $validated['frozen']]);
    }

    // MUL-274: config da integracao Bling do fornecedor (toggle auto-sync de pedidos pagos)
    public function blingConfig(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $erp = \App\Models\ErpAccount::where('supplier_id', $this->supplierId())
            ->where('platform', 'bling')->where('status', 'active')->first();
        return response()->json(['data' => [
            'connected'        => (bool) $erp,
            'account_name'     => $erp?->account_name,
            'auto_sync_orders' => (bool) ($erp->auto_sync_orders ?? false),
            'nfe_entrada_trigger' => $erp->nfe_entrada_trigger ?? 'off',
        ]]);
    }

    public function setBlingConfig(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $validated = $request->validate([
            'auto_sync_orders'    => 'sometimes|boolean',
            'nfe_entrada_trigger' => 'sometimes|in:off,paid,label_printed',
        ]);
        if ($validated === []) return response()->json(['error' => 'Nada pra atualizar.'], 422);
        $erp = \App\Models\ErpAccount::where('supplier_id', $this->supplierId())
            ->where('platform', 'bling')->where('status', 'active')->first();
        if (!$erp) return response()->json(['error' => 'Fornecedor sem conta Bling ativa (erp_accounts).'], 422);
        $upd = ['updated_at' => now()];
        if (array_key_exists('auto_sync_orders', $validated)) $upd['auto_sync_orders'] = $validated['auto_sync_orders'] ? 1 : 0;
        if (array_key_exists('nfe_entrada_trigger', $validated)) $upd['nfe_entrada_trigger'] = $validated['nfe_entrada_trigger'];
        \Illuminate\Support\Facades\DB::table('erp_accounts')->where('id', $erp->id)->update($upd);
        \Illuminate\Support\Facades\Log::info('[MUL-274/MUL-276] bling config atualizada', [
            'erp_account_id' => $erp->id,
            'changes'        => $validated,
            'user_id'        => $request->user()->id,
        ]);
        $fresh = \Illuminate\Support\Facades\DB::table('erp_accounts')->where('id', $erp->id)->first();
        return response()->json(['data' => [
            'auto_sync_orders'    => (bool) $fresh->auto_sync_orders,
            'nfe_entrada_trigger' => $fresh->nfe_entrada_trigger ?? 'off',
        ]]);
    }

        public function ordersTopProducts(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $sid   = $this->supplierId();
        $limit = min(max((int) $request->query('limit', 10), 1), 50);
        $q = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.supplier_id', $sid)
            ->where('orders.is_draft', 0); // MUL-197: rascunho fora de top products
        if ($request->filled('start') && $request->filled('end')) {
            $q->whereBetween('orders.created_at', [
                \Carbon\Carbon::parse($request->query('start'))->startOfDay(),
                \Carbon\Carbon::parse($request->query('end'))->endOfDay(),
            ]);
        }
        $rows = $q->selectRaw(
            'order_items.name as name,
             MAX(order_items.product_image) as legacy_image,
             SUM(order_items.quantity) as qty,
             SUM(order_items.total) as revenue,
             COUNT(DISTINCT order_items.order_id) as orders_count'
        )->groupBy('order_items.name')->orderByDesc('qty')->limit($limit)->get();

        return response()->json(['data' => $rows->map(fn ($r) => [
            'name'          => $r->name,
            'image'         => $r->legacy_image,
            'quantity_sold' => (int) $r->qty,
            'revenue'       => (float) $r->revenue,
            'orders'        => (int) $r->orders_count,
        ])->all()]);
    }

    /**
     * NOV-117 -- Historico de movimentacoes de estoque de um produto.
     * GET /api/v1/supplier-admin/products/{product_id}/stock-movements
     */
    public function productStockMovements(Request $request, int $productId): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        $supplierId = $this->supplierId();

        $request->validate([
            'marketplace' => 'sometimes|string|in:ml,shopee,bling,manual',
            'type'        => 'sometimes|string',
            'from'        => 'sometimes|date',
            'to'          => 'sometimes|date',
            'per_page'    => 'sometimes|integer|min:1|max:200',
        ]);

        $perPage = min((int) $request->query('per_page', 50), 200);

        $query = \App\Models\InventoryMovement::query()
            ->withoutGlobalScopes()
            ->where('product_id', $productId)
            ->whereIn('supplier_id', $this->supplierIdsForCatalog()) // MUL-296: movimento da Filial tambem
            ->with('user:id,name')
            ->orderByDesc('created_at');

        if ($mp = $request->query('marketplace')) {
            $query->where('marketplace', $mp);
        }

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        if ($from = $request->query('from')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $movements = $query->paginate($perPage);

        return response()->json([
            'data' => $movements->map(fn ($m) => [
                'id'             => $m->id,
                'type'           => $m->type,
                'qty_before'     => $m->qty_before,
                'qty_change'     => $m->qty_change,
                'qty_after'      => $m->qty_after,
                'marketplace'    => $m->marketplace,
                'reference_type' => $m->reference_type,
                'reference_id'   => $m->reference_id,
                'notes'          => $m->notes,
                'user'           => $m->user?->name,
                'created_at'     => $m->created_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'current_page' => $movements->currentPage(),
                'last_page'    => $movements->lastPage(),
                'per_page'     => $movements->perPage(),
                'total'        => $movements->total(),
            ],
        ]);
    }

    // =========================================================================
    // MUL-197 — Rascunhos: edicao admin (whitelist), pull manual da integracao
    // =========================================================================

    /**
     * MUL-197: campos editaveis via PATCH /supplier-admin/orders/{id}.
     * NUNCA incluir campos financeiros de captura: wallet_paid_at, label_url,
     * wallet_transaction_id, supplier_total, invoice_*, shipped_at etc.
     */
    private const ORDER_EDITABLE_FIELDS = [
        'customer_name', 'customer_email', 'customer_phone',
        'customer_document_type', 'customer_document_number', 'customer_address',
        'total', 'subtotal', 'shipping_cost', 'discount_amount',
        'tracking_number', 'carrier_name', 'delivery_type', 'channel_name',
        'buyer_username', 'buyer_nickname', 'paid_at',
    ];

    /**
     * PATCH /api/v1/supplier-admin/orders/{id}
     * MUL-197: edicao admin de pedido (foco: completar rascunhos manualmente).
     * Whitelist estrita de campos; itens opcionais (name/sku/quantity/unit_price).
     * Apos salvar, tenta promover rascunho (se completo, dispara fanout+AutoPay).
     */
    public function updateOrderAdmin(Request $request, int $id): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        if (! in_array($request->user()->role, ['super_admin', 'admin'], true)) {
            abort(403, 'Somente admin pode editar pedidos.');
        }

        $order = Order::where('supplier_id', $this->supplierId())->findOrFail($id);

        $payload = $request->only(self::ORDER_EDITABLE_FIELDS);
        if (empty($payload) && ! $request->has('items')) {
            return response()->json(['message' => 'Nenhum campo editavel informado.'], 422);
        }

        // Sanitizacao basica de numericos
        foreach (['total', 'subtotal', 'shipping_cost', 'discount_amount'] as $numField) {
            if (array_key_exists($numField, $payload) && $payload[$numField] !== null) {
                $payload[$numField] = round((float) $payload[$numField], 2);
            }
        }
        if (array_key_exists('paid_at', $payload) && $payload['paid_at']) {
            try {
                $payload['paid_at'] = \Carbon\Carbon::parse($payload['paid_at']);
            } catch (\Throwable) {
                unset($payload['paid_at']);
            }
        }

        if (! empty($payload)) {
            // saveQuietly: edicao de rascunho nao dispara efeitos do observer;
            // efeitos (fanout/AutoPay) saem exclusivamente da promocao.
            $order->forceFill($payload);
            $order->saveQuietly();
        }

        // Itens opcionais: [{id?, name, sku, quantity, unit_price}]
        if (is_array($request->input('items'))) {
            foreach ($request->input('items') as $it) {
                if (! is_array($it)) continue;
                $itemData = [];
                foreach (['name', 'sku', 'quantity', 'unit_price'] as $k) {
                    if (array_key_exists($k, $it)) $itemData[$k] = $it[$k];
                }
                if (isset($itemData['quantity'], $itemData['unit_price'])) {
                    $itemData['total'] = round((float) $itemData['quantity'] * (float) $itemData['unit_price'], 2);
                }
                if (! empty($it['id'])) {
                    $order->items()->where('id', (int) $it['id'])->first()?->update($itemData);
                } elseif (! empty($itemData['name'])) {
                    $order->items()->create($itemData + ['quantity' => $itemData['quantity'] ?? 1, 'unit_price' => $itemData['unit_price'] ?? 0]);
                }
            }
        }

        $order = $order->fresh(['items']);
        $promoted = false;
        $missing  = [];
        if ($order->is_draft) {
            [$promoted, $missing] = app(\App\Services\Orders\DraftOrderPromoter::class)->promote($order, 'admin_edit');
            $order = $order->fresh();
        }

        return response()->json(['data' => [
            'id'           => $order->id,
            'is_draft'     => (bool) $order->is_draft,
            'draft_reason' => $order->draft_reason,
            'promoted'     => $promoted,
            'missing'      => $missing,
            'status'       => $order->status,
            'total'        => (float) $order->total,
            'customer_name'=> $order->customer_name,
        ]]);
    }

    /**
     * POST /api/v1/supplier-admin/orders/{id}/pull-integration
     * MUL-197: puxa dados do pedido direto da integracao (Shopee get_order_detail)
     * de forma sincrona e tenta promover. 422 para sources sem suporte a pull.
     */
    public function pullOrderFromIntegration(Request $request, int $id): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        if (! in_array($request->user()->role, ['super_admin', 'admin'], true)) {
            abort(403, 'Somente admin pode puxar dados da integracao.');
        }

        $order = Order::where('supplier_id', $this->supplierId())->findOrFail($id);

        $result = app(\App\Services\Orders\ShopeeOrderEnricher::class)->enrich($order);

        $order = $order->fresh();
        $httpCode = 200;
        if (in_array($result['code'] ?? '', ['unsupported_source', 'no_shopee_account'], true)) {
            $httpCode = 422;
        }

        return response()->json(['data' => [
            'id'           => $order->id,
            'ok'           => (bool) ($result['ok'] ?? false),
            'code'         => $result['code'] ?? null,
            'missing'      => $result['missing'] ?? [],
            'is_draft'     => (bool) $order->is_draft,
            'draft_reason' => $order->draft_reason,
            'total'        => (float) $order->total,
            'customer_name'=> $order->customer_name,
            'items_count'  => $order->items()->count(),
        ]], $httpCode);
    }

    /**
     * POST /api/v1/supplier-admin/orders/pull-integration
     * MUL-197: pull em massa — enfileira EnrichShopeeOrderJob para cada rascunho
     * Shopee do supplier (filtros opcionais: client_id, account_id, limit<=500).
     */
    public function pullOrdersFromIntegrationBulk(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);
        if (! in_array($request->user()->role, ['super_admin', 'admin'], true)) {
            abort(403, 'Somente admin pode puxar dados da integracao.');
        }

        $limit = min(max((int) $request->input('limit', 100), 1), 500);

        $q = Order::where('supplier_id', $this->supplierId())
            ->where('is_draft', 1)
            ->where('source', 'shopee')
            ->whereNotNull('marketplace_order_id')
            ->whereNotNull('marketplace_account_id')
            ->orderBy('id');

        if ($cid = (int) $request->input('client_id')) {
            $q->where('client_id', $cid);
        }
        if ($aid = (int) $request->input('account_id')) {
            $q->where('marketplace_account_id', $aid);
        }

        $ids = $q->limit($limit)->pluck('id');
        foreach ($ids as $i => $orderId) {
            // Espacar 3s entre jobs para nao estourar rate limit da Shopee
            \App\Jobs\EnrichShopeeOrderJob::dispatch($orderId)->delay(now()->addSeconds(3 * $i));
        }

        Log::channel('marketplace')->info('[MUL-197] Bulk pull-integration disparado', [
            'supplier_id' => $this->supplierId(),
            'count'       => $ids->count(),
            'by_user'     => $request->user()->id,
        ]);

        return response()->json(['data' => [
            'dispatched' => $ids->count(),
            'order_ids'  => $ids->all(),
        ]]);
    }

    // =====================================================================
    // MUL-213 item 16 — Setores & Operadores (cadastro administrativo)
    // =====================================================================

    private const OPERATOR_PERMISSIONS = ['pedidos', 'produtos', 'clientes', 'financeiro', 'picking', 'chamados', 'configuracoes'];

    public function listSectors(Request $request): JsonResponse
    {
        $sid = $this->supplierId();
        $sectors = \DB::table('sectors')->where('supplier_id', $sid)->orderBy('name')->get()->map(function ($s) {
            $arr = (array) $s;
            $arr['operators_count'] = \DB::table('operators')->where('sector_id', $s->id)->count();
            return $arr;
        })->values();
        return response()->json(['data' => $sectors]);
    }

    public function storeSector(Request $request): JsonResponse
    {
        $sid = $this->supplierId();
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_active'   => 'sometimes|boolean',
        ]);
        $data['supplier_id'] = $sid;
        $data['is_active']   = $data['is_active'] ?? true;
        $data['created_at']  = now();
        $data['updated_at']  = now();
        $id = \DB::table('sectors')->insertGetId($data);
        return response()->json(['message' => 'Setor criado.', 'sector' => \DB::table('sectors')->find($id)], 201);
    }

    public function updateSector(Request $request, int $id): JsonResponse
    {
        $sid = $this->supplierId();
        if (!\DB::table('sectors')->where('id', $id)->where('supplier_id', $sid)->exists()) {
            abort(404, 'Setor nao encontrado.');
        }
        $data = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string',
            'is_active'   => 'sometimes|boolean',
        ]);
        if (!empty($data)) {
            $data['updated_at'] = now();
            \DB::table('sectors')->where('id', $id)->update($data);
        }
        return response()->json(['message' => 'Setor atualizado.', 'sector' => \DB::table('sectors')->find($id)]);
    }

    public function destroySector(Request $request, int $id): JsonResponse
    {
        $sid = $this->supplierId();
        if (!\DB::table('sectors')->where('id', $id)->where('supplier_id', $sid)->exists()) {
            abort(404, 'Setor nao encontrado.');
        }
        \DB::table('operators')->where('sector_id', $id)->update(['sector_id' => null]);
        \DB::table('sectors')->where('id', $id)->delete();
        return response()->json(['message' => 'Setor removido.']);
    }

    public function listOperatorsCad(Request $request): JsonResponse
    {
        $sid = $this->supplierId();
        $ops = \DB::table('operators')->where('supplier_id', $sid)->orderBy('name')->get()->map(function ($o) {
            $arr = (array) $o;
            $arr['permissions'] = !empty($o->permissions) ? json_decode($o->permissions, true) : [];
            $arr['sector_name'] = $o->sector_id ? \DB::table('sectors')->where('id', $o->sector_id)->value('name') : null;
            return $arr;
        })->values();
        return response()->json(['data' => $ops, 'available_permissions' => self::OPERATOR_PERMISSIONS]);
    }

    public function storeOperator(Request $request): JsonResponse
    {
        $sid = $this->supplierId();
        $data = $this->validateOperator($request, $sid, null, true);
        $data['supplier_id'] = $sid;
        $data['is_active']   = $data['is_active'] ?? true;
        $data['created_at']  = now();
        $data['updated_at']  = now();
        $id = \DB::table('operators')->insertGetId($data);
        return response()->json(['message' => 'Operador criado.', 'operator' => \DB::table('operators')->find($id)], 201);
    }

    public function updateOperator(Request $request, int $id): JsonResponse
    {
        $sid = $this->supplierId();
        if (!\DB::table('operators')->where('id', $id)->where('supplier_id', $sid)->exists()) {
            abort(404, 'Operador nao encontrado.');
        }
        $data = $this->validateOperator($request, $sid, $id, false);
        if (!empty($data)) {
            $data['updated_at'] = now();
            \DB::table('operators')->where('id', $id)->update($data);
        }
        return response()->json(['message' => 'Operador atualizado.', 'operator' => \DB::table('operators')->find($id)]);
    }

    public function destroyOperator(Request $request, int $id): JsonResponse
    {
        $sid = $this->supplierId();
        if (!\DB::table('operators')->where('id', $id)->where('supplier_id', $sid)->exists()) {
            abort(404, 'Operador nao encontrado.');
        }
        \DB::table('operators')->where('id', $id)->delete();
        return response()->json(['message' => 'Operador removido.']);
    }

    private function validateOperator(Request $request, int $sid, ?int $id, bool $isCreate): array
    {
        $req = $isCreate ? 'required' : 'sometimes';
        $data = $request->validate([
            'name'          => $req . '|string|max:255',
            'email'         => 'sometimes|nullable|email|max:255',
            'badge_code'    => 'sometimes|nullable|string|max:255',
            'sector_id'     => 'sometimes|nullable|integer',
            'permissions'   => 'sometimes|nullable|array',
            'permissions.*' => 'string|in:' . implode(',', self::OPERATOR_PERMISSIONS),
            'is_active'     => 'sometimes|boolean',
        ]);
        if (!empty($data['sector_id'])) {
            $ok = \DB::table('sectors')->where('id', $data['sector_id'])->where('supplier_id', $sid)->exists();
            if (!$ok) abort(422, 'Setor invalido.');
        }
        if (array_key_exists('permissions', $data)) {
            $data['permissions'] = !empty($data['permissions']) ? json_encode(array_values($data['permissions'])) : null;
        }
        return $data;
    }

    public function markLabelsPrintedFromFederation(Request $request): JsonResponse
    {
        $request->validate([
            'order_ids'   => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer'],
        ]);
        $ids = $request->input('order_ids');
        $tenantSlug = $request->attributes->get('federation_tenant');

        $tenantId = \DB::table('tenants')->where('slug', $tenantSlug)->value('id');
        $suppliers = \DB::table('tenant_supplier')->where('tenant_id', $tenantId)->pluck('supplier_id');
        $filtered = \App\Models\Order::whereIn('id', $ids)
            ->whereIn('supplier_id', $suppliers)
            ->whereNotNull('label_url')
            ->whereNull('label_printed_at')
            ->pluck('id')->all();

        if (empty($filtered)) {
            return response()->json(['success' => true, 'affected' => 0, 'reason' => 'no_eligible_orders']);
        }

        \App\Models\Order::whereIn('id', $filtered)->update([
            'label_printed_at' => now(),
            'updated_at' => now(),
        ]);

        \App\Jobs\EmitBlingNfeJob::dispatchIfTrigger($filtered, 'label_printed'); // MUL-276

        foreach ($filtered as $oid) {
            \App\Jobs\FanoutOrderWebhookJob::dispatch($oid, 'order.updated', ['source_wl' => $tenantSlug]);
        }

        return response()->json(['success' => true, 'affected' => count($filtered), 'order_ids' => $filtered]);
    }


    // ================ INF-054 R3 — Categoria Y (legado bridge) ================
    // Estes metodos NAO gravam local no hub. Delegam pro LegacyBridgeService
    // que chama o legado. Legado depois propaga pro hub via SyncLegacyOrdersJob.
    // Quando legado for depreciado, migrar logica pra ca.

    public function orderCancelFromFederation(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['motivo' => 'required|string|max:500']);
        $res = $this->bridge->orderCancel($id, $data['motivo']);
        if (!$res['success']) return response()->json(['error' => $res['error']], 502);
        \App\Jobs\FanoutOrderWebhookJob::dispatch($id, 'order.updated', ['source_wl' => $request->attributes->get('federation_tenant'), 'action' => 'cancel']);
        return response()->json(['data' => $res['data']]);
    }

    public function orderCancelLabelFromFederation(Request $request, int $id): JsonResponse
    {
        $motivo = trim((string) $request->input('motivo', 'Cancelamento solicitado pelo painel'));
        $res = $this->bridge->orderCancelLabel($id, $motivo);
        if (!$res['success']) return response()->json(['error' => $res['error']], 502);
        \App\Jobs\FanoutOrderWebhookJob::dispatch($id, 'order.updated', ['source_wl' => $request->attributes->get('federation_tenant'), 'action' => 'cancel_label']);
        return response()->json(['data' => $res['data']]);
    }

    public function orderRefundFromFederation(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'motivo' => 'required|string|max:500',
            'valor'  => 'nullable|numeric|min:0',
        ]);
        $res = $this->bridge->orderRefund($id, $data['motivo'], $data['valor'] ?? null);
        if (!$res['success']) return response()->json(['error' => $res['error']], 502);
        \App\Jobs\FanoutOrderWebhookJob::dispatch($id, 'order.updated', ['source_wl' => $request->attributes->get('federation_tenant'), 'action' => 'refund']);
        return response()->json(['data' => $res['data']]);
    }

    public function orderBlockFromFederation(Request $request, int $id): JsonResponse
    {
        $desbloquear = $request->isMethod('delete');
        $motivo = '';
        if (!$desbloquear) {
            $data = $request->validate(['motivo' => 'required|string|max:500']);
            $motivo = $data['motivo'];
        }
        $res = $this->bridge->orderBlock($id, $motivo, $desbloquear);
        if (!$res['success']) return response()->json(['error' => $res['error']], 502);
        \App\Jobs\FanoutOrderWebhookJob::dispatch($id, 'order.updated', ['source_wl' => $request->attributes->get('federation_tenant'), 'action' => $desbloquear ? 'unblock' : 'block']);
        return response()->json(['data' => $res['data']]);
    }

    public function orderSwapSkuFromFederation(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'itens'                => 'required|array|min:1',
            'itens.*.id_sku_pai'   => 'required|integer|min:1',
            'itens.*.quantidade'   => 'required|integer|min:1',
            'itens.*.nome_produto' => 'nullable|string|max:300',
            'itens.*.preco_unit'   => 'nullable|numeric|min:0',
            'motivo'               => 'nullable|string|max:500',
        ]);
        $res = $this->bridge->orderSwapSku(
            $id,
            $data['itens'],
            $data['motivo'] ?? 'Troca de SKU solicitada pelo painel'
        );
        if (!$res['success']) return response()->json(['error' => $res['error']], 502);
        \App\Jobs\FanoutOrderWebhookJob::dispatch($id, 'order.updated', ['source_wl' => $request->attributes->get('federation_tenant'), 'action' => 'swap_sku']);
        return response()->json(['data' => $res['data']]);
    }

    /** MUL-264: sincronizar pedido com o Bling do fornecedor (via federation). */
    public function syncBlingFromFederation(Request $request, int $id): JsonResponse
    {
        $order = \App\Models\Order::findOrFail($id);
        $erp = \App\Models\ErpAccount::where('supplier_id', $order->supplier_id)
            ->where('platform','bling')->where('status','active')->first();
        if (!$erp) return response()->json(['error'=>'Fornecedor sem conta Bling ativa (erp_accounts).'], 422);
        // MUL-264 (24/07): sync sempre re-envia (UPDATE se ja existe)
        $wasSyncedFed = (bool) $order->bling_pedido_id;
        try {
            $svc = app(\App\Services\Integrations\Erps\Bling\BlingOrderSync::class);
            $res = $svc->exportSupplierOrder($erp, $order);
            if (!$res || !empty($res['_error'])) {
                $msg = $res['bling_description'] ?? ($res['message'] ?? 'Falha ao exportar (ver logs).');
                \Illuminate\Support\Facades\DB::table('orders')->where('id',$order->id)->update([
                    'bling_sync_error' => mb_substr((string) $msg, 0, 500),
                    'bling_sync_attempted_at' => now(),
                ]);
                return response()->json([
                    'error' => 'bling_export_failed',
                    'message' => $msg,
                    'bling_fields' => $res['bling_fields'] ?? [],
                    'raw' => $res['message'] ?? null,
                ], 422);
            }
            $blingId = $res['data']['id'] ?? $order->bling_pedido_id;
            if ($blingId) {
                \Illuminate\Support\Facades\DB::table('orders')->where('id',$order->id)->update([
                    'bling_pedido_id' => $blingId,
                    'bling_pedido_url' => "https://www.bling.com.br/vendas.php#/venda/$blingId",
                    'bling_synced_at' => now(),
                ]);
                \App\Jobs\FanoutOrderWebhookJob::dispatch($id, 'order.updated', ['source_wl' => $request->attributes->get('federation_tenant'), 'action' => 'sync_bling', 'bling_pedido_id' => $blingId]);
            }
            return response()->json(['data'=>['action'=>$wasSyncedFed ? 'updated' : 'created','bling_pedido_id'=>$blingId,'bling_pedido_url'=>$blingId ? "https://www.bling.com.br/vendas.php#/venda/$blingId" : null]]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[syncBlingFromFed] '.$e->getMessage(), ['order_id'=>$order->id]);
            return response()->json(['error'=>'Erro: '.$e->getMessage()], 500);
        }
    }

    /** MUL-265/MUL-275: emitir NF-e no Bling do fornecedor (via federation). */
    public function emitNfeFromFederation(Request $request, int $id): JsonResponse
    {
        $order = \App\Models\Order::findOrFail($id);
        if (!$order->bling_pedido_id) {
            return response()->json(['error'=>'not_synced','message'=>'Pedido nao sincronizado com Bling. Sincronize primeiro.'], 422);
        }
        if ($order->nfe_entrada_number && $order->nfe_entrada_status === 'authorized') {
            return response()->json(['data'=>['action'=>'already_emitted','nfe_number'=>$order->nfe_entrada_number,'nfe_access_key'=>$order->nfe_entrada_access_key]]);
        }
        $erp = \App\Models\ErpAccount::where('supplier_id', $order->supplier_id)
            ->where('platform', 'bling')->where('status', 'active')->first();
        if (!$erp) {
            return response()->json(['error'=>'no_bling_account','message'=>'Fornecedor sem conta Bling ativa.'], 422);
        }
        try {
            $res = app(\App\Services\Integrations\Erps\Bling\BlingNfeService::class)->emitForOrder($erp, $order);
            return response()->json(['data'=>$res]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[emitNfeFromFed] '.$e->getMessage(), ['order_id'=>$order->id]);
            return response()->json(['error'=>'nfe_error','message'=>$e->getMessage()], 422);
        }
    }


    /**
     * INF-060: busca dados de pagamento no banco do WL de origem via cross-database.
     * Retorna null se não encontrar (pedido sem pagamento local ou WL não suportada).
     */
    private function getPaymentDetails(int $hubOrderId, ?string $tenantSlug): ?array
    {
        if (!$tenantSlug) return null;

        // INF-060 v2: se estamos rodando em WL (JT/etc), fazer proxy pro hub que tem cross-DB
        if (\App\Services\Federation\HubProxyHelper::isWl()) {
            try {
                $resp = \App\Services\Federation\HubProxyHelper::forwardToHub('get', "/orders/$hubOrderId/payment-details", []);
                $body = $resp->getData(true);
                return $body['payment_details'] ?? null;
            } catch (\Throwable $e) { return null; }
        }

        // Mapear tenant_slug pra conexão configurada em database.php
        $conn = match($tenantSlug) {
            'fornecefy' => 'fornecefy',
            'multdrop', 'multdrop.app' => 'multdrop',
            default => null,
        };
        if (!$conn) return null;

        try {
            // Achar order local no WL via hubai_order_id
            $wlOrder = \DB::connection($conn)->table('orders')
                ->where('hubai_order_id', $hubOrderId)
                ->select('id', 'wallet_paid_at', 'wallet_transaction_id')
                ->first();
            if (!$wlOrder) return null;

            // Payment mais recente pro pedido
            $payment = \DB::connection($conn)->table('payments')
                ->where('order_id', $wlOrder->id)
                ->orderByDesc('id')
                ->first();

            // Pix transaction ligada
            $pix = null;
            if ($payment && $payment->pix_transaction_id) {
                $pix = \DB::connection($conn)->table('pix_transactions')
                    ->where('id', $payment->pix_transaction_id)
                    ->first();
            }
            // Fallback: buscar direto por order_id
            if (!$pix) {
                $pix = \DB::connection($conn)->table('pix_transactions')
                    ->where('order_id', $wlOrder->id)
                    ->where('status', 'paid')
                    ->orderByDesc('id')
                    ->first();
            }

            // Débito da wallet (quando pagamento veio de saldo)
            $walletTx = null;
            if ($wlOrder->wallet_transaction_id) {
                $walletTx = \DB::connection($conn)->table('client_supplier_transactions')
                    ->where('id', $wlOrder->wallet_transaction_id)
                    ->first();
            }
            if (!$walletTx) {
                $walletTx = \DB::connection($conn)->table('client_supplier_transactions')
                    ->where('order_id', $wlOrder->id)
                    ->where('type', 'debit')
                    ->orderByDesc('id')
                    ->first();
            }

            return [
                'source_wl' => $tenantSlug,
                'wl_order_id' => (int) $wlOrder->id,
                'pix_transaction' => $pix ? [
                    'id' => (int) $pix->id,
                    'external_id' => $pix->external_id,
                    'gateway' => $pix->gateway,
                    'amount' => (float) $pix->amount,
                    'fee_amount' => (float) ($pix->fee_amount ?? 0),
                    'net_amount' => (float) ($pix->net_amount ?? 0),
                    'status' => $pix->status,
                    'paid_at' => $pix->paid_at,
                    'expires_at' => $pix->expires_at,
                    'qr_code_text' => $pix->qr_code_text,
                    'idempotency_key' => $pix->idempotency_key,
                    'manually_confirmed_at' => $pix->manually_confirmed_at ?? null,
                    'created_at' => $pix->created_at,
                ] : null,
                'wallet_debit' => $walletTx ? [
                    'id' => (int) $walletTx->id,
                    'type' => $walletTx->type,
                    'amount' => (float) $walletTx->amount,
                    'description' => $walletTx->description,
                    'transaction_type' => $walletTx->transaction_type ?? null,
                    'created_at' => $walletTx->created_at,
                ] : null,
                'payment' => $payment ? [
                    'id' => (int) $payment->id,
                    'gateway' => $payment->gateway,
                    'method' => $payment->method,
                    'amount' => (float) $payment->amount,
                    'wallet_amount' => (float) ($payment->wallet_amount ?? 0),
                    'pix_amount' => (float) ($payment->pix_amount ?? 0),
                    'status' => $payment->status,
                    'paid_at' => $payment->paid_at ?? null,
                    'created_at' => $payment->created_at,
                ] : null,
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[INF-060] getPaymentDetails erro', [
                'hub_order_id' => $hubOrderId, 'tenant' => $tenantSlug, 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }


    /**
     * INF-060: endpoint federation pra WLs consultarem payment_details.
     * Chamado pelo próprio hub via HubProxyHelper (WL → HUB → cross-DB fornecefy).
     */
    public function paymentDetailsFromFederation(Request $request, int $id): JsonResponse
    {
        $order = \App\Models\Order::find($id);
        if (!$order) return response()->json(['error' => 'order_not_found'], 404);
        $tenantSlug = $order->tenant_slug;
        $details = $this->getPaymentDetails($id, $tenantSlug);
        return response()->json(['payment_details' => $details]);
    }

}
