<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drop_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            $table->foreignId('drop_store_id')->constrained('drop_stores')->onDelete('cascade');
            $table->foreignId('imported_product_id')->nullable()->constrained('imported_products')->nullOnDelete();
            $table->string('shopify_order_id')->unique();
            $table->string('shopify_order_number')->nullable();
            $table->string('customer_name')->nullable();
            $table->text('customer_email')->nullable();
            $table->text('customer_phone')->nullable();
            $table->text('shipping_address')->nullable();
            $table->decimal('total_amount', 10, 4)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->enum('status', [
                'pending_payment',
                'payment_received',
                'awaiting_supplier',
                'ordered_supplier',
                'awaiting_tracking',
                'shipped',
                'in_transit',
                'out_for_delivery',
                'delivered',
                'logistics_issue',
                'refunded',
                'cancelled',
            ])->default('pending_payment');
            $table->string('traffic_source')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('fbp')->nullable();
            $table->string('fbc')->nullable();
            $table->string('gclid')->nullable();
            $table->string('event_id')->nullable();
            $table->decimal('profit_estimate', 10, 4)->default(0);
            $table->decimal('gateway_fee', 10, 4)->default(0);
            $table->decimal('platform_fee', 10, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('client_id');
            $table->index('drop_store_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drop_orders');
    }
};
