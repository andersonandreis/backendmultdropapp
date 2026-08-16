<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pix_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('order_id')
                ->nullable()
                ->constrained('orders')
                ->nullOnDelete(); // null quando for recarga de wallet

            $table->enum('type', ['order_payment', 'wallet_topup']);
            $table->string('gateway', 20);
            $table->string('external_id')->nullable(); // ID retornado pelo gateway

            $table->decimal('amount', 10, 2);
            $table->decimal('fee_amount', 10, 2)->default(0);
            $table->decimal('net_amount', 10, 2);

            $table->enum('status', ['pending', 'paid', 'expired', 'refunded', 'failed'])->default('pending');

            $table->text('qr_code')->nullable();      // base64 da imagem QR
            $table->text('qr_code_text')->nullable(); // copia e cola PIX

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->json('gateway_response')->nullable();
            $table->string('idempotency_key')->unique();

            $table->timestamps();

            // Indexes
            $table->index('supplier_id');
            $table->index('client_id');
            $table->index('order_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pix_transactions');
    }
};
