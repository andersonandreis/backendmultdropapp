<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imported_product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('imported_product_id')->constrained('imported_products')->onDelete('cascade');
            $table->string('shopify_variant_id')->nullable();
            $table->string('title');
            $table->string('sku')->nullable();
            $table->decimal('cost_usd', 10, 4)->default(0);
            $table->decimal('sell_price', 10, 4)->default(0);
            $table->integer('stock')->default(0);
            $table->string('option1')->nullable();
            $table->string('option2')->nullable();
            $table->string('option3')->nullable();
            $table->timestamps();

            $table->index('imported_product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imported_product_variants');
    }
};
