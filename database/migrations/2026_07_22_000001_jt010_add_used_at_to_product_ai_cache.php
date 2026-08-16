<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * JT-010: Adiciona used_at à product_ai_cache para rastreamento de reserva de títulos IA.
 * Títulos com used_at IS NULL estão disponíveis para uso (reserva).
 * Ao servir um título do banco, marcamos used_at=NOW() atomicamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_ai_cache', function (Blueprint $table) {
            $table->timestamp('used_at')->nullable()->after('generated_at');
            $table->index(['sku_codigo', 'marketplace', 'used_at'], 'idx_sku_market_used');
        });
    }

    public function down(): void
    {
        Schema::table('product_ai_cache', function (Blueprint $table) {
            $table->dropIndex('idx_sku_market_used');
            $table->dropColumn('used_at');
        });
    }
};
