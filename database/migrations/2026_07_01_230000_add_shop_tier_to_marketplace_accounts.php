<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MUL-095 — Shopee Selo Indicado
 *
 * shop_tier: tier do programa Shopee ('normal'|'indicated'|'preferred'|'platinum'|null)
 * is_indicated: bool — true se tier for 'indicated' ou superior
 * shop_tier_synced_at: timestamp do ultimo sync Shopee
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            $table->string('shop_tier', 30)->nullable()->after('shop_id');
            $table->boolean('is_indicated')->default(false)->after('shop_tier');
            $table->timestamp('shop_tier_synced_at')->nullable()->after('is_indicated');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            $table->dropColumn(['shop_tier', 'is_indicated', 'shop_tier_synced_at']);
        });
    }
};
