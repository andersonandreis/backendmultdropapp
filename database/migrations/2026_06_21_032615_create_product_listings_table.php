<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_listings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('client_id')->index('idx_client');
            $table->string('sku_codigo', 100)->index('idx_sku');
            $table->string('marketplace', 20);
            $table->string('item_id', 50)->nullable();
            $table->string('listing_url', 500)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_listings');
    }
};
