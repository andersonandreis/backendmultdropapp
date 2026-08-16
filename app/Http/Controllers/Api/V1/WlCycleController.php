<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NOV-217 — Proxy Laravel do close-whitelabel-cycle (Supabase edge function).
 *
 * POST /api/v1/wl/cycle/close
 * POST /api/v1/wl/sync
 *
 * Protegido por auth:sanctum + role admin/super_admin.
 * Repassa a requisicao para o Supabase HubAI via service_role key.
 *
 * close-cycle: equivalente completo da edge function close-whitelabel-cycle.
 * sync:        equivalente da edge function sync-whitelabel-data.
 */
class WlCycleController extends Controller
{
    private string $supabaseUrl;
    private string $supabaseServiceKey;
    private string $supabaseAnonKey;
    private string $monitorApiUrl;
    private string $monitorApiKey;

    public function __construct()
    {
        $this->supabaseUrl        = config('services.supabase_hubai.url', 'https://omvstizxjosygkcolzzl.supabase.co');
        $this->supabaseServiceKey = config('services.supabase_hubai.service_role', '');
        $this->supabaseAnonKey    = config('services.supabase_hubai.anon_key', '');
        $this->monitorApiUrl      = env('MONITOR_API_URL', '');
        $this->monitorApiKey      = env('MONITOR_API_KEY', '');
    }

    private function key(): string
    {
        return $this->supabaseServiceKey ?: $this->supabaseAnonKey;
    }

    private function sbHeaders(): array
    {
        $k = $this->key();
        return [
            'apikey'        => $k,
            'Authorization' => 'Bearer ' . $k,
            'Content-Type'  => 'application/json',
        ];
    }

    private function sb(string $path, string $method = 'GET', array $params = [], array $body = [])
    {
        $url  = rtrim($this->supabaseUrl, '/') . '/rest/v1/' . ltrim($path, '/');
        $req  = Http::withHeaders($this->sbHeaders());
        if ($method === 'GET') {
            return $req->get($url, $params)->json() ?? [];
        }
        return $req->withUrlParameters($params)->post($url, $body)->json() ?? [];
    }

    private function sbGet(string $table, array $params): array
    {
        $url = rtrim($this->supabaseUrl, '/') . '/rest/v1/' . $table;
        return Http::withHeaders($this->sbHeaders())->get($url, $params)->json() ?? [];
    }

    private function sbPost(string $table, array $body, array $extra = []): array
    {
        $url = rtrim($this->supabaseUrl, '/') . '/rest/v1/' . $table;
        return Http::withHeaders(array_merge($this->sbHeaders(), $extra))->post($url, $body)->json() ?? [];
    }

    private function sbPatch(string $table, array $filter, array $body): array
    {
        $url    = rtrim($this->supabaseUrl, '/') . '/rest/v1/' . $table;
        $params = [];
        foreach ($filter as $col => $val) {
            $params[$col] = 'eq.' . $val;
        }
        return Http::withHeaders($this->sbHeaders())->patch($url . '?' . http_build_query($params), $body)->json() ?? [];
    }

