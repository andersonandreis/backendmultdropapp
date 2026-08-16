<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Importa pedidos NOVOS do banco legado para o NovoHubAI.
 *
 * O SyncLegacyOrdersJob apenas atualiza status de pedidos que ja existem
 * (com legacy_id setado). Este job complementa: varre o legado em busca
 * de pedidos que ainda nao foram importados (de-dup por legacy_id).
 *
 * Fluxo:
 *   1. Para cada Client com legacy_id_login preenchido:
 *      a. Busca integracoes do cliente no legado (integracao.id_login = legacy_id_login)
 *      b. Busca legacy_ids ja importados (orders.legacy_id WHERE client_id = ?)
 *      c. Consulta pedidos do legado NAO presentes (pedidos.id NOT IN imported)
 *      d. Cria Order + OrderItems no NovoHubAI
 *
 * De-dup: firstOrCreate por legacy_id garante idempotencia mesmo em corrida paralela.
 *
 * Agendado em routes/console.php junto ao SyncLegacyOrdersJob (5 min).
 *
 * Limite de seguranca: processa no maximo BATCH_PER_CLIENT pedidos por cliente
 * por execucao para nao sobrecarregar a conexao legada.
 */
class ImportLegacyOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximo de pedidos importados POR CLIENTE por execucao (safety net).
     * Cada cliente migrado recebe seu proprio slot independente, garantindo
     * que um cliente com muitos pedidos pendentes nao bloqueia os demais.
     */
    public int $timeout = 600;
    public int $tries = 3;

    /**
     * Backoff: 30s, 5min, 30min entre tentativas.
     */
    public function backoff(): array
    {
        return [30, 300, 1800];
    }
    const BATCH_PER_CLIENT = 30;

    /**
     * Mapeamento id_canal legado -> source no NovoHubAI.
     * id_canal=20 = pedidos manuais/Bling no legado (nome_canal vazio).
     * id_canal=3  = Shopee
     * id_canal=6  = MercadoLivre
     * id_canal=4  = Magalu
     * id_canal=13 = TikTok
     */
    const CANAL_SOURCE_MAP = [
        3  => 'shopee',
        4  => 'magalu',
        6  => 'mercadolivre',
        7  => 'bling',
        8  => 'tiktok',
        13 => 'tiktok',
        20 => 'bling',
    ];

    /**
     * @param int|null $clientId   null = modo dispatcher, int = modo worker (1 cliente)
     * @param int|null $supplierId supplier_id do NovoHubAI para filtrar integracoes corretamente.
     *                             NOV-038: obrigatorio no modo worker para evitar fallback
     *                             incorreto para supplier 30 (Multdrop).
     *
     * NOV-037 (2026-06-23): dispatcher/worker pattern para suportar 22k clientes sem timeout.
     */
    public ?int $clientId   = null;
    public ?int $supplierId = null;

    public function __construct(?int $clientId = null, ?int $supplierId = null)
    {
        $this->clientId   = $clientId;
        $this->supplierId = $supplierId;
        // HUB-113: queue dedicada legacy-import (isola das queues default/webhooks).
        $this->onQueue('legacy-import');
    }

    public function handle(): void
    {
        // Modo DISPATCHER: enfileira 1 job por cliente
        if ($this->clientId === null) {
            $this->dispatchPerClient();
            return;
        }

        // Modo WORKER: processa 1 cliente especifico
        $client = Client::find($this->clientId);
        if (!$client || !$client->legacy_id_login) {
            return;
        }

        $startedAt = now();
        [$imported, $errors] = $this->importForClient($client, self::BATCH_PER_CLIENT);

        Log::info('ImportLegacyOrdersJob[worker]: client=' . $this->clientId
            . ' supplier=' . ($this->supplierId ?? 'null')
            . ' imported=' . $imported . ' errors=' . $errors);

        if ($imported > 0 || $errors > 0) {
            try {
                \App\Models\LegacySyncRun::create([
                    'job'         => 'import-orders',
                    'status'      => $errors > 0 ? 'partial' : 'success',
                    'processed'   => $imported,
                    'errors'      => $errors,
                    'message'     => sprintf('client=%d supplier=%d %d importados / %d erros', $this->clientId, $this->supplierId ?? 0, $imported, $errors),
                    'started_at'  => $startedAt,
                    'finished_at' => now(),
                    'duration_ms' => (int) $startedAt->diffInMilliseconds(now()),
                ]);
            } catch (\Throwable $e) {
                Log::warning('ImportLegacyOrdersJob: falha gravando LegacySyncRun: ' . $e->getMessage());
            }
        }
    }

    /**
     * Modo DISPATCHER: enfileira 1 job worker por combinacao (cliente x supplier).
     *
     * NOV-038: itera sobre todos os suppliers com legacy_empresa_id preenchido,
     * buscando clientes que tenham integracoes no legado para aquele id_empresa.
     * Isso substitui o filtro hardcoded de id_deposito=[498,773] que excluia
     * suppliers como Drop Auto Pecas (id_empresa=20).
     *
     * Limita a 200 despachos por ciclo de 5min para nao inundar a fila.
     */
    private function dispatchPerClient(): void
    {
        $dispatched = 0;
        $limit = 200;

        // Carrega mapa legacy_empresa_id -> supplier para todos os suppliers ativos.
        $suppliers = DB::table('suppliers')
            ->whereNotNull('legacy_empresa_id')
            ->where('legacy_empresa_id', '>', 0)
            ->where('is_active', 1)
            ->select(['id', 'legacy_empresa_id'])
            ->get()
            ->keyBy('legacy_empresa_id');

        if ($suppliers->isEmpty()) {
            Log::warning('ImportLegacyOrdersJob[dispatcher]: nenhum supplier com legacy_empresa_id');
            return;
        }

        $legacyEmpresaIds = $suppliers->keys()->all();

        Client::whereNotNull('legacy_id_login')
            ->select(['id', 'legacy_id_login'])
            ->chunkById(100, function ($clients) use (&$dispatched, $limit, $suppliers, $legacyEmpresaIds) {
                if ($dispatched >= $limit) {
                    return false;
                }

                $loginIds = $clients->pluck('legacy_id_login')->all();

                // Busca integracoes do legado para esses clientes, por id_empresa.
                $integRows = DB::connection('legacy')
                    ->table('integracao')
                    ->whereIn('id_login', $loginIds)
                    ->whereIn('id_empresa', $legacyEmpresaIds)
                    ->select(['id_login', 'id_empresa'])
                    ->distinct()
                    ->get();

                $loginEmpresaMap = [];
                foreach ($integRows as $row) {
                    $loginEmpresaMap[$row->id_login][] = $row->id_empresa;
                }

                foreach ($clients as $client) {
                    if ($dispatched >= $limit) break;

                    $empresaIds = $loginEmpresaMap[$client->legacy_id_login] ?? [];
                    if (empty($empresaIds)) continue;

                    foreach ($empresaIds as $empresaId) {
                        if ($dispatched >= $limit) break;
                        $supplier = $suppliers->get($empresaId);
                        if (!$supplier) continue;
                        // HUB-113: queue dedicada legacy-import (antes era default — sufocava webhooks).
                        static::dispatch($client->id, (int) $supplier->id)->onQueue('legacy-import');
                        $dispatched++;
                    }
                }
            });

        Log::info('ImportLegacyOrdersJob[dispatcher]: dispatched=' . $dispatched . ' worker jobs');
    }

    private function importForClient(Client $client, int $limit): array
    {
        // NOV-038: determina o supplier_id e seu legacy_empresa_id para filtrar
        // NOV-150-C: cutoff date - so importa pedidos historicos anteriores ao webhook-first (NOV-150-B)
        // Com LEGACY_IMPORT_CUTOFF_DATE=2026-06-28, pedidos novos chegam via webhook diretamente.
        $cutoffDate = config('services.legacy.import_cutoff_date');

        // integracoes pelo id_empresa correto (substitui o whereIn id_deposito hardcoded).
        $supplierId = $this->supplierId;

        if (!$supplierId) {
            // Fallback legado: tenta inferir pelo primeiro pedido existente.
            $supplierId = Order::where('client_id', $client->id)
                ->whereNotNull('supplier_id')
                ->value('supplier_id') ?? (int) config('multdrop.supplier_id', 30);
        }

        $legacyEmpresaId = DB::table('suppliers')
            ->where('id', $supplierId)
            ->value('legacy_empresa_id');

        if (!$legacyEmpresaId) {
            Log::warning('ImportLegacyOrdersJob: supplier_id=' . $supplierId . ' sem legacy_empresa_id — client=' . $client->id);
            return [0, 0];
        }

        $integIds = DB::connection('legacy')
            ->table('integracao')
            ->where('id_login', $client->legacy_id_login)
            ->where('id_empresa', $legacyEmpresaId)
            ->pluck('id')
            ->all();

        if (empty($integIds)) {
            return [0, 0];
        }

        $alreadyImported = Order::where('client_id', $client->id)
            ->where('supplier_id', $supplierId)
            ->whereNotNull('legacy_id')
            ->pluck('legacy_id')
            ->all();

        $query = DB::connection('legacy')
            ->table('pedidos')
            ->whereIn('id_integracao', $integIds)
            ->select([
                'id',
                'id_integracao',
                'id_canal',
                'nome_canal',
                'status',
                'status_marketplace',
                'valor_total',
                'frete_valor',
                'cliente_nome',
                'cliente_cpf',
                'data_pedido_canal',
                'data_add',
                'curso_pago',
                'rastreio',
                'url_img',
                'enviado_etiqueta',
                'shipping_id',
                'nr_canal',
                'endereco_endereco',
                'endereco_numero',
                'endereco_complemento',
                'endereco_cidade',
                'endereco_estado',
                'endereco_bairro',
                'endereco_cep',
                'tipo_entrega',
                // === Fase 1: campos extras pra fornecedor (carrier, NF, JSON dados) ===
                'carrier_name',
                'id_nota_erp',
                'serie_nota_erp',
                'danfe_nota',
                'xml_nota',
                'data_nfe_auto',
                'desc_status_nota',
                'dados',
            ])
            ->orderBy('id', 'asc')
            ->limit($limit);

        // NOV-150-C: filtro historico - so processa pedidos criados antes do cutoff
        if ($cutoffDate) {
            $query->where('data_add', '<', $cutoffDate);
        }

        if (!empty($alreadyImported)) {
            $query->whereNotIn('id', $alreadyImported);
        }

        $legacyOrders = $query->get();

        if ($legacyOrders->isEmpty()) {
            return [0, 0];
        }

        // Fase 1: resolver slug do tenant a partir do supplier_id para roteamento de webhook.
        // Um supplier pode pertencer a múltiplos tenants via tenant_supplier (ex: Multdrop está
        // em fornecefy E multdrop.app). Usamos o tenant de visibilidade "scoped" como principal
        // — se houver múltiplos, escolhemos o que tem slug mais específico (não 'fornecefy').
        // Se o supplier estiver APENAS em fornecefy, usamos fornecefy.
        $tenantSlug = '';
        if ($supplierId) {
            $tenantRows = \DB::table('tenant_supplier as ts')
                ->join('tenants as t', 't.id', '=', 'ts.tenant_id')
                ->where('ts.supplier_id', $supplierId)
                ->where('t.status', 'active')
                ->orderByRaw("CASE WHEN t.slug = 'fornecefy' THEN 1 ELSE 0 END ASC")
                ->pluck('t.slug');
            $tenantSlug = $tenantRows->first() ?? '';
        }

        $imported = 0;
        $errors   = 0;

        foreach ($legacyOrders as $lp) {
            // Nao importar pedidos cancelados — sem valor operacional, poluem o painel.
            $smRaw = strtoupper((string) ($lp->status_marketplace ?? ''));
            if (in_array($smRaw, ['CANCELLED', 'CANCELED', 'IN_CANCEL', 'REFUNDED', 'TO_RETURN', 'PEDIDO RETORNADO'])) {
                continue;
            }
            try {
                $this->importSingleOrder($lp, $client->id, $supplierId, $tenantSlug);
                $imported++;
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), '1062')) {
                    $imported++;
                    continue;
                }
                Log::error('ImportLegacyOrdersJob: erro ao importar pedido legado #' . $lp->id, [
                    'client_id' => $client->id,
                    'error'     => $e->getMessage(),
                ]);
                $errors++;
            }
        }

        return [$imported, $errors];
    }

    private function importSingleOrder(object $lp, int $clientId, int $supplierId, string $tenantSlug = ''): void
    {
        $status = $this->mapStatus($lp);
        $source = self::CANAL_SOURCE_MAP[$lp->id_canal] ?? 'manual';

        $paidAt      = null;
        $shippedAt   = null;
        $deliveredAt = null;
        $cancelledAt = null;

        // paid_at: usa SEMPRE o curso_pago do legado quando existir, independente
        // do status. O legado registra a data de credito do pagamento mesmo
        // antes do pedido virar 'paid' no nosso enum (98.5% dos pedidos da
        // Victoria tem curso_pago no legado, contra 26% no novo — gap de import).
        if ($lp->curso_pago) {
            $paidAt = $lp->curso_pago;
        }
        if (in_array($status, ['shipped', 'delivered']) && $lp->enviado_etiqueta) {
            $shippedAt = $lp->enviado_etiqueta;
        }
        if ($status === 'delivered') {
            $shippedAt   = $shippedAt ?? now();
            $deliveredAt = now();
        }
        if ($status === 'cancelled') {
            $cancelledAt = now();
        }

        $customerAddress = null;
        if (!empty($lp->endereco_endereco)) {
            $customerAddress = [
                'street'       => $lp->endereco_endereco,
                'number'       => $lp->endereco_numero ?? '',
                'complement'   => $lp->endereco_complemento ?? '',
                'neighborhood' => $lp->endereco_bairro ?? '',
                'city'         => $lp->endereco_cidade ?? '',
                'state'        => $lp->endereco_estado ?? '',
                'zip_code'     => $lp->endereco_cep ?? '',
                'country'      => 'BR',
            ];
        }

        $total    = (float) ($lp->valor_total ?? 0);
        $shipping = (float) ($lp->frete_valor ?? 0);
        $subtotal = max(0, $total - $shipping);

        // ------------------------------------------------------------------
        // Buscar itens legados ANTES de criar o Order para calcular
        // supplier_total (soma dos custos de todos os itens do pedido).
        // Join: pedidos_produtos.id_pedido = pedidos.id
        // Colunas de custo: custo_dia (primario) > custo_pago_dia (fallback)
        // Evidencia: 18.325 itens importados tem custo_dia > 0;
        //            18.268 tem custo_pago_dia > 0. Ambos coincidem quando
        //            presentes (validado em order_id=19212/legacy_id=1719637).
        // ------------------------------------------------------------------
        $legacyItems = DB::connection('legacy')
            ->table('pedidos_produtos')
            ->where('id_pedido', $lp->id)
            ->select([
                'id',
                'id_sku_pai',
                'sku',
                'descricao',
                'qtd',
                'valor_unitario',
                'foto',
                'custo_dia',
                'custo_pago_dia',
            ])
            ->get();

        $supplierTotal = 0.0;
        foreach ($legacyItems as $li) {
            $unitCost = $this->resolveItemCost($li);
            $qty      = max(1, (int) ($li->qtd ?? 1));
            $supplierTotal += $unitCost * $qty;
        }

        $order = Order::firstOrCreate(
            ['legacy_id' => $lp->id],
            [
                'client_id'                => $clientId,
                'supplier_id'              => $supplierId,
                'source'                   => $source,
                'external_order_id'        => $lp->nr_canal ?: ('LEG-' . $lp->id),
                'customer_name'            => $lp->cliente_nome ?? 'Desconhecido',
                'customer_document_number' => $lp->cliente_cpf ?? null,
                'customer_address'         => $customerAddress,
                'status'                   => $status,
                'canonical_status'         => $status,
                'order_processing_status'  => $this->mapProcessingStatus($status),
                'subtotal'                 => $subtotal,
                'shipping_cost'            => $shipping,
                'total'                    => $total,
                'supplier_total'           => $supplierTotal > 0 ? round($supplierTotal, 2) : null,
                'currency'                 => 'BRL',
                'tracking_number'          => $lp->rastreio ?: null,
                'label_url'                => $lp->url_img ?: null,
                'delivery_type'            => $lp->tipo_entrega ?? null,
                'channel_name'             => $lp->nome_canal ?? null,
                'paid_at'                  => $paidAt,
                'shipped_at'               => $shippedAt,
                'delivered_at'             => $deliveredAt,
                'cancelled_at'             => $cancelledAt,
                // === Fase 1: campos extras ===
                'customer_phone'           => $this->extractCustomerPhone($lp),
                'shipping_mode'            => $this->resolveShippingMode($lp),
                'carrier_name'             => $lp->carrier_name ?: $this->extractCarrierFromDados($lp),
                'invoice_number'           => $lp->id_nota_erp ?: null,
                'invoice_series'           => $lp->serie_nota_erp ?: null,
                'invoice_access_key'       => $lp->danfe_nota ?: null,
                'invoice_issued_at'        => $lp->data_nfe_auto ?: null,
                'invoice_status'           => $lp->desc_status_nota ?: null,
                'invoice_xml'              => $lp->xml_nota ?: null,
                'notes'                    => 'Importado do legado em ' . now()->toDateTimeString(),
                // === Fase 1: roteamento de webhook por tenant ===
                'tenant_slug'              => $tenantSlug ?: null,
            ]
        );

        if (!$order->wasRecentlyCreated) {
            return;
        }

        // created_at = data REAL do pedido no legado (data_add), nao a data
        // do import. Nao da pra passar no firstOrCreate: created_at nao esta
        // no $fillable do Order, seria descartado e o Laravel poria now().
        // Por isso corrige direto via DB logo apos a criacao.
        //
        // Timestamp fix: legado armazena UTC como DATETIME sem fuso. A coluna
        // created_at no novo banco e TIMESTAMP e a sessao MySQL roda em
        // America/Sao_Paulo (UTC-3). Se passarmos a string UTC diretamente,
        // o MySQL adiciona 3h ao armazenar. Convertemos para representacao BRT
        // antes de enviar; o MySQL converte BRT->UTC e armazena o valor correto.
        $rawDate = $lp->data_add ?: ($lp->data_pedido_canal ?: null);
        if ($rawDate) {
            $realDate = \Carbon\Carbon::parse((string) $rawDate, 'UTC')
                ->setTimezone('America/Sao_Paulo')
                ->toDateTimeString();
            DB::table('orders')->where('id', $order->id)->update(['created_at' => $realDate]);
        }

        // Fase 1: disparar webhook de novo pedido importado (stub — sem endpoints ativos ainda)
        if ($tenantSlug) {
            DispatchTenantOrderWebhookJob::dispatch($order->id, 'order.created');
        }

        // Pre-load catalog products keyed by legacy_sku_pai_id for efficient lookup
        $skuPaiIds = array_filter(array_column((array) $legacyItems, 'id_sku_pai'));
        // MUL-063-B: legacy nem sempre traz id_sku_pai — bater tambem por SKU direto
        $skus = array_filter(array_column((array) $legacyItems, 'sku'));
        $catalogProducts    = [];
        $catalogImages      = [];
        $catalogProductsBySku = [];
        $catalogImagesByPid = [];
        if (!empty($skuPaiIds)) {
            $catalogRows = \DB::table('products')
                ->whereIn('legacy_sku_pai_id', $skuPaiIds)
                ->select(['id', 'legacy_sku_pai_id'])
                ->get()
                ->keyBy('legacy_sku_pai_id');

            foreach ($catalogRows as $spId => $prod) {
                $catalogProducts[$spId] = $prod->id;
            }

            $coverMedia = \DB::table('product_media')
                ->join('products', 'products.id', '=', 'product_media.product_id')
                ->whereIn('products.legacy_sku_pai_id', $skuPaiIds)
                ->where('product_media.is_cover', 1)
                ->select(['products.legacy_sku_pai_id', 'product_media.url'])
                ->get();
            foreach ($coverMedia as $m) {
                $catalogImages[$m->legacy_sku_pai_id] = $m->url;
            }
        }
        // MUL-063-B: resolver product_id + imagem via SKU quando id_sku_pai ausente
        if (!empty($skus)) {
            \DB::table('products')
                ->whereIn('sku', $skus)
                ->select(['id', 'sku'])
                ->get()
                ->each(function ($p) use (&$catalogProductsBySku) {
                    $catalogProductsBySku[$p->sku] = $p->id;
                });
            if (!empty($catalogProductsBySku)) {
                \DB::table('product_media')
                    ->whereIn('product_id', array_values($catalogProductsBySku))
                    ->where(function ($q) { $q->where('is_cover', 1)->orWhere('is_cover', 0); })
                    ->orderByDesc('is_cover')
                    ->select(['product_id', 'url'])
                    ->get()
                    ->each(function ($m) use (&$catalogImagesByPid) {
                        if (!isset($catalogImagesByPid[$m->product_id])) {
                            $catalogImagesByPid[$m->product_id] = $m->url;
                        }
                    });
            }
        }

        foreach ($legacyItems as $li) {
            $qty      = max(1, (int) ($li->qtd ?? 1));
            $price    = (float) ($li->valor_unitario ?? 0);
            $unitCost = $this->resolveItemCost($li);

            $spId          = $li->id_sku_pai ?: null;
            $skuVal        = $li->sku ?? null;
            $catalogProdId = $spId ? ($catalogProducts[$spId] ?? null) : null;
            // MUL-063-B: fallback SKU direto quando id_sku_pai ausente
            if (!$catalogProdId && $skuVal && isset($catalogProductsBySku[$skuVal])) {
                $catalogProdId = $catalogProductsBySku[$skuVal];
            }
            // Cascata da imagem: catalog(spId) -> foto legacy -> catalog(product_id via SKU)
            $productImage = null;
            if ($spId && isset($catalogImages[$spId])) $productImage = $catalogImages[$spId];
            if (!$productImage && !empty($li->foto)) $productImage = $li->foto;
            if (!$productImage && $catalogProdId && isset($catalogImagesByPid[$catalogProdId])) {
                $productImage = $catalogImagesByPid[$catalogProdId];
            }

            OrderItem::create([
                'order_id'            => $order->id,
                'sku'                 => $li->sku ?? '',
                'name'                => $li->descricao ?? 'Produto',
                'quantity'            => $qty,
                'unit_price'          => $price,
                'total'               => round($price * $qty, 2),
                'supplier_unit_cost'  => $unitCost > 0 ? $unitCost : null,
                'supplier_total_cost' => $unitCost > 0 ? round($unitCost * $qty, 2) : null,
                'product_id'          => $catalogProdId,
                'product_image'       => $productImage,
                'legacy_sku_pai_id'   => $spId,
            ]);
        }
    }

    /**
     * Resolve o custo unitario do fornecedor de um item legado.
     *
     * Hierarquia (mais confiavel -> fallback):
     *   1. custo_dia      — custo na data do pedido (18.325 itens, cobertura maior)
     *   2. custo_pago_dia — custo na data do pagamento (18.268 itens)
     *
     * Retorna 0.0 se nenhuma fonte tiver valor.
     */
    private function resolveItemCost(object $li): float
    {
        $costDia     = (float) ($li->custo_dia ?? 0);
        $costPagoDia = (float) ($li->custo_pago_dia ?? 0);

        if ($costDia > 0) {
            return $costDia;
        }
        if ($costPagoDia > 0) {
            return $costPagoDia;
        }
        // Fallback: busca custo do produto pelo legacy_sku_pai_id
        if (!empty($li->id_sku_pai)) {
            $product = DB::table('products')->where('legacy_sku_pai_id', (int) $li->id_sku_pai)->value('cost');
            if ($product && (float) $product > 0) {
                return (float) $product;
            }
        }
        return 0.0;
    }

    private function mapStatus(object $lp): string
    {
        $sm = strtoupper((string) ($lp->status_marketplace ?? ''));

        if (in_array($sm, ['CANCELLED', 'CANCELED', 'REFUNDED', 'TO_RETURN', 'PEDIDO RETORNADO'])) {
            return 'cancelled';
        }
        if (in_array($sm, ['DELIVERED', 'COMPLETED'])) {
            return 'delivered';
        }
        if (in_array($sm, ['SHIPPED', 'TO_CONFIRM_RECEIVE'])) {
            return 'shipped';
        }
        if ($sm === 'READY_TO_SHIP') {
            return 'paid';
        }
        if (in_array($sm, ['APPROVED', 'PAID']) && $lp->curso_pago) {
            return 'paid';
        }
        if ($sm === 'APPROVED' && !$lp->curso_pago) {
            return 'pending_payment';
        }
        if ($lp->enviado_etiqueta) {
            return 'shipped';
        }
        if ($lp->curso_pago) {
            return 'paid';
        }

        return 'pending_payment';
    }

    private function mapProcessingStatus(string $status): string
    {
        return match ($status) {
            'paid'      => 'awaiting_label',
            'shipped'   => 'shipped',
            'delivered' => 'delivered',
            'cancelled' => 'cancelled',
            default     => 'pending',
        };
    }

    /**
     * Extrai o telefone do destinatario do JSON `dados` do pedido legado.
     * Cada canal tem layout diferente:
     *   - Shopee (canal 3): dados.orders[0].recipient_address.phone
     *   - Bling  (canal 20): nao traz no JSON contato (4 keys: id/nome/tipoPessoa/numeroDocumento)
     *   - ML     (canal 6):  dados.buyer.phone.area_code + number  (a confirmar)
     *   - TikTok (canal 13): payload escasso
     * Retorna null quando nao conseguir extrair.
     */
    private function extractCustomerPhone(object $lp): ?string
    {
        if (empty($lp->dados)) {
            return null;
        }
        $d = json_decode($lp->dados, true);
        if (!is_array($d)) {
            return null;
        }

        // Shopee
        if (($lp->id_canal ?? null) == 3) {
            $phone = $d['orders'][0]['recipient_address']['phone'] ?? null;
            return $phone ? (string) $phone : null;
        }

        // Mercado Livre (estrutura tipica: buyer.phone.area_code + number)
        if (($lp->id_canal ?? null) == 6) {
            $ac  = $d['buyer']['phone']['area_code'] ?? null;
            $num = $d['buyer']['phone']['number'] ?? null;
            if ($ac && $num) return $ac . $num;
            return $d['buyer']['phone']['raw'] ?? null;
        }

        // Bling / outros — JSON nao traz fone
        return null;
    }

    /**
     * Extrai transportadora do JSON `dados` quando a coluna `carrier_name`
     * do legado nao tem o valor.
     *
     * Canais suportados:
     *  - Shopee  (id_canal=3):  dados.orders[0].shipping_carrier
     *  - ML      (id_canal=6):  dados.shipping.carrier_name / shipping.mode
     *  - Shopify (id_canal=11): dados.shipping_lines[0].title
     *  - Magalu  (id_canal=1):  sem carrier legivel no JSON -> 'Magalu Entregas'
     */
    private function extractCarrierFromDados(object $lp): ?string
    {
        if (empty($lp->dados)) {
            return null;
        }
        $d = json_decode($lp->dados, true);
        if (!is_array($d)) {
            return null;
        }

        // Shopee
        if (($lp->id_canal ?? null) == 3) {
            return $d['orders'][0]['package_list'][0]['shipping_carrier'] ?? $d['orders'][0]['shipping_carrier'] ?? null;
        }

        // ML
        if (($lp->id_canal ?? null) == 6) {
            return $d['shipping']['carrier_name'] ?? $d['shipping']['mode'] ?? null;
        }

        // Shopify (id_canal=11): nome esta em shipping_lines[0].title
        if (($lp->id_canal ?? null) == 11) {
            return $d['shipping_lines'][0]['title'] ?? null;
        }

        // Magalu / integracommerce (id_canal=1): JSON nao tem carrier legivel
        if (($lp->id_canal ?? null) == 1) {
            return 'Magalu Entregas';
        }

        return null;
    }

    /**
     * Resolve o modo de envio (shipping_mode) normalizando IDs numericos
     * que Shopify (id_canal=11) e Magalu (id_canal=1) gravam em
     * tipo_entrega como ID interno de logistica.
     *
     * Se tipo_entrega for numerico puro -> descarta e tenta extrair nome
     * do JSON dados. Caso nao consiga, retorna null (frontend mostra '-').
     */
    private function resolveShippingMode(object $lp): ?string
    {
        $raw = $lp->tipo_entrega ?? null;

        // tipo_entrega legivel -> usar direto
        if ($raw && !ctype_digit((string) $raw)) {
            return $raw;
        }

        // tipo_entrega numerico (ID de logistica) -> tentar extrair nome legivel
        if ($raw && ctype_digit((string) $raw)) {
            // Shopify: shipping_lines[0].title tem o nome legivel
            if (($lp->id_canal ?? null) == 11 && !empty($lp->dados)) {
                $d = json_decode($lp->dados, true);
                if (is_array($d)) {
                    $title = $d['shipping_lines'][0]['title'] ?? null;
                    if ($title) {
                        return $title;
                    }
                }
            }

            // Magalu: ID de logistica sem nome no JSON -> fallback padrao
            if (($lp->id_canal ?? null) == 1) {
                return 'Magalu Entregas';
            }

            // Outros canais com ID numerico -> null (frontend mostra '-')
            return null;
        }

        return null;
    }
}
