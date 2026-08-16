<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backend de Kits (Fase 5) — substitui localStorage do /kits.
 *
 * Um kit agrupa N produtos do catalogo do cliente em um SKU
 * "virtual" que pode ser vendido como bundle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_kits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('name', 200);
            $table->string('sku', 100)->nullable();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['client_id', 'is_active']);
            $table->unique(['client_id', 'sku']);
        });

        Schema::create('client_kit_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kit_id')->constrained('client_kits')->cascadeOnDelete();
            $table->unsignedBigInteger('client_product_id');
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();

            $table->index('kit_id');
            $table->index('client_product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_kit_items');
        Schema::dropIfExists('client_kits');
    }
};
