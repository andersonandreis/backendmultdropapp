<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOV-142 — Adicionar limite generico de integracoes aos planos.
 *
 * A tabela 'plans' ja tem max_marketplace_connections e max_erp_connections.
 * Adicionando max_integrations como teto geral (-1 = ilimitado) usado pelo
 * legado MEStoreDrop.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (!Schema::hasColumn('plans', 'max_integrations')) {
                $table->integer('max_integrations')->nullable()->after('max_erp_connections');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (Schema::hasColumn('plans', 'max_integrations')) {
                $table->dropColumn('max_integrations');
            }
        });
    }
};
