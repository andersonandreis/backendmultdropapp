<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HUB-080 Fase 1 — adiciona coluna `service` na tabela clients.
 *
 * O frontend hubApiV2.ts espera client.service para identificar o whitelabel
 * do usuario (ex: 'hubai', 'pluglar', 'mestoredrop', etc.).
 * No legado, esse dado vem da coluna `loja.servico` do MySQL tudoonline.
 * O padrao para usuarios do goolhub.io/hubai.io e 'hubai'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'service')) {
                $table->string('service', 60)->default('hubai')->after('legacy_id_login')
                    ->comment('Identificador do whitelabel do cliente (hubai, pluglar, mestoredrop, etc.)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'service')) {
                $table->dropColumn('service');
            }
        });
    }
};
