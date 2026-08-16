<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-040: junção admin ↔ user pra permissões e limites de IA.
 * - plans.ai_features: features globais liberadas no plano
 * - plans.ai_monthly_video_limit / ai_monthly_credits: cotas mensais
 * - users.ai_features_extra: overrides individuais (admin libera pra 1 user)
 * - users.ai_features_blocked: overrides individuais (admin bloqueia)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (!Schema::hasColumn('plans', 'ai_features')) $table->json('ai_features')->nullable();
            if (!Schema::hasColumn('plans', 'ai_monthly_video_limit')) $table->integer('ai_monthly_video_limit')->default(0);
            if (!Schema::hasColumn('plans', 'ai_monthly_credits')) $table->integer('ai_monthly_credits')->default(0);
        });
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'ai_features_extra')) $table->json('ai_features_extra')->nullable();
            if (!Schema::hasColumn('users', 'ai_features_blocked')) $table->json('ai_features_blocked')->nullable();
            if (!Schema::hasColumn('users', 'ai_credits_balance')) $table->integer('ai_credits_balance')->default(0);
        });
    }
    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['ai_features', 'ai_monthly_video_limit', 'ai_monthly_credits']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['ai_features_extra', 'ai_features_blocked', 'ai_credits_balance']);
        });
    }
};
