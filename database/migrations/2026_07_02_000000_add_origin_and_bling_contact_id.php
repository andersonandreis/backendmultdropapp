<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MUL-107 — Bling: campos completos ao exportar produto
 *
 * products.origin: origem fiscal SPED (0=nacional, 1-8=importado) — padrão 0
 * erp_accounts.bling_supplier_contact_id: cache do ID do contato-fornecedor no Bling do lojista
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Origem fiscal SPED: 0=Nacional, 1=Importado direto, 2..8 variações
            $table->tinyInteger('origin')->default(0)->after('ncm');
        });

        Schema::table('erp_accounts', function (Blueprint $table) {
            // Cache do ID do contato-fornecedor criado no Bling do lojista (evita POST /contatos repetido)
            $table->unsignedBigInteger('bling_supplier_contact_id')->nullable()->after('bling_seller_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('origin');
        });

        Schema::table('erp_accounts', function (Blueprint $table) {
            $table->dropColumn('bling_supplier_contact_id');
        });
    }
};
