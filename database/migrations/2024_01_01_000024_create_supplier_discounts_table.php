<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('target')->default('all_clients'); // all_clients, specific_client, client_group
            $table->unsignedBigInteger('target_id')->nullable(); // client_id se 'specific_client'
            $table->string('trigger_type'); // volume_qty, volume_value, category, sku, first_buy, coupon
            $table->boolean('is_stackable')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_discounts');
    }
};
