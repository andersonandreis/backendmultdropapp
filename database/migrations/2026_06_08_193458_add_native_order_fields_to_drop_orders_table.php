<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drop_orders', function (Blueprint $table) {
            $table->string('order_key', 36)->nullable()->unique()->after('id');
            $table->string('customer_cpf', 20)->nullable()->after('customer_phone');
            $table->string('shipping_zip', 10)->nullable()->after('shipping_address');
            $table->string('shipping_city', 100)->nullable()->after('shipping_zip');
            $table->string('shipping_state', 2)->nullable()->after('shipping_city');
            $table->json('items_json')->nullable()->after('shipping_state');
            $table->string('payment_method', 50)->nullable()->after('currency');
            $table->string('tracking_code', 100)->nullable()->after('notes');
            $table->string('source', 50)->nullable()->default('shopify')->after('tracking_code');
            // make shopify_order_id nullable for native orders
            $table->string('shopify_order_id', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('drop_orders', function (Blueprint $table) {
            $table->dropColumn([
                'order_key', 'customer_cpf', 'shipping_zip', 'shipping_city',
                'shipping_state', 'items_json', 'payment_method', 'tracking_code', 'source',
            ]);
            $table->string('shopify_order_id', 255)->nullable(false)->change();
        });
    }
};
