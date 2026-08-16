<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SEL-103 Ruan 22:40: comissão paga pelo fornecedor ao seller (0-100%).
// Permite ordenar/filtrar Lista de Fornecedores por quem paga mais.
return new class extends Migration {
    public function up(): void {
        if (Schema::hasTable('directory_suppliers') && !Schema::hasColumn('directory_suppliers', 'commission_percent')) {
            Schema::table('directory_suppliers', function (Blueprint $t) {
                $t->decimal('commission_percent', 5, 2)->nullable()->after('commercial_terms')->index();
            });
        }
    }
    public function down(): void {
        if (Schema::hasTable('directory_suppliers') && Schema::hasColumn('directory_suppliers', 'commission_percent')) {
            Schema::table('directory_suppliers', function (Blueprint $t) {
                $t->dropColumn('commission_percent');
            });
        }
    }
};
