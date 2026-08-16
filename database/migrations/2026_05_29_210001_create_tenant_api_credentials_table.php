<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier Core — Fase 3 / M1.2 — Credenciais de API por tenant.
 * key_id e o prefixo publico (ex: "ht_live_..."); key_hash guarda o segredo hasheado.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_api_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('key_id', 64)->unique();
            $table->string('key_hash', 128);
            $table->json('scopes');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_api_credentials');
    }
};
