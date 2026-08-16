<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier Core — Fase 3 / M1.2 — Endpoints de webhook por tenant.
 * shadow=true => envia mas marca como "nao processar" (modo M3 inicial).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_webhook_endpoints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('url', 500);
            $table->json('events');
            $table->string('secret', 128);
            $table->boolean('active')->default(true);
            $table->boolean('shadow')->default(false);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_webhook_endpoints');
    }
};
