<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_supplier_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();

            // Créditos acumulados de devoluções, abatimentos, etc.
            $table->decimal('balance', 12, 2)->default(0);
            $table->timestamps();

            // Um Lojista tem apenas 1 saldo por Galpão
            $table->unique(['client_id', 'supplier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_supplier_balances');
    }
};
