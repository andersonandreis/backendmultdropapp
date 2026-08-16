<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 — Centralização sync pedidos.
 *
 * Adiciona tenant_slug em orders como campo desnormalizado de roteamento.
 * NÃO há FK porque o modelo de tenant usa UUID e pedidos não têm dono fixo
 * (um pedido do supplier Multdrop pode ser consumido por multdrop.app E fornecefy).
 *
 * O campo serve para o DispatchTenantOrderWebhookJob identificar rapidamente
 * qual slug (sistema externo) originou o import, sem precisar resolver via
 * tenant_supplier em tempo de despacho.
 *
 * Populado pelo ImportLegacyOrdersJob com base no supplier_id do pedido.
 * Índice composto (tenant_slug, created_at DESC) para queries de webhook backfill.
 *
 * Decisão de arquitetura: tenant_id (UUID) foi removido de orders em
 * 2026_05_30_170000_reframe_tenant_to_supplier_access.php porque pedido não tem
 * dono fixo. tenant_slug é mais leve e serve apenas para roteamento de sync.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('tenant_slug', 50)->nullable()->after('supplier_id')
                ->comment('Slug do tenant que originou o import (ex: multdrop.app, fornecefy, hubai)');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->index(['tenant_slug', 'created_at'], 'idx_orders_tenant_slug_created');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('idx_orders_tenant_slug_created');
            $table->dropColumn('tenant_slug');
        });
    }
};
