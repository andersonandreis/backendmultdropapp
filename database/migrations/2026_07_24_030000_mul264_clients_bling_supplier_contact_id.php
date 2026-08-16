<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MUL-264: cache do contato do seller no Bling do fornecedor.
 * MVP: 1 coluna por client (single-supplier WLs como MultDrop).
 * Se multi-fornecedor futuro, migrar pra tabela pivot.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('clients', function (Blueprint $t) {
            if (!Schema::hasColumn('clients','bling_supplier_contact_id')) {
                $t->unsignedBigInteger('bling_supplier_contact_id')->nullable();
                $t->index('bling_supplier_contact_id', 'clients_bling_supplier_contact_id_idx');
            }
        });
    }
    public function down(): void {}
};
