<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * MIGRA-V3 — Importa historico financeiro + pedidos do legado e ajusta saldo.
 *
 * Modos de operacao:
 *   --mode=balance  : Sobrescreve saldo novo com saldo legado.
 *                     Registra transaction audit (description='ajuste_migracao_legado_2026_06_23').
 *   --mode=history  : Importa conta_corrente + pedidos_curso do legado
 *                     para imported_legacy_transactions / imported_legacy_orders.
 *                     Idempotente (firstOrCreate por legacy_id_transacao / legacy_order_id).
 *   --mode=full     : Roda balance + history em sequencia.
 *
 * Flags:
 *   --wl=multdrop|mestoredrop  : Define qual WL processar.
 *   --dry-run                  : Mostra preview sem gravar nada.
 *   --limit=N                  : Limita a N clientes (0=todos).
 *
 * Uso:
 *   php artisan import:legacy-history --wl=multdrop --mode=full --dry-run --limit=5
 *   php artisan import:legacy-history --wl=multdrop --mode=full
 *   php artisan import:legacy-history --wl=mestoredrop --mode=full --dry-run
 */
class ImportLegacyHistory extends Command
{
    protected $signature = 'import:legacy-history
                            {--wl=multdrop : Whitelabel: multdrop|mestoredrop}
                            {--dry-run : Preview sem gravar nada}
                            {--mode=full : Modo: balance|history|full}
                            {--limit=0 : Limitar N clientes (0=todos)}';

    protected $description = 'MIGRA-V3: Importa historico legado e ajusta saldo (balance/history/full)';

    // Configs por WL: empresa, supplier_id no novo, deposito no legado
    private const WL_CONFIG = [
        'multdrop' => [
            'id_empresa'  => 24,
            'supplier_id' => 1,   // suppliers.id onde slug='multdrop'
            'deposito'    => 498, // MUL-FIX-11 confirmado
            'label'       => 'MultDrop',
            'db'          => null, // usa conexao principal do projeto
        ],
        'mestoredrop' => [
            'id_empresa'  => 20,
            'supplier_id' => 25,  // suppliers.id onde legacy_empresa_id=447
            'deposito'    => 447,
            'label'       => 'MEStoreDrop',
            'db'          => null,
        ],
    ];

    private bool $dry   = false;
    private array $cfg  = [];
    private string $wl  = '';

    // Contadores para relatorio final
    private int $balanceAjustados   = 0;
    private int $balanceSemMudanca  = 0;
    private float $totalSaldoAntes  = 0.0;
    private float $totalSaldoDepois = 0.0;
    private int $txImportadas       = 0;
    private int $txExistentes       = 0;
    private int $ordersImportados   = 0;
    private int $ordersExistentes   = 0;

    public function handle(): int
    {
        $wl   = (string) $this->option('wl');
        $mode = (string) $this->option('mode');
        $this->dry = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        if (!isset(self::WL_CONFIG[$wl])) {
            $this->error("WL invalida: {$wl}. Use multdrop ou mestoredrop.");
            return self::FAILURE;
        }

        $this->wl  = $wl;
        $this->cfg = self::WL_CONFIG[$wl];

        $label = $this->cfg['label'];
        $this->info("[import:legacy-history] WL={$label} | mode={$mode} | dry-run=" . ($this->dry ? 'SIM' : 'nao'));

        // Carrega clientes mapeados com legacy_id_login
        $clients = $this->loadClients($limit);

        if ($clients->isEmpty()) {
            $this->warn("Nenhum cliente com legacy_id_login encontrado para {$label}.");
            return self::SUCCESS;
        }

        $this->info("Clientes com legacy_id_login: {$clients->count()}");

        if ($mode === 'balance' || $mode === 'full') {
            $this->runBalance($clients);
        }

        if ($mode === 'history' || $mode === 'full') {
            $this->runHistory($clients);
        }

        $this->printSummary($mode);

        return self::SUCCESS;
    }

