<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-264 — Aba Avisos (canal notícias + push automático).
 * Ruan usa pra: compliance, dicas live, ofertas assinatura, novidade feature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avisos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('titulo', 200);
            $table->string('body_push', 200);
            $table->text('conteudo_markdown');
            $table->enum('categoria', ['compliance', 'oferta', 'dica', 'alerta', 'novidade'])->default('dica');
            $table->enum('prioridade', ['urgente', 'alta', 'media', 'baixa'])->default('media');
            $table->timestamp('published_at')->nullable()->index();
            $table->string('cta_label')->nullable();
            $table->string('cta_url')->nullable();
            $table->string('cover_url')->nullable();
            $table->enum('requires_plan', ['free', 'scaling', 'pro'])->nullable();
            $table->timestamp('push_sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
            $table->index(['categoria', 'published_at']);
            $table->index(['prioridade', 'published_at']);
        });

        Schema::create('aviso_reads', function (Blueprint $table) {
            $table->id();
            $table->uuid('aviso_id');
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->timestamp('read_at')->useCurrent();
            $table->foreign('aviso_id')->references('id')->on('avisos')->cascadeOnDelete();
            $table->unique(['aviso_id', 'client_id']);
            $table->index('read_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aviso_reads');
        Schema::dropIfExists('avisos');
    }
};
