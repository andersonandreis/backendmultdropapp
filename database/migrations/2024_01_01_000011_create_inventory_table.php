<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('suppliers');
            $table->foreignId('product_id')->constrained();
            $table->foreignId('producer_id')->constrained('suppliers');
            $table->integer('quantity')->default(0);
            $table->integer('reserved')->default(0);
            $table->decimal('warehouse_price', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory');
    }
};
