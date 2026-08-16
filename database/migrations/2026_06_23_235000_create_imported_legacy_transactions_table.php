<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIGRA-V3 — Tabela de historico auditavel de transacoes legadas.
 *
 * Importa lancamentos do conta_corrente do legado como referencia historica
 * (is_historical=true). Inclui o ajuste de saldo sobrescrito (source=ajuste_migracao).
 * Idempotente por legacy_id_transacao UNIQUE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imported_legacy_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->integer('legacy_user_id')->nullable()->index();
            $table->integer('legacy_empresa_id')->nullable()->index();
            $table->integer('legacy_id_transacao')->nullable();
            $table->string('tipo', 1)->nullable()->comment('C=credito D=debito A=ajuste');
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('description', 500)->nullable();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->timestamp('imported_at')->nullable();
            $table->boolean('is_historical')->default(true);
            $table->string('source', 50)->default('legado')->comment('legado|ajuste_migracao');

            $table->timestamps();

            $table->unique(['client_id', 'legacy_id_transacao'], 'ilt_client_transacao_unique');
            $table->index(['client_id', 'occurred_at'], 'ilt_client_occurred_idx');
            $table->index(['legacy_user_id', 'occurred_at'], 'ilt_legacy_occurred_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imported_legacy_transactions');
    }
};
