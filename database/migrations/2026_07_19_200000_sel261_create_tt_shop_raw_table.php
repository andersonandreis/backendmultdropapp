<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-261 — snapshot cru do TikTok Shop BR (fonte primária nativa).
 *
 * Análogo a kalodata_raw mas com schema do TT Shop:
 * product_id, image.url_list[0], seller_info.shop_logo, seo_url.canonical_url,
 * product_price_info.sale_price_decimal, sold_info.sold_count, rate_info.score.
 *
 * Preenchido via TtShopImportJsonCommand (JSON dump gerado por Playwright).
 * Payload cru guardado pra retrocompat quando TT muda campos.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('tt_shop_raw')) {
            return;
        }

        Schema::create('tt_shop_raw', function (Blueprint $t) {
            $t->id();
            $t->enum('type', ['product', 'shop', 'category', 'search'])
              ->default('product');
            $t->date('snapshot_date');
            $t->string('category_slug', 128)->nullable();
            $t->string('external_id', 64)->nullable();
            $t->json('payload');
            $t->timestamps();

            $t->index(['type', 'snapshot_date']);
            $t->index(['type', 'external_id', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tt_shop_raw');
    }
};