    /**
     * Carrega clientes com legacy_id_login mapeados no banco atual.
     */
    private function loadClients(int $limit)
    {
        $supId = $this->cfg['supplier_id'];

        // MUL-269 fase 2: nome do seller vem do user (clients.company_name removido).
        $q = DB::table('clients as c')
            ->leftJoin('client_supplier_balances as b', function ($j) use ($supId) {
                $j->on('b.client_id', '=', 'c.id')
                  ->where('b.supplier_id', '=', $supId);
            })
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->whereNotNull('c.legacy_id_login')
            ->select([
                'c.id as client_id',
                'c.user_id',
                'c.legacy_id_login',
                DB::raw("COALESCE(NULLIF(u.full_name,''), u.name) as company_name"),
                DB::raw('COALESCE(b.balance, 0) as balance_novo'),
                DB::raw("EXISTS(SELECT 1 FROM client_supplier_balances WHERE client_id=c.id AND supplier_id={$supId}) as has_csb"),
            ])
            ->orderBy('c.id');

        if ($limit > 0) {
            $q->limit($limit);
        }

        return $q->get();
    }

    // ──────────────────────────────────────────────────────────────────────
    // MODE: balance — sobrescreve saldo do novo com saldo do legado
    // ──────────────────────────────────────────────────────────────────────

