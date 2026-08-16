<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-259 — Snapshots de lives TikTok ativos por nicho.
 *
 * Preenchida pelo AlertActiveLivesJob (a cada 5min via cron) lendo
 * o endpoint webcast.tiktok.com/webcast/game_feed/api/feed_card/strategy
 * (capturado via Playwright com session Ruan).
 *
 * external_id = room_id ou uid da live no TikTok.
 * niche = slug de categoria (ex: "beauty-personal-care") pra cruzar
 *   com push_preferences.niches[] do cliente.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('tt_live_snapshots')) {
            return;
        }

        Schema::create('tt_live_snapshots', function (Blueprint $t) {
            $t->id();
            $t->string('external_id', 64)->nullable();
            $t->string('handle', 128)->nullable();
            $t->string('title', 255)->nullable();
            $t->unsignedInteger('viewers_now')->default(0);
            $t->string('niche', 128)->nullable();
            $t->text('image_url')->nullable();
            $t->text('tiktok_url')->nullable();
            $t->timestamp('snapshot_at')->useCurrent();
            $t->timestamps();

            $t->index(['niche', 'snapshot_at']);
            $t->index('snapshot_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tt_live_snapshots');
    }
};
