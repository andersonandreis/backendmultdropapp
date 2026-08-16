<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Torna supplier_id nullable em orders.
     *
     * Clientes que vendem produtos proprios (sem fornecedor HubAI)
     * conectam suas contas ML/Shopee diretamente nao possuem supplier_id.
     * O SyncMLOrdersJob falhava com constraint violation ao tentar gravar
     * pedidos de contas com supplier_id NULL (ex: client_id 22492, ma_id 162).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign('orders_supplier_id_foreign');
            $table->unsignedBigInteger('supplier_id')->nullable()->change();
            $table->foreign('supplier_id')->references('id')->on('suppliers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign('orders_supplier_id_foreign');
            $table->unsignedBigInteger('supplier_id')->nullable(false)->change();
            $table->foreign('supplier_id')->references('id')->on('suppliers');
        });
    }
};
