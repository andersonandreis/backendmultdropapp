<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('tiktok_shop_trends', function (Blueprint $t) {
            if (!Schema::hasColumn('tiktok_shop_trends', 'matched_supplier_id')) {
                $t->unsignedBigInteger('matched_supplier_id')->nullable()->index()->after('gmv');
            }
            if (!Schema::hasColumn('tiktok_shop_trends', 'matched_supplier_score')) {
                $t->unsignedTinyInteger('matched_supplier_score')->nullable()->after('matched_supplier_id');
            }
            if (!Schema::hasColumn('tiktok_shop_trends', 'ml_volume')) {
                $t->unsignedInteger('ml_volume')->nullable()->after('matched_supplier_score');
            }
            if (!Schema::hasColumn('tiktok_shop_trends', 'ml_competition')) {
                $t->unsignedInteger('ml_competition')->nullable()->after('ml_volume');
            }
            if (!Schema::hasColumn('tiktok_shop_trends', 'ml_price_min')) {
                $t->decimal('ml_price_min', 12, 2)->nullable()->after('ml_competition');
            }
            if (!Schema::hasColumn('tiktok_shop_trends', 'ml_price_max')) {
                $t->decimal('ml_price_max', 12, 2)->nullable()->after('ml_price_min');
            }
            if (!Schema::hasColumn('tiktok_shop_trends', 'ml_top_url')) {
                $t->string('ml_top_url', 512)->nullable()->after('ml_price_max');
            }
            if (!Schema::hasColumn('tiktok_shop_trends', 'enriched_at')) {
                $t->timestamp('enriched_at')->nullable()->after('ml_top_url');
            }
        });
    }
    public function down(): void {
        Schema::table('tiktok_shop_trends', function (Blueprint $t) {
            $t->dropColumn(['matched_supplier_id','matched_supplier_score','ml_volume','ml_competition','ml_price_min','ml_price_max','ml_top_url','enriched_at']);
        });
    }
};
