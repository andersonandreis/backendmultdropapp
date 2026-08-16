<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOV-171-A — M-171-04
 * Cria tabela federation_order_notifications — SOMENTE nos WLs (multdrop, fornecefy, mestoredrop).
 * NAO eh uma copia de orders — os WLs nao tem tabela orders (confirmado via SSH 02/07/2026).
 * Eh o registro local da notificacao de pedido recebida via webhook do hub.
 * Usado para exibicao no painel WL e controle de status (WL MANDA — decisao Ruan 02/07).
 * hub_delivery_id (UNIQUE) garante dedup de webhook: mesma entrega nao cria duplicata.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('federation_order_notifications')) {
            return;
        }

        Schema::create('federation_order_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hub_order_id')
                ->comment('orders.id no hubaiapp — referencia numerica (sem FK cruzada)');
            $table->char('hub_delivery_id', 36)
                ->comment('UUID do DispatchWebhookJob — garante idempotencia de entrega');
            $table->string('origin_tenant', 50)
                ->comment('Slug do WL de origem do pedido (multdrop, fornecefy, etc.)');
            $table->unsignedBigInteger('client_id')->nullable()
                ->comment('clients.id local se o lojista foi mapeado');
            $table->json('payload')
                ->comment('Payload completo do webhook do hub — inclui origin_tenant, hub_order_id, status, produtos');
            $table->enum('status', ['pending', 'processing', 'done', 'failed'])->default('pending');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique('hub_delivery_id', 'uq_hub_delivery');
            $table->index('hub_order_id', 'idx_hub_order');
            $table->index(['status', 'created_at'], 'idx_status_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('federation_order_notifications');
    }
};
