<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier Core / Fase 3 / M4 — habilita write API + tabela de idempotencia.
 *
 * - tenants.write_enabled (default false): feature flag por tenant pra ativar
 *   PATCH/POST. Default OFF — sangramento controlado.
 * - idempotency_keys: armazena resposta cacheada por key+tenant_id por 7 dias.
 *   Reenvio com a mesma key devolve a mesma resposta sem reexecutar a acao.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('write_enabled')->default(false)->after('default_supplier_visibility');
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('tenant_id');
            $table->string('key', 128);
            $table->string('endpoint', 128);
            $table->unsignedSmallInteger('response_status');
            $table->longText('response_body');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'key'], 'idem_tenant_key_unique');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index('expires_at', 'idem_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('write_enabled');
        });
    }
};
