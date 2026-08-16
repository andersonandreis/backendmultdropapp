<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-186: trilha de cupons aplicados em assinaturas.
 *
 * Registra cada vez que um cupom e usado em uma assinatura para:
 *  - auditoria financeira (quanto desconto foi dado)
 *  - prevenir duplo uso do mesmo cupom na mesma subscription
 *  - historico de campanhas do grupo
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_coupons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('subscription_id');
            $table->unsignedBigInteger('coupon_id');
            $table->decimal('discount_amount_applied', 10, 2);
            $table->timestamp('applied_at')->useCurrent();
            $table->timestamps();

            $table->foreign('subscription_id')->references('id')->on('subscriptions')->onDelete('cascade');
            $table->foreign('coupon_id')->references('id')->on('coupons')->onDelete('restrict');

            // Um cupom so pode ser aplicado uma vez por assinatura
            $table->unique(['subscription_id', 'coupon_id'], 'uq_subscription_coupon');

            $table->index('coupon_id');
            $table->index('subscription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_coupons');
    }
};
