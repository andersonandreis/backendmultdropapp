<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MUL-264/265: colunas de config Bling do fornecedor (idempotente pros 7 backends).
 *
 * Contexto: MUL-252-C criou essas colunas via ALTER TABLE direto no HUB (hubaiapp),
 * sem migration versionada. Os outros 5 backends (multdrop/seller/fornecefy/jt/mestoredrop)
 * ficaram sem elas. Esta migration adiciona idempotente pra normalizar todos.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('erp_accounts', function (Blueprint $t) {
            if (!Schema::hasColumn('erp_accounts', 'sku_prefixes_to_strip')) {
                $t->string('sku_prefixes_to_strip', 255)->nullable()->after('api_version');
            }
            if (!Schema::hasColumn('erp_accounts', 'auto_sync_orders')) {
                $t->boolean('auto_sync_orders')->default(false);
            }
            if (!Schema::hasColumn('erp_accounts', 'nfe_saida_trigger')) {
                $t->enum('nfe_saida_trigger', ['off','paid','label_printed','shipped'])->default('off');
            }
            if (!Schema::hasColumn('erp_accounts', 'nfe_entrada_trigger')) {
                $t->enum('nfe_entrada_trigger', ['off','paid','label_printed','shipped'])->default('off');
            }
        });
    }

    public function down(): void
    {
        // Sem rollback: colunas mantidas por segurança (dados de config podem ter sido preenchidos).
    }
};
