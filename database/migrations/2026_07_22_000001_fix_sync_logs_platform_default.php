<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-319: sync_logs.platform sem default acarreta SQLSTATE 1364 no fluxo de publicacao.
 * Adiciona default '' para evitar erro quando caller nao fornece platform.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sync_logs', function (Blueprint $table) {
            $table->string('platform')->default('')->change();
        });
    }

    public function down(): void
    {
        Schema::table('sync_logs', function (Blueprint $table) {
            $table->string('platform')->default(null)->change();
        });
    }
};
