<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-UPGRADE (09/08, agente fullstack-dev): tabela de auditoria/idempotencia
 * pro fluxo de upgrade de plano pagando so a DIFERENCA.
 *
 * Por que uma tabela nova em vez de reusar `subscriptions` direto:
 *   - Precisamos rastrear a cobranca da DIFERENCA (gateway_order_id) separada
 *     da cobranca recorrente normal (subscriptions.pagarme_subscription_id),
 *     senao uma reentrega de webhook ou race condition pode subir o plano
 *     2x ou confundir com uma renovacao normal.
 *   - Idempotencia: gateway_order_id e UNIQUE. O webhook so aplica o upgrade
 *     uma vez, mesmo com reentrega do Pagar.me.
 *   - Auditoria: fica registrado quem pediu, de qual plano pra qual, quanto
 *     pagou, quando confirmou. Financeiro sensivel = precisa rastro.
 *
 * NAO mexe em `subscriptions` nem `plans` — so soma uma tabela nova, aditiva,
 * zero risco pro que ja existe. Nada le essa tabela ainda (rota nao esta
 * registrada / feature flag off), entao criar ela agora e seguro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_upgrade_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignId('from_plan_id')->constrained('plans');
            $table->foreignId('to_plan_id')->constrained('plans');
            $table->unsignedInteger('diff_amount_cents'); // valor cobrado (so a diferenca)
            $table->string('payment_method', 20); // pix|credit_card
            $table->string('gateway', 20)->default('pagarme');
            $table->string('gateway_customer_id')->nullable();
            $table->string('gateway_order_id')->unique(); // id do order/charge no Pagar.me
            $table->string('status', 20)->default('pending'); // pending|paid|expired|failed|canceled
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('gateway_response')->nullable(); // payload cru pra debug/auditoria
            $table->timestamps();

            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_upgrade_charges');
    }
};
