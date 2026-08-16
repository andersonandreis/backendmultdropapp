<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOV-171-A — M-171-03
 * Cria tabela federation_sync_log — SOMENTE hubaiapp.
 * Auditoria de todas as operacoes de federacao hub<->WL.
 * Permite rastrear loops, falhas, skips e dedup via payload_hash.
 * WLs nao precisam desta tabela — o hub eh o ponto de auditoria central.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('federation_sync_log')) {
            return;
        }

        Schema::create('federation_sync_log', function (Blueprint $table) {
            $table->id();
            $table->enum('direction', ['hub_to_wl', 'wl_to_hub'])->index();
            $table->enum('entity_type', ['product', 'order', 'order_status']);
            $table->unsignedBigInteger('entity_id')
                ->comment('products.id ou orders.id no hubaiapp');
            $table->string('target_tenant', 50)
                ->comment('Slug do WL destino ou origem (multdrop, fornecefy, mestoredrop)');
            $table->enum('status', ['success', 'failed', 'skipped']);
            $table->char('payload_hash', 64)->nullable()
                ->comment('SHA-256 do payload para dedup — se igual ao ultimo registro para (entity_id, target_tenant), pula');
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id'], 'idx_fed_entity');
            $table->index(['target_tenant', 'status', 'created_at'], 'idx_fed_tenant_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('federation_sync_log');
    }
};
