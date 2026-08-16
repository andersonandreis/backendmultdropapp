<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-361 Fase A — Tabelas de feedback loop + banco de aprendizado de pipeline.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Tabela de feedback pós-geração
        Schema::create('video_feedback', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('generation_id')->index()
                ->comment('Referencia ai_video_pipelines.id');
            $table->unsignedBigInteger('client_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->enum('rating', ['great', 'ok', 'bad']);
            $table->string('free_text', 500)->nullable()
                ->comment('Palavra livre do cliente sobre o que melhorar');
            // Contexto do pipeline no momento do feedback
            $table->string('pipeline', 64)->nullable();
            $table->string('category', 128)->nullable();
            $table->string('vibe', 64)->nullable();
            $table->string('hook_type', 32)->nullable();
            $table->timestamps();

            $table->index(['pipeline', 'category', 'vibe', 'hook_type'], 'vf_pipeline_ctx');
        });

        // Tabela de aprendizado acumulado (atualizada pelo AnalyzeFeedbackJob diário)
        Schema::create('pipeline_learnings', function (Blueprint $table) {
            $table->id();
            $table->string('category', 128)->comment('Categoria do produto detectada');
            $table->string('pipeline', 64)->comment('Pipeline: pov_so_mao, showcase_silencioso, etc.');
            $table->string('vibe', 64)->nullable()->comment('Vibe: energetico, suave, asmr, etc.');
            $table->string('hook_type', 32)->nullable()->comment('reveal_macro|corte_acao|antes_depois|pergunta_visual|zoom_explosivo');
            $table->unsignedInteger('wins')->default(0);
            $table->unsignedInteger('losses')->default(0);
            $table->unsignedInteger('neutrals')->default(0);
            $table->decimal('win_rate', 5, 4)->default(0)->comment('wins / (wins+losses+neutrals)');
            $table->boolean('is_baseline')->default(false)
                ->comment('true = dado de referência Kalodata (nao conta como win/loss real)');
            $table->json('metadata')->nullable()
                ->comment('Dados extras: avg_cuts, avg_shot_duration, cta_position, etc.');
            $table->timestamp('last_analyzed_at')->nullable();
            $table->timestamps();

            $table->unique(['category', 'pipeline', 'vibe', 'hook_type'], 'pl_context_unique');
            $table->index('win_rate');
        });

        // Tabela de eventos do AntiStrikeGuard (auditoria)
        Schema::create('strike_guard_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->string('check_type', 64)->comment('avatar_exclusive|celebrity|trademark|content_policy');
            $table->enum('result', ['allowed', 'blocked', 'warned']);
            $table->string('pipeline', 64)->nullable();
            $table->text('reason')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'check_type', 'result']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strike_guard_events');
        Schema::dropIfExists('pipeline_learnings');
        Schema::dropIfExists('video_feedback');
    }
};
