<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('supplier_product_sku')->nullable();
            $table->string('custom_sku')->nullable();
            $table->string('custom_title')->nullable();
            $table->text('custom_description')->nullable();
            $table->decimal('custom_price', 10, 2)->nullable();
            $table->json('custom_attributes')->nullable();
            $table->string('pricing_mode')->default('manual'); // manual, margin
            $table->decimal('profit_margin', 5, 2)->nullable();
            $table->string('listing_type_id')->nullable();
            $table->string('external_listing_id')->nullable();
            $table->string('external_variation_id')->nullable();
            $table->string('external_category_id')->nullable();
            // marketplace_account_id added later or in a separate step if no cycles
            $table->unsignedBigInteger('marketplace_account_id')->nullable();
            $table->string('sync_status')->default('pending'); // pending, synced, error, paused
            $table->timestamp('last_sync_at')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_products');
    }
};
