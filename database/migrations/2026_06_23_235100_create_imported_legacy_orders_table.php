<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIGRA-V3 — Tabela de historico auditavel de pedidos legados.
 *
 * Importa pedidos do sistema legado (pedidos_curso + pedidos_curso_pedidos)
 * para referencia historica no painel do cliente.
 * Idempotente por legacy_order_id UNIQUE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imported_legacy_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->integer('legacy_user_id')->nullable()->index();
            $table->integer('legacy_order_id')->nullable();
            $table->integer('status_legado')->nullable();
            $table->string('status_novo', 50)->nullable();
            $table->decimal('valor_total', 18, 2)->nullable();
            $table->string('marketplace', 50)->nullable();
            $table->string('marketplace_order_id', 100)->nullable()->index();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->timestamp('imported_at')->nullable();
            $table->boolean('is_historical')->default(true);

            $table->timestamps();

            $table->unique(['client_id', 'legacy_order_id'], 'ilo_client_order_unique');
            $table->index(['client_id', 'occurred_at'], 'ilo_client_occurred_idx');
            $table->index(['legacy_user_id', 'occurred_at'], 'ilo_legacy_occurred_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imported_legacy_orders');
    }
};
