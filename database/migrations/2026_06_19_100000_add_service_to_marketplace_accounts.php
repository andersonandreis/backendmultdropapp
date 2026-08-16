<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            $table->string('service', 50)->nullable()->after('platform')->comment('Sistema de origem: hubai, multdrop, fornecefy');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            $table->dropColumn('service');
        });
    }
};
