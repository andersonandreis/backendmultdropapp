<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela de codigos de barras por produto.
 *
 * Permite multiplos barcodes por produto (EAN13, QR, DUN14, etc.),
 * usados no fluxo de picking/packing via scanner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')
                  ->constrained('products')
                  ->cascadeOnDelete();
            $table->string('barcode', 128)->index();
            $table->string('type', 20)->default('EAN13');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_barcodes');
    }
};
