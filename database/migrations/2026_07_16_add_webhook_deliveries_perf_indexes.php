<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * PERF 2026-07-16 — corrige lentidao /admin/webhook-deliveries e AggregateIntegrationLogsCommand.
 *
 * webhook_deliveries (2.67M rows) faltava indice em:
 *   - `event`     -> filtro Filament SelectFilter fazia SELECT DISTINCT event -> 117s
 *   - `updated_at` -> AggregateIntegrationLogsCommand: ORDER BY updated_at LIMIT 2000 -> "Creating sort index" 60s
 *
 * integration_logs (5.79M rows) precisava coluna virtual + indice pra remover CAST no MAX(source_id):
 *   - source_id era varchar(64), MAX(CAST(source_id AS UNSIGNED)) forcava full scan a cada 5min (cron).
 *   - source_id de webhook_deliveries e UUID = CAST retornava 0 sempre = reprocessava tudo.
 *
 * Indices ja aplicados diretamente no MariaDB em produtcao 15:35-16:10; migration aqui
 * apenas registra pros outros 4 backends (multdrop/fornecefy/mestoredrop/jtdrop) e re-runs.
 * Todas as operacoes sao idempotentes (IF NOT EXISTS via SHOW INDEX + verificacao de coluna).
 */
return new class extends Migration {
    public function up(): void
    {
        // ── webhook_deliveries ────────────────────────────────────────────────
        if (Schema::hasTable('webhook_deliveries')) {
            $wdIdx = collect(DB::select('SHOW INDEX FROM webhook_deliveries'))
                ->pluck('Key_name')->unique()->values()->all();

            if (! in_array('idx_updated_at', $wdIdx)) {
                DB::statement('ALTER TABLE webhook_deliveries ADD INDEX idx_updated_at (updated_at), ALGORITHM=INPLACE, LOCK=NONE');
            }

            if (! in_array('idx_event', $wdIdx)) {
                DB::statement('ALTER TABLE webhook_deliveries ADD INDEX idx_event (event), ALGORITHM=INPLACE, LOCK=NONE');
            }
        }

        // ── integration_logs.source_id_bigint (VIRTUAL) + indice ──────────────
        if (Schema::hasTable('integration_logs')) {
            if (! Schema::hasColumn('integration_logs', 'source_id_bigint')) {
                DB::statement("ALTER TABLE integration_logs ADD COLUMN source_id_bigint BIGINT UNSIGNED AS (IF(source_id REGEXP '^[0-9]+\$', CAST(source_id AS UNSIGNED), NULL)) VIRTUAL");
            }

            $ilIdx = collect(DB::select('SHOW INDEX FROM integration_logs'))
                ->pluck('Key_name')->unique()->values()->all();

            if (! in_array('idx_source_bigint', $ilIdx)) {
                DB::statement('ALTER TABLE integration_logs ADD INDEX idx_source_bigint (source_table, source_id_bigint), ALGORITHM=INPLACE, LOCK=NONE');
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('webhook_deliveries')) {
            Schema::table('webhook_deliveries', function (Blueprint $t) {
                try { $t->dropIndex('idx_updated_at'); } catch (\Throwable) {}
                try { $t->dropIndex('idx_event'); } catch (\Throwable) {}
            });
        }
        if (Schema::hasTable('integration_logs')) {
            Schema::table('integration_logs', function (Blueprint $t) {
                try { $t->dropIndex('idx_source_bigint'); } catch (\Throwable) {}
            });
            if (Schema::hasColumn('integration_logs', 'source_id_bigint')) {
                Schema::table('integration_logs', fn (Blueprint $t) => $t->dropColumn('source_id_bigint'));
            }
        }
    }
};
