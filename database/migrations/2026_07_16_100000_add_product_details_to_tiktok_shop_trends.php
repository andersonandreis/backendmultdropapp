<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-148 — Adiciona dados de produto TikTok Shop Affiliate:
 *   avg_rating       — avaliação média (0-5 estrelas)
 *   review_count     — quantidade de reviews/comentários
 *   commission_rate  — comissão do produto no programa de afiliados (%)
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('tiktok_shop_trends', function (Blueprint $t) {
            if (!Schema::hasColumn('tiktok_shop_trends', 'avg_rating')) {
                $t->float('avg_rating', 3, 2)->nullable()->after('enriched_at')
                   ->comment('Avaliação média do produto (0-5 estrelas)');
            }
            if (!Schema::hasColumn('tiktok_shop_trends', 'review_count')) {
                $t->unsignedInteger('review_count')->nullable()->after('avg_rating')
                   ->comment('Quantidade de reviews/comentários');
            }
            if (!Schema::hasColumn('tiktok_shop_trends', 'commission_rate')) {
                $t->float('commission_rate', 5, 2)->nullable()->after('review_count')
                   ->comment('Taxa de comissão do afiliado (%) — vem da Affiliate API');
            }
        });
    }
    public function down(): void {
        Schema::table('tiktok_shop_trends', function (Blueprint $t) {
            $t->dropColumn(['avg_rating', 'review_count', 'commission_rate']);
        });
    }
};
