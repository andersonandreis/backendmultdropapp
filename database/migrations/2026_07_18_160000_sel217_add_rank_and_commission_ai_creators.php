<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-217 — Adiciona campos de ranking e comissão em ai_creators
 *
 * - tokfy_sync_id: ID do registro na tabela social_profiles do Supabase Tokfy
 *   (permite deduplicação no SyncTokfyCreatorsJob e rastreio da fonte)
 * - commission: comissão estimada R$ (campo separado do commission_items/quantidade)
 *   Tokfy exibe "COMISSÃO" como valor monetário distinto do GMV — esta coluna acomoda isso.
 * - tokfy_synced_at: timestamp do último sync com Supabase Tokfy (controle de staleness)
 *
 * Campos rank_position e estimated_revenue já existem (SEL-199/SEL-213).
 * O cron SyncTokfyCreatorsJob passa a preencher rank_position sequencial (1..N)
 * ordenado por estimated_revenue DESC após cada importação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_creators', function (Blueprint $table) {
            // ID do perfil na social_profiles do Supabase Tokfy (para dedup no sync)
            $table->string('tokfy_sync_id')->nullable()->unique()->after('source');

            // Comissão estimada R$ (distinta de commission_items que é contagem de itens)
            // Tokfy chama de "COMISSÃO" — valor numérico R$ (ex: R$5.600 → 5600.00)
            $table->decimal('commission', 12, 2)->nullable()->after('commission_items');

            // Controle de staleness do sync Tokfy
            $table->timestamp('tokfy_synced_at')->nullable()->after('tokfy_sync_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_creators', function (Blueprint $table) {
            $table->dropColumn(['tokfy_sync_id', 'commission', 'tokfy_synced_at']);
        });
    }
};
