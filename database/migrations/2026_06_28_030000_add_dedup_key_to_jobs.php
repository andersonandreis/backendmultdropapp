<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Coluna ja existe (criada por migration anterior) — apenas garantir o indice.
        // O indice e criado via ALTER TABLE separado para evitar timeout com 2.6M rows;
        // quando a fila estiver limpa o indice pode ser adicionado sem lock de producao.
        if (! Schema::hasColumn('jobs', 'dedup_key')) {
            Schema::table('jobs', function (Blueprint $table) {
                $table->string('dedup_key', 200)->nullable()->after('queue')
                      ->comment('Chave de deduplicacao: "{JobClass}:{primary_id}". Usado por firstOrCreate antes do dispatch.');
            });
        }
        // Nota: INDEX em dedup_key sera criado via migration separada apos limpeza de backlog.
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            if (Schema::hasColumn('jobs', 'dedup_key')) {
                $table->dropColumn('dedup_key');
            }
        });
    }
};
