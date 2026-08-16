<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MestoredropTenantSeeder — NOV-047-A
 *
 * Seed idempotente do tenant MEStoreDrop + tenants canônicos (hubai, multdrop, fornecefy).
 *
 * Uso:
 *   php artisan db:seed --class=MestoredropTenantSeeder --force
 *
 * MEStoreDrop = WL 20 do legado TudoOnline (legacy_empresa_id=447).
 * Supplier local: "M&E store atacado e drop" (suppliers.id=25).
 *
 * Modelo: tenant representa sistema externo consumidor da API.
 * Acesso a fornecedores controlado via tenant_supplier (N:N).
 */
class MestoredropTenantSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $tenants = [
            [
                'slug'                        => 'hubai',
                'name'                        => 'HubAI (sistema interno)',
                'legacy_empresa_id'           => null,
                'status'                      => 'active',
                'default_supplier_visibility' => 'all',
                'write_enabled'               => false,
                'rate_limit_per_min'          => 1000,
                'supplier_legacy_id'          => null,  // acessa todos (all)
            ],
            [
                'slug'                        => 'multdrop',
                'name'                        => 'MultDrop (frontend WL)',
                'legacy_empresa_id'           => null,
                'status'                      => 'active',
                'default_supplier_visibility' => 'scoped',
                'write_enabled'               => false,
                'rate_limit_per_min'          => 100,
                'supplier_legacy_id'          => 498,  // Multdrop (suppliers.legacy_empresa_id=498)
            ],
            [
                'slug'                        => 'fornecefy',
                'name'                        => 'Fornecefy (multi-fornecedor)',
                'legacy_empresa_id'           => null,
                'status'                      => 'active',
                'default_supplier_visibility' => 'scoped',
                'write_enabled'               => false,
                'rate_limit_per_min'          => 100,
                'supplier_legacy_id'          => null,  // vinculado manualmente no admin
            ],
            [
                'slug'                        => 'mestoredrop',
                'name'                        => 'MEStoreDrop (WL 20 — migração do legado)',
                'legacy_empresa_id'           => 447,
                'status'                      => 'active',
                'default_supplier_visibility' => 'scoped',
                'write_enabled'               => false,
                'rate_limit_per_min'          => 100,
                'supplier_legacy_id'          => 447,  // M&E store atacado e drop
            ],
        ];

        foreach ($tenants as $data) {
            $supplierLegacyId = $data['supplier_legacy_id'];
            unset($data['supplier_legacy_id']);

            $existingTenant = DB::table('tenants')->where('slug', $data['slug'])->first();

            if (!$existingTenant) {
                $uuid = Str::uuid()->toString();
                DB::table('tenants')->insert(array_merge($data, [
                    'id'         => $uuid,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
                $existingTenant = DB::table('tenants')->where('slug', $data['slug'])->first();
                $this->command->info("  [CRIADO] Tenant: {$data['slug']} (UUID: {$existingTenant->id})");
            } else {
                $this->command->line("  [SKIP]   Tenant já existe: {$data['slug']}");
            }

            // Vincular supplier se definido
            if ($supplierLegacyId && $existingTenant) {
                $supplierId = DB::table('suppliers')
                    ->where('legacy_empresa_id', $supplierLegacyId)
                    ->value('id');

                if ($supplierId) {
                    $linked = DB::table('tenant_supplier')
                        ->where('tenant_id', $existingTenant->id)
                        ->where('supplier_id', $supplierId)
                        ->exists();

                    if (!$linked) {
                        DB::table('tenant_supplier')->insert([
                            'tenant_id'   => $existingTenant->id,
                            'supplier_id' => $supplierId,
                            'created_at'  => $now,
                            'updated_at'  => $now,
                        ]);
                        $this->command->info("  [LINK]   {$data['slug']} -> supplier #{$supplierId}");
                    } else {
                        $this->command->line("  [SKIP]   Link tenant_supplier já existe: {$data['slug']} -> #{$supplierId}");
                    }
                } else {
                    $this->command->warn("  [WARN]   Supplier legacy_empresa_id={$supplierLegacyId} não encontrado.");
                }
            }
        }

        $this->command->info('');
        $this->command->info('MestoredropTenantSeeder concluido.');
        $this->command->info('Tenants ativos:');
        DB::table('tenants')->where('status', 'active')->orderBy('slug')->each(function ($t) {
            $this->command->line("  - {$t->slug}: {$t->name}");
        });
    }
}
