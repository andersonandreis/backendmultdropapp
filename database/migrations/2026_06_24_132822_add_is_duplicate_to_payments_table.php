<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOV-014: Adiciona colunas de auditoria de duplicata na tabela payments.
 *
 * is_duplicate    = true  indica Record B (nao contar em relatorios financeiros)
 * duplicate_of_id = ID do Record A (original) ao qual este e duplicata
 * duplicate_reason = razao da duplicata (legacy_migration_rerun, pagarme_webhook_bug, etc)
 *
 * NUNCA deletar registros - apenas marcar para auditoria e filtragem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->boolean('is_duplicate')->default(false)->after('gateway_response')
                  ->comment('NOV-014: true = duplicata, nao contar em relatorios');
            $table->unsignedBigInteger('duplicate_of_id')->nullable()->after('is_duplicate')
                  ->comment('NOV-014: ID do registro original (Record A)');
            $table->string('duplicate_reason', 100)->nullable()->after('duplicate_of_id')
                  ->comment('NOV-014: razao da duplicata');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['is_duplicate', 'duplicate_of_id', 'duplicate_reason']);
        });
    }
};
