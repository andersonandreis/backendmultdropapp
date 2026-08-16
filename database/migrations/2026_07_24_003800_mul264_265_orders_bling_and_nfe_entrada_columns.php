<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MUL-264/265: colunas em orders para sync Bling + NF-e entrada (idempotente 7 backends).
 *
 * Contexto: MUL-252-C criou essas colunas via ALTER TABLE direto no HUB.
 * Outros 5 backends (multdrop/seller/fornecefy/jt/mestoredrop) ficaram sem elas.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            if (!Schema::hasColumn('orders', 'bling_pedido_id')) {
                $t->unsignedBigInteger('bling_pedido_id')->nullable();
                $t->index('bling_pedido_id', 'orders_bling_pedido_id_idx');
            }
            if (!Schema::hasColumn('orders', 'bling_pedido_url')) {
                $t->string('bling_pedido_url', 255)->nullable();
            }
            if (!Schema::hasColumn('orders', 'bling_synced_at')) {
                $t->timestamp('bling_synced_at')->nullable();
            }
            if (!Schema::hasColumn('orders', 'nfe_entrada_number')) {
                $t->string('nfe_entrada_number', 20)->nullable();
            }
            if (!Schema::hasColumn('orders', 'nfe_entrada_access_key')) {
                $t->char('nfe_entrada_access_key', 44)->nullable();
            }
            if (!Schema::hasColumn('orders', 'nfe_entrada_received_at')) {
                $t->timestamp('nfe_entrada_received_at')->nullable();
            }
            if (!Schema::hasColumn('orders', 'nfe_entrada_updated_at')) {
                $t->timestamp('nfe_entrada_updated_at')->nullable();
            }
            if (!Schema::hasColumn('orders', 'nfe_entrada_pdf_url')) {
                $t->string('nfe_entrada_pdf_url', 500)->nullable();
            }
            if (!Schema::hasColumn('orders', 'nfe_entrada_xml_url')) {
                $t->string('nfe_entrada_xml_url', 500)->nullable();
            }
        });
    }

    public function down(): void { /* sem rollback: dados de config podem existir */ }
};
