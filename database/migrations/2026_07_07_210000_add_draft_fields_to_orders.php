<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MUL-197 — Pipeline de rascunho pra pedidos de marketplace (Shopee).
 *
 * Representacao escolhida: coluna dedicada `is_draft` (e NAO um valor novo de
 * canonical_status nem global scope). Motivos documentados:
 *  - canonical_status novo quebraria a maquina de estados (StatusTransitioner::STATES)
 *    e todos os mapeamentos de status existentes;
 *  - global scope esconderia o draft dos lookups de dedup (SyncShopeeOrdersJob /
 *    ImportMarketplaceAccountDataJob nao usam withoutGlobalScopes) e o pedido
 *    seria criado DUPLICADO na proxima rodada do cron;
 *  - coluna aditiva com default 0 e zero-downtime e retrocompativel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'is_draft')) {
                $table->boolean('is_draft')->default(false)->index()->after('status');
            }
            if (! Schema::hasColumn('orders', 'draft_reason')) {
                $table->string('draft_reason', 500)->nullable()->after('is_draft');
            }
            if (! Schema::hasColumn('orders', 'enrich_attempts')) {
                $table->unsignedSmallInteger('enrich_attempts')->default(0)->after('draft_reason');
            }
            if (! Schema::hasColumn('orders', 'last_enriched_at')) {
                $table->timestamp('last_enriched_at')->nullable()->after('enrich_attempts');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach (['last_enriched_at', 'enrich_attempts', 'draft_reason', 'is_draft'] as $col) {
                if (Schema::hasColumn('orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
