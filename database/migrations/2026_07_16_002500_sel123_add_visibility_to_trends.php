<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-123 Ruan PDF: controle admin sobre exibicao de trends (criadores/produtos/lives).
 * is_visible=0 esconde de todos (equivale a "remover" da UI sem apagar).
 * is_approved=0 nao aparece no plano DEMO (mas ainda visivel nos pagos).
 * Default 1/1 pra nao quebrar clientes ja usando o painel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiktok_shop_trends', function (Blueprint $t) {
            if (!Schema::hasColumn('tiktok_shop_trends', 'is_visible')) {
                $t->boolean('is_visible')->default(true)->after('captured_at')->index();
            }
            if (!Schema::hasColumn('tiktok_shop_trends', 'is_approved')) {
                $t->boolean('is_approved')->default(true)->after('is_visible')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tiktok_shop_trends', function (Blueprint $t) {
            if (Schema::hasColumn('tiktok_shop_trends', 'is_approved')) $t->dropColumn('is_approved');
            if (Schema::hasColumn('tiktok_shop_trends', 'is_visible')) $t->dropColumn('is_visible');
        });
    }
};
