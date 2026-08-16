<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_supplier', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->boolean('is_extra')->default(true)->comment('true = adicionado manualmente pelo admin; false = herdado do plano');
            $table->timestamps();

            $table->unique(['client_id', 'supplier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_supplier');
    }
};
