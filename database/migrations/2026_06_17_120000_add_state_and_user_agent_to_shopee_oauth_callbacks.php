<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_oauth_callbacks', function (Blueprint $table) {
            $table->string('state', 1024)->nullable()->after('code')->comment('State OAuth recebido da Shopee (NULL indica callback sem state)');
            $table->string('user_agent', 512)->nullable()->after('source_ip')->comment('User-Agent do request');
            $table->string('referer', 512)->nullable()->after('user_agent')->comment('Referer HTTP do request');
            $table->enum('flow', ['legado', 'novohubai', 'bridge', 'account_id', 'no_state', 'unknown'])->default('unknown')->after('processed')->comment('Fluxo identificado no callback');
            $table->string('resolution', 255)->nullable()->after('flow')->comment('Como o callback foi resolvido');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_oauth_callbacks', function (Blueprint $table) {
            $table->dropColumn(['state', 'user_agent', 'referer', 'flow', 'resolution']);
        });
    }
};
