<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TenantsSeeder — garante que os tenants canônicos do HubAI existam.
 *
 * Idempotente: usa firstOrCreate por slug.
 *
 * Tenants seeded:
 *   - hubai     → sistema interno HubAI (acessa todos os suppliers)
 *   - fornecefy → já existe via migration 2026_05_30_170000 (skip se presente)
 *   - multdrop.app → já existe via migration 2026_05_30_170000 (skip se presente)
 *
 * supplier_id: campo não existe na tabela tenants atual (relação é N:N via
 * tenant_supplier). Deixamos o vínculo via console/admin após confirmar
 * os IDs de supplier corretos.
 */
class TenantsSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $tenants = [
            [
                'slug'                        => 'hubai',
                'name'                        => 'HubAI (sistema interno)',
                'status'                      => 'active',
                'default_supplier_visibility' => 'all',
                'write_enabled'               => false,
                'rate_limit_per_min'          => 1000,
            ],
            [
                'slug'                        => 'fornecefy',
                'name'                        => 'Fornecefy (multi-fornecedor)',
                'status'                      => 'active',
                'default_supplier_visibility' => 'scoped',
                'write_enabled'               => false,
                'rate_limit_per_min'          => 100,
            ],
            [
                'slug'                        => 'multdrop.app',
                'name'                        => 'multdrop.app (sistema separado do MultDrop)',
                'status'                      => 'active',
                'default_supplier_visibility' => 'scoped',
                'write_enabled'               => false,
                'rate_limit_per_min'          => 100,
            ],
        ];

        foreach ($tenants as $data) {
            $exists = DB::table('tenants')->where('slug', $data['slug'])->exists();
            if (!$exists) {
                DB::table('tenants')->insert(array_merge($data, [
                    'id'         => Str::uuid()->toString(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
                $this->command->info("Tenant criado: {$data['slug']}");
            } else {
                $this->command->line("Tenant já existe (skip): {$data['slug']}");
            }
        }
    }
}
