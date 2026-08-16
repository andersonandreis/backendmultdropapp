<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('missed_orders_alerts', function (Blueprint $table) {
            $table->id();

            // Ownership
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('marketplace_account_id')->nullable()->constrained('marketplace_accounts')->nullOnDelete();

            // Marketplace identification
            $table->string('marketplace', 20); // shopee | ml | bling
            $table->string('marketplace_order_id', 100);

            // Buyer snapshot (optional — nem sempre disponível antes de chamar API)
            $table->string('buyer_name')->nullable();
            $table->string('buyer_doc', 20)->nullable();

            // Value
            $table->unsignedBigInteger('amount_cents')->nullable(); // em centavos
            $table->char('currency', 3)->default('BRL');

            // Dismiss reason
            $table->string('dismiss_reason', 50)->nullable(); // not_mine | already_registered | cancelled_at_marketplace | other

            // Lifecycle timestamps
            $table->timestamp('detected_at');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();

            // Conversion FK — preenchido quando cliente usa o wizard a partir deste alerta
            $table->foreignId('converted_to_order_id')->nullable()->constrained('orders')->nullOnDelete();

            // Notification hard cap (max 3: criação + digest D+1 + digest D+3)
            $table->unsignedSmallInteger('notification_count')->default(0);

            // Raw snapshot do marketplace para pré-preencher o wizard
            $table->json('payload')->nullable();

            $table->timestamps();

            // Deduplicação: um único alerta por pedido por cliente
            $table->unique(['marketplace', 'marketplace_order_id', 'client_id'], 'uniq_missed_order_per_client');

            // Listagem de alertas ativos (pending): filtra por client + estados nulos
            $table->index(['client_id', 'dismissed_at', 'converted_to_order_id'], 'idx_missed_alerts_active');

            // Busca por marketplace para reconciliação
            $table->index('marketplace', 'idx_missed_alerts_marketplace');

            // Purge de alertas antigos
            $table->index('detected_at', 'idx_missed_alerts_detected_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('missed_orders_alerts');
    }
};
