<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-047: Adiciona tiktok_connection_id em marketplace_accounts.
 *
 * Rastreia qual registro em tiktok_shop_connections originou esta conta espelho.
 * Padrao identico ao bling_access_token / ml_access_token: token fica no
 * campo access_token padrao; este campo e apenas ponteiro de auditoria.
 *
 * Nullable porque as outras plataformas nao tem esse campo. Sem FK para nao
 * depender de ordem de migration em bancos das WLs (que nao tem a tabela ainda).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('tiktok_connection_id')
                  ->nullable()
                  ->after('shop_id')
                  ->comment('SEL-047: FK logica para tiktok_shop_connections.id (sem FK real para portabilidade entre WLs)');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            $table->dropColumn('tiktok_connection_id');
        });
    }
};
