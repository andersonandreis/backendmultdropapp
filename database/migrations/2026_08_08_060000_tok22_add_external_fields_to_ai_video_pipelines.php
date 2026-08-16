<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TOK-22 -- campos de origem externa em ai_video_pipelines.
 *
 * O motor de video do seller.global passa a aceitar pedidos de OUTRO produto
 * (Tokfy) com prioridade baixa. Estas colunas so existem pra correlacionar o
 * pedido de volta com quem pediu -- nao mudam nada do fluxo do seller.global,
 * que continua gravando `source` NULL como sempre gravou.
 *
 * Guard `hasColumn` em todas: esta branch (feature/SEL-417-video-plans) nao vai
 * pra main, mas o repo e compartilhado por 7 backends e a migration precisa ser
 * re-executavel sem quebrar se um dia for parar em outro lugar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_video_pipelines', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_video_pipelines', 'source')) {
                // NULL = seller.global (comportamento historico, nao precisa backfill).
                $table->string('source', 32)->nullable()->default(null)->after('user_id');
                $table->index('source', 'aivp_source_idx');
            }
            if (! Schema::hasColumn('ai_video_pipelines', 'external_ref')) {
                // uuid do pipeline no tokfy_app. Indexado porque o enqueue faz
                // lookup por ele a cada chamada (idempotencia).
                $table->string('external_ref', 128)->nullable()->default(null)->after('source');
                $table->index('external_ref', 'aivp_external_ref_idx');
            }
            if (! Schema::hasColumn('ai_video_pipelines', 'callback_url')) {
                $table->string('callback_url', 2048)->nullable()->default(null)->after('external_ref');
            }
            if (! Schema::hasColumn('ai_video_pipelines', 'callback_sent_at')) {
                // Marca que o callback ja foi entregue -- evita disparar duas vezes
                // se o job de acompanhamento for reprocessado.
                $table->timestamp('callback_sent_at')->nullable()->default(null)->after('callback_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_video_pipelines', function (Blueprint $table) {
            if (Schema::hasColumn('ai_video_pipelines', 'source')) {
                $table->dropIndex('aivp_source_idx');
            }
            if (Schema::hasColumn('ai_video_pipelines', 'external_ref')) {
                $table->dropIndex('aivp_external_ref_idx');
            }
            foreach (['callback_sent_at', 'callback_url', 'external_ref', 'source'] as $coluna) {
                if (Schema::hasColumn('ai_video_pipelines', $coluna)) {
                    $table->dropColumn($coluna);
                }
            }
        });
    }
};
