<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * NOV-047-A — Seed tenant 'mestoredrop' na tabela tenants.
 *
 * MEStoreDrop (WL 20 do legado TudoOnline, legacy_empresa_id=447, id_empresa=447)
 * vira tenant do sistema matriz api.hubai.io com acesso ao supplier
 * "M&E store atacado e drop" (suppliers.id=25, legacy_empresa_id=447).
 *
 * Modelo de tenant: sistemas externos consumidores da API (não isolamento por linha).
 * Arquitetura: tenant_supplier N:N controla visibilidade de fornecedores.
 *
 * Referências:
 *   - NOV-047 (tarefa-mãe) — Migração MEStoreDrop legado → Lovable + api.hubai.io
 *   - 2026_05_30_170000_reframe_tenant_to_supplier_access.php (modelo vigente)
 *   - Obsidian HubAI/Projetos/NovoHubAI/Arquitetura.md
 */
return new class extends Migration {
    public function up(): void
    {
        $now = now();

        // Idempotente — não cria se já existir
        $exists = DB::table('tenants')->where('slug', 'mestoredrop')->exists();

        if (!$exists) {
            $mestoredropUuid = Str::uuid()->toString();

            DB::table('tenants')->insert([
                'id'                          => $mestoredropUuid,
                'slug'                        => 'mestoredrop',
                'name'                        => 'MEStoreDrop (WL 20 — migração do legado)',
                'legacy_empresa_id'           => 447,
                'status'                      => 'active',
                'default_supplier_visibility' => 'scoped',
                'write_enabled'               => false,
                'rate_limit_per_min'          => 100,
                'created_at'                  => $now,
                'updated_at'                  => $now,
            ]);

            // Vincular ao supplier M&E store atacado e drop (id=25, legacy_empresa_id=447)
            $supplierId = DB::table('suppliers')
                ->where('legacy_empresa_id', 447)
                ->value('id');

            if ($supplierId) {
                // Idempotente via insert ignore
                $alreadyLinked = DB::table('tenant_supplier')
                    ->where('tenant_id', $mestoredropUuid)
                    ->where('supplier_id', $supplierId)
                    ->exists();

                if (!$alreadyLinked) {
                    DB::table('tenant_supplier')->insert([
                        'tenant_id'   => $mestoredropUuid,
                        'supplier_id' => $supplierId,
                        'created_at'  => $now,
                        'updated_at'  => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $tenant = DB::table('tenants')->where('slug', 'mestoredrop')->first();

        if ($tenant) {
            DB::table('tenant_supplier')->where('tenant_id', $tenant->id)->delete();
            DB::table('tenants')->where('slug', 'mestoredrop')->delete();
        }
    }
};
