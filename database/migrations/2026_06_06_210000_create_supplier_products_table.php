<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop Internacional - Fase 2
 * Tabela de produtos minerados de fornecedores externos (CJ, AliExpress, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('supplier_slug');
            $table->string('external_id');
            $table->string('title');
            $table->longText('description')->nullable();
            $table->json('images')->nullable();
            $table->json('variants')->nullable();
            $table->decimal('cost_usd', 10, 4);
            $table->decimal('shipping_usd', 10, 4)->default(0);
            $table->integer('estimated_days')->nullable();
            $table->decimal('rating', 3, 1)->nullable();
            $table->integer('sales_count')->nullable();
            $table->text('supplier_url')->nullable();
            $table->string('category')->nullable();
            $table->integer('risk_score')->default(0);
            $table->timestamp('last_fetched_at')->nullable();
            $table->timestamps();

            $table->foreign('client_id')
                  ->references('id')
                  ->on('clients')
                  ->onDelete('cascade');

            $table->unique(['client_id', 'supplier_slug', 'external_id'], 'sup_products_client_supplier_ext_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_products');
    }
};
