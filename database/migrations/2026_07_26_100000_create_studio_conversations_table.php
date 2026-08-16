<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-360 Fase 1 — Studio Chat (conversational video director).
 * Armazena conversas + mensagens do Studio Chat pra histórico e contexto LLM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_conversations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('uuid', 36)->unique();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('status', 20)->default('active'); // active|generating|done
            $table->json('context')->nullable(); // produto analisado, assets, intent atual
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('studio_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('conversation_id')->index();
            $table->string('role', 16); // user|assistant|tool
            $table->longText('content');
            $table->json('attachments')->nullable(); // imagens enviadas pelo user
            $table->json('ui_widget')->nullable();   // cards, pickers, progress — renderização frontend
            $table->json('tool_calls')->nullable();  // function calls do LLM
            $table->string('tts_url', 500)->nullable(); // URL do áudio TTS opt-in
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_messages');
        Schema::dropIfExists('studio_conversations');
    }
};
