<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Espelha os lançamentos do conta_corrente do legado para client_supplier_transactions.
 *
 *   --supplier-id=N : supplier_id alvo no novo sistema (padrão: env LOCAL_SUPPLIER_ID, fallback 1)
 *   --wipe    : apaga os lançamentos SEM legacy_cc_id (dados antigos errados) antes de importar.
 *               Usar uma única vez, na limpeza inicial.
 *   --dry-run : só mostra o que faria, não grava nada.
 *
 * Sem flags = modo incremental (polling): só insere lançamentos novos, de-dup por legacy_cc_id.
 *
 * FIX MUL-060: supplier_id era hardcoded em 30, agora usa LOCAL_SUPPLIER_ID do env ou --supplier-id.
 */
class SyncLegacyFinance extends Command
{
    protected $signature = 'finance:sync-legacy
        {--supplier-id= : supplier_id alvo (padrão: LOCAL_SUPPLIER_ID do .env)}
        {--wipe         : apaga lançamentos sem legacy_cc_id antes de importar}
        {--dry-run      : só mostra o que faria, não grava}';

    protected $description = 'Sincroniza o financeiro do legado (conta_corrente) para o NovoHubAI';

    public function handle(): int
    {
        $wipe       = (bool) $this->option('wipe');
        $dry        = (bool) $this->option('dry-run');
        $supplierId = (int) ($this->option('supplier-id') ?: env('LOCAL_SUPPLIER_ID', 1));

        $this->line(sprintf(
            '[finance:sync-legacy] supplier_id=%d | wipe=%s | dry-run=%s',
            $supplierId,
            $wipe ? 'SIM' : 'nao',
            $dry  ? 'SIM' : 'nao'
        ));

        // Clientes migrados: tem legacy_id_login + carteira no supplier alvo.
        // MUL-269 fase 2: nome do seller vem do user (clients.company_name removido).
        $clients = DB::table('clients as c')
            ->join('client_supplier_balances as b', 'b.client_id', '=', 'c.id')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->whereNotNull('c.legacy_id_login')
            ->where('c.is_active', 1)
            ->where('b.supplier_id', $supplierId)
            ->get(['c.id as client_id', 'c.legacy_id_login', 'b.supplier_id', DB::raw("COALESCE(NULLIF(u.full_name,''), u.name) as company_name")]);

        if ($clients->isEmpty()) {
            $this->warn("Nenhum cliente migrado encontrado para supplier_id={$supplierId}.");
            return self::SUCCESS;
        }

        $this->line("Clientes encontrados: " . $clients->count());
        $totalInserted = 0;

        foreach ($clients as $cli) {
            $inserted = $this->syncClient($cli, $wipe, $dry);
            $totalInserted += $inserted;
        }

        $this->info("[finance:sync-legacy] Concluído. Total inserido: {$totalInserted}");
        Log::info("finance:sync-legacy supplier_id={$supplierId} inserted={$totalInserted}");

        return self::SUCCESS;
    }

    private function syncClient(object $cli, bool $wipe, bool $dry): int
    {
        $cid = (int) $cli->client_id;
        $sup = (int) $cli->supplier_id;
        $lid = (int) $cli->legacy_id_login;

        // Lançamentos confirmados do legado.
        $legacy = DB::connection('legacy')->table('conta_corrente')
            ->where('id_login', $lid)
            ->where('status', 1)
            ->orderBy('data')->orderBy('id')
            ->get(['id', 'data', 'tipo', 'valor', 'descricao']);

        // legacy_cc_id já espelhados (de-dup).
        $existing = DB::table('client_supplier_transactions')
            ->where('client_id', $cid)
            ->where('supplier_id', $sup)
            ->whereNotNull('legacy_cc_id')
            ->pluck('legacy_cc_id')->flip();

        $toInsert   = $legacy->reject(fn ($r) => isset($existing[$r->id]))->values();
        $wrongCount = (int) DB::table('client_supplier_transactions')
            ->where('client_id', $cid)
            ->where('supplier_id', $sup)
            ->whereNull('legacy_cc_id')
            ->count();

        $cred = (float) $legacy->where('tipo', 'C')->sum('valor');
        $deb  = (float) $legacy->where('tipo', 'D')->sum('valor');
        $bal  = round($cred - $deb, 2);

        $this->line(sprintf(
            '  %s (cid=%d sup=%d): legado %d lanc. (%dC/%dD) | inserir %d | errados %d | saldo R$ %s',
            mb_substr((string) $cli->company_name, 0, 30), $cid, $sup,
            $legacy->count(),
            $legacy->where('tipo', 'C')->count(),
            $legacy->where('tipo', 'D')->count(),
            $toInsert->count(), $wrongCount,
            number_format($bal, 2, '.', '')
        ));

        if ($dry) {
            return 0;
        }
        if (!$wipe && $toInsert->isEmpty()) {
            return 0; // incremental sem novidade
        }

        $inserted = 0;
        DB::transaction(function () use ($cid, $sup, $wipe, $toInsert, $bal, &$inserted) {
            if ($wipe) {
                DB::table('client_supplier_transactions')
                    ->where('client_id', $cid)
                    ->where('supplier_id', $sup)
                    ->whereNull('legacy_cc_id')
                    ->delete();
            }
            $now  = now();
            $rows = [];
            foreach ($toInsert as $r) {
                $desc = trim(strip_tags((string) $r->descricao));
                $rows[] = [
                    'client_id'        => $cid,
                    'supplier_id'      => $sup,
                    'type'             => $r->tipo === 'C' ? 'credit' : 'debit',
                    'amount'           => $r->valor,
                    'description'      => mb_substr($desc !== '' ? $desc : 'Lançamento legado', 0, 255),
                    'reference'        => 'legacy_cc',
                    'transaction_type' => 'legacy_sync',
                    'legacy_cc_id'     => $r->id,
                    'created_at'       => $r->data,
                    'updated_at'       => $now,
                ];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('client_supplier_transactions')->insert($chunk);
            }
            $inserted = count($rows);
            DB::table('client_supplier_balances')
                ->where('client_id', $cid)
                ->where('supplier_id', $sup)
                ->update(['balance' => $bal, 'updated_at' => $now]);
        });

        $this->info("    OK — cid={$cid} sup={$sup} +{$inserted} lanc. saldo R$ " . number_format($bal, 2));
        return $inserted;
    }
}
