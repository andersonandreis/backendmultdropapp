<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-462 Fase 1: Tabela de drill-down de criadores Kalodata.
 * Guard hasTable evita erro se re-executada (licao SEL-361).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kalodata_creator_detail')) {
            return;
        }

        Schema::create('kalodata_creator_detail', function (Blueprint $t) {
            $t->id();
            $t->date('snapshot_date')->index();
            $t->integer('rank_kalodata');
            $t->string('handle', 100);
            $t->string('nickname')->nullable();
            $t->text('bio_full')->nullable();
            $t->string('avatar_url_local')->nullable();
            $t->string('avatar_url_original', 1024)->nullable();
            $t->bigInteger('followers')->nullable();
            $t->bigInteger('following')->nullable();
            $t->bigInteger('likes_total')->nullable();
            $t->integer('videos_count')->nullable();
            $t->string('gender', 20)->nullable();
            $t->string('main_category')->nullable();
            $t->json('sub_categories')->nullable();
            $t->decimal('gmv_30d', 15, 2)->nullable();
            $t->integer('sales_30d')->nullable();
            $t->decimal('unit_price_avg', 10, 2)->nullable();
            $t->decimal('engagement_rate', 5, 2)->nullable();
            $t->bigInteger('views_30d')->nullable();
            $t->json('top_videos')->nullable();
            $t->json('promoted_products')->nullable();
            $t->json('live_summary')->nullable();
            $t->json('revenue_trend_90d')->nullable();
            $t->json('views_trend_90d')->nullable();
            $t->json('raw_payload')->nullable();
            $t->timestamp('scraped_at')->useCurrent();
            $t->timestamps();
            $t->unique(['snapshot_date', 'handle'], 'kcd_snap_handle_uniq');
            $t->index(['snapshot_date', 'rank_kalodata'], 'kcd_snap_rank_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kalodata_creator_detail');
    }
};
