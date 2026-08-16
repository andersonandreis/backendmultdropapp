<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-186: adiciona applies_to_plan_slug e description na tabela coupons.
 *
 * applies_to_plan_slug: se preenchido, cupom so vale pra assinatura desse plano.
 *   NULL = vale pra qualquer plano.
 *   Uso tipico: 'premium' para cupons de lancamento do plano unico.
 *
 * description: texto interno pro Ruan anotar o contexto do cupom
 *   (ex: "grupo VIP jul/26 - 50% off 3 meses").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            if (!Schema::hasColumn('coupons', 'applies_to_plan_slug')) {
                $table->string('applies_to_plan_slug')->nullable()->after('is_active')
                    ->comment('Se preenchido, cupom so e valido para o plano com este slug');
            }
            if (!Schema::hasColumn('coupons', 'description')) {
                $table->text('description')->nullable()->after('code')
                    ->comment('Anotacao interna (contexto, campanha, grupo)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            if (Schema::hasColumn('coupons', 'applies_to_plan_slug')) {
                $table->dropColumn('applies_to_plan_slug');
            }
            if (Schema::hasColumn('coupons', 'description')) {
                $table->dropColumn('description');
            }
        });
    }
};
