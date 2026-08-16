<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona colunas ausentes nas migrations originais:
 *  - client_products.excluido: soft-delete legado (usado em ClientProductResource::planInfo)
 *  - client_products.supplier_unit_cost: custo do fornecedor lido pelo ManualOrderController
 *
 * BUG-1: ManualOrderController::store() le supplier_unit_cost de
 *        $cp->product->supplier_unit_cost (inexistente em Product — o campo correto e cost)
 *        e de $cp->getAttribute(supplier_unit_cost) (coluna inexistente em client_products).
 *        Esta migration adiciona a coluna como workaround ate o controller ser corrigido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table("client_products", function (Blueprint $table) {
            if (!Schema::hasColumn("client_products", "excluido")) {
                $table->tinyInteger("excluido")->default(0)->after("is_active")
                    ->comment("Soft-delete legado: 0=ativo, 1=excluido");
            }
            if (!Schema::hasColumn("client_products", "supplier_unit_cost")) {
                $table->decimal("supplier_unit_cost", 10, 2)->nullable()->after("excluido")
                    ->comment("Custo unitario do fornecedor (lido pelo ManualOrderController)");
            }
        });
    }

    public function down(): void
    {
        Schema::table("client_products", function (Blueprint $table) {
            if (Schema::hasColumn("client_products", "excluido")) {
                $table->dropColumn("excluido");
            }
            if (Schema::hasColumn("client_products", "supplier_unit_cost")) {
                $table->dropColumn("supplier_unit_cost");
            }
        });
    }
};
