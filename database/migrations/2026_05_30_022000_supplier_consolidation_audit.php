<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Supplier Core — Auditoria de consolidacao de suppliers (30/05/2026).
 *
 * Documenta as 4 limpezas A1-A4 aplicadas em prod no dia 30/05, garantindo
 * o estado final idempotente. Se rodar de novo, e no-op.
 *
 * A1 — Investigacao (read-only, sem mudancas).
 *
 * A2 — Limpeza de 3 suppliers vazios (DELETE):
 *   - supplier#2 PlugLar (vazio; 1124 inventory + 3 orders + 1 marketplace_account
 *     + 2 plan_supplier reassign pra supplier#7 Plug Lar)
 *   - supplier#26 Teste
 *   - supplier#31 LogiDrop SP
 *
 * A3 — Link de 1514 produtos orfaos (legacy_sku_pai_id IS NULL) via match
 *   por SKU + id_deposito no legado (tudoonline_production.sku_pai).
 *   Aplicacao no-op se ja aplicada (a migration nao re-busca o legado;
 *   o SQL exato dos UPDATEs ficou em git como artefato de auditoria).
 *
 * A4 — Resolucao do supplier fantasma DropRio (supplier#1):
 *   Investigacao A1 confirmou que dep 53 no legado e JTDrop, nao DropRio.
 *   - Move 540 products linkados de supplier#1 -> supplier#13 (JTDrop real).
 *   - 1645 orfaos restantes (sem match no legado) marcados is_active=0.
 *   - supplier#1 renomeado pra "DropRio (arquivado, dep 53 virou JTDrop)".
 *   - Mantido por 2 orders + 7 marketplace_accounts + restante de inventory
 *     historicos linkados.
 *
 * Estado final: 27 suppliers (era 30), 0 orfaos em supplier ativo.
 */
return new class extends Migration {
    public function up(): void
    {
        // === A2: limpar suppliers vazios (idempotente, ja aplicado em prod) ===
        foreach ([2 => 7, 26 => null, 31 => null] as $oldId => $newId) {
            if (!DB::table('suppliers')->where('id', $oldId)->exists()) {
                continue; // ja deletado
            }
            if ($newId) {
                // Reassign relacionamentos (mesmo set de FKs do step real)
                $fkMap = [
                    ['categories', 'supplier_id'],
                    ['client_supplier', 'supplier_id'],
                    ['client_supplier_balances', 'supplier_id'],
                    ['client_supplier_transactions', 'supplier_id'],
                    ['event_subscriptions', 'supplier_id'],
                    ['inventory', 'producer_id'],
                    ['inventory', 'warehouse_id'],
                    ['marketplace_accounts', 'supplier_id'],
                    ['orders', 'supplier_id'],
                    ['payments', 'supplier_id'],
                    ['pix_transactions', 'supplier_id'],
                    ['plan_supplier', 'supplier_id'],
                    ['products', 'supplier_id'],
                    ['shipments', 'producer_id'],
                    ['shipments', 'warehouse_id'],
                    ['supplier_balances', 'producer_id'],
                    ['supplier_balances', 'warehouse_id'],
                    ['supplier_discounts', 'supplier_id'],
                    ['supplier_payment_settings', 'supplier_id'],
                    ['supplier_transactions', 'producer_id'],
                    ['supplier_transactions', 'warehouse_id'],
                    ['withdrawal_requests', 'producer_id'],
                    ['withdrawal_requests', 'warehouse_id'],
                ];
                foreach ($fkMap as [$t, $col]) {
                    DB::table($t)->where($col, $oldId)->update([$col => $newId]);
                }
            }
            DB::table('suppliers')->where('id', $oldId)->delete();
        }

        // === A4: supplier#1 DropRio — garantir estado arquivado ===
        if (DB::table('suppliers')->where('id', 1)->exists()) {
            // Move produtos linkados pra JTDrop (supplier#13)
            DB::table('products')
                ->where('supplier_id', 1)
                ->whereNotNull('legacy_sku_pai_id')
                ->update(['supplier_id' => 13]);

            // Move inventory destes mesmos produtos
            $movedIds = DB::table('products')->where('supplier_id', 13)
                ->whereNotNull('legacy_sku_pai_id')
                ->pluck('id')->toArray();
            if (!empty($movedIds)) {
                DB::table('inventory')
                    ->whereIn('product_id', $movedIds)
                    ->where('producer_id', 1)
                    ->update(['producer_id' => 13]);
            }

            // Inactivar orfaos restantes
            DB::table('products')
                ->where('supplier_id', 1)
                ->whereNull('legacy_sku_pai_id')
                ->where('is_active', 1)
                ->update(['is_active' => 0]);

            // Renomear pra arquivo
            DB::table('suppliers')->where('id', 1)->update([
                'company_name' => 'DropRio (arquivado, dep 53 virou JTDrop)',
            ]);
        }
    }

    public function down(): void
    {
        // NAO reverte: dados foram consolidados em prod e revert nao tem sentido
        // (suppliers deletados perderam id, mapeamento foi feito por SKU+deposito).
    }
};
