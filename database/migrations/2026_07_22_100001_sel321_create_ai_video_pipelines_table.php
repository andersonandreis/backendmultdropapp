<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-321: Gerente em tempo real de pipelines de geração de vídeo.
 * Rastreia cada etapa: render → voice → lipsync → finalize → done/failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_video_pipelines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('mode', 20)->default('perfect')
                  ->comment('perfect=Modo A, clone=Modo B');
            $table->string('product_key', 64)->nullable()->index();
            $table->string('step', 30)->default('queued')
                  ->comment('queued|render|voice|lipsync|finalize|done|failed');
            $table->json('payloads')->nullable()
                  ->comment('Payload completo por etapa (render,voice,lipsync)');
            $table->string('render_task_id', 128)->nullable();
            $table->string('voice_path', 2048)->nullable();
            $table->string('lipsync_task_id', 128)->nullable();
            $table->string('output_url', 2048)->nullable()
                  ->comment('URL final local (tt-media) ou rota /v1/ai/video/{id}/download');
            $table->unsignedTinyInteger('retries')->default(0);
            $table->text('error_message')->nullable();
            $table->boolean('dry_run')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_video_pipelines');
    }
};
