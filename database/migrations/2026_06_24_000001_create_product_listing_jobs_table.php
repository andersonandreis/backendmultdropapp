<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOV-072 - Robo de Cadastro v2
 *
 * Tabela de fila para publicacao de ClientProducts ja existentes nos marketplaces.
 * Fluxo: client_product (draft/ready) -> product_listing_jobs -> API ML/Shopee -> published.
 *
 * DIFERENTE de auto_listing_queue_items (que cria ClientProducts a partir do catalogo).
 * Esta tabela controla a etapa FINAL de publicacao de produtos ja cadastrados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_listing_jobs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('client_id')
                ->constrained('clients')
                ->cascadeOnDelete();

            $table->foreignId('marketplace_account_id')
                ->constrained('marketplace_accounts')
                ->cascadeOnDelete();

            $table->foreignId('client_product_id')
                ->constrained('client_products')
                ->cascadeOnDelete();

            $table->enum('status', ['pending', 'processing', 'done', 'failed', 'skipped'])
                ->default('pending');

            $table->tinyInteger('attempt')->unsigned()->default(0);

            $table->text('error_message')->nullable();

            // ID retornado pelo marketplace apos publicacao bem-sucedida
            $table->string('external_listing_id', 255)->nullable();

            // Geracao de titulo+descricao melhorada com IA (gpt-4o-mini) antes de publicar
            $table->tinyInteger('generate_image')->default(0)
                ->comment('0=sem IA, 1=gerar titulo+descricao com IA antes de publicar');

            // Controle de velocidade de processamento por cliente
            $table->enum('speed', ['slow', 'normal', 'fast'])->default('normal')
                ->comment('slow=1/min, normal=5/min, fast=20/min');

            $table->timestamps();

            // Indices para queries de fila por cliente e por status global
            $table->index(['client_id', 'status'], 'idx_client_status');
            $table->index('status', 'idx_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_listing_jobs');
    }
};
