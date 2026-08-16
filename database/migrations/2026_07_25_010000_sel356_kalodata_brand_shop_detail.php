<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-356: Tabelas para drill-down de marcas e lojas Kalodata.
 * Tambem adiciona avatar e kalodata_rank nas tabelas ads e lives_ranking.
 */
return new class extends Migration
{
    public function up(): void
    {
        // kalodata_brand_detail — top_products + top_creators por marca
        if (!Schema::hasTable('kalodata_brand_detail')) {
            Schema::create('kalodata_brand_detail', function (Blueprint $table) {
                $table->id();
                $table->string('brand_external_id', 64)->index();
                $table->date('snapshot_date')->index();
                $table->string('country', 2)->default('BR')->index();
                $table->longText('top_products')->nullable();   // JSON
                $table->longText('top_creators')->nullable();   // JSON
                $table->longText('gmv_by_day')->nullable();     // JSON
                $table->longText('detail_payload')->nullable(); // JSON raw
                $table->timestamps();
                // nome curto pra evitar erro MySQL (max 64 chars)
                $table->unique(['brand_external_id', 'snapshot_date', 'country'], 'kbranddet_uniq');
            });
        }

        // kalodata_shop_detail — top_products + creators por loja
        if (!Schema::hasTable('kalodata_shop_detail')) {
            Schema::create('kalodata_shop_detail', function (Blueprint $table) {
                $table->id();
                $table->string('shop_external_id', 64)->index();
                $table->date('snapshot_date')->index();
                $table->string('country', 2)->default('BR')->index();
                $table->longText('top_products')->nullable();   // JSON
                $table->longText('top_creators')->nullable();   // JSON
                $table->longText('gmv_by_day')->nullable();     // JSON
                $table->longText('detail_payload')->nullable(); // JSON raw
                $table->timestamps();
                $table->unique(['shop_external_id', 'snapshot_date', 'country'], 'kshopdet_uniq');
            });
        }

        // Adiciona avatar + kalodata_rank em kalodata_ads
        if (Schema::hasTable('kalodata_ads') && !Schema::hasColumn('kalodata_ads', 'avatar')) {
            Schema::table('kalodata_ads', function (Blueprint $table) {
                $table->string('avatar', 1024)->nullable()->after('creator_handle');
                $table->unsignedSmallInteger('kalodata_rank')->nullable()->after('avatar');
            });
        }

        // Adiciona avatar + kalodata_rank em kalodata_lives_ranking
        if (Schema::hasTable('kalodata_lives_ranking') && !Schema::hasColumn('kalodata_lives_ranking', 'avatar')) {
            Schema::table('kalodata_lives_ranking', function (Blueprint $table) {
                $table->string('avatar', 1024)->nullable()->after('creator_handle');
                $table->unsignedSmallInteger('kalodata_rank')->nullable()->after('avatar');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kalodata_brand_detail');
        Schema::dropIfExists('kalodata_shop_detail');
        if (Schema::hasColumn('kalodata_ads', 'avatar')) {
            Schema::table('kalodata_ads', function (Blueprint $table) {
                $table->dropColumn(['avatar', 'kalodata_rank']);
            });
        }
        if (Schema::hasColumn('kalodata_lives_ranking', 'avatar')) {
            Schema::table('kalodata_lives_ranking', function (Blueprint $table) {
                $table->dropColumn(['avatar', 'kalodata_rank']);
            });
        }
    }
};
