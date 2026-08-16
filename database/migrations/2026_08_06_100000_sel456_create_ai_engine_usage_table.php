<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-456 -- Quota tracking por engine de IA.
 *
 * Registra quantas gerações cada engine fez por dia,
 * permitindo o AiEnginePool::reserveEngine() bloquear engines
 * que atingiram o limite diário antes de tentar abrir o perfil.
 *
 * Reset: coluna date (UTC) -- quando a data muda, um novo registro é criado.
 * Reset manual: via artisan tinker ou cron (reinicia às 00:00 UTC, compatível
 * com quota do Google Flow que reseta meia-noite PT / 03:00 UTC).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_engine_usage', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('engine_id')->comment('FK ai_engines.id');
            $table->date('date')->comment('Data UTC da contagem (YYYY-MM-DD)');
            $table->unsignedInteger('generated_count')->default(0)->comment('Gerações concluídas neste dia');
            $table->unsignedInteger('reserved_count')->default(0)->comment('Reservas ativas (lock adquirido, ainda gerando)');
            $table->timestamp('last_used_at')->nullable()->comment('Último momento em que o engine foi reservado');
            $table->timestamp('reset_at')->nullable()->comment('Timestamp do último reset manual (auditoria)');
            $table->timestamps();

            $table->unique(['engine_id', 'date'], 'ui_engine_usage_engine_date');

            $table->foreign('engine_id')
                  ->references('id')
                  ->on('ai_engines')
                  ->onDelete('cascade');

            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_engine_usage');
    }
};
