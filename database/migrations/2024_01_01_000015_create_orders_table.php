<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained();
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->string('order_number');
            $table->string('source')->default('manual'); // manual, mercadolivre, shopee
            $table->string('external_order_id')->nullable();
            $table->string('external_pack_id')->nullable();
            $table->string('external_shipping_id')->nullable();

            // Dados comprador
            $table->string('buyer_id')->nullable();
            $table->string('buyer_nickname')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_document_type')->nullable();
            $table->string('customer_document_number')->nullable();
            $table->json('customer_address')->nullable();

            // Valores
            $table->decimal('supplier_total', 10, 2)->default(0); // Custo total do fornecedor
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('shipping_cost', 10, 2)->default(0);
            $table->decimal('marketplace_fee', 10, 2)->default(0);
            $table->decimal('platform_fee', 10, 2)->nullable();
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->string('currency')->default('BRL');

            // Status
            $table->string('status')->default('pending_payment');
            $table->string('fulfillment_status')->default('awaiting_label');
            $table->string('cancel_reason')->nullable();

            // Envio
            $table->string('shipping_mode')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('tracking_url')->nullable();
            $table->text('label_url')->nullable();
            $table->string('carrier_name')->nullable();

            // NF
            $table->string('invoice_number')->nullable();
            $table->string('invoice_series')->nullable();
            $table->string('invoice_access_key', 44)->nullable();
            $table->timestamp('invoice_issued_at')->nullable();

            // Timestamps Fulfillment
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('label_printed_at')->nullable();
            $table->timestamp('separated_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
