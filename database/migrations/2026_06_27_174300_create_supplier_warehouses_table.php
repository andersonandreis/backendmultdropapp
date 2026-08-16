<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOV-145 — Depositos / fornecedores parceiros do supplier (legado: depositos).
 *
 * Cada supplier pode ter N depositos fisicos (matriz, filial, dropshipping etc).
 * Mapeamento com legado via legacy_deposito_id.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->unsignedBigInteger('legacy_deposito_id')->nullable()->index();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('number')->nullable();
            $table->string('complement')->nullable();
            $table->string('district')->nullable();
            $table->string('city')->nullable();
            $table->string('state', 2)->nullable();
            $table->string('zip_code', 16)->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['supplier_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_warehouses');
    }
};
