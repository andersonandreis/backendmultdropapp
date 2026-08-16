<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier Core — Fase 3 / M1.2 — Historico de entregas de webhook.
 * idempotency_key e UNIQUE pra impedir reenvio duplicado caso o dispatcher reentre.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('endpoint_id');
            $table->string('event', 64);
            $table->json('payload');
            $table->string('idempotency_key', 128)->unique();
            $table->unsignedInteger('attempt')->default(0);
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamp('next_retry_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('endpoint_id')->references('id')->on('tenant_webhook_endpoints')->onDelete('cascade');
            $table->index(['endpoint_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
