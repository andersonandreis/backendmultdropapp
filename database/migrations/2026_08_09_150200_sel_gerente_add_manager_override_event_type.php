<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SEL-GERENTE (09/08): affiliate_commissions.event_type era ENUM('signup','upgrade','recurring')
 * (sel345_expand_affiliates_table) — faltava 'manager_override' pra registrar a comissao
 * recorrente do GERENTE sobre a venda de um afiliado sob ele. Sem isso o insert quebra
 * (SQLSTATE 01000 "Data truncated for column 'event_type'").
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE affiliate_commissions MODIFY event_type ENUM('signup','upgrade','recurring','manager_override') NOT NULL DEFAULT 'upgrade'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE affiliate_commissions MODIFY event_type ENUM('signup','upgrade','recurring') NOT NULL DEFAULT 'upgrade'");
    }
};
