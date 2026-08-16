<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MUL-057 — Localização de produtos (warehouse_location)
     *
     * Adiciona campo de localização física no depósito ao catálogo de produtos.
     * Formato usado pela MultDrop: R{corredor}-T-L{lado}-P{posição} (ex: R2-T-LB-P5)
     *
     * Dados migrados do legado (sku_pai.local) via script de migração 2026-06-26.
     * 457 produtos atualizados com localização do depósito.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'warehouse_location')) {
                $table->string('warehouse_location', 255)->nullable()->after('category_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'warehouse_location')) {
                $table->dropColumn('warehouse_location');
            }
        });
    }
};
