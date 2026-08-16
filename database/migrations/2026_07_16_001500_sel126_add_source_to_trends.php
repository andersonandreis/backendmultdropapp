<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * SEL-126 Ruan 15/07: unificar produtos em alta de multiplas fontes
 * (TikTok Shop + Mercado Livre Trends + Shopee Blog Mais Vendidos).
 *
 * Adiciona coluna `source` em tiktok_shop_trends (default 'tiktok_shop').
 * ML Trends e Shopee blog serao inseridos com source correspondente.
 * View unificada agrega tudo pra UI cruzar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiktok_shop_trends', function (Blueprint $t) {
            if (!Schema::hasColumn('tiktok_shop_trends', 'source')) {
                $t->string('source', 32)->default('tiktok_shop')->after('kind')->index();
            }
            if (!Schema::hasColumn('tiktok_shop_trends', 'source_url')) {
                $t->text('source_url')->nullable()->after('source');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tiktok_shop_trends', function (Blueprint $t) {
            if (Schema::hasColumn('tiktok_shop_trends', 'source_url')) {
                $t->dropColumn('source_url');
            }
            if (Schema::hasColumn('tiktok_shop_trends', 'source')) {
                $t->dropColumn('source');
            }
        });
    }
};
