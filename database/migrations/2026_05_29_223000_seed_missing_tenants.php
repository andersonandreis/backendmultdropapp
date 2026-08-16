<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Supplier Core — Fase 3 — completar tenants a partir de empresas do legado
 * (tudoonline_production.empresas).
 *
 * No M1 inicial usei whitelabel_billing_config (16 empresas), mas o legado tem 23.
 * Faltam 6: Hubmarket.IO (3), Grupo Shopmix (6), Trimpro (9), Super Estoque (11),
 * Empreenvivendo (12), InfinityDrop (14). Adicionadas como active (sao whitelabels
 * com host ativo no legado, apenas sem billing config no Supabase).
 *
 * Idempotente: usa INSERT ... ON DUPLICATE KEY UPDATE (legacy_empresa_id e UNIQUE).
 */
return new class extends Migration {
    public function up(): void
    {
        $now = now();
        $rows = [
            ['hubmarketio',    'Hubmarket.IO',   3,  'active'],
            ['gruposhopmix',   'Grupo Shopmix',  6,  'active'],
            ['trimpro',        'Trimpro',        9,  'active'],
            ['superestoque',   'Super Estoque',  11, 'active'],
            ['empreenvivendo', 'Empreenvivendo', 12, 'active'],
            ['infinitydrop',   'InfinityDrop',   14, 'active'],
        ];

        foreach ($rows as [$slug, $name, $legacyId, $status]) {
            $exists = DB::table('tenants')->where('legacy_empresa_id', $legacyId)->exists();
            if ($exists) continue;

            DB::table('tenants')->insert([
                'id'                          => Str::uuid()->toString(),
                'slug'                        => $slug,
                'name'                        => $name,
                'legacy_empresa_id'           => $legacyId,
                'status'                      => $status,
                'default_supplier_visibility' => 'all',
                'created_at'                  => $now,
                'updated_at'                  => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('tenants')->whereIn('legacy_empresa_id', [3, 6, 9, 11, 12, 14])->delete();
    }
};
