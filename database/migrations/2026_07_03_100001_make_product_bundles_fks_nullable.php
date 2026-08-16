<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MES-046-E: Torna parent_product_id e component_product_id nullable em product_bundles.
 * Necessario para suportar kits com estrutura propria (sem produto pai no novo catalogo).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('product_bundles', function (Blueprint $table) {
            // Dropar a FK e o indice existente para permitir alter
            $table->dropForeign(['parent_product_id']);
            $table->dropForeign(['component_product_id']);

            $table->unsignedBigInteger('parent_product_id')->nullable()->change();
            $table->unsignedBigInteger('component_product_id')->nullable()->change();

            // Recriar FKs como nullable
            $table->foreign('parent_product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('component_product_id')->references('id')->on('products')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_bundles', function (Blueprint $table) {
            $table->dropForeign(['parent_product_id']);
            $table->dropForeign(['component_product_id']);

            $table->unsignedBigInteger('parent_product_id')->nullable(false)->change();
            $table->unsignedBigInteger('component_product_id')->nullable(false)->change();

            $table->foreign('parent_product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('component_product_id')->references('id')->on('products')->cascadeOnDelete();
        });
    }
};
