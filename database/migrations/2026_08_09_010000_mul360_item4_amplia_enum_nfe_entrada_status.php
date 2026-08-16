<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MUL-360 item 4: o enum de nfe_entrada_status (MUL-161) nao comportava os status
 * reais que BlingNfeService::mapStatus produz — 'authorized', 'denied' e 'blocked'
 * estouravam "Data truncated" e derrubavam tanto a emissao (MUL-275) quanto o
 * espelhamento da NF do fornecedor. Valores novos APENAS no fim do enum
 * (ALTER in-place, sem rebuild da tabela).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE orders MODIFY nfe_entrada_status
            ENUM('pending','received','rejected','exempt','cancelled','authorized','denied','blocked')
            NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("UPDATE orders SET nfe_entrada_status = 'received'
            WHERE nfe_entrada_status IN ('authorized')");
        DB::statement("UPDATE orders SET nfe_entrada_status = 'rejected'
            WHERE nfe_entrada_status IN ('denied','blocked')");
        DB::statement("ALTER TABLE orders MODIFY nfe_entrada_status
            ENUM('pending','received','rejected','exempt','cancelled')
            NOT NULL DEFAULT 'pending'");
    }
};
