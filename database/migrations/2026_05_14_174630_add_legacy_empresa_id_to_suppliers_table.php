<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adiciona campo legacy_empresa_id na tabela suppliers.
     * Permite mapear um supplier do novo HubAI para o id_empresa do banco legado (tudoonline_production).
     * MultDrop (supplier id=30) = empresa 24 no legado.
     */
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->unsignedInteger('legacy_empresa_id')->nullable()->after('user_id')
                ->comment('ID da empresa no banco legado tudoonline_production (tabela login.id_empresa)');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('legacy_empresa_id');
        });
    }
};
