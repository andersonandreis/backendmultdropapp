<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imported_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            $table->foreignId('drop_store_id')->constrained('drop_stores')->onDelete('cascade');
            $table->string('supplier_slug')->nullable();
            $table->string('external_supplier_id')->nullable();
            $table->string('shopify_product_id')->nullable();
            $table->string('title');
            $table->string('title_ai')->nullable();
            $table->longText('description')->nullable();
            $table->longText('description_ai')->nullable();
            $table->json('images')->nullable();
            $table->json('variants_data')->nullable();
            $table->decimal('cost_usd', 10, 4)->default(0);
            $table->decimal('shipping_usd', 10, 4)->default(0);
            $table->decimal('sell_price', 10, 4)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->decimal('markup_pct', 5, 2)->default(0);
            $table->decimal('margin_usd', 10, 4)->default(0);
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->timestamp('shopify_published_at')->nullable();
            $table->timestamps();

            $table->index('client_id');
            $table->index('drop_store_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imported_products');
    }
};
