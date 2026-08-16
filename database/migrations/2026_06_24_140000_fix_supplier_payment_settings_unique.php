<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrige o unique constraint da tabela supplier_payment_settings.
 *
 * Antes : UNIQUE(supplier_id)          → máximo 1 gateway por fornecedor
 * Depois: UNIQUE(supplier_id, gateway) → múltiplos gateways por fornecedor
 *
 * MySQL não permite dropar um index usado por FK constraint diretamente.
 * Sequência correta:
 *   1. Drop FK
 *   2. Drop unique index antigo (supplier_id)
 *   3. Add unique index novo (supplier_id, gateway)
 *   4. Recriar FK apontando para suppliers.id
 *
 * NOV-067 — 2026-06-24
 */
return new class extends Migration {
    public function up(): void
    {
        // 1. Drop a foreign key que depende do unique index
        DB::statement('ALTER TABLE supplier_payment_settings DROP FOREIGN KEY supplier_payment_settings_supplier_id_foreign');

        // 2. Drop o unique index antigo (apenas supplier_id)
        DB::statement('ALTER TABLE supplier_payment_settings DROP INDEX supplier_payment_settings_supplier_id_unique');

        // 3. Criar novo unique composto (supplier_id, gateway)
        DB::statement('ALTER TABLE supplier_payment_settings ADD UNIQUE KEY supplier_payment_settings_supplier_gateway_unique (supplier_id, gateway)');

        // 4. Recriar a foreign key (agora sem depender do unique index antigo)
        DB::statement('ALTER TABLE supplier_payment_settings ADD CONSTRAINT supplier_payment_settings_supplier_id_foreign FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE CASCADE');
    }

    public function down(): void
    {
        // Reverter: drop FK → drop composto → recriar simples → recriar FK
        // (só funciona se não houver múltiplos registros com mesmo supplier_id)
        DB::statement('ALTER TABLE supplier_payment_settings DROP FOREIGN KEY supplier_payment_settings_supplier_id_foreign');
        DB::statement('ALTER TABLE supplier_payment_settings DROP INDEX supplier_payment_settings_supplier_gateway_unique');
        DB::statement('ALTER TABLE supplier_payment_settings ADD UNIQUE KEY supplier_payment_settings_supplier_id_unique (supplier_id)');
        DB::statement('ALTER TABLE supplier_payment_settings ADD CONSTRAINT supplier_payment_settings_supplier_id_foreign FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE CASCADE');
    }
};
