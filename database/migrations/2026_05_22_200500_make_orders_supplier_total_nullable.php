<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * orders.supplier_total era NOT NULL. O ImportLegacyOrdersJob grava null
 * quando o pedido nao tem custo de fornecedor no legado — o insert/update
 * quebrava (capturado pelo try/catch), deixando o pedido sem itens.
 *
 * Mesmo motivo de 2026_05_22_200000 (order_items). null = "custo
 * desconhecido no legado", diferente de 0 = "custo zero".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('supplier_total', 10, 2)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('supplier_total', 10, 2)->default(0)->change();
        });
    }
};
