<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOV-115 — Histórico de movimentação de estoque (replica estoque_real do legado).
 *
 * Log imutável (sem updated_at). Toda alteração em Inventory.quantity gera 1 linha.
 * Filtrado por tenant via TenantSupplierScope (coluna supplier_id).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variation_id')->nullable();
            $table->unsignedBigInteger('inventory_id');
            $table->enum('type', [
                'entrada',
                'saida',
                'ajuste',
                'venda',
                'devolucao',
                'zerar',
                'sync_marketplace',
            ]);
            $table->integer('qty_before');
            $table->integer('qty_change');
            $table->integer('qty_after');
            $table->string('reference_type', 64)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('marketplace', 32)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['supplier_id', 'created_at'], 'idx_im_supp_created');
            $table->index(['product_id', 'created_at'], 'idx_im_prod_created');
            $table->index(['type', 'created_at'], 'idx_im_type_created');
            $table->index(['inventory_id', 'created_at'], 'idx_im_inv_created');
            $table->index(['reference_type', 'reference_id'], 'idx_im_ref');

            $table->foreign('supplier_id')->references('id')->on('suppliers')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('inventory_id')->references('id')->on('inventory')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
