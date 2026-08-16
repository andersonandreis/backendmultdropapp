<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-214 Fase2: adiciona commission_rate e affiliate_link em
 * tiktok_shop_seller_products para exibir botao "Afiliar no TikTok"
 * no painel Fornecedores pra Afiliado.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tiktok_shop_seller_products', function (Blueprint $t) {
            $t->decimal('commission_rate', 5, 2)->nullable()->after('price')
              ->comment('Percentual de comissao do afiliado (ex: 20.00 = 20%)');
            $t->string('affiliate_link', 512)->nullable()->after('commission_rate')
              ->comment('Link direto programa afiliados TikTok Shop deste produto');
        });

        Schema::table('tiktok_shop_sellers', function (Blueprint $t) {
            $t->decimal('avg_commission_rate', 5, 2)->nullable()->after('avg_rating')
              ->comment('Comissao media dos produtos deste seller (%)');
        });
    }

    public function down(): void
    {
        Schema::table('tiktok_shop_seller_products', function (Blueprint $t) {
            $t->dropColumn(['commission_rate', 'affiliate_link']);
        });
        Schema::table('tiktok_shop_sellers', function (Blueprint $t) {
            $t->dropColumn('avg_commission_rate');
        });
    }
};
