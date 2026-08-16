<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-237 Ruan 18/07/2026 — produtos que cada criador IA vende no TikTok Shop.
 *
 * Preenchido pelo command ai-creators:fetch-products (dailyAt 07:30 BRT).
 * Fluxo: pra cada ai_creator, busca tikwm/api/user/videos → extrai
 * anchors type_id=2 (product) → grava row por (creator, product_id) único.
 *
 * Frontend usa isso pra mostrar catálogo REAL de produtos do criador no modal.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_creator_products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ai_creator_id')->constrained('ai_creators')->cascadeOnDelete();
            $t->string('product_id', 128);
            $t->string('shop_id', 128)->nullable();
            $t->string('title', 500)->nullable();
            $t->string('image_url', 1024)->nullable();
            $t->decimal('price', 12, 2)->nullable();
            $t->string('currency', 8)->nullable();
            $t->decimal('rating', 3, 2)->nullable();
            $t->unsignedInteger('sold_count')->nullable();
            $t->string('product_url', 1024)->nullable();
            $t->string('shop_name', 255)->nullable();
            $t->text('raw')->nullable();
            $t->timestamp('scraped_at')->useCurrent();
            $t->timestamps();

            $t->unique(['ai_creator_id', 'product_id'], 'uk_creator_product');
            $t->index('shop_id');
            $t->index('scraped_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_creator_products');
    }
};
