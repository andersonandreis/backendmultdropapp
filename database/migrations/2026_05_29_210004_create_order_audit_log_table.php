<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier Core — Fase 3 / M1.2 — Trilha de auditoria por pedido.
 * Cada transicao de status / acao por tenant ou sistema vira uma linha.
 * Base do "historico" exigido pela diretriz Ruan.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_audit_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id')->index();
            $table->string('actor_type', 32);   // tenant | hubai | supplier | marketplace | system
            $table->string('actor_id', 128)->nullable();
            $table->string('action', 64);       // status_change | tracking_set | cancel | refund | ...
            $table->string('from_state', 32)->nullable();
            $table->string('to_state', 32)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('at')->useCurrent();

            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
            $table->index(['order_id', 'at']);
            $table->index(['actor_type', 'actor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_audit_log');
    }
};
