<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_supplier_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();

            // credit (devolução) ou debit (compra com desconto da wallet)
            $table->string('type');

            $table->decimal('amount', 12, 2);
            $table->string('description')->nullable();

            $table->unsignedBigInteger('order_id')->nullable();
            // opcional caso gerado a partir de logistica reversa ou uso no checkout

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_supplier_transactions');
    }
};
