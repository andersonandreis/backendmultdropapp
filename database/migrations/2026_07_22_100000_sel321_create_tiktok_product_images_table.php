<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-321: Imagens multi-fonte de produto TikTok Shop.
 * Persiste fotos do anúncio e avaliações para alimentar geração de vídeo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_product_images', function (Blueprint $table) {
            $table->id();
            $table->string('product_key', 64)->index();
            $table->enum('source', ['kalodata', 'catalog', 'listing', 'review', 'upload']);
            $table->string('url_original', 2048)->nullable();
            $table->string('url_local', 2048)->nullable();
            $table->unsignedTinyInteger('quality_score')->default(50)
                  ->comment('0-100: upload=90, kalodata=80, listing=70, review=60, catalog=50');
            $table->string('scrape_status', 20)->default('pending');
            $table->text('scrape_error')->nullable();
            $table->timestamps();
            $table->index(['product_key', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_product_images');
    }
};
