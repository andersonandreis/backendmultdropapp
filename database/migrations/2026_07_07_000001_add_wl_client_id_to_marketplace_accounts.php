<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            // MUL-188: client_id da conta espelho na WL de origem (service = tenant).
            // Usado pelo push de token Bling pós-renovação central.
            $table->unsignedBigInteger('wl_client_id')->nullable()->after('service');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            $table->dropColumn('wl_client_id');
        });
    }
};
