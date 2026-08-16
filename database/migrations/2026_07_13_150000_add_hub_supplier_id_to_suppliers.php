<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MUL-225: espelhamento hub→WL de fornecedores
// hub_supplier_id no WL aponta pro id do supplier no HUB.
// Permite que WL espelhe múltiplos catálogos do HUB (ex.: MultDrop + Multdrop Filial).
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('suppliers', 'hub_supplier_id')) {
            Schema::table('suppliers', function (Blueprint $t) {
                $t->unsignedBigInteger('hub_supplier_id')->nullable()->after('legacy_loja_id');
                $t->index('hub_supplier_id');
            });
        }
    }
    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $t) {
            $t->dropIndex(['hub_supplier_id']);
            $t->dropColumn('hub_supplier_id');
        });
    }
};
