<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * order_items.supplier_unit_cost / supplier_total_cost eram NOT NULL
 * (decimal default 0). O ImportLegacyOrdersJob grava null quando o item
 * nao tem custo no legado — o insert quebrava silenciosamente (capturado
 * pelo try/catch do job), deixando o pedido SEM itens.
 *
 * Tornar nullable: null = "custo desconhecido no legado" (correto),
 * diferente de 0 = "custo zero".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('supplier_unit_cost', 10, 2)->nullable()->default(null)->change();
            $table->decimal('supplier_total_cost', 10, 2)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('supplier_unit_cost', 10, 2)->default(0)->change();
            $table->decimal('supplier_total_cost', 10, 2)->default(0)->change();
        });
    }
};
