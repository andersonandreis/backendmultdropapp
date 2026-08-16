<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-239 hotfix — avatar_url VARCHAR(255) é curto pra URLs TikTok CDN
 * (com query params tem 400-800 chars). Aumenta pra VARCHAR(1024).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('ai_creators', function (Blueprint $t) {
            $t->string('avatar_url', 1024)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ai_creators', function (Blueprint $t) {
            $t->string('avatar_url', 255)->nullable()->change();
        });
    }
};
