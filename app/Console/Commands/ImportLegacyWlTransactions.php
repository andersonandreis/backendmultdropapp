<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Importa conta_corrente_loja_white do legado para imported_legacy_transactions.
 *
 * A conta_corrente_loja_white registra movimentações financeiras da própria loja WL
 * (empresa) como entidade — créditos de pedidos enviados, depósitos PIX, etc.
 * NÃO confundir com conta_corrente (lojistas individuais).
 *
 * Deduplicação: source='wl_cc' + legacy_id_transacao (evita duplicar com conta_corrente).
 *
 * Uso:
 *   php artisan finance:sync-legacy-wl --empresa-id=24          (MultDrop)
 *   php artisan finance:sync-legacy-wl --empresa-id=20          (MEStoreDrop)
 *   php artisan finance:sync-legacy-wl --empresa-id=24 --dry-run
 *
 * MUL-060 — importação conta_corrente_loja_white
 */
class ImportLegacyWlTransactions extends Command
{
    protected $signature = 'finance:sync-legacy-wl
        {--empresa-id=   : id_empresa no legado (obrigatório)}
        {--dry-run       : só mostra o que faria, sem gravar}';

    protected $description = 'Importa conta_corrente_loja_white do legado (nível WL/empresa) para imported_legacy_transactions';

    public function handle(): int
    {
        $empresaId = (int) $this->option('empresa-id');
        $dry       = (bool) $this->option('dry-run');

        if ($empresaId <= 0) {
            $this->error('--empresa-id é obrigatório. Ex: --empresa-id=24');
            return self::FAILURE;
        }

        $this->line(sprintf(
            '[finance:sync-legacy-wl] empresa_id=%d | dry-run=%s',
            $empresaId, $dry ? 'SIM' : 'nao'
        ));

        // Buscar registros do legado com status=1 (confirmados).
        $legacyRows = DB::connection('legacy')
            ->table('conta_corrente_loja_white')
            ->where('id_empresa', $empresaId)
            ->where('status', 1)
            ->orderBy('data_add')
            ->orderBy('id')
            ->get(['id', 'data_add', 'tipo', 'valor', 'descricao', 'id_loja', 'id_empresa', 'id_pedido']);

        if ($legacyRows->isEmpty()) {
            $this->warn("Nenhum registro encontrado para empresa_id={$empresaId}.");
            return self::SUCCESS;
        }

        $this->line("Registros no legado: " . $legacyRows->count());

        // IDs já importados (dedup por source=wl_cc + legacy_id_transacao).
        $existingIds = DB::table('imported_legacy_transactions')
            ->where('source', 'wl_cc')
            ->where('legacy_empresa_id', $empresaId)
            ->whereNotNull('legacy_id_transacao')
            ->pluck('legacy_id_transacao')
            ->flip();

        $toInsert = $legacyRows->reject(fn ($r) => isset($existingIds[$r->id]))->values();

        $this->line(sprintf(
            "Já importados: %d | A inserir: %d",
            $legacyRows->count() - $toInsert->count(),
            $toInsert->count()
        ));

        $cred = (float) $legacyRows->where('tipo', 'C')->sum('valor');
        $deb  = (float) $legacyRows->where('tipo', 'D')->sum('valor');
        $this->line(sprintf("Saldo WL empresa_id=%d: R$ %s (C=%s / D=%s)",
            $empresaId,
            number_format($cred - $deb, 2),
            number_format($cred, 2),
            number_format($deb, 2)
        ));

        if ($dry || $toInsert->isEmpty()) {
            if ($dry) {
                $this->info('[dry-run] Nenhum dado gravado.');
            }
            return self::SUCCESS;
        }

        $now   = now();
        $rows  = [];
        foreach ($toInsert as $r) {
            $desc = trim(strip_tags((string) $r->descricao));
            $rows[] = [
                'user_id'              => null,
                'client_id'            => null,
                'legacy_user_id'       => $r->id_loja,   // id da loja WL no legado
                'legacy_empresa_id'    => $r->id_empresa,
                'legacy_id_transacao'  => $r->id,
                'tipo'                 => $r->tipo,
                'amount'               => $r->valor,
                'description'          => mb_substr($desc ?: 'Lançamento WL', 0, 500),
                'occurred_at'          => $r->data_add,
                'imported_at'          => $now,
                'is_historical'        => 1,
                'source'               => 'wl_cc',
                'created_at'           => $now,
                'updated_at'           => $now,
            ];
        }

        $inserted = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('imported_legacy_transactions')->insert($chunk);
            $inserted += count($chunk);
            $this->line("  Inseridos: {$inserted}/" . count($rows));
        }

        $this->info("[finance:sync-legacy-wl] OK — empresa_id={$empresaId} +{$inserted} registros importados.");
        Log::info("finance:sync-legacy-wl empresa_id={$empresaId} inserted={$inserted}");

        return self::SUCCESS;
    }
}
