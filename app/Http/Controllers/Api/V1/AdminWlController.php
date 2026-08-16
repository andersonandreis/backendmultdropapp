<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NOV-217 â€” Admin WL Controller
 *
 * GET  /api/v1/admin/wl/live-counts
 *      Conta clientes de cada WL diretamente no banco da WL (fonte de verdade).
 *      Retorna total e ativos por empresa_id. Protegido por auth:sanctum + super_admin.
 *
 * PATCH /api/v1/admin/wl/{empresa_id}/config
 *      Atualiza whitelabel_billing_config no Supabase HubAI (requer SUPABASE_HUBAI_SERVICE_ROLE_KEY).
 *
 * PATCH /api/v1/admin/wl/{empresa_id}/block
 *      Atalho para bloquear/desbloquear WL (is_blocked, blocked_reason).
 */
class AdminWlController extends Controller
{
    // Mapa de conexoes por empresa_id
    private const WL_DB_MAP = [
        22 => [
            'nome'     => 'Fornecefy',
            'host'     => '127.0.0.1',
            'port'     => 3307,
            'database' => 'fornecefyapp_production',
            'username' => 'fornecefyapp',
            'password' => 'frnAppPwd_2026_xT8vQ3mN7kL5pJ2z',
        ],
        24 => [
            'nome'     => 'MultDrop',
            'host'     => '127.0.0.1',
            'port'     => 3307,
            'database' => 'multdropapp_production',
            'username' => 'multdropapp',
            'password' => 'mdAppPwd_2026_aF5bH8jK4eD3sQ7w',
        ],
        17 => [
            'nome'     => 'JTDrop',
            'host'     => '127.0.0.1',
            'port'     => 3306,
            'database' => 'jtdrop_prod',
            'username' => 'jtdrop_app',
            'password' => '259fe64e0ec6d4cedf480473103f8f4dbf4d49768ad78de570afbdf0950f98e3',
        ],
        20 => [
            'nome'     => 'MEStoreDrop',
            'host'     => '127.0.0.1',
            'port'     => 3306,
            'database' => 'mestoredropapp_production',
            'username' => 'mestoredropapp',
            'password' => '2c0b0ae12fb223a14b55cfd99992410c2d1c218b7dbed851b5c338cd1006db27',
        ],
        21 => [
            'nome'     => 'DropKSR',
            'host'     => '127.0.0.1',
            'port'     => 3306,
            'database' => 'dropksrapp_production',
            'username' => 'dropksrapp',
            'password' => 'DropKSR2026db!',
        ],
    ];

    private function supabaseServiceKey(): string
    {
        $sk = config('services.supabase_hubai.service_role', '');
        return $sk ?: config('services.supabase_hubai.anon_key', '');
    }

    private function supabaseUrl(): string
    {
        return rtrim(config('services.supabase_hubai.url', 'https://omvstizxjosygkcolzzl.supabase.co'), '/');
    }

    /**
     * SEL-430 — Mapa empresa_id -> empresa_nome (campo is_blocked no Supabase).
     * Necessario para resolver qual cache key Redis do EnforceWlBillingGate invalidar.
     */
    private const EMPRESA_NOME_MAP = [
        22 => 'Fornecefy',
        24 => 'MultDrop',
        17 => 'JTDrop',
        20 => 'MEStoreDrop',
        21 => 'DropKsr',
    ];

