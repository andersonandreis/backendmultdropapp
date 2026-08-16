<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('marketplace_accounts', 'legacy_id')) {
                $table->unsignedInteger('legacy_id')->nullable()->after('id')->index();
            }
        });

        Schema::table('erp_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('erp_accounts', 'legacy_id')) {
                $table->unsignedInteger('legacy_id')->nullable()->after('id')->index();
            }
            if (! Schema::hasColumn('erp_accounts', 'supplier_id')) {
                $table->unsignedBigInteger('supplier_id')->nullable()->after('client_id')->index();
            }
            if (! Schema::hasColumn('erp_accounts', 'refresh_token')) {
                $table->text('refresh_token')->nullable()->after('api_key');
            }
            if (! Schema::hasColumn('erp_accounts', 'token_expires_at')) {
                $table->timestamp('token_expires_at')->nullable()->after('refresh_token');
            }
            if (! Schema::hasColumn('erp_accounts', 'account_name')) {
                $table->string('account_name', 255)->nullable()->after('platform');
            }
            if (! Schema::hasColumn('erp_accounts', 'bling_id_loja')) {
                $table->unsignedInteger('bling_id_loja')->nullable()->after('account_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('marketplace_accounts', 'legacy_id')) {
                $table->dropColumn('legacy_id');
            }
        });

        Schema::table('erp_accounts', function (Blueprint $table) {
            foreach (['legacy_id', 'supplier_id', 'refresh_token', 'token_expires_at', 'account_name', 'bling_id_loja'] as $col) {
                if (Schema::hasColumn('erp_accounts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
