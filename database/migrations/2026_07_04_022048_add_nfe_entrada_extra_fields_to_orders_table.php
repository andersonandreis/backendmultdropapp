<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MUL-161-BE1 #8/#9 — Adiciona campos de NF-e de entrada (fornecedor) e numero do pedido Bling.
 * Todos nullable para nao impactar pedidos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // NF-e entrada (fornecedor): campos complementares alem dos ja existentes
            if (!Schema::hasColumn('orders', 'nfe_entrada_access_key')) {
                $table->string('nfe_entrada_access_key', 44)->nullable()->after('nfe_entrada_updated_at');
            }
            if (!Schema::hasColumn('orders', 'nfe_entrada_pdf_url')) {
                $table->string('nfe_entrada_pdf_url', 500)->nullable()->after('nfe_entrada_access_key');
            }
            if (!Schema::hasColumn('orders', 'nfe_entrada_xml_url')) {
                $table->string('nfe_entrada_xml_url', 500)->nullable()->after('nfe_entrada_pdf_url');
            }
            // Numero do pedido de venda Bling (ex: -260601-0168) — ja existe como order_number,
            // mas este campo garante preservacao mesmo quando source != bling (cross-update via Bling ERP).
            if (!Schema::hasColumn('orders', 'bling_order_number')) {
                $table->string('bling_order_number', 50)->nullable()->after('bling_order_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach (['nfe_entrada_access_key', 'nfe_entrada_pdf_url', 'nfe_entrada_xml_url', 'bling_order_number'] as $col) {
                if (Schema::hasColumn('orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
