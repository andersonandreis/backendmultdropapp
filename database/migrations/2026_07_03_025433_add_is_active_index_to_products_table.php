<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Índice em products.is_active para acelerar queries de catálogo filtradas
     * por supplier + is_active (70k+ produtos no supplier 10).
     * Diagnóstico: NOV-177 — sem índice, COUNT leva ~108ms; com índice, cai para ~5ms.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Índice composto (supplier_id, is_active) cobre a query:
            // WHERE is_active = 1 AND supplier_id = ?
            // Muito mais seletivo que is_active sozinho.
            $table->index(['supplier_id', 'is_active'], 'products_supplier_active_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_supplier_active_index');
        });
    }
};
