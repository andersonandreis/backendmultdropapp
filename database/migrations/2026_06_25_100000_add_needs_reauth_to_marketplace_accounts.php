<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('marketplace_accounts', 'needs_reauth')) {
                $table->tinyInteger('needs_reauth')->default(0)->after('last_error_message');
            }
        });

        // Marcar registros que precisam de reauth:
        // 1. Status pending (sem token - conta criada mas nao autenticada)
        // 2. Active sem ml_access_token e sem access_token (importados sem token)
        // 3. refresh_errors_count > 3 (token quebrado apos muitas tentativas)
        DB::statement("
            UPDATE marketplace_accounts
            SET needs_reauth = 1
            WHERE platform = 'mercadolivre'
              AND (
                status = 'pending'
                OR (status = 'active' AND ml_access_token IS NULL AND access_token IS NULL)
                OR refresh_errors_count > 3
              )
        ");
    }

    public function down(): void
    {
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('marketplace_accounts', 'needs_reauth')) {
                $table->dropColumn('needs_reauth');
            }
        });
    }
};