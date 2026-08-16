<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Campos Shopee necessarios para SyncShopeeOrdersJob
            $table->unsignedBigInteger('marketplace_account_id')->nullable()->after('source');
            $table->string('marketplace_order_id')->nullable()->after('marketplace_account_id');
            $table->unsignedBigInteger('shop_id')->nullable()->after('marketplace_order_id');
            $table->string('buyer_username')->nullable()->after('buyer_nickname');
            $table->json('raw_payload')->nullable()->after('notes');

            $table->index(['marketplace_order_id', 'source'], 'orders_marketplace_order_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_marketplace_order_source_idx');
            $table->dropColumn(['marketplace_account_id', 'marketplace_order_id', 'shop_id', 'buyer_username', 'raw_payload']);
        });
    }
};