    /**
     * SEL-430 — Invalida o cache Redis do EnforceWlBillingGate em api.hubai.io.
     * Chamado SEMPRE que is_blocked mudar para false (desbloquear ou marcar pago).
     * Fire-and-forget: nao derruba a resposta se falhar (apenas loga warning).
     */
    private function flushBillingCache(int $empresaId): void
    {
        $empresaNome = self::EMPRESA_NOME_MAP[$empresaId] ?? null;
        if (!$empresaNome) {
            Log::warning('[SEL-430] flushBillingCache: empresa_id desconhecido', ['empresa_id' => $empresaId]);
            return;
        }

        try {
            $hubUrl  = config('federation.hub_url', 'https://api.hubai.io');
            $internalKey = config('app.internal_bridge_key', '');

            Http::timeout(3)->withHeaders([
                'X-Internal-Key' => $internalKey,
                'Content-Type'   => 'application/json',
            ])->post(rtrim($hubUrl, '/') . '/api/internal/wl-billing/flush-cache', [
                'empresa_nome' => $empresaNome,
            ]);

            Log::info('[SEL-430] billing cache flush enviado', [
                'empresa_id'   => $empresaId,
                'empresa_nome' => $empresaNome,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[SEL-430] flushBillingCache falhou (nao critico)', [
                'empresa_id' => $empresaId,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    private function sbHeaders(?string $preferReturn = null): array
    {
        $k = $this->supabaseServiceKey();
        $h = [
            'apikey'        => $k,
            'Authorization' => 'Bearer ' . $k,
            'Content-Type'  => 'application/json',
        ];
        if ($preferReturn) {
            $h['Prefer'] = $preferReturn;
        }
        return $h;
    }

    // GET /api/v1/admin/wl/live-counts
    public function liveCounts(Request $request)
    {
        $results = [];
        $errors  = [];

        foreach (self::WL_DB_MAP as $empresaId => $dbCfg) {
            try {
                $connName = 'wl_live_' . $empresaId;
                config([
                    "database.connections.$connName" => [
                        'driver'    => 'mysql',
                        'host'      => $dbCfg['host'],
                        'port'      => $dbCfg['port'],
                        'database'  => $dbCfg['database'],
                        'username'  => $dbCfg['username'],
                        'password'  => $dbCfg['password'],
                        'charset'   => 'utf8mb4',
                        'collation' => 'utf8mb4_unicode_ci',
                        'options'   => [
                            \PDO::ATTR_TIMEOUT => 5,
                            \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
                        ],
                    ],
                ]);

                $row = DB::connection($connName)
                    ->table('clients')
                    ->selectRaw('COUNT(*) as total, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as ativos')
                    ->first();

                DB::purge($connName);

                $results[] = [
                    'empresa_id'   => $empresaId,
                    'nome'         => $dbCfg['nome'],
                    'total_users'  => (int)($row->total ?? 0),
                    'active_users' => (int)($row->ativos ?? 0),
                    'source'       => 'live_db',
                    'fetched_at'   => now()->toISOString(),
                ];
            } catch (\Throwable $e) {
                Log::warning("[AdminWl] liveCounts empresa=$empresaId: " . $e->getMessage());
                $results[] = [
                    'empresa_id'   => $empresaId,
                    'nome'         => $dbCfg['nome'],
                    'total_users'  => null,
                    'active_users' => null,
                    'source'       => 'error',
                ];
                $errors[] = "empresa $empresaId: " . $e->getMessage();
            }
        }

        return response()->json([
            'success' => true,
            'data'    => $results,
            'errors'  => $errors,
        ]);
    }

    // PATCH /api/v1/admin/wl/{empresa_id}/config
    public function updateConfig(Request $request, int $empresaId)
    {
        $data = $request->validate([
            'preco_por_usuario' => 'sometimes|numeric|min:0',
            'preco_por_pedido'  => 'sometimes|numeric|min:0',
            'dia_cobranca'      => 'sometimes|integer|min:1|max:28',
            'contato_cobranca'  => 'sometimes|nullable|string|max:255',
            'notas_internas'    => 'sometimes|nullable|string',
            'hidden'            => 'sometimes|boolean',
        ]);

        if (empty($data)) {
            return response()->json(['error' => 'Nenhum campo a atualizar'], 422);
        }

        $serviceKey    = config('services.supabase_hubai.service_role', '');
        $hasServiceKey = !empty($serviceKey);

        $sbUpdated = false;
        $sbError   = null;

        try {
            $key  = $hasServiceKey ? $serviceKey : config('services.supabase_hubai.anon_key', '');
            $resp = Http::withHeaders([
                'apikey'        => $key,
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
                'Prefer'        => 'return=representation',
            ])->patch(
                $this->supabaseUrl() . '/rest/v1/whitelabel_billing_config?empresa_id=eq.' . $empresaId,
                $data
            );

            if ($resp->successful() && !empty($resp->json())) {
                $sbUpdated = true;
            } elseif (!$hasServiceKey) {
                $sbError = 'SUPABASE_HUBAI_SERVICE_ROLE_KEY nao configurada no .env de api.seller.global â€” update requer service_role key.';
            } else {
                $sbError = 'Supabase update falhou: ' . $resp->body();
            }
        } catch (\Throwable $e) {
            $sbError = $e->getMessage();
            Log::error("[AdminWl] updateConfig empresa=$empresaId: " . $e->getMessage());
        }

        // Leitura da config atual para retornar ao frontend
        $configAtual = null;
        try {
            $k          = $this->supabaseServiceKey();
            $configResp = Http::withHeaders(['apikey' => $k, 'Authorization' => 'Bearer ' . $k])
                ->get($this->supabaseUrl() . '/rest/v1/whitelabel_billing_config', [
                    'empresa_id' => 'eq.' . $empresaId,
                    'select'     => '*',
                ]);
            $configAtual = $configResp->json()[0] ?? null;
        } catch (\Throwable $e) {
            // ignore leitura
        }

        $httpStatus = $sbUpdated ? 200 : ($hasServiceKey ? 500 : 503);
        return response()->json([
            'success'          => $sbUpdated,
            'supabase_updated' => $sbUpdated,
            'has_service_key'  => $hasServiceKey,
            'warning'          => $sbUpdated ? null : $sbError,
            'config'           => $configAtual,
        ], $httpStatus);
    }

    // PATCH /api/v1/admin/wl/{empresa_id}/block
    public function toggleBlock(Request $request, int $empresaId)
    {
        $data      = $request->validate([
            'is_blocked'     => 'required|boolean',
            'blocked_reason' => 'sometimes|nullable|string|max:500',
        ]);
        $isBlocked = (bool)$data['is_blocked'];
        $actor     = $request->user()?->email ?? 'admin';

        $payload = $isBlocked
            ? [
                'is_blocked'     => true,
                'blocked_at'     => now()->toISOString(),
                'blocked_by'     => $actor,
                'blocked_reason' => $data['blocked_reason'] ?? 'Cobranca pendente',
            ]
            : [
                'is_blocked'     => false,
                'blocked_at'     => null,
                'blocked_by'     => null,
                'blocked_reason' => null,
            ];

        $serviceKey    = config('services.supabase_hubai.service_role', '');
        $hasServiceKey = !empty($serviceKey);
        $sbUpdated     = false;
        $sbError       = null;

        try {
            $key  = $hasServiceKey ? $serviceKey : config('services.supabase_hubai.anon_key', '');
            $resp = Http::withHeaders([
                'apikey'        => $key,
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
                'Prefer'        => 'return=representation',
            ])->patch(
                $this->supabaseUrl() . '/rest/v1/whitelabel_billing_config?empresa_id=eq.' . $empresaId,
                $payload
            );

            if ($resp->successful() && !empty($resp->json())) {
                $sbUpdated = true;
                // Audit log
                try {
                    Http::withHeaders($this->sbHeaders('return=minimal'))->post(
                        $this->supabaseUrl() . '/rest/v1/whitelabel_audit_log',
                        [
                            'empresa_id'  => $empresaId,
                            'entity_type' => 'billing_config',
                            'entity_id'   => (string)$empresaId,
                            'action'      => $isBlocked ? 'block_wl' : 'unblock_wl',
                            'actor'       => $actor,
                            'notes'       => $isBlocked
                                ? ('WL bloqueada: ' . ($data['blocked_reason'] ?? '-'))
                                : 'WL desbloqueada',
                        ]
                    );
                } catch (\Throwable $e) { /* audit nao critico */ }
            } elseif (!$hasServiceKey) {
                $sbError = 'SUPABASE_HUBAI_SERVICE_ROLE_KEY nao configurada â€” bloqueio requer service_role key.';
            } else {
                $sbError = 'Supabase update falhou: ' . $resp->body();
            }
        } catch (\Throwable $e) {
            $sbError = $e->getMessage();
        }

        // SEL-430: flush instantaneo do cache Redis em api.hubai.io (< 1s)
        // Executado quando desbloqueando (is_blocked=false) E o Supabase atualizou.
        if ($sbUpdated && !$isBlocked) {
            $this->flushBillingCache($empresaId);
        }

        return response()->json([
            'success'          => $sbUpdated,
            'supabase_updated' => $sbUpdated,
            'has_service_key'  => $hasServiceKey,
            'warning'          => $sbUpdated ? null : $sbError,
            'is_blocked'       => $isBlocked,
        ], $sbUpdated ? 200 : 503);
    }

    // â”€â”€ Mapa de fornecedores JT Drop por WL â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    private const JT_SUPPLIER_PER_WL = [
        22 => 1,  // Fornecefy: JT Drop = supplier_id 1
    ];

    // GET /api/v1/admin/wl/{empresa_id}/pedidos-auditoria
    // Retorna auditoria cross-fornecedor dos pedidos contabilizados na cobranca.
    // Cruzamento: WL orders com JT Drop (jtdrop_prod) via hubai_order_id.
    // GET /api/v1/admin/wl/{empresa_id}/pedidos-auditoria
    // Auditoria cross-fornecedor usando banco legado (fonte de verdade da cobranca).
    // Consulta tudoonline_production.pedidos para a empresa_id no ciclo e
    // cruza com id_loja=128 (JT Drop) via status_marketplace/impressao/rastreio.
    // NOV-JT-AUDIT reescrito 03/08/2026 apos descoberta que billing usa legado.
    public function pedidosAuditoria(Request $request, int $empresaId)
    {
        // Resolucao do ciclo
        $cycle      = $request->query('cycle', date('Y-m'));
        $parts      = explode('-', $cycle);
        $cycleYear  = (int) $parts[0];
        $cycleMon   = (int) ($parts[1] ?? date('m'));
        $cycleStart = sprintf('%04d-%02d-01', $cycleYear, $cycleMon);
        $nextMon    = $cycleMon === 12 ? 1 : $cycleMon + 1;
        $nextYear   = $cycleMon === 12 ? $cycleYear + 1 : $cycleYear;
        $cycleEnd   = sprintf('%04d-%02d-01', $nextYear, $nextMon);

        // Mapa empresa_id -> nome WL (empresas legado)
        $WL_NAMES = [
            22 => 'Fornecefy',
            24 => 'MultDrop',
            17 => 'JTDrop',
            20 => 'MEStoreDrop',
            21 => 'DropKSR',
            15 => 'PlugLar',
            5  => 'Envio Nacional',
        ];

        if (!isset($WL_NAMES[$empresaId])) {
            return response()->json(['error' => 'WL nao encontrada: ' . $empresaId], 404);
        }

        // loja 128 = "Jt drop" no legado (confirmado query 03/08/2026)
        $JT_LOJA_ID = 128;
        // WLs que tem JT Drop como fornecedor registrado no legado
        $WL_COM_JT  = [22, 17];
        $hasJtCross = in_array($empresaId, $WL_COM_JT, true);
        $wlName     = $WL_NAMES[$empresaId];

        // Status marketplace que indicam envio/entrega confirmados
        $enviados   = ['shipped', 'delivered', 'in_transit', 'delivering'];
        // Status marketplace que indicam cancelamento
        $cancelados = ['cancelled', 'canceled', 'cancellation_in_process'];

        $buckets = [
            'enviado_JT'           => 0,
            'impresso_nao_enviado' => 0,
            'pendente_JT'          => 0,
            'cancelado_JT'         => 0,
            'nao_chegou_JT'        => 0,
            'sem_cruzamento'       => 0,
        ];
        $pedidos = [];

        try {
            // Consulta legado: todos pedidos da empresa no ciclo via integracao
            $rows = DB::connection('legacy')
                ->table('pedidos as p')
                ->join('integracao as i', 'i.id', '=', 'p.id_integracao')
                ->select([
                    'p.id',
                    'p.nr_canal as order_number',
                    'p.id_loja',
                    'p.status_marketplace',
                    'p.data_impresso_etiqueta',
                    'p.rastreio',
                    'p.valor_total as total',
                    'p.data_add as created_at',
                ])
                ->where('i.id_empresa', $empresaId)
                ->where('p.data_add', '>=', $cycleStart)
                ->where('p.data_add', '<', $cycleEnd)
                ->where('p.status', '!=', 2)  // status=2 = registro deletado/arquivado no legado
                ->orderBy('p.data_add', 'desc')
                ->limit(2000)
                ->get();
        } catch (\Throwable $e) {
            Log::error('[AdminWl] pedidosAuditoria legado: ' . $e->getMessage());
            return response()->json([
                'error' => 'Erro ao consultar legado: ' . $e->getMessage(),
            ], 500);
        }

        foreach ($rows as $o) {
            $isJtLoja    = ((int) $o->id_loja === $JT_LOJA_ID);
            $statusMkp   = $o->status_marketplace ?? '';
            $statusLow   = strtolower($statusMkp);
            $impresso    = !empty($o->data_impresso_etiqueta);
            $temRastreio = !empty($o->rastreio);

            if (!$hasJtCross || !$isJtLoja) {
                // Fornecedor proprio (nao JT Drop) ou WL sem cruzamento JT
                $bucket   = 'sem_cruzamento';
                $statusJt = null;
                $decisao  = 'OK - fornecedor proprio (loja ' . $o->id_loja . ')';
            } elseif (in_array($statusLow, $cancelados, true)) {
                // Cancelado no marketplace (JT nao enviou)
                $bucket   = 'cancelado_JT';
                $statusJt = 'cancelado';
                $decisao  = 'POSSIVEL INDEVIDO - marketplace cancelou: ' . $statusMkp;
            } elseif (in_array($statusLow, $enviados, true) || $temRastreio) {
                // Enviado ou entregue com rastreio
                $bucket   = 'enviado_JT';
                $statusJt = 'enviado';
                $decisao  = 'OK - JT Drop enviou (status: ' . $statusMkp . ($temRastreio ? ' + rastreio' : '') . ')';
            } elseif ($impresso) {
                // Etiqueta gerada mas nao postado ainda
                $bucket   = 'impresso_nao_enviado';
                $statusJt = 'impresso';
                $decisao  = 'ATENCAO - etiqueta emitida mas nao postado ainda';
            } elseif ($statusLow === 'ready_to_ship') {
                // Aguardando postagem
                $bucket   = 'pendente_JT';
                $statusJt = 'ready_to_ship';
                $decisao  = 'VERIFICAR - aguardando postagem no JT Drop';
            } else {
                $bucket   = 'pendente_JT';
                $statusJt = $statusMkp ?: 'sem_status';
                $decisao  = 'VERIFICAR - status desconhecido: ' . ($statusMkp ?: '(vazio)');
            }

            $buckets[$bucket]++;
            $pedidos[] = [
                'wl_order_id'    => $o->id,
                'order_number'   => $o->order_number,
                'hubai_order_id' => null,
                'status_wl'      => $statusMkp,
                'has_shipped_at' => in_array($statusLow, ['shipped', 'delivered'], true),
                'has_printed'    => $impresso,
                'status_jt'      => $isJtLoja ? $statusJt : null,
                'jt_order_id'    => $isJtLoja ? $o->id : null,
                'valor'          => (float) ($o->total ?? 0),
                'created_at'     => $o->created_at,
                'bucket'         => $bucket,
                'decisao'        => $decisao,
                'id_loja'        => (int) $o->id_loja,
            ];
        }

        return response()->json([
            'wl'            => strtolower($wlName),
            'empresa_id'    => $empresaId,
            'cycle'         => $cycle,
            'cycle_start'   => $cycleStart,
            'cycle_end'     => $cycleEnd,
            'total_cobrado' => count($pedidos),
            'has_jt_cross'  => $hasJtCross,
            'buckets'       => $buckets,
            'pedidos'       => $pedidos,
        ]);
    }

    // ── pedidosAuditoriaV2 ──────────────────────────────────────────────────────
    // GET /api/v1/admin/wl/{empresa_id}/pedidos-auditoria-v2?cycle=YYYY-MM
    //
    // Compara REGRA ANTIGA (shipped OR shipped_at) vs REGRA NOVA (OR 5 camadas)
    // usando BillingRules::isBillable(). ZERO writes. APENAS SELECT.
    //
    // Retorna JSON com:
    //  - regra_antiga / regra_nova totals
    //  - diff (nova - antiga)
    //  - buckets_regra_nova detalhados
    //  - top_suspeitos (antiga cobra, nova nao)
    //  - top_novos_capturados (nova cobra, antiga nao)
    public function pedidosAuditoriaV2(Request $request, int $empresaId)
    {
        // Validar empresa
        if (!isset(self::WL_DB_MAP[$empresaId])) {
            return response()->json(['error' => 'WL nao encontrada: ' . $empresaId], 404);
        }

        $dbCfg = self::WL_DB_MAP[$empresaId];
        $wlName = $dbCfg['nome'];

        // Ciclo
        $cycle     = $request->query('cycle', date('Y-m'));
        $parts     = explode('-', $cycle);
        $cycleYear = (int)$parts[0];
        $cycleMon  = (int)($parts[1] ?? date('m'));
        $cycleStart = sprintf('%04d-%02d-01', $cycleYear, $cycleMon);
        $nextMon    = $cycleMon === 12 ? 1 : $cycleMon + 1;
        $nextYear   = $cycleMon === 12 ? $cycleYear + 1 : $cycleYear;
        $cycleEnd   = sprintf('%04d-%02d-01', $nextYear, $nextMon);

        // Conectar ao banco da WL
        $connName = 'wl_audit_v2_' . $empresaId;
        try {
            config([
                "database.connections.$connName" => [
                    'driver'    => 'mysql',
                    'host'      => $dbCfg['host'],
                    'port'      => $dbCfg['port'],
                    'database'  => $dbCfg['database'],
                    'username'  => $dbCfg['username'],
                    'password'  => $dbCfg['password'],
                    'charset'   => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                    'options'   => [
                        \PDO::ATTR_TIMEOUT => 10,
                        \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
                    ],
                ],
            ]);

            $orders = DB::connection($connName)
                ->table('orders')
                ->select([
                    'id', 'order_number', 'status', 'total',
                    'label_printed_at', 'tracking_number', 'wallet_paid_at',
                    'shipped_at', 'cancelled_at', 'created_at',
                ])
                ->where('created_at', '>=', $cycleStart)
                ->where('created_at', '<', $cycleEnd)
                ->orderBy('created_at', 'desc')
                ->get();

            DB::purge($connName);
        } catch (\Throwable $e) {
            DB::purge($connName);
            Log::error("[AdminWl] pedidosAuditoriaV2 empresa=$empresaId: " . $e->getMessage());
            return response()->json(['error' => 'Erro DB: ' . $e->getMessage()], 500);
        }

        // Contadores
        $totalRegra_antiga = 0;
        $totalRegra_nova   = 0;
        $totalCancelados   = 0;
        $totalSemProva     = 0;

        $buckets = [
            'cancelados'                    => 0,
            'multi_camada_forte'            => 0,
            'so_etiqueta'                   => 0,
            'so_pagamento_fornec'           => 0,
            'so_tracking'                   => 0,
            'novo_capturado'                => 0,
            'suspeito_antiga_cobra_nova_nao' => 0,
            'sem_prova_nenhuma'             => 0,
        ];

        $topSuspeitos  = [];   // antiga cobra, nova não
        $topCapturados = [];   // nova cobra, antiga não

        foreach ($orders as $o) {
            $orderArr = (array)$o;
            $oldBillable = \App\Support\BillingRules::isBillableOld($orderArr);
            $result      = \App\Support\BillingRules::isBillable($orderArr);
            $newBillable = $result['billable'];
            $bucket      = \App\Support\BillingRules::bucket($result, $oldBillable);

            $buckets[$bucket]++;

            if ($oldBillable) $totalRegra_antiga++;
            if ($newBillable) $totalRegra_nova++;
            if ($bucket === 'cancelados') $totalCancelados++;
            if ($bucket === 'sem_prova_nenhuma') $totalSemProva++;

            // Suspeitos: antiga cobra mas nova não (pedidos que serão REMOVIDOS com nova regra)
            if ($oldBillable && !$newBillable && count($topSuspeitos) < 10) {
                $topSuspeitos[] = [
                    'order_number' => $o->order_number,
                    'status_wl'    => $o->status,
                    'reasons'      => $result['reasons'],
                    'blocked_by'   => $result['blocked_by'],
                    'valor'        => (float)$o->total,
                ];
            }

            // Novos capturados: nova cobra mas antiga não (pedidos que serão ADICIONADOS)
            if ($newBillable && !$oldBillable && count($topCapturados) < 10) {
                $topCapturados[] = [
                    'order_number' => $o->order_number,
                    'status_wl'    => $o->status,
                    'reasons'      => $result['reasons'],
                    'valor'        => (float)$o->total,
                ];
            }
        }

        $diff = $totalRegra_nova - $totalRegra_antiga;

        return response()->json([
            'wl'           => strtolower($wlName),
            'empresa_id'   => $empresaId,
            'cycle'        => $cycle,
            'cycle_start'  => $cycleStart,
            'cycle_end'    => $cycleEnd,
            'total_pedidos_ciclo' => count($orders),
            'regra_antiga' => [
                'total'    => $totalRegra_antiga,
                'criterio' => 'status=shipped OR shipped_at IS NOT NULL, nao cancelled',
            ],
            'regra_nova' => [
                'total'    => $totalRegra_nova,
                'criterio' => 'OR de 5 camadas: etiqueta | tracking | wallet_paid | shipped_at | status_shipped',
            ],
            'diff'                => $diff,
            'diff_sinal'          => $diff > 0 ? '+' . $diff : (string)$diff,
            'buckets_regra_nova'  => $buckets,
            'aviso'               => 'REGRA NOVA APENAS COMPARATIVA — nao aplicada na cobranca real. Aprovar com Ruan.',
            'top_suspeitos_antiga_cobra_nova_nao' => $topSuspeitos,
            'top_novos_capturados_regra_nova'     => $topCapturados,
            'gerado_em' => now()->toISOString(),
        ]);
    }


    // ── SEL-markpaid: Marcar ciclo como pago manualmente ─────────────────────────
    // PATCH /api/v1/admin/wl/cycles/{cycle_id}/mark-paid
    // Body: { amount_paid, payment_method, paid_at, note }
    public function markCyclePaid(Request $request, string $cycleId)
    {
        $data = $request->validate([
            'amount_paid'    => 'required|numeric|min:0',
            'payment_method' => 'required|string|in:PIX,Transferencia,Dinheiro,Outro',
            'paid_at'        => 'required|date',
            'note'           => 'sometimes|nullable|string|max:500',
        ]);

        $actor  = $request->user()?->email ?? 'admin@seller.global';
        $note   = $data['note'] ?? null;
        $reason = 'Pagamento manual: ' . $data['payment_method'] . ' em ' . date('d/m/Y', strtotime($data['paid_at'])) . ' (admin: ' . $actor . ')' . ($note ? ' — ' . $note : '');

        $payload = [
            'status'             => 'paid',
            'amount_paid'        => (float)$data['amount_paid'],
            'data_pagamento'     => date('Y-m-d', strtotime($data['paid_at'])),
            'manual_paid_by'     => $actor,
            'manual_paid_reason' => $reason,
            'notas'              => $reason,
        ];

        try {
            $key  = $this->supabaseServiceKey();
            $resp = Http::withHeaders([
                'apikey'        => $key,
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
                'Prefer'        => 'return=representation',
            ])->patch(
                $this->supabaseUrl() . '/rest/v1/whitelabel_billing_cycles?id=eq.' . $cycleId,
                $payload
            );

            if ($resp->successful() && !empty($resp->json())) {
                try {
                    Http::withHeaders($this->sbHeaders('return=minimal'))->post(
                        $this->supabaseUrl() . '/rest/v1/whitelabel_audit_log',
                        [
                            'empresa_id'  => $resp->json()[0]['empresa_id'] ?? null,
                            'entity_type' => 'billing_cycle',
                            'entity_id'   => $cycleId,
                            'action'      => 'mark_cycle_paid',
                            'actor'       => $actor,
                            'notes'       => $reason,
                        ]
                    );
                } catch (\Throwable $e) { /* audit nao critico */ }

                return response()->json([
                    'success' => true,
                    'cycle'   => $resp->json()[0] ?? null,
                ]);
            }

            return response()->json([
                'success' => false,
                'error'   => 'Supabase update falhou: ' . $resp->body(),
            ], 500);
        } catch (\Throwable $e) {
            Log::error('[AdminWl] markCyclePaid cycle=' . $cycleId . ': ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // PATCH /api/v1/admin/wl/cycles/{cycle_id}/unmark-paid
    // Reverte ciclo de volta para "closed".
    public function unmarkCyclePaid(Request $request, string $cycleId)
    {
        $actor   = $request->user()?->email ?? 'admin@seller.global';
        $payload = [
            'status'             => 'closed',
            'amount_paid'        => 0,
            'data_pagamento'     => null,
            'manual_paid_by'     => null,
            'manual_paid_reason' => null,
            'notas'              => 'Pagamento desfeito por ' . $actor . ' em ' . now()->format('d/m/Y H:i'),
        ];

        try {
            $key  = $this->supabaseServiceKey();
            $resp = Http::withHeaders([
                'apikey'        => $key,
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
                'Prefer'        => 'return=representation',
            ])->patch(
                $this->supabaseUrl() . '/rest/v1/whitelabel_billing_cycles?id=eq.' . $cycleId,
                $payload
            );

            if ($resp->successful()) {
                return response()->json(['success' => true]);
            }

            return response()->json([
                'success' => false,
                'error'   => 'Supabase update falhou: ' . $resp->body(),
            ], 500);
        } catch (\Throwable $e) {
            Log::error('[AdminWl] unmarkCyclePaid cycle=' . $cycleId . ': ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ── SEL-wlpaid: Marcar WL como paga (ciclo mais recente) + desbloquear ──────
    // PATCH /api/v1/admin/wl/{empresa_id}/mark-paid
    // Body: { payment_method?, amount_paid?, paid_at?, note? }
    //
    // Ruan pediu um jeito de marcar "paguei" numa WL bloqueada (ex: Fornecefy
    // ja pagou e nao tinha como registrar). Faz DUAS coisas numa chamada so:
    //   1) marca o ciclo de cobranca mais recente da empresa como status=paid
    //      (mesmo efeito de markCyclePaid, mas resolvido pelo empresa_id em vez
    //      de cycle_id — a UI so conhece a WL, nao o id do ciclo)
    //   2) desbloqueia a WL em whitelabel_billing_config (is_blocked=false)
    //
    // IMPORTANTE (achado na auditoria SEL-wlblock-audit): is_blocked hoje so
    // controla a UI/billing deste painel — nenhum backend de WL (fornecefy,
    // multdrop, mestoredrop, jtdrop, dropksr) le esse campo, entao bloquear
    // aqui NAO impede login do dono nem dos clientes da WL. Esse endpoint
    // desbloqueia o MESMO flag cosmetico que o /block liga — nao ha, ainda,
    // enforcement real de login para desfazer. Ver nota na auditoria.
    public function markPaidAndUnblock(Request $request, int $empresaId)
    {
        $data = $request->validate([
            'payment_method' => 'sometimes|nullable|string|in:PIX,Transferencia,Dinheiro,Outro',
            'amount_paid'    => 'sometimes|nullable|numeric|min:0',
            'paid_at'        => 'sometimes|nullable|date',
            'note'           => 'sometimes|nullable|string|max:500',
        ]);

        $actor         = $request->user()?->email ?? 'admin@seller.global';
        $paymentMethod = $data['payment_method'] ?? 'Outro';
        $paidAtDate    = !empty($data['paid_at']) ? date('Y-m-d', strtotime($data['paid_at'])) : now()->format('Y-m-d');
        $note          = $data['note'] ?? null;
        $key           = $this->supabaseServiceKey();

        // 1) Busca o ciclo mais recente da empresa (independente do status)
        $cycle = null;
        try {
            $resp = Http::withHeaders(['apikey' => $key, 'Authorization' => 'Bearer ' . $key])
                ->get($this->supabaseUrl() . '/rest/v1/whitelabel_billing_cycles', [
                    'empresa_id' => 'eq.' . $empresaId,
                    'order'      => 'cycle_start.desc',
                    'limit'      => 1,
                    'select'     => '*',
                ]);
            $cycle = $resp->successful() ? ($resp->json()[0] ?? null) : null;
        } catch (\Throwable $e) {
            Log::error("[AdminWl] markPaidAndUnblock busca ciclo empresa=$empresaId: " . $e->getMessage());
        }

        $cycleUpdated = false;
        $cycleResult  = null;
        if ($cycle) {
            $amountPaid   = $data['amount_paid'] ?? ($cycle['amount_due'] ?? 0);
            $reason       = 'Pagamento manual: ' . $paymentMethod . ' em ' . date('d/m/Y', strtotime($paidAtDate)) . ' (admin: ' . $actor . ')' . ($note ? ' — ' . $note : '');
            $cyclePayload = [
                'status'             => 'paid',
                'amount_paid'        => (float) $amountPaid,
                'data_pagamento'     => $paidAtDate,
                'manual_paid_by'     => $actor,
                'manual_paid_reason' => $reason,
                'notas'              => $reason,
            ];
            try {
                $cResp = Http::withHeaders($this->sbHeaders('return=representation'))->patch(
                    $this->supabaseUrl() . '/rest/v1/whitelabel_billing_cycles?id=eq.' . $cycle['id'],
                    $cyclePayload
                );
                if ($cResp->successful() && !empty($cResp->json())) {
                    $cycleUpdated = true;
                    $cycleResult  = $cResp->json()[0];
                }
            } catch (\Throwable $e) {
                Log::error("[AdminWl] markPaidAndUnblock update ciclo empresa=$empresaId: " . $e->getMessage());
            }
        }

        // 2) Desbloqueia a WL (mesmo campo do /block)
        $configUpdated = false;
        $configResult  = null;
        try {
            $cfgResp = Http::withHeaders($this->sbHeaders('return=representation'))->patch(
                $this->supabaseUrl() . '/rest/v1/whitelabel_billing_config?empresa_id=eq.' . $empresaId,
                [
                    'is_blocked'     => false,
                    'blocked_at'     => null,
                    'blocked_by'     => null,
                    'blocked_reason' => null,
                ]
            );
            if ($cfgResp->successful() && !empty($cfgResp->json())) {
                $configUpdated = true;
                $configResult  = $cfgResp->json()[0];
            }
        } catch (\Throwable $e) {
            Log::error("[AdminWl] markPaidAndUnblock unblock empresa=$empresaId: " . $e->getMessage());
        }

        // 3) Audit log (nao critico — nao derruba a resposta se falhar)
        try {
            Http::withHeaders($this->sbHeaders('return=minimal'))->post(
                $this->supabaseUrl() . '/rest/v1/whitelabel_audit_log',
                [
                    'empresa_id'  => $empresaId,
                    'entity_type' => 'billing_config',
                    'entity_id'   => (string) $empresaId,
                    'action'      => 'mark_paid_and_unblock',
                    'actor'       => $actor,
                    'notes'       => 'Marcado como pago (' . $paymentMethod . ')' . ($cycleUpdated ? ', ciclo ' . $cycle['id'] . ' quitado' : ', sem ciclo encontrado') . ' + WL desbloqueada' . ($note ? ' — ' . $note : ''),
                ]
            );
        } catch (\Throwable $e) { /* audit nao critico */ }

        // SEL-430: flush instantaneo do cache Redis em api.hubai.io quando WL foi desbloqueada.
        if ($configUpdated) {
            $this->flushBillingCache($empresaId);
        }

        if (!$cycle && !$configUpdated) {
            return response()->json([
                'success' => false,
                'error'   => 'Nenhum ciclo de cobranca encontrado e desbloqueio falhou para empresa ' . $empresaId,
            ], 404);
        }

        return response()->json([
            'success'        => $configUpdated,
            'cycle_updated'  => $cycleUpdated,
            'config_updated' => $configUpdated,
            'cycle'          => $cycleResult,
            'config'         => $configResult,
            'warning'        => (!$cycle)
                ? 'Nenhum ciclo de cobranca encontrado para essa WL — apenas o desbloqueio foi aplicado.'
                : (!$cycleUpdated ? 'Falha ao marcar ciclo como pago, mas a WL foi desbloqueada.' : null),
        ], $configUpdated ? 200 : 500);
    }

}
