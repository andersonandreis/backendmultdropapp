<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * NOV-156 — Limpa contas marketplace pending/fantasma historicas.
 *
 * Cliente leojesus1006@gmail.com (Fornecefy, client_id=629) e outros tinham
 * ma_id=1300/etc. pending sem token bloqueando publicacao com store=all.
 * Esta migration limpa todas as orphans com >1h.
 *
 * Criterio: status=pending AND seller_id IS NULL AND sem nenhum token AND created_at < NOW() - 1h.
 *
 * O Job CleanOrphanedPendingMarketplaceAccountsJob continua a limpeza rotineira
 * (hourly, cutoff de 30min).
 */
return new class extends Migration {
    public function up(): void
    {
        $snapshot = DB::table('marketplace_accounts')
            ->where('status', 'pending')
            ->whereNull('seller_id')
            ->whereNull('access_token')
            ->whereNull('ml_access_token')
            ->whereNull('bling_access_token')
            ->where('created_at', '<', now()->subHour())
            ->get(['id', 'client_id', 'platform', 'created_at'])
            ->toArray();

        $count = count($snapshot);

        if ($count === 0) {
            Log::info('[migration NOV-156] Nenhuma conta pending fantasma historica encontrada.');
            return;
        }

        $ids = array_map(fn($row) => $row->id, $snapshot);

        $deleted = DB::table('marketplace_accounts')->whereIn('id', $ids)->delete();

        Log::info('[migration NOV-156] Contas pending fantasma historicas removidas', [
            'deleted'  => $deleted,
            'expected' => $count,
            'sample_ids' => array_slice($ids, 0, 10),
        ]);
    }

    public function down(): void
    {
        // Irreversivel: registros pending sem token nao tem valor de restauracao.
    }
};
