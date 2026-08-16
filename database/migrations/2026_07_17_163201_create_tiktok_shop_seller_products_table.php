<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-198: pivot denormalizado seller <-> produto scrapado.
 * product_json = payload bruto do produto (titulo, imagem, URL).
 * Atualizado diariamente junto com o seller.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tiktok_shop_seller_products', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignId('seller_id')
              ->constrained('tiktok_shop_sellers')
              ->cascadeOnDelete();
            $t->string('external_product_id', 120)->nullable()->index();
            $t->text('product_json');                 // JSON denorm: title, image, url, category
            $t->unsignedInteger('sales_count')->default(0);
            $t->decimal('rating', 3, 2)->nullable();
            $t->decimal('price', 12, 2)->nullable();
            $t->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $t->index(['seller_id', 'sales_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_shop_seller_products');
    }
};
