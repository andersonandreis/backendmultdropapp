<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona supplier_id na tabela operators para isolamento multi-tenant.
 *
 * Cada operador pertence a um supplier (fornecedor/deposito).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operators', function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_id')->nullable()->after('id');
            $table->index('supplier_id', 'operators_supplier_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('operators', function (Blueprint $table) {
            $table->dropIndex('operators_supplier_id_index');
            $table->dropColumn('supplier_id');
        });
    }
};
