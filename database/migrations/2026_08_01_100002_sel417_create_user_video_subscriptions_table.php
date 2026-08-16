<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-417: Assinaturas de video por usuario.
 * Registra o plano ativo, ciclo atual e contador de videos gerados no mes.
 * Guard Schema::hasTable previne erro em ambientes que ja tiverem a tabela.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable("user_video_subscriptions")) {
            return;
        }

        Schema::create("user_video_subscriptions", function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger("user_id")->index();
            $table->foreign("user_id")->references("id")->on("users")->onDelete("cascade");
            $table->string("plan_slug", 30)->default("free")
                  ->comment("Referencia ao slug em video_subscription_plans");
            $table->string("status", 20)->default("active")
                  ->comment("active|canceled|trialing");
            $table->string("asaas_subscription_id", 100)->nullable()
                  ->comment("ID da assinatura no Asaas (null para plano free)");
            $table->timestamp("cycle_started_at")->nullable()
                  ->comment("Inicio do ciclo de cobranca atual");
            $table->timestamp("cycle_ends_at")->nullable()
                  ->comment("Fim do ciclo (null = sem expiracao para free)");
            $table->unsignedSmallInteger("videos_used_this_cycle")->default(0)
                  ->comment("Contador de videos gerados no ciclo atual");
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("user_video_subscriptions");
    }
};