    private function runBalance($clients): void
    {
        $this->info("\n[BALANCE] Sobrescrevendo saldos...");
        $supId  = $this->cfg['supplier_id'];
        $empId  = $this->cfg['id_empresa'];
        $now    = now();

        foreach ($clients as $cli) {
            $legadoSaldo = $this->getLegadoSaldo((int) $cli->legacy_id_login, $empId);
            $novoSaldo   = (float) $cli->balance_novo;
            $diff        = round($legadoSaldo - $novoSaldo, 2);

            $this->totalSaldoAntes  += $novoSaldo;
            $this->totalSaldoDepois += $legadoSaldo;

            $this->line(sprintf(
                "  %s (cid=%d lid=%d): novo=R$%.2f legado=R$%.2f diff=%s%.2f",
                $cli->company_name,
                $cli->client_id,
                $cli->legacy_id_login,
                $novoSaldo,
                $legadoSaldo,
                $diff >= 0 ? '+' : '',
                $diff
            ));

            if (abs($diff) < 0.01) {
                $this->balanceSemMudanca++;
                continue;
            }

            if ($this->dry) {
                $this->balanceAjustados++;
                continue;
            }

            DB::transaction(function () use ($cli, $supId, $legadoSaldo, $diff, $now, $empId) {
                $cid = (int) $cli->client_id;
                $uid = (int) $cli->user_id;

                // Upsert client_supplier_balances
                $exists = DB::table('client_supplier_balances')
                    ->where('client_id', $cid)
                    ->where('supplier_id', $supId)
                    ->exists();

                if ($exists) {
                    DB::table('client_supplier_balances')
                        ->where('client_id', $cid)
                        ->where('supplier_id', $supId)
                        ->update(['balance' => $legadoSaldo, 'updated_at' => $now]);
                } else {
                    DB::table('client_supplier_balances')->insert([
                        'client_id'  => $cid,
                        'supplier_id' => $supId,
                        'balance'    => $legadoSaldo,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                // Transaction de auditoria
                DB::table('client_supplier_transactions')->insert([
                    'client_id'        => $cid,
                    'supplier_id'      => $supId,
                    'type'             => $diff >= 0 ? 'credit' : 'debit',
                    'amount'           => abs($diff),
                    'description'      => 'ajuste_migracao_legado_2026_06_23',
                    'reference'        => 'migra-v3',
                    'transaction_type' => 'ajuste',
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ]);

                // Audit em imported_legacy_transactions com source=ajuste_migracao
                DB::table('imported_legacy_transactions')->updateOrInsert(
                    [
                        'client_id'  => $cid,
                        'source'     => 'ajuste_migracao',
                        'legacy_user_id' => (int) $cli->legacy_id_login,
                    ],
                    [
                        'user_id'         => $uid,
                        'legacy_empresa_id' => $empId,
                        'legacy_id_transacao' => null,
                        'tipo'            => $diff >= 0 ? 'C' : 'D',
                        'amount'          => abs($diff),
                        'description'     => 'Ajuste migração MIGRA-V3 2026-06-23 — saldo legado R$' . number_format($legadoSaldo, 2),
                        'occurred_at'     => $now,
                        'imported_at'     => $now,
                        'is_historical'   => false,
                        'created_at'      => $now,
                        'updated_at'      => $now,
                    ]
                );
            });

            $this->balanceAjustados++;
            $this->info("    OK cid={$cli->client_id} saldo R$" . number_format($legadoSaldo, 2));
        }
    }

    /**
     * Busca saldo consolidado do cliente no legado (conta_corrente C - D com status=1).
     */
    private function getLegadoSaldo(int $legacyIdLogin, int $idEmpresa): float
    {
        $rows = DB::connection('legacy')
            ->table('conta_corrente')
            ->where('id_login', $legacyIdLogin)
            ->where('status', 1)
            ->select(['tipo', DB::raw('SUM(valor) as total')])
            ->groupBy('tipo')
            ->get();

        $cred = 0.0;
        $deb  = 0.0;
        foreach ($rows as $r) {
            if ($r->tipo === 'C') {
                $cred = (float) $r->total;
            } elseif ($r->tipo === 'D') {
                $deb = (float) $r->total;
            }
        }

        return round($cred - $deb, 2);
    }

    // ──────────────────────────────────────────────────────────────────────
    // MODE: history — importa transacoes e pedidos do legado
    // ──────────────────────────────────────────────────────────────────────

    private function runHistory($clients): void
    {
        $this->info("\n[HISTORY] Importando historico...");
        $empId = $this->cfg['id_empresa'];
        $now   = now();

        foreach ($clients as $cli) {
            $lid = (int) $cli->legacy_id_login;
            $cid = (int) $cli->client_id;
            $uid = (int) $cli->user_id;

            $this->importTransactions($cli, $lid, $cid, $uid, $empId, $now);
            $this->importOrders($cli, $lid, $cid, $uid, $now);
        }
    }

    private function importTransactions(object $cli, int $lid, int $cid, int $uid, int $empId, $now): void
    {
        $legacy = DB::connection('legacy')
            ->table('conta_corrente')
            ->where('id_login', $lid)
            ->where('status', 1)
            ->orderBy('id')
            ->get(['id', 'data', 'tipo', 'valor', 'descricao']);

        $existing = DB::table('imported_legacy_transactions')
            ->where('client_id', $cid)
            ->whereNotNull('legacy_id_transacao')
            ->pluck('legacy_id_transacao')
            ->flip();

        $toInsert = $legacy->reject(fn ($r) => isset($existing[$r->id]))->values();

        $this->line(sprintf(
            "  TX %s (cid=%d): %d total legado | %d existentes | %d a inserir",
            $cli->company_name, $cid, $legacy->count(), $existing->count(), $toInsert->count()
        ));

        $this->txExistentes += $existing->count();

        if ($toInsert->isEmpty() || $this->dry) {
            $this->txImportadas += $this->dry ? $toInsert->count() : 0;
            return;
        }

        $rows = [];
        foreach ($toInsert as $r) {
            $desc  = mb_substr(trim(strip_tags((string) $r->descricao)), 0, 499);
            $rows[] = [
                'user_id'             => $uid,
                'client_id'           => $cid,
                'legacy_user_id'      => $lid,
                'legacy_empresa_id'   => $empId,
                'legacy_id_transacao' => $r->id,
                'tipo'                => $r->tipo,
                'amount'              => $r->valor,
                'description'         => $desc !== '' ? $desc : 'Lancamento legado',
                'occurred_at'         => $r->data,
                'imported_at'         => $now,
                'is_historical'       => true,
                'source'              => 'legado',
                'created_at'          => $now,
                'updated_at'          => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('imported_legacy_transactions')->insertOrIgnore($chunk);
        }

        $this->txImportadas += $toInsert->count();
    }

    private function importOrders(object $cli, int $lid, int $cid, int $uid, $now): void
    {
        // pedidos_curso (id, id_login, data, status, valor_pix, origem)
        // pedidos_curso_pedidos (id_pedidos_curso, id_pedido) → pedidos (id, status, valor_total, nr_canal, nome_canal, data_add)
        $legacyOrders = DB::connection('legacy')
            ->table('pedidos_curso as pc')
            ->join('pedidos_curso_pedidos as pcp', 'pcp.id_pedidos_curso', '=', 'pc.id')
            ->join('pedidos as p', 'p.id', '=', 'pcp.id_pedido')
            ->where('pc.id_login', $lid)
            ->select([
                'pc.id as curso_id',
                'pcp.id_pedido as legacy_order_id',
                'pc.status as status_legado',
                'p.valor_total',
                'p.nr_canal as marketplace_order_id',
                'p.nome_canal as marketplace',
                'p.data_add as occurred_at',
            ])
            ->orderBy('p.id')
            ->get();

        $existing = DB::table('imported_legacy_orders')
            ->where('client_id', $cid)
            ->whereNotNull('legacy_order_id')
            ->pluck('legacy_order_id')
            ->flip();

        $toInsert = $legacyOrders->reject(fn ($r) => isset($existing[$r->legacy_order_id]))->values();

        $this->line(sprintf(
            "  ORD %s (cid=%d): %d total legado | %d existentes | %d a inserir",
            $cli->company_name, $cid, $legacyOrders->count(), $existing->count(), $toInsert->count()
        ));

        $this->ordersExistentes += $existing->count();

        if ($toInsert->isEmpty() || $this->dry) {
            $this->ordersImportados += $this->dry ? $toInsert->count() : 0;
            return;
        }

        $statusMap = [
            0 => 'pending',
            1 => 'processing',
            2 => 'shipped',
            3 => 'delivered',
            4 => 'cancelled',
        ];

        $rows = [];
        foreach ($toInsert as $r) {
            $rows[] = [
                'user_id'              => $uid,
                'client_id'            => $cid,
                'legacy_user_id'       => $lid,
                'legacy_order_id'      => $r->legacy_order_id,
                'status_legado'        => $r->status_legado,
                'status_novo'          => $statusMap[$r->status_legado] ?? 'unknown',
                'valor_total'          => $r->valor_total,
                'marketplace'          => mb_substr((string) $r->marketplace, 0, 49),
                'marketplace_order_id' => mb_substr((string) $r->marketplace_order_id, 0, 99),
                'occurred_at'          => $r->occurred_at,
                'imported_at'          => $now,
                'is_historical'        => true,
                'created_at'           => $now,
                'updated_at'           => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('imported_legacy_orders')->insertOrIgnore($chunk);
        }

        $this->ordersImportados += $toInsert->count();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Relatorio final
    // ──────────────────────────────────────────────────────────────────────

    private function printSummary(string $mode): void
    {
        $prefix = $this->dry ? '[DRY-RUN] ' : '';
        $this->newLine();
        $this->info("═══════════════════════════════════════");
        $this->info("{$prefix}MIGRA-V3 — {$this->cfg['label']} — RESUMO");
        $this->info("═══════════════════════════════════════");

        if ($mode === 'balance' || $mode === 'full') {
            $this->info("BALANCE:");
            $this->line("  Ajustados : {$this->balanceAjustados}");
            $this->line("  Sem mudanca: {$this->balanceSemMudanca}");
            $this->line("  Total antes  : R$ " . number_format($this->totalSaldoAntes, 2));
            $this->line("  Total depois : R$ " . number_format($this->totalSaldoDepois, 2));
        }

        if ($mode === 'history' || $mode === 'full') {
            $this->info("HISTORY:");
            $this->line("  TX importadas  : {$this->txImportadas}");
            $this->line("  TX existentes  : {$this->txExistentes}");
            $this->line("  ORD importados : {$this->ordersImportados}");
            $this->line("  ORD existentes : {$this->ordersExistentes}");
        }

        $this->info("═══════════════════════════════════════");
    }
}