    // =========================================================================
    // POST /api/v1/wl/cycle/close
    // =========================================================================
    public function close(Request $request)
    {
        // Auth checada no middleware (role:admin,super_admin)
        $body       = $request->json()->all();
        $adminActor = $request->user()?->email ?? 'admin';

        $forceDate           = $body['force_date'] ?? null;
        $onlyEmpresa         = isset($body['empresa_id']) ? (int)$body['empresa_id'] : null;
        $recalcOnly          = (bool)($body['recalc_only'] ?? false);
        $forcePreviousBalance = isset($body['force_previous_balance']) ? (float)$body['force_previous_balance'] : null;

        [$cycleStart, $cycleEnd] = $this->resolveCycleRange($body, $forceDate);
        $billingMonth = substr($cycleEnd, 0, 7);

        Log::info("[WlCycle] close periodo $cycleStart -> $cycleEnd (mes $billingMonth)");

        // 1. Configs
        $cfgParams = [
            'hidden'     => 'eq.false',
            'empresa_id' => 'neq.1',
            'select'     => 'empresa_id,empresa_nome,preco_por_usuario,preco_por_pedido,dia_cobranca',
        ];
        if ($onlyEmpresa) {
            $cfgParams['empresa_id'] = 'eq.' . $onlyEmpresa;
        }
        $configs = $this->sbGet('whitelabel_billing_config', $cfgParams);
        if (empty($configs)) {
            return response()->json(['success' => true, 'message' => 'Nenhuma whitelabel', 'closed' => 0]);
        }

        // 2. Snapshots para active_users
        $snapshots = $this->sbGet('whitelabel_snapshots', [
            'date'       => 'gte.' . $cycleStart,
            'date.lte'   => $cycleEnd,
            'order'      => 'date.desc',
            'select'     => 'empresa_id,active_users,date',
        ]);
        // Workaround: PostgREST nao suporta dois filtros na mesma coluna sem range operator
        // Filtrar manualmente
        $filteredSnaps = array_filter($snapshots, function ($s) use ($cycleStart, $cycleEnd) {
            return ($s['date'] ?? '') >= $cycleStart && ($s['date'] ?? '') <= $cycleEnd;
        });
        $latestSnapByEmpresa = [];
        foreach ($filteredSnaps as $s) {
            $eid = (int)$s['empresa_id'];
            if (!isset($latestSnapByEmpresa[$eid])) {
                $latestSnapByEmpresa[$eid] = (int)($s['active_users'] ?? 0);
            }
        }

        $processed = [];
        $errors    = [];

        foreach ($configs as $cfg) {
            try {
                $empresaId   = (int)$cfg['empresa_id'];
                $activeUsers = $latestSnapByEmpresa[$empresaId] ?? 0;
                $billableUsers = $activeUsers;

                // 3. Busca pedidos via monitor legado
                [$ordersList, $ordersAggCount] = $this->fetchOrders($empresaId, $cycleStart, $cycleEnd);

                // Upsert ciclo
                $existing = $this->sbGet('whitelabel_billing_cycles', [
                    'empresa_id'  => 'eq.' . $empresaId,
                    'cycle_start' => 'eq.' . $cycleStart,
                    'cycle_end'   => 'eq.' . $cycleEnd,
                    'select'      => 'id,status,amount_paid,previous_balance,ajuste_manual,desconto',
                ]);
                $existing = $existing[0] ?? null;

                if ($existing && in_array($existing['status'] ?? '', ['invoiced', 'paid']) && !$recalcOnly) {
                    Log::info("[WlCycle] empresa $empresaId ja faturada, pulando");
                    continue;
                }

                // previous_balance
                $previousBalance = $forcePreviousBalance ?? ($existing ? (float)($existing['previous_balance'] ?? 0) : 0);
                if (!$previousBalance || $recalcOnly) {
                    $prevCycles = $this->sbGet('whitelabel_billing_cycles', [
                        'empresa_id'  => 'eq.' . $empresaId,
                        'cycle_end'   => 'lt.' . $cycleStart,
                        'status'      => 'in.(closed,invoiced)',
                        'order'       => 'cycle_end.desc',
                        'limit'       => '1',
                        'select'      => 'amount_due,amount_paid',
                    ]);
                    $pc = $prevCycles[0] ?? null;
                    if ($pc) {
                        $previousBalance = max(0, round(((float)($pc['amount_due'] ?? 0) - (float)($pc['amount_paid'] ?? 0)) * 100) / 100);
                    }
                }

                $vencimentoDate = (new \DateTime($cycleEnd . 'T12:00:00Z'))->modify('+5 days');
                $vencimentoStr  = $vencimentoDate->format('Y-m-d');

                $cycleId = $existing['id'] ?? null;
                if (!$cycleId) {
                    $ins = $this->sbPost('whitelabel_billing_cycles', [
                        'empresa_id'           => $empresaId,
                        'cycle_start'          => $cycleStart,
                        'cycle_end'            => $cycleEnd,
                        'status'               => 'closed',
                        'active_users_snapshot'=> $billableUsers,
                        'orders_snapshot'      => 0,
                        'previous_balance'     => $previousBalance,
                        'current_consumption'  => 0,
                        'amount_due'           => $previousBalance,
                        'vencimento'           => $vencimentoStr,
                        'criado_por'           => $adminActor,
                    ], ['Prefer' => 'return=representation']);
                    $cycleId = $ins[0]['id'] ?? null;
                }
                if (!$cycleId) throw new \Exception('Falha ao criar/obter ciclo para empresa ' . $empresaId);

                // 4. Insere pedidos snapshot (idempotente)
                $orderRowsInserted = 0;
                if (!empty($ordersList)) {
                    $rows = [];
                    foreach ($ordersList as $o) {
                        $extId = $o['id'] ?? $o['pedido_id'] ?? $o['order_id'] ?? $o['external_id'] ?? null;
                        if ($extId === null) continue;
                        $rows[] = [
                            'cycle_id'         => $cycleId,
                            'empresa_id'       => $empresaId,
                            'external_order_id'=> (string)$extId,
                            'order_date'       => $o['data'] ?? $o['data_pedido'] ?? $o['created_at'] ?? null,
                            'customer_email'   => $o['cliente_email'] ?? $o['customer_email'] ?? $o['email'] ?? null,
                            'customer_name'    => $o['cliente_nome'] ?? $o['customer_name'] ?? $o['nome'] ?? null,
                            'customer_doc'     => $o['cpf'] ?? $o['documento'] ?? null,
                            'amount'           => (float)($o['valor'] ?? $o['amount'] ?? $o['total'] ?? 0),
                            'status'           => $o['status'] ?? null,
                            'billed_amount'    => (float)$cfg['preco_por_pedido'],
                            'raw_payload'      => json_encode($o),
                        ];
                    }
                    if (!empty($rows)) {
                        $this->sbPost('whitelabel_orders_snapshot', $rows, [
                            'Prefer' => 'resolution=ignore-duplicates,return=minimal',
                        ]);
                        $orderRowsInserted = count($rows);
                    }
                }

                // 5. Recount pedidos
                $countResp = Http::withHeaders($this->sbHeaders())
                    ->get(rtrim($this->supabaseUrl, '/') . '/rest/v1/whitelabel_orders_snapshot', [
                        'cycle_id' => 'eq.' . $cycleId,
                        'select'   => 'id',
                        'limit'    => '1',
                    ], );
                // Usar Prefer: count=exact via HEAD
                $countHead = Http::withHeaders(array_merge($this->sbHeaders(), ['Prefer' => 'count=exact']))
                    ->head(rtrim($this->supabaseUrl, '/') . '/rest/v1/whitelabel_orders_snapshot?cycle_id=eq.' . $cycleId);
                $ordersInCycle = 0;
                $rangeHeader = $countHead->header('Content-Range');
                if ($rangeHeader && preg_match('/\/(\d+)$/', $rangeHeader, $m)) {
                    $ordersInCycle = (int)$m[1];
                }

                $ordersCount = $ordersInCycle > 0 ? $ordersInCycle : $ordersAggCount;

                $precoPorUsuario    = (float)$cfg['preco_por_usuario'];
                $precoPorPedido     = (float)$cfg['preco_por_pedido'];
                $consumoUsuarios    = $billableUsers * $precoPorUsuario;
                $consumoPedidos     = $ordersCount * $precoPorPedido;
                $currentConsumption = round(($consumoUsuarios + $consumoPedidos) * 100) / 100;
                $ajusteManual       = (float)($existing['ajuste_manual'] ?? 0);
                $desconto           = (float)($existing['desconto'] ?? 0);
                $amountDue          = round(($previousBalance + $currentConsumption - $desconto + $ajusteManual) * 100) / 100;

                // 6. Update ciclo com numeros finais
                $updateData = [
                    'status'               => ($existing && in_array($existing['status'] ?? '', ['invoiced', 'paid'])) ? $existing['status'] : 'closed',
                    'active_users_snapshot'=> $billableUsers,
                    'orders_snapshot'      => $ordersCount,
                    'previous_balance'     => $previousBalance,
                    'current_consumption'  => $currentConsumption,
                    'amount_due'           => $amountDue,
                ];
                if (!($existing && $recalcOnly)) {
                    $updateData['closed_at'] = now()->toISOString();
                }
                Http::withHeaders($this->sbHeaders())
                    ->patch(rtrim($this->supabaseUrl, '/') . '/rest/v1/whitelabel_billing_cycles?id=eq.' . $cycleId, $updateData);

                // 7. Lancamento ledger (idempotente)
                if ($currentConsumption > 0 && !$recalcOnly) {
                    $existingLed = $this->sbGet('whitelabel_ledger', [
                        'empresa_id' => 'eq.' . $empresaId,
                        'source'     => 'eq.billing_cycle',
                        'source_ref' => 'eq.' . $cycleId,
                        'select'     => 'id,amount',
                    ]);
                    $existingLed = $existingLed[0] ?? null;

                    $desc = "Ciclo mensal {$cycleStart} a {$cycleEnd} - {$billableUsers} usuarios x R\${$precoPorUsuario} + {$ordersCount} pedidos x R\${$precoPorPedido}";
                    $ledPayload = [
                        'empresa_id'  => $empresaId,
                        'entry_type'  => 'debit',
                        'amount'      => $currentConsumption,
                        'source'      => 'billing_cycle',
                        'source_ref'  => $cycleId,
                        'description' => $desc,
                        'meta'        => json_encode([
                            'cycle_start'        => $cycleStart,
                            'cycle_end'          => $cycleEnd,
                            'billable_users'     => $billableUsers,
                            'orders_count'       => $ordersCount,
                            'previous_balance'   => $previousBalance,
                            'current_consumption'=> $currentConsumption,
                            'amount_due_full'    => $amountDue,
                        ]),
                    ];
                    if ($existingLed) {
                        if ((float)$existingLed['amount'] !== $currentConsumption) {
                            Http::withHeaders($this->sbHeaders())
                                ->patch(rtrim($this->supabaseUrl, '/') . '/rest/v1/whitelabel_ledger?id=eq.' . $existingLed['id'], [
                                    'amount'      => $currentConsumption,
                                    'description' => $desc,
                                    'meta'        => $ledPayload['meta'],
                                ]);
                        }
                    } else {
                        $this->sbPost('whitelabel_ledger', $ledPayload);
                    }
                }

                // 8. Audit log
                $this->sbPost('whitelabel_audit_log', [
                    'empresa_id'  => $empresaId,
                    'entity_type' => 'billing_cycle',
                    'entity_id'   => $cycleId,
                    'action'      => $recalcOnly ? 'recalc_cycle' : 'close_cycle',
                    'actor'       => $adminActor,
                    'notes'       => "Ciclo {$cycleStart} a {$cycleEnd}: {$billableUsers} usuarios, {$ordersCount} pedidos, R\${$amountDue}",
                    'after_data'  => json_encode([
                        'billable_users'     => $billableUsers,
                        'orders_count'       => $ordersCount,
                        'previous_balance'   => $previousBalance,
                        'current_consumption'=> $currentConsumption,
                        'amount_due'         => $amountDue,
                        'modelo'             => 'mensal_01_01_R$30_usuario_R$0.99_pedido',
                    ]),
                ]);

                $processed[] = [
                    'empresa_id'          => $empresaId,
                    'empresa_nome'        => $cfg['empresa_nome'],
                    'billable_users'      => $billableUsers,
                    'orders_count'        => $ordersCount,
                    'orders_inserted'     => $orderRowsInserted,
                    'previous_balance'    => $previousBalance,
                    'current_consumption' => $currentConsumption,
                    'amount_due'          => $amountDue,
                ];
            } catch (\Throwable $e) {
                $msg = "empresa {$cfg['empresa_id']}: " . $e->getMessage();
                Log::error('[WlCycle] ' . $msg);
                $errors[] = $msg;
            }
        }

        return response()->json([
            'success'    => true,
            'cycle'      => ['start' => $cycleStart, 'end' => $cycleEnd, 'month' => $billingMonth],
            'recalc_only'=> $recalcOnly,
            'processed'  => count($processed),
            'details'    => $processed,
            'errors'     => $errors,
        ]);
    }

