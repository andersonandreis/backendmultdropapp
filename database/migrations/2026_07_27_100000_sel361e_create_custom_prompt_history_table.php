<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-361 Fase E — Historico de prompts livres do cliente.
 * Permite que o cliente reutilize prompts anteriores no Modo Prompt Livre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_prompt_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('pipeline_id')->nullable()->index();
            $table->text('prompt');
            $table->string('gear', 20)->default('recomendado'); // rapido|recomendado|cinema|ultra
            $table->unsignedSmallInteger('duration_sec')->default(5);
            $table->string('aspect_ratio', 10)->default('9:16');
            $table->string('model', 40)->nullable();
            $table->string('image_url', 2048)->nullable(); // imagem base enviada
            $table->string('negative_prompt', 1000)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_prompt_history');
    }
};
