<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-246 — flag pra evitar reprocessar mesmo vídeo no scraper de anchors.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tiktok_viral_videos', function (Blueprint $t) {
            $t->boolean('anchors_processed')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('tiktok_viral_videos', function (Blueprint $t) {
            $t->dropColumn('anchors_processed');
        });
    }
};
