<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HUB-032 — Tabela unificada de logs de integração.
 *
 * Centraliza eventos inbound (webhooks recebidos) e outbound (chamadas que
 * o sistema dispara) de qualquer integração (Pagar.me, ML, Shopee, Bling,
 * Chatwoot, OpenAI, Bunny, WL-relay, etc).
 *
 * Estratégia A (ETL) popula a tabela a partir das tabelas existentes
 * (webhook_logs, webhook_deliveries, app_logs, legacy_sync_runs,
 * bridge_relay_queue, email_logs). Estratégia B (real-time via
 * IntegrationLogger + Http::loggedClient) preenche em paralelo.
 *
 * A coluna `source_table` + `source_id` evita duplicação entre execuções
 * sucessivas do agregador.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('integration_logs')) {
            return;
        }
        Schema::create('integration_logs', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Origem
            $table->string('integration_name', 64)
                ->comment('pagarme, mercadolivre, shopee, bling, chatwoot, openai, bunny, wl-relay, cloudflare, etc');
            $table->enum('direction', ['inbound', 'outbound']);
            $table->string('method', 10)->nullable();
            $table->text('url')->nullable();

            // Resultado
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('status', 32)->nullable()->comment('success, failed, pending, dead, received, processed');
            $table->unsignedInteger('response_time_ms')->nullable();

            // Conteúdo (truncado em 8KB pelo logger)
            $table->json('request_payload')->nullable();
            $table->json('response_body')->nullable();
            $table->text('error_message')->nullable();

            // Tenant & relacionamento
            $table->string('tenant_slug', 32)->nullable()
                ->comment('hubai, fornecefy, multdrop, mestoredrop');
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('related_resource_type', 64)->nullable()
                ->comment('order, marketplace_account, product, etc');
            $table->string('related_resource_id', 64)->nullable();
            $table->string('correlation_id', 64)->nullable()
                ->comment('rastreio de chains de chamadas');

            // Idempotência (estratégia A)
            $table->string('source_table', 64)->nullable();
            $table->string('source_id', 64)->nullable();

            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();

            // Índices para queries no Filament
            $table->index(['integration_name', 'created_at'], 'idx_integration_created');
            $table->index(['integration_name', 'status_code', 'created_at'], 'idx_integration_status_created');
            $table->index(['tenant_slug', 'created_at'], 'idx_tenant_created');
            $table->index(['direction', 'created_at'], 'idx_direction_created');
            $table->index('correlation_id', 'idx_correlation');
            $table->index(['related_resource_type', 'related_resource_id'], 'idx_resource');
            $table->unique(['source_table', 'source_id'], 'uq_source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_logs');
    }
};
