<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'legacy_id')) {
                $table->unsignedBigInteger('legacy_id')->nullable()->after('id')->index()
                    ->comment('id do pedido na tabela legada');
            }
        });

        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'legacy_id_login')) {
                $table->unsignedInteger('legacy_id_login')->nullable()->after('id')->index()
                    ->comment('id_login do cliente na tabela legada');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'legacy_id')) {
                $table->dropColumn('legacy_id');
            }
        });

        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'legacy_id_login')) {
                $table->dropColumn('legacy_id_login');
            }
        });
    }
};
