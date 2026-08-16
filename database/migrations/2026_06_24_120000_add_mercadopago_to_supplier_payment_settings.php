<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adiciona 'mercadopago' ao enum gateway da tabela supplier_payment_settings.
 *
 * MySQL nao suporta ALTER COLUMN em enums de forma direta via Blueprint::enum()
 * sem recriar a coluna; usamos ALTER TABLE raw para evitar perda de dados.
 *
 * NOV-066 — 2026-06-24
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement("
            ALTER TABLE supplier_payment_settings
            MODIFY COLUMN gateway ENUM('asaas','shipay','pagarme','mercadopago') NOT NULL DEFAULT 'asaas'
        ");
    }

    public function down(): void
    {
        // Reverter para enum sem mercadopago (somente se nenhum registro usar mercadopago)
        DB::statement("
            ALTER TABLE supplier_payment_settings
            MODIFY COLUMN gateway ENUM('asaas','shipay','pagarme') NOT NULL DEFAULT 'asaas'
        ");
    }
};
