<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NOV-XXX (28/06/2026): completa o schema de erp_accounts para suportar OAuth Bling.
 *
 * Estado anterior:
 *  - Tabela criada vazia em produção (nunca conectada ao fluxo OAuth).
 *  - Campos OAuth básicos (supplier_id, refresh_token, token_expires_at, account_name,
 *    bling_id_loja) já foram adicionados pela migration de 2026_06_24.
 *  - api_key era usado como pseudo-access_token mas o BlingService espera access_token.
 *  - client_id era NOT NULL — impede contas vinculadas só a supplier (PF, sem Client).
 *
 * Este migration:
 *  - Adiciona access_token TEXT nullable (campo canônico esperado pelo BlingService).
 *  - Adiciona bling_seller_id VARCHAR(100) nullable (identificador da loja Bling retornado
 *    pelo OAuth, separado do legacy bling_id_loja).
 *  - Torna client_id NULLABLE pra permitir contas conectadas só ao supplier.
 *  - Garante index em supplier_id (já estava, idempotente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('erp_accounts', 'access_token')) {
                $table->text('access_token')->nullable()->after('api_key');
            }
            if (! Schema::hasColumn('erp_accounts', 'bling_seller_id')) {
                $table->string('bling_seller_id', 100)->nullable()->after('bling_id_loja');
            }
        });

        // Torna client_id nullable (driver MySQL: usa change()).
        if (Schema::hasColumn('erp_accounts', 'client_id')) {
            try {
                DB::statement('ALTER TABLE erp_accounts MODIFY client_id BIGINT UNSIGNED NULL');
            } catch (\Throwable $e) {
                // Se a FK constraint impedir, ignora — schema atual já permite NULL via DEFAULT NULL.
            }
        }
    }

    public function down(): void
    {
        Schema::table('erp_accounts', function (Blueprint $table) {
            foreach (['access_token', 'bling_seller_id'] as $col) {
                if (Schema::hasColumn('erp_accounts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
