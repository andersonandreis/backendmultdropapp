<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FOR-029: seed tenant_supplier para o tenant 'fornecefy'.
 *
 * O unico fornecedor do Fornecefy e o JTDrop (slug='droprio').
 * MEStoreDrop e outros suppliers NAO devem ser vinculados ao Fornecefy.
 *
 * Idempotente via insertOrIgnore.
 */
return new class extends Migration {
    public function up(): void
    {
        $tenantId = DB::table('tenants')->where('slug', 'fornecefy')->value('id');
        if (! $tenantId) {
            return; // tenant nao encontrado — skip silencioso
        }

        // Apenas JTDrop (DropRio) e fornecedor do Fornecefy
        $supplierId = DB::table('suppliers')->where('slug', 'droprio')->value('id');
        if (! $supplierId) {
            return;
        }

        $now = now();
        DB::table('tenant_supplier')->insertOrIgnore([
            'tenant_id'   => $tenantId,
            'supplier_id' => $supplierId,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
    }

    public function down(): void
    {
        $tenantId = DB::table('tenants')->where('slug', 'fornecefy')->value('id');
        if (! $tenantId) {
            return;
        }
        DB::table('tenant_supplier')->where('tenant_id', $tenantId)->delete();
    }
};
