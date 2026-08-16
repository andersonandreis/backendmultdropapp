<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-CARTEIRA-AFILIADO (14/08) — pedido do Ruan na live:
 *
 *   "faz uma carteira pra eles, com QR code, eles botam saldo, e com esse saldo
 *    eles criam os usuarios na plataforma. Eles pagam METADE do plano, porque a
 *    comissao deles e 50% — eu ganho metade da PRIMEIRA mensalidade so. E eu quero
 *    controle do meu lado: quem pagou quanto, quanto tem de saldo, quanto saiu."
 *
 * Tres tabelas, uma responsabilidade cada:
 *   affiliate_wallets              -> o saldo de agora (uma linha por afiliado)
 *   affiliate_wallet_deposits      -> cada PIX gerado (o QR code vive aqui)
 *   affiliate_wallet_transactions  -> o extrato imutavel (entrou/saiu, com saldo_depois)
 *
 * TUDO EM CENTAVOS (int). Dinheiro em float arredonda errado e some centavo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_wallets', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('affiliate_id')->unique();
            // saldo pode ficar negativo? NAO — mas guardo como SIGNED de proposito:
            // coluna UNSIGNED estoura na subtracao antes de qualquer guarda rodar
            // (aprendido hoje no ai_engine_usage.reserved_count, SQLSTATE[22003]).
            $t->bigInteger('balance_cents')->default(0);
            $t->bigInteger('lifetime_deposited_cents')->default(0);
            $t->bigInteger('lifetime_spent_cents')->default(0);
            $t->timestamps();
            $t->index('affiliate_id', 'idx_afw_aff');
        });

        Schema::create('affiliate_wallet_deposits', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('affiliate_id');
            $t->bigInteger('amount_cents');
            $t->string('gateway', 20)->default('pagarme');
            $t->string('gateway_order_id', 64)->nullable();
            $t->string('gateway_charge_id', 64)->nullable()->unique();
            $t->text('qr_code')->nullable();        // copia e cola
            $t->text('qr_code_url')->nullable();    // imagem do QR
            $t->string('status', 20)->default('pending'); // pending|paid|expired|canceled
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            // quem confirmou na mao, quando o webhook nao veio
            $t->unsignedBigInteger('confirmed_by_user_id')->nullable();
            $t->text('gateway_response')->nullable();
            $t->timestamps();
            $t->index(['affiliate_id', 'status'], 'idx_afd_aff_status');
        });

        Schema::create('affiliate_wallet_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('affiliate_id');
            $t->string('direction', 3);              // in | out
            $t->bigInteger('amount_cents');
            $t->bigInteger('balance_after_cents');   // extrato tem que fechar sozinho
            // deposito_pix | criacao_usuario | ajuste_admin | estorno
            $t->string('kind', 30);
            $t->string('ref', 64)->nullable();       // id do deposito, id do usuario criado
            $t->string('note', 255)->nullable();
            $t->timestamps();
            $t->index(['affiliate_id', 'created_at'], 'idx_afwt_aff_data');
            $t->index('kind', 'idx_afwt_kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_wallet_transactions');
        Schema::dropIfExists('affiliate_wallet_deposits');
        Schema::dropIfExists('affiliate_wallets');
    }
};
