<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_payment_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')
                ->unique()
                ->constrained('suppliers')
                ->cascadeOnDelete();

            $table->enum('gateway', ['asaas', 'shipay', 'pagarme'])->default('asaas');

            // Credenciais — serializadas encrypted no model
            $table->text('api_key')->nullable();
            $table->text('api_secret')->nullable();
            $table->json('api_extra')->nullable(); // campos adicionais por gateway (ex: Shipay access_key)

            // Webhook
            $table->string('webhook_url')->nullable();
            $table->string('webhook_secret', 64)->nullable();

            // Configuracoes de taxa PIX
            $table->enum('pix_fee_type', ['fixed', 'percentage'])->default('percentage');
            $table->decimal('pix_fee_value', 8, 4)->default(0);

            // Funcionalidades habilitadas
            $table->boolean('allows_wallet_topup')->default(true);
            $table->boolean('allows_wallet_payment')->default(true);
            $table->enum('refund_policy', ['wallet_credit', 'manual_estorno'])->default('wallet_credit');
            $table->boolean('pix_only_orders')->default(true);
            $table->integer('pix_timeout_minutes')->default(30);

            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_settings');
    }
};
