<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adiciona unique index composto (client_id + custom_sku) em client_products.
     * Garante que dois clientes distintos podem usar o mesmo SKU,
     * mas um mesmo cliente nao pode ter o mesmo SKU em dois produtos.
     */
    public function up(): void
    {
        Schema::table('client_products', function (Blueprint $table) {
            $table->unique(['client_id', 'custom_sku'], 'client_products_client_sku_unique');
        });
    }

    public function down(): void
    {
        Schema::table('client_products', function (Blueprint $table) {
            $table->dropUnique('client_products_client_sku_unique');
        });
    }
};
