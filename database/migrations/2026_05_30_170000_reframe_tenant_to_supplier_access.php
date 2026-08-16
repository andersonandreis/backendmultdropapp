<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Reframing 30/05/2026 — tenant agora representa SISTEMA EXTERNO consumidor da API.
 *
 * Mudanças:
 *  - DROP orders.tenant_id, orders.tenant_seller_id (pedido nao tem dono fixo)
 *  - DROP clients.tenant_id (cliente nao pertence a sistema externo)
 *  - CREATE tenant_supplier (N:N) — qual sistema enxerga quais fornecedores
 *  - TRUNCATE tenants (22 entries que eram whitelabels do legado, conceito errado)
 *  - SEED: 2 tenants reais — multdrop.app (ve so MultDrop) e fornecefy (ve todos)
 *
 * Order::scopeForTenant agora usa subquery via tenant_supplier.
 *
 * Ver: Obsidian/Recursos/Arquitetura Legado Goolhub.md (legado é monolito)
 *      Obsidian/Recursos/Arquitetura NovoHubAI Sistemas Externos.md (a criar)
 *      memory/feedback_legado_eh_monolito_compartilhado.md
 */
return new class extends Migration {
    public function up(): void
    {
        // 1) Drop colunas obsoletas em orders
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropIndex('idx_orders_tenant_status');
            $table->dropIndex('idx_orders_tenant_created');
            $table->dropColumn(['tenant_id', 'tenant_seller_id']);
        });

        // 2) Drop coluna obsoleta em clients
        if (Schema::hasColumn('clients', 'tenant_id')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropForeign(['tenant_id']);
                $table->dropIndex(['tenant_id']);
                $table->dropColumn('tenant_id');
            });
        }

        // 3) Criar tabela tenant_supplier (N:N)
        Schema::create('tenant_supplier', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->unsignedBigInteger('supplier_id');
            $table->timestamps();

            $table->primary(['tenant_id', 'supplier_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('supplier_id')->references('id')->on('suppliers')->onDelete('cascade');
            $table->index('supplier_id');
        });

        // 4) Reset tenants + tabelas filhas (FKs desligadas momentaneamente)
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('idempotency_keys')->truncate();
        DB::table('webhook_deliveries')->truncate();
        DB::table('tenant_webhook_endpoints')->truncate();
        DB::table('tenant_api_credentials')->truncate();
        DB::table('tenant_divergence_log')->truncate();
        DB::table('tenant_supplier')->truncate();
        DB::table('tenants')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // 5) Seed 2 tenants reais (sistemas externos consumidores)
        $multdropApp = Str::uuid()->toString();
        $fornecefy   = Str::uuid()->toString();
        $now         = now();

        DB::table('tenants')->insert([
            [
                'id'                          => $multdropApp,
                'slug'                        => 'multdrop.app',
                'name'                        => 'multdrop.app (sistema separado do MultDrop)',
                'legacy_empresa_id'           => null,
                'status'                      => 'active',
                'default_supplier_visibility' => 'scoped',
                'write_enabled'               => false,
                'rate_limit_per_min'          => 100,
                'created_at'                  => $now,
                'updated_at'                  => $now,
            ],
            [
                'id'                          => $fornecefy,
                'slug'                        => 'fornecefy',
                'name'                        => 'Fornecefy (multi-fornecedor)',
                'legacy_empresa_id'           => null,
                'status'                      => 'active',
                'default_supplier_visibility' => 'scoped',
                'write_enabled'               => false,
                'rate_limit_per_min'          => 100,
                'created_at'                  => $now,
                'updated_at'                  => $now,
            ],
        ]);

        // 6) tenant_supplier: multdrop.app -> supplier MultDrop (legacy_empresa_id=498)
        $multdropSupplierId = DB::table('suppliers')->where('legacy_empresa_id', 498)->value('id');
        if ($multdropSupplierId) {
            DB::table('tenant_supplier')->insert([
                'tenant_id'   => $multdropApp,
                'supplier_id' => $multdropSupplierId,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        // 7) tenant_supplier: fornecefy -> TODOS suppliers (exceto o arquivado DropRio)
        $allSuppliers = DB::table('suppliers')
            ->where('company_name', 'not like', '%arquivado%')
            ->pluck('id');
        foreach ($allSuppliers as $sid) {
            DB::table('tenant_supplier')->insert([
                'tenant_id'   => $fornecefy,
                'supplier_id' => $sid,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }

    public function down(): void
    {
        // No-op: reframing nao tem revert sensato. Tabelas/colunas removidas
        // foram criadas em migrations anteriores que podem ser revertidas
        // individualmente se necessario (custos historicos).
    }
};
