<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table("marketplace_accounts", function (Blueprint $table) {
            $table->string("bling_access_token", 512)->nullable()->after("sync_blocked_at");
            $table->string("bling_refresh_token", 512)->nullable()->after("bling_access_token");
            $table->timestamp("bling_token_expires_at")->nullable()->after("bling_refresh_token");
        });
    }

    public function down(): void
    {
        Schema::table("marketplace_accounts", function (Blueprint $table) {
            $table->dropColumn(["bling_access_token", "bling_refresh_token", "bling_token_expires_at"]);
        });
    }
};
