<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-245 Ruan 18/07/2026 — Wallet cliente pra créditos IA (estilo Tokfy).
 * Mínimo R$50 depósito PIX via Pagar.me. Débito automático ao gerar
 * vídeo/imagem IA. Admin vê saldo Kling e histórico de consumo por cliente.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_ai_wallets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->unique()->constrained('clients')->cascadeOnDelete();
            $t->decimal('balance', 12, 2)->default(0);
            $t->decimal('lifetime_deposited', 12, 2)->default(0);
            $t->decimal('lifetime_consumed', 12, 2)->default(0);
            $t->timestamps();
        });

        Schema::create('ai_wallet_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $t->enum('direction', ['credit', 'debit']);
            $t->decimal('amount', 12, 2);
            $t->decimal('balance_after', 12, 2);
            $t->string('kind', 32); // deposit_pix, video_gen, image_gen, admin_adjust
            $t->string('ref', 128)->nullable(); // pagarme_charge_id ou video_generation_id
            $t->text('note')->nullable();
            $t->timestamps();
            $t->index(['client_id', 'created_at']);
            $t->index('ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_wallet_transactions');
        Schema::dropIfExists('client_ai_wallets');
    }
};