    // =========================================================================
    // POST /api/v1/wl/sync
    // =========================================================================
    public function sync(Request $request)
    {
        // Auth checada no middleware
        if (empty($this->monitorApiUrl) || empty($this->monitorApiKey)) {
            return response()->json(['success' => false, 'error' => 'MONITOR_API_URL ou MONITOR_API_KEY nao configurados'], 500);
        }

        $httpUrl = str_replace('https://', 'http://', $this->monitorApiUrl);
        $apiResp = Http::withHeaders(['X-Monitor-Key' => $this->monitorApiKey])->get($httpUrl);

        if (!$apiResp->successful()) {
            $err = 'API fetch failed: ' . $apiResp->status();
            Log::error('[WlSync] ' . $err);
            return response()->json(['success' => false, 'error' => $err], 502);
        }

        $apiData = $apiResp->json();
        if (!($apiData['success'] ?? false) || !is_array($apiData['data'] ?? null)) {
            Log::error('[WlSync] formato invalido: ' . json_encode(array_slice($apiData, 0, 3)));
            return response()->json(['success' => false, 'error' => 'API retornou formato invalido'], 502);
        }

        $synced  = 0;
        $errors  = 0;
        $today   = now()->toDateString();

        foreach ($apiData['data'] as $item) {
            $payload = [
                'empresa_id'     => $item['empresa_id'],
                'date'           => $item['date'] ?? $today,
                'total_users'    => $item['total_users']    ?? 0,
                'active_users'   => $item['active_users']   ?? 0,
                'inactive_users' => $item['inactive_users'] ?? 0,
                'duplicates'     => $item['duplicates']     ?? 0,
                'orders_count'   => $item['orders_count']   ?? 0,
            ];
            $resp = Http::withHeaders(array_merge($this->sbHeaders(), ['Prefer' => 'resolution=merge-duplicates,return=minimal']))
                ->post(rtrim($this->supabaseUrl, '/') . '/rest/v1/whitelabel_snapshots', $payload);
            if ($resp->successful()) {
                $synced++;
            } else {
                Log::error('[WlSync] upsert erro empresa ' . $item['empresa_id'] . ': ' . $resp->body());
                $errors++;
            }
        }

        $total = count($apiData['data']);
        Log::info("[WlSync] Concluido: {$synced}/{$total} synced, {$errors} erros");

        return response()->json([
            'success'    => true,
            'synced'     => $synced,
            'errors'     => $errors,
            'total'      => $total,
            'started_at' => now()->toISOString(),
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================
    private function resolveCycleRange(array $body, ?string $forceDate): array
    {
        if (!empty($body['cycle_start']) && !empty($body['cycle_end'])) {
            return [$body['cycle_start'], $body['cycle_end']];
        }
        $now = $forceDate ? new \DateTime($forceDate . 'T12:00:00Z') : new \DateTime();
        $endYear  = (int)$now->format('Y');
        $endMonth = (int)$now->format('m');
        $fim      = sprintf('%04d-%02d-01', $endYear, $endMonth);
        $startDate = new \DateTime($now->format('Y-' . str_pad((string)($endMonth - 1 ?: 12), 2, '0', STR_PAD_LEFT) . '-01'));
        if ($endMonth === 1) {
            $startDate = new \DateTime(($endYear - 1) . '-12-01');
        } else {
            $startDate = new \DateTime($endYear . '-' . str_pad((string)($endMonth - 1), 2, '0', STR_PAD_LEFT) . '-01');
        }
        $inicio = $startDate->format('Y-m-d');
        return [$inicio, $fim];
    }

    private function fetchOrders(int $empresaId, string $cycleStart, string $cycleEnd): array
    {
        if (empty($this->monitorApiUrl) || empty($this->monitorApiKey)) {
            return [[], 0];
        }
        $ordersList    = [];
        $ordersAggCount = 0;
        try {
            $url = $this->monitorApiUrl . '?' . http_build_query([
                'data_inicio' => $cycleStart,
                'data_fim'    => $cycleEnd,
                'empresa_id'  => $empresaId,
            ]);
            $r = Http::withHeaders(['X-Monitor-Key' => $this->monitorApiKey, 'Accept' => 'application/json'])->get($url);
            if ($r->successful()) {
                $j = $r->json();
                $d = $j['data'] ?? $j;
                if (is_array($d)) {
                    $ordersList = $d;
                } elseif (is_array($d['data'] ?? null)) {
                    $ordersList = $d['data'];
                } elseif (is_array($d['pedidos'] ?? null)) {
                    $ordersList = $d['pedidos'];
                } elseif (is_array($d['orders'] ?? null)) {
                    $ordersList = $d['orders'];
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[WlCycle] fetchOrders detail empresa=$empresaId: " . $e->getMessage());
        }

        if (empty($ordersList)) {
            try {
                $url = $this->monitorApiUrl . '?' . http_build_query([
                    'data_inicio' => $cycleStart,
                    'data_fim'    => $cycleEnd,
                    'resumo'      => '1',
                ]);
                $r = Http::withHeaders(['X-Monitor-Key' => $this->monitorApiKey, 'Accept' => 'application/json'])->get($url);
                if ($r->successful()) {
                    $j    = $r->json();
                    $rows = $j['data'] ?? [];
                    foreach ($rows as $row) {
                        if ((int)($row['empresa_id'] ?? 0) === $empresaId) {
                            $ordersAggCount = (int)($row['enviados'] ?? $row['total'] ?? $row['total_pedidos'] ?? 0);
                            break;
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("[WlCycle] fetchOrders summary empresa=$empresaId: " . $e->getMessage());
            }
        }

        return [$ordersList, $ordersAggCount];
    }
}
