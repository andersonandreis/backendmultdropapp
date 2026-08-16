<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'ml_category_id')) {
                $table->string('ml_category_id', 50)->nullable()->after('shopee_category_id');
            }
            if (! Schema::hasColumn('products', 'ml_attributes')) {
                $table->json('ml_attributes')->nullable()->after('ml_category_id');
            }
            if (! Schema::hasColumn('products', 'shopee_attributes')) {
                $table->json('shopee_attributes')->nullable()->after('ml_attributes');
            }
            if (! Schema::hasColumn('products', 'quality_score_shopee')) {
                $table->tinyInteger('quality_score_shopee')->unsigned()->nullable()->after('shopee_attributes');
            }
            if (! Schema::hasColumn('products', 'quality_score_ml')) {
                $table->tinyInteger('quality_score_ml')->unsigned()->nullable()->after('quality_score_shopee');
            }
            if (! Schema::hasColumn('products', 'quality_issues')) {
                $table->json('quality_issues')->nullable()->after('quality_score_ml');
            }
        });

        Schema::table('client_products', function (Blueprint $table) {
            if (! Schema::hasColumn('client_products', 'ml_external_item_id')) {
                $table->string('ml_external_item_id', 50)->nullable()->after('sync_attempt_count');
            }
            if (! Schema::hasColumn('client_products', 'shopee_external_item_id')) {
                $table->string('shopee_external_item_id', 50)->nullable()->after('ml_external_item_id');
            }
            if (! Schema::hasColumn('client_products', 'listing_quality_score')) {
                $table->tinyInteger('listing_quality_score')->unsigned()->nullable()->after('shopee_external_item_id');
            }
            if (! Schema::hasColumn('client_products', 'listing_quality_issues')) {
                $table->json('listing_quality_issues')->nullable()->after('listing_quality_score');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            foreach (['ml_category_id','ml_attributes','shopee_attributes','quality_score_shopee','quality_score_ml','quality_issues'] as $col) {
                if (Schema::hasColumn('products', $col)) $table->dropColumn($col);
            }
        });
        Schema::table('client_products', function (Blueprint $table) {
            foreach (['ml_external_item_id','shopee_external_item_id','listing_quality_score','listing_quality_issues'] as $col) {
                if (Schema::hasColumn('client_products', $col)) $table->dropColumn($col);
            }
        });
    }
};