<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historico das execucoes dos jobs de sync legado→novo. Alimenta
 * `/admin/sync` que mostrava "Nenhum registro" mesmo com
 * SyncLegacyOrdersJob + ImportLegacyOrdersJob + SyncLegacyFinanceJob
 * rodando a cada 5min.
 *
 * Nao reutilizei `sync_logs` (que ja existe pra sync marketplace).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('job', 60);
            $table->string('status', 20)->default('success');
            $table->integer('processed')->default(0);
            $table->integer('errors')->default(0);
            $table->text('message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['job', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_sync_runs');
    }
};
