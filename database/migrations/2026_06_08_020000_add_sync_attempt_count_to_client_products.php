<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_products', function (Blueprint $table) {
            $table->unsignedTinyInteger('sync_attempt_count')->default(0)->after('last_sync_error');
        });
    }

    public function down(): void
    {
        Schema::table('client_products', function (Blueprint $table) {
            $table->dropColumn('sync_attempt_count');
        });
    }
};
