<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-096 Ruan 20:20: liberação gradual de fornecedores.
 *
 * Cliente do plano "supplier_only" (R$49,90) tem acesso limitado — 50 novos
 * fornecedores/semana. Essa tabela rastreia quais IDs de directory_suppliers
 * já foram liberados pra cada cliente + a data.
 *
 * Também adiciona `supplier_unlock_starts_at` em clients (marca início da
 * assinatura pro cálculo de "6 dias pra liberar próxima leva").
 */
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('client_supplier_unlocks')) {
            Schema::create('client_supplier_unlocks', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('client_id');
                $t->unsignedBigInteger('directory_supplier_id');
                $t->timestamp('unlocked_at');
                $t->timestamps();
                $t->unique(['client_id', 'directory_supplier_id'], 'csu_unique');
                $t->index('client_id');
            });
        }
        if (Schema::hasTable('clients') && !Schema::hasColumn('clients', 'supplier_unlock_starts_at')) {
            Schema::table('clients', function (Blueprint $t) {
                $t->timestamp('supplier_unlock_starts_at')->nullable()->after('updated_at');
            });
        }
    }
    public function down(): void {
        Schema::dropIfExists('client_supplier_unlocks');
        if (Schema::hasTable('clients') && Schema::hasColumn('clients', 'supplier_unlock_starts_at')) {
            Schema::table('clients', function (Blueprint $t) {
                $t->dropColumn('supplier_unlock_starts_at');
            });
        }
    }
};
