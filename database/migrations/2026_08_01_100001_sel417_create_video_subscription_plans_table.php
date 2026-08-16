<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-417: Tabela de planos de assinatura do servico "Criador de Videos com IA".
 * Guard Schema::hasTable previne erro em ambientes que ja tiverem a tabela.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable("video_subscription_plans")) {
            return;
        }

        Schema::create("video_subscription_plans", function (Blueprint $table) {
            $table->id();
            $table->string("slug", 30)->unique()
                  ->comment("free|pro|turbo");
            $table->string("name", 60);
            $table->unsignedInteger("price_cents_monthly")->default(0)
                  ->comment("Preco mensal em centavos (0 = gratis)");
            $table->unsignedInteger("price_cents_yearly")->default(0)
                  ->comment("Preco anual em centavos (0 = gratis)");
            $table->unsignedSmallInteger("videos_per_month")
                  ->comment("Limite de videos gerados por ciclo mensal");
            $table->json("features_json")->nullable()
                  ->comment("Lista de recursos exibida na landing page");
            $table->boolean("is_featured")->default(false)
                  ->comment("Destaque visual na landing (Mais popular)");
            $table->boolean("is_active")->default(true);
            $table->unsignedTinyInteger("sort_order")->default(0)
                  ->comment("Ordem de exibicao crescente");
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("video_subscription_plans");
    }
};

