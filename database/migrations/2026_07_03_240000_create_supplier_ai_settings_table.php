<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MUL-142-H: Configurações de IA por WL/supplier.
 *
 * Armazena chave OpenAI (criptografada), prompts customizados (base + por marketplace)
 * e flag enabled. Relação 1:1 com suppliers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_ai_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_id')->unique();
            $table->text('openai_api_key')->nullable()->comment('Chave OpenAI criptografada via cast');
            $table->string('openai_model', 60)->nullable()->default('gpt-4o-mini');
            $table->text('system_prompt_base')->nullable()->comment('Prompt base para geração de conteúdo');
            $table->json('system_prompts_marketplace')->nullable()->comment('Prompts por marketplace: {ml, shopee, magalu, ...}');
            $table->boolean('ai_enabled')->default(false);
            $table->timestamps();

            $table->foreign('supplier_id')->references('id')->on('suppliers')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_ai_settings');
    }
};
