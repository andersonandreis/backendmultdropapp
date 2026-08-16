<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variation_id')->nullable()->constrained()->nullOnDelete();

            $table->string('sku');
            $table->string('variation_sku')->nullable();
            $table->string('name');
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('total', 10, 2);
            $table->decimal('supplier_unit_cost', 10, 2)->default(0);
            $table->decimal('supplier_total_cost', 10, 2)->default(0);

            $table->string('external_item_id')->nullable();
            $table->string('external_variation_id')->nullable();
            $table->decimal('sale_fee', 10, 2)->nullable();
            $table->string('listing_type_id')->nullable();
            $table->timestamp('scanned_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
