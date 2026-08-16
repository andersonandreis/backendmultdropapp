<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-218: tabela de vídeos virais do TikTok BR descobertos via scraping de
 * termos de "descoberta de produto" (product finds, achadinhos, tiktok shop brasil).
 *
 * Fonte: tikwm.com/api/feed/search (região BR).
 * Populada por ScrapeTiktokViralVideosJob (diário 04:00 BRT).
 * Limpa automaticamente: scraped_at > 7 dias.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tiktok_viral_videos', function (Blueprint $t) {
            $t->id();
            $t->string('external_video_id', 64)->unique();           // aweme_id / video_id do tikwm
            $t->string('video_url', 512);                            // https://www.tiktok.com/@user/video/id
            $t->string('cover_url', 512)->nullable();                // thumbnail CDN
            $t->string('play_url_hd', 512)->nullable();              // link direto do vídeo HD
            $t->string('creator_handle', 120)->nullable();
            $t->string('creator_name', 255)->nullable();
            $t->string('creator_avatar_url', 512)->nullable();
            $t->text('caption')->nullable();
            $t->json('hashtags')->nullable();                        // array de hashtags
            $t->bigInteger('views')->default(0);
            $t->integer('comments')->default(0);
            $t->integer('likes')->default(0);
            $t->integer('shares')->default(0);
            $t->float('viral_score')->default(0);                    // log-weighted score
            $t->string('detected_product_title', 255)->nullable();   // extraído do caption (IA futura ou regex)
            $t->string('detected_product_url', 512)->nullable();     // link shop.tiktok.com no caption
            $t->string('search_term', 128)->nullable();              // termo que trouxe o vídeo
            $t->timestamp('published_at')->nullable();
            $t->timestamp('scraped_at')->useCurrent();
            $t->timestamps();

            $t->index(['viral_score', 'scraped_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_viral_videos');
    }
};
