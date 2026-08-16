<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_discount_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_discount_id')->constrained()->cascadeOnDelete();
            $table->integer('from_order');
            $table->integer('to_order')->nullable();
            $table->string('discount_type')->default('percentage'); // percentage, fixed
            $table->decimal('discount_value', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_discount_tiers');
    }
};
