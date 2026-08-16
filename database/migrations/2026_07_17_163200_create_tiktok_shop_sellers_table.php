<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-198: tabela de sellers (lojistas) do TikTok Shop BR.
 * Alimentada por SyncTikTokShopSellersJob (diario 06:00 BRT via console.php).
 * Endpoint publico: GET /api/v1/tiktok/sellers
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tiktok_shop_sellers', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('handle', 120)->unique();          // ex: @loja_br_oficial
            $t->string('name', 255);                      // nome exibido da loja
            $t->string('avatar_url', 512)->nullable();
            $t->unsignedBigInteger('followers')->default(0);
            $t->unsignedInteger('products_count')->default(0);
            $t->decimal('avg_rating', 3, 2)->nullable();  // ex: 4.85
            $t->unsignedBigInteger('top_product_id')->nullable(); // FK soft para tiktok_shop_trends.id
            $t->decimal('gmv_estimate', 18, 2)->default(0); // GMV estimado R$
            $t->unsignedInteger('rank_position')->nullable(); // posicao no ranking (1=top)
            $t->string('category_l1', 120)->nullable();
            $t->json('raw')->nullable();                  // payload bruto do scrape
            $t->boolean('is_visible')->default(true);
            $t->timestamps();

            $t->index(['rank_position']);
            $t->index(['gmv_estimate']);
            $t->index(['avg_rating']);
            $t->index(['followers']);
            $t->index(['is_visible']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_shop_sellers');
    }
};
