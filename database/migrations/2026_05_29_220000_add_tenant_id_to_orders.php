<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier Core — Fase 3 / M2.1 — Adiciona tenant_id + canonical_status + external_refs em orders.
 *
 * Default tenant_id = UUID do tenant "hubai" (b43c2e8a-00cb-4f44-9c45-3b045ea51aab).
 * Pedido sem tenant explicito vira hubai — seguro contra vazamento de pedido entre whitelabels.
 *
 * canonical_status: mapeado a partir do status legado (paid/shipped/delivered/cancelled mantem,
 * pending/pending_payment viram "created").
 *
 * Backfill ajuste fino (mapeamento client_id->tenant via legado) e M2.2/M2.3 (comando artisan).
 */
return new class extends Migration {
    private const HUBAI_UUID = 'b43c2e8a-00cb-4f44-9c45-3b045ea51aab';

    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->uuid('tenant_id')->default(self::HUBAI_UUID)->after('id');
            $table->uuid('tenant_seller_id')->nullable()->after('tenant_id');
            $table->string('canonical_status', 32)->default('created')->after('status');
            $table->json('external_refs')->nullable()->after('canonical_status');
        });

        // FK + indices (separado pra evitar lock combinado)
        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->index(['tenant_id', 'canonical_status'], 'idx_orders_tenant_status');
            $table->index(['tenant_id', 'created_at'], 'idx_orders_tenant_created');
        });

        // Popular canonical_status baseado em status legado
        DB::statement("
            UPDATE orders SET canonical_status = CASE
                WHEN status = 'paid' THEN 'paid'
                WHEN status = 'shipped' THEN 'shipped'
                WHEN status = 'delivered' THEN 'delivered'
                WHEN status = 'cancelled' THEN 'cancelled'
                WHEN status = 'pending_payment' THEN 'created'
                WHEN status = 'pending' THEN 'created'
                ELSE 'created'
            END
        ");
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropIndex('idx_orders_tenant_status');
            $table->dropIndex('idx_orders_tenant_created');
            $table->dropColumn(['tenant_id', 'tenant_seller_id', 'canonical_status', 'external_refs']);
        });
    }
};
