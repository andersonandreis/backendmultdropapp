<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-082 F7 — Coluna survey (JSON) em clients pra guardar respostas do
 * questionário do onboarding freemium (nicho, estado, perfil, ticket_medio).
 * Também flag survey_skipped_at pra permitir "pular por enquanto".
 *
 * SEL-082 F8 — settings global catalog_release_schedule já é coberto pela
 * tabela `settings` existente (chave = 'catalog_release_schedule', value JSON).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $t) {
            if (! Schema::hasColumn('clients', 'survey')) {
                $t->longText('survey')->nullable();
            }
            if (! Schema::hasColumn('clients', 'survey_skipped_at')) {
                $t->timestamp('survey_skipped_at')->nullable();
            }
            if (! Schema::hasColumn('clients', 'account_started_at')) {
                // Pra F8: base pra cronograma calcular "quantos dias desde entrada"
                $t->timestamp('account_started_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $t) {
            if (Schema::hasColumn('clients', 'survey')) $t->dropColumn('survey');
            if (Schema::hasColumn('clients', 'survey_skipped_at')) $t->dropColumn('survey_skipped_at');
            if (Schema::hasColumn('clients', 'account_started_at')) $t->dropColumn('account_started_at');
        });
    }
};
