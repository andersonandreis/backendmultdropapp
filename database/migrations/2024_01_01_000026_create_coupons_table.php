<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('owner_type'); // platform, supplier
            $table->unsignedBigInteger('owner_id')->nullable(); // supplier_id
            $table->string('discount_type')->default('percentage'); // percentage, fixed
            $table->decimal('discount_value', 10, 2);
            $table->decimal('min_order_value', 10, 2)->nullable();
            $table->integer('max_uses')->nullable();
            $table->integer('max_uses_per_client')->nullable();
            $table->integer('uses_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
