<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-073: extrato de creditos IA (debitos por video, ajustes admin, grants de plano)
 * + coluna usage_tokens em ai_generations (consumo real usage.completion_tokens).
 * Aditiva — nao toca em nada existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_credit_transactions')) {
            Schema::create('ai_credit_transactions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('generation_id')->nullable()->index();
                $table->integer('delta');                       // negativo = debito
                $table->integer('balance_after');
                $table->string('reason', 40)->default('debit_video'); // debit_video|admin_adjust|monthly_grant|purchase
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('ai_generations') && ! Schema::hasColumn('ai_generations', 'usage_tokens')) {
            Schema::table('ai_generations', function (Blueprint $table) {
                $table->unsignedBigInteger('usage_tokens')->nullable()->after('cost_usd');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_credit_transactions');
        if (Schema::hasTable('ai_generations') && Schema::hasColumn('ai_generations', 'usage_tokens')) {
            Schema::table('ai_generations', function (Blueprint $table) {
                $table->dropColumn('usage_tokens');
            });
        }
    }
};
