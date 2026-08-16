<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * NOV-082: Popula refresh_token_expires_at para contas Shopee legadas sem esse campo.
 *
 * TTL do refresh_token Shopee = 30 dias a partir da criacao da conta.
 * Backfill: nao altera schema, apenas preenche dados ausentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            UPDATE marketplace_accounts
            SET refresh_token_expires_at = DATE_ADD(created_at, INTERVAL 30 DAY)
            WHERE platform = 'shopee'
              AND refresh_token IS NOT NULL
              AND refresh_token_expires_at IS NULL
        ");
    }

    public function down(): void
    {
        // Reversao manual se necessario: UPDATE marketplace_accounts SET refresh_token_expires_at = NULL WHERE ...
    }
};
