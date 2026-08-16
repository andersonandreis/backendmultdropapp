<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MUL-340 — devolucoes de marketplace.
 *
 * O sistema nao acompanhava devolucao nenhuma: order_returns existia com 0 linhas e
 * orders.return_status nunca era preenchido, enquanto a API da Shopee tinha 63 devolucoes numa
 * conta so, com R$ 2.318,16 estornados.
 *
 * Duas decisoes de modelagem, ambas vindas da medicao de 06/08:
 *
 * 1. order_sn e NULLABLE e nao tem foreign key. Das 63 devolucoes medidas, so 14 tinham pedido
 *    correspondente no hub — as outras 49 sao de pedidos que o sistema nunca importou. Amarrar
 *    na order perderia 78% do dado.
 *
 * 2. A chave natural e (marketplace_account_id, return_sn). O return_sn e unico dentro da loja,
 *    nao globalmente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_returns', function (Blueprint $table) {
            $table->id();

            $table->foreignId('marketplace_account_id')->constrained()->cascadeOnDelete();
            $table->string('return_sn', 64);
            $table->string('order_sn', 64)->nullable();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->unsignedBigInteger('supplier_id')->nullable();

            // estado e motivo
            $table->string('status', 32)->nullable();          // ACCEPTED, PROCESSING, CANCELLED
            $table->string('reason', 40)->nullable();           // WRONG_ITEM, ITEM_MISSING, ...
            $table->text('text_reason')->nullable();
            $table->string('return_solution', 32)->nullable();
            $table->string('return_refund_type', 32)->nullable();

            // dinheiro
            $table->decimal('refund_amount', 12, 2)->nullable();
            $table->decimal('amount_before_discount', 12, 2)->nullable();
            $table->string('currency', 8)->nullable();

            // o que exige acao, e ate quando — e aqui que se perde dinheiro por prazo vencido
            $table->json('follow_up_action_list')->nullable();
            $table->string('seller_proof_status', 32)->nullable();
            $table->string('seller_compensation_status', 32)->nullable();
            $table->string('negotiation_status', 32)->nullable();
            $table->timestamp('seller_evidence_deadline')->nullable();
            $table->timestamp('return_seller_due_date')->nullable();
            $table->timestamp('return_ship_due_date')->nullable();
            $table->timestamp('due_date')->nullable();

            // a mercadoria esta voltando?
            $table->boolean('needs_logistics')->default(false);
            $table->boolean('is_arrived_at_warehouse')->default(false);
            $table->string('tracking_number', 64)->nullable();

            // o payload cru, para nao perder campo que ainda nao mapeamos
            $table->json('raw_payload')->nullable();

            $table->timestamp('marketplace_created_at')->nullable();
            $table->timestamp('marketplace_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['marketplace_account_id', 'return_sn'], 'mkt_returns_conta_sn_unique');
            $table->index('order_sn');
            $table->index(['supplier_id', 'reason']);   // qualidade da expedicao por fornecedor
            $table->index(['status', 'seller_evidence_deadline']);   // fila de acao
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_returns');
    }
};