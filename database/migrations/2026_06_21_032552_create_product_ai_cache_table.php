<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_ai_cache', function (Blueprint $table) {
            $table->id();
            $table->string('sku_codigo', 100)->index();
            $table->string('marketplace', 20);
            $table->string('title', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('suggested_category', 50)->nullable();
            $table->json('attributes')->nullable();
            $table->timestamp('generated_at')->useCurrent();

            $table->index(['sku_codigo', 'marketplace'], 'idx_sku_market');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_ai_cache');
    }
};
