<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_discount_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_discount_id')->constrained()->cascadeOnDelete();
            $table->integer('min_quantity')->nullable();
            $table->integer('max_quantity')->nullable();
            $table->decimal('min_value', 12, 2)->nullable();
            $table->decimal('max_value', 12, 2)->nullable();
            $table->string('discount_type')->default('percentage'); // percentage, fixed
            $table->decimal('discount_value', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_discount_tiers');
    }
};
