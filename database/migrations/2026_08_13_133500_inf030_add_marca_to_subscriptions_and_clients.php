<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INF-030-MARCA (13/08, pedido Ruan) — "saber qual plataforma o cliente
 * pagou... pra separar ele e organizar" antes de comecar a disparar e-mail
 * segmentado por marca.
 *
 * Ate hoje o banco NAO tinha nenhuma coluna de marca (seller.global x
 * tokfy.io) e o unico "proxy" existente (App\Support\BrandKit::TOKFY_PLAN_IDS
 * = [99,100,101], baseado no ID do plano) estava ERRADO: medido no banco real,
 * o plan_id=100 tem 294 subscriptions, mas so 221 batem com a lista real de
 * compradores Tokfy (cruzamento por e-mail); as outras 73 sao clientes
 * seller.global genuinos que compraram o mesmo plano de video. Plano NAO
 * serve de proxy de marca.
 *
 * Estas duas colunas passam a ser a fonte de verdade, escritas pelo
 * CheckoutController a partir do Origin/Referer real da requisicao (unico
 * sinal que o checkout de fato recebe hoje — o front nao manda campo de
 * marca nenhum; medido batendo em /api/checkout/plans com Origin forjado e
 * conferindo que o middleware de CORS do Laravel reflete o valor certo por
 * requisicao real).
 *
 * subscriptions.marca = marca da COMPRA especifica (uma marca por venda).
 * clients.marca       = marca "de casa" do cliente, fixada na 1a compra.
 *
 * NAO RODAR EM PRODUCAO SEM O OK DO RUAN (INF-030). Depois de aplicada, rodar
 * o backfill em scripts/inf030_backfill_marca.php (tambem preparado, tambem
 * NAO executado) pra marcar retroativamente os 221 Tokfy conhecidos + o resto
 * como seller.global.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('marca', 20)->nullable()->after('payment_method')->index();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->string('marca', 20)->nullable()->after('is_active')->index();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('marca');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('marca');
        });
    }
};
