<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producer_id')->constrained('suppliers');
            $table->foreignId('warehouse_id')->constrained('suppliers');
            $table->string('type'); // sale, withdrawal, adjustment
            $table->decimal('amount', 10, 2);
            $table->string('description')->nullable();
            $table->unsignedBigInteger('order_id')->nullable(); // Set FK after orders is created
            $table->unsignedBigInteger('withdrawal_request_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_transactions');
    }
};
