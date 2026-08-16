<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HUB-131 — Tabela de deduplicacao de webhooks ML/Shopee.
 *
 * Problema: ML/Shopee reenviam webhook se nao recebem HTTP 200 em <5s.
 * Se o processamento (relay legado, DB write) demorar, o marketplace reenvia.
 * Sem dedup, o mesmo evento pode ser processado N vezes.
 *
 * Solucao: UNIQUE constraint em (source, external_id).
 * Handler verifica via INSERT IGNORE antes de despachar job.
 * TTL: 30 dias (eventos antigos sao podados pelo PruneProcessedWebhooksCommand).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('processed_webhook_ids', function (Blueprint $table) {
            $table->id();

            // Origem do webhook: 'mercadolivre', 'shopee', 'bling'
            $table->string('source', 50);

            // ID unico do evento na plataforma:
            // ML: campo data.id (notification_id numerico)
            // Shopee: timestamp|shop_id|code (composto)
            $table->string('external_id', 200);

            // Dados adicionais para auditoria/debug
            $table->string('topic', 100)->nullable();
            $table->timestamp('processed_at')->useCurrent();

            // UNIQUE: garante que o mesmo (source, external_id) nao seja processado 2x
            $table->unique(['source', 'external_id'], 'uniq_processed_webhook');

            // Index para poda automatica por TTL
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_webhook_ids');
    }
};
