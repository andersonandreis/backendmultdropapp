<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('marketplace_fees', function (Blueprint $table) {
            $table->id();
            $table->string('platform'); // mercadolivre, shopee
            $table->string('category_id')->nullable();
            $table->string('category_name');
            $table->string('listing_type_id')->nullable(); // gold_special, gold_pro, free
            $table->decimal('fee_percentage', 5, 2)->default(0);
            $table->decimal('fixed_fee', 10, 2)->default(0);
            $table->string('shipping_fee_type')->nullable();
            $table->decimal('min_price', 10, 2)->nullable();
            $table->decimal('max_price', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_fees');
    }
};
