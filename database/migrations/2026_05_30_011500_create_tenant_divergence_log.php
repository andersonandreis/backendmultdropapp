<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier Core / Fase 3 / M5 — Log de divergencia detectada pelo monitor.
 * Sempre que o DivergenceCheckJob acha algo que nao bate (estado, deliveries
 * presas, etc), grava aqui. Painel admin pode listar / alertar.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_divergence_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('tenant_id');
            $table->string('check_id', 64);   // ex: 'order_state_mismatch', 'webhook_stuck'
            $table->string('kind', 32);       // 'warning' | 'critical'
            $table->string('subject', 128);   // ex: 'order:23031'
            $table->text('detail')->nullable();
            $table->boolean('resolved')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'resolved', 'created_at'], 'divlog_open_idx');
            $table->index(['check_id', 'created_at'], 'divlog_check_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_divergence_log');
    }
};
