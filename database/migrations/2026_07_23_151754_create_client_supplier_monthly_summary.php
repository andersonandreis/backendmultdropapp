<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MUL-262 Fase 2 camada 2: fechamento mensal.
 * Dashboards de "totais por mês" leem 1 row/mês em vez de varrer 16k+ tx.
 * Mês vigente atualiza via Observer (created); meses passados são imutáveis (regra de negócio).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_supplier_monthly_summary', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_id');
            $t->unsignedBigInteger('supplier_id');
            $t->char('year_month', 7); // YYYY-MM
            $t->decimal('credits_total', 12, 2)->default(0);
            $t->decimal('debits_total', 12, 2)->default(0);
            $t->decimal('closing_balance', 12, 2)->default(0);
            $t->unsignedInteger('tx_count')->default(0);
            $t->timestamps();

            $t->unique(['client_id', 'supplier_id', 'year_month'], 'csms_unique_period');
            $t->index(['client_id', 'supplier_id'], 'csms_pair_idx');
            $t->index('year_month', 'csms_ym_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_supplier_monthly_summary');
    }
};
