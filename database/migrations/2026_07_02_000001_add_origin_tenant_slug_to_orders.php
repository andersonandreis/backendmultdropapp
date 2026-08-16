<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOV-171-A — M-171-01
 * Adiciona origin_tenant_slug em orders — SOMENTE hubaiapp.
 * Identifica de qual WL o pedido se originou (ex: multdrop, fornecefy).
 * WLs nao tem tabela orders — guard retorna imediatamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Nao adicionar se orders nao existe (nenhum backend deve chegar aqui sem a tabela)
        if (!Schema::hasTable('orders')) {
            return;
        }

        if (Schema::hasColumn('orders', 'origin_tenant_slug')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->string('origin_tenant_slug', 50)
                ->nullable()
                ->after('tenant_slug')
                ->comment('Slug do WL de origem do pedido (multdrop, fornecefy, mestoredrop). Null = pedido nativo do hub.');

            $table->index('origin_tenant_slug', 'idx_orders_origin_tenant');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        if (!Schema::hasColumn('orders', 'origin_tenant_slug')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('idx_orders_origin_tenant');
            $table->dropColumn('origin_tenant_slug');
        });
    }
};
