<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-040: historico de geracoes IA (video/imagem/audio/roteiro).
 * Serve pra galeria + regerar + auditoria de custo e reembolso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_generations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('service', 40);              // video|image|tts|dubbing|script|analyze|virtual_try_on|lip_sync|voice_clone|sound_effects|transcribe
            $table->string('provider', 24);             // seedance|kling|elevenlabs|openai
            $table->string('provider_model', 80)->nullable();
            $table->string('provider_task_id', 128)->nullable()->index();
            $table->json('wizard_payload')->nullable(); // seleções do wizard pra regerar
            $table->longText('final_prompt')->nullable();
            $table->string('status', 24)->default('queued');  // queued|processing|succeeded|failed|expired|cancelled
            $table->text('output_url')->nullable();     // URL do artefato final
            $table->string('storage_key', 255)->nullable();  // chave S3/Supabase se baixarmos
            $table->unsignedInteger('credits_debited')->default(0);
            $table->decimal('cost_usd', 10, 4)->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'created_at'], 'ai_gen_tenant_user_created');
            $table->index(['service', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_generations');
    }
};
