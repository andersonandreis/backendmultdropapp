<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-333 Fase 1 — Tabela de eventos de geracao de video.
 *
 * Schema basico para rastreamento (Fase 1).
 * Cron AnalyzeVideoPatterns (Fase 3) vai popular avg_review_score e ruan_approved.
 *
 * Dados coletados por chamada ao Video Director:
 *   - pipeline_used, product_category, category_confidence, director_reason
 *   - avg_review_score (null na F1 — preenchido pelo auto-review F2)
 *   - ruan_approved (null = pendente, true = aprovado, false = rejeitado)
 *   - generation_time_sec (preenchido pelo Job ao finalizar)
 *   - cost_credits, kling_variant (preenchidos apos finalize)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_generation_events', function (Blueprint $table) {
            $table->id();

            // Vinculo com o pipeline executado
            $table->unsignedBigInteger('pipeline_id')->nullable()->index()
                  ->comment('FK para ai_video_pipelines.id — pode ser nulo se pipeline falhou antes de criar');
            $table->unsignedBigInteger('user_id')->nullable()->index();

            // Decisao do Director
            $table->string('pipeline_used', 50)
                  ->comment('showcase_silencioso | pov | video_perfeito | ...');
            $table->string('product_category', 50)
                  ->comment('Categoria detectada pelo Vision (taxonomia SEL-333)');
            $table->float('category_confidence')->nullable()
                  ->comment('Confianca do Vision na classificacao (0.0-1.0)');
            $table->text('director_reason')->nullable()
                  ->comment('Motivo da escolha do pipeline pelo Director');

            // Qualidade do resultado (Fase 2 — auto-review)
            $table->float('avg_review_score')->nullable()
                  ->comment('Score medio dos 3 frames (0.0-1.0) — preenchido pela F2');
            $table->unsignedSmallInteger('retry_count')->default(0)
                  ->comment('Quantas vezes o Director refez o video');

            // Aprovacao manual (Fase 3 — admin Filament)
            $table->boolean('ruan_approved')->nullable()
                  ->comment('null=pendente | true=aprovado | false=rejeitado');
            $table->text('ruan_notes')->nullable();

            // Metricas de custo e performance
            $table->unsignedSmallInteger('generation_time_sec')->nullable()
                  ->comment('Tempo total de geracao em segundos');
            $table->float('cost_credits')->nullable()
                  ->comment('Creditos gastos nesta geracao');
            $table->string('kling_variant', 30)->nullable()
                  ->comment('Variante do Kling usada (kling-v3, kling-v2-master, etc)');

            $table->timestamps();

            // Indices para Fase 3 (aprendizado por categoria+pipeline)
            $table->index(['pipeline_used', 'product_category']);
            $table->index(['ruan_approved', 'avg_review_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_generation_events');
    }
};
