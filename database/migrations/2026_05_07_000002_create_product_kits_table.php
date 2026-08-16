<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_kits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('child_product_id')->constrained('products')->cascadeOnDelete();
            $table->integer('quantity')->default(1);
            $table->timestamps();
            $table->unique(['product_id', 'child_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_kits');
    }
};
