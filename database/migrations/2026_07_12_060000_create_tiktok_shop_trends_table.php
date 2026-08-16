<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-044: cache de tendencias raspadas do TikTok Shop Seller Center.
 * Alimentado por script Playwright do lado cliente (posta via /api/v1/admin/trends/tiktok-shop/ingest).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tiktok_shop_trends', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('kind', 24)->index();          // product|creator|live
            $t->string('external_id', 64)->nullable()->index();
            $t->string('title', 255);
            $t->json('images')->nullable();
            $t->string('category_l1', 120)->nullable();
            $t->string('category_l2', 120)->nullable();
            $t->string('category_l3', 120)->nullable();
            $t->string('search_volume', 32)->nullable();
            $t->string('online_products', 32)->nullable();
            $t->string('sales_l30d', 32)->nullable();
            $t->string('gmv_l30d', 40)->nullable();
            $t->string('potential_score', 32)->nullable();
            $t->string('orders', 32)->nullable();
            $t->string('gmv', 40)->nullable();
            $t->json('raw')->nullable();
            $t->timestamp('captured_at')->index();
            $t->timestamps();
            $t->index(['kind','captured_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('tiktok_shop_trends'); }
};
