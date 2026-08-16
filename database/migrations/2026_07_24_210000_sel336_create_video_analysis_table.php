<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-336 — tabela de análise estruturada de vídeos virais Kalodata.
 *
 * Cada linha = 1 vídeo Kalodata analisado via Whisper + GPT-4o-mini.
 * Alimentada pelo cron AnalyzeKalodataVideosCommand (daily 07:00 BRT).
 * Consumida por:
 *   GET  /api/v1/insights/kalodata/videos/{id}/analysis  (front exibe transcrição + insight)
 *   POST /api/v1/ai/video-modelar-viral                  (gera vídeo Kling seguindo estrutura)
 *
 * Campos:
 *   kalodata_video_id — external_id do vídeo em kalodata_raw (type=videos)
 *   country           — BR | US (suporta Fase 2 US)
 *   transcript        — tudo que a pessoa falou (Whisper)
 *   hook_0_3s         — o gancho dos primeiros 3 segundos
 *   problem           — problema/dor que o vídeo resolve
 *   solution          — solução apresentada (o produto)
 *   cta               — chamada pra ação no fim
 *   vibe              — categoria do tipo de vídeo
 *   duration_sec      — duração em segundos (da análise)
 *   video_url_cached  — URL do mp4 baixado (storage local ou original)
 *   whisper_cost_usd  — custo da chamada Whisper (auditoria)
 *   gpt_cost_usd      — custo da chamada GPT (auditoria)
 *   analyzed_at       — quando a análise foi concluída
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('video_analysis', function (Blueprint $t) {
            $t->id();
            $t->string('kalodata_video_id', 64)->index(); // external_id de kalodata_raw
            $t->string('country', 2)->default('BR');      // BR | US
            $t->longText('transcript')->nullable();        // transcrição completa Whisper
            $t->text('hook_0_3s')->nullable();             // gancho primeiros 3s
            $t->text('problem')->nullable();               // problema/dor
            $t->text('solution')->nullable();              // solução (produto)
            $t->text('cta')->nullable();                   // call to action final
            $t->enum('vibe', ['review', 'unboxing', 'showcase', 'reacao', 'tutorial', 'outro'])->default('outro');
            $t->unsignedSmallInteger('duration_sec')->nullable(); // duração
            $t->string('video_url_cached', 2048)->nullable();     // mp4 local/original
            $t->decimal('whisper_cost_usd', 8, 6)->nullable();
            $t->decimal('gpt_cost_usd', 8, 6)->nullable();
            $t->timestamp('analyzed_at')->nullable();
            $t->timestamps();

            // Índice composto: 1 análise por vídeo+país
            $t->unique(['kalodata_video_id', 'country']);
            $t->index(['country', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_analysis');
    }
};
