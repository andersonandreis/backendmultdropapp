<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FOR-053: add client_product_id a sync_logs.
 *
 * A coluna foi adicionada via SQL direto no Fornecefy (02/07/2026).
 * Esta migration registra a mudanca no schema formal e aplica
 * nos outros bancos (hubaiapp, multdrop, mestoredrop) de forma
 * idempotente (com hasColumn guard).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sync_logs', 'client_product_id')) {
            return; // ja existe (ex Fornecefy adicionado via SQL direto)
        }

        Schema::table('sync_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('client_product_id')->nullable()->after('id')->index();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('sync_logs', 'client_product_id')) {
            return;
        }
        Schema::table('sync_logs', function (Blueprint $table) {
            $table->dropColumn('client_product_id');
        });
    }
};
