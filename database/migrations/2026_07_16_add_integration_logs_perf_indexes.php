<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * PERF 2026-07-16 — /admin lento (widget IntegrationLogsOverview + AggregateLogsCommand
 * faziam full scan em integration_logs 5.7M rows). Adiciona indice composto
 * (created_at, status_code) — cobre as 4 contagens de 24h do widget — e
 * (source_table, occurred_at) — cobre o MAX(occurred_at) do AggregateLogsCommand.
 *
 * ALGORITHM=INPLACE, LOCK=NONE em MariaDB 10.11 (online, nao bloqueia writes).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('integration_logs')) return;

        $existing = collect(DB::select('SHOW INDEX FROM integration_logs'))
            ->pluck('Key_name')
            ->unique()
            ->values()
            ->all();

        if (! in_array('idx_created_status', $existing)) {
            DB::statement('ALTER TABLE integration_logs ADD INDEX idx_created_status (created_at, status_code), ALGORITHM=INPLACE, LOCK=NONE');
        }

        if (! in_array('idx_source_occurred', $existing)) {
            DB::statement('ALTER TABLE integration_logs ADD INDEX idx_source_occurred (source_table, occurred_at), ALGORITHM=INPLACE, LOCK=NONE');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('integration_logs')) return;

        Schema::table('integration_logs', function (Blueprint $t) {
            try { $t->dropIndex('idx_created_status'); } catch (\Throwable) {}
            try { $t->dropIndex('idx_source_occurred'); } catch (\Throwable) {}
        });
    }
};
