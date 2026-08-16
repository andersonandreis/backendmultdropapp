<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Supplier Core — Suspensao da API multi-tenant (30/05/2026).
 *
 * Premissa revista: whitelabels do legado rodam no mesmo monólito PHP
 * (mesma stack, mesmo MySQL, isolamento por id_empresa). Não há sistema
 * externo pra consumir API + receber webhook. Ver
 * Obsidian/Recursos/Arquitetura Legado Goolhub.md +
 * memory/feedback_legado_eh_monolito_compartilhado.md.
 *
 * Esta migration documenta a suspensao (idempotente):
 *  - revoga todas tenant_api_credentials ativas
 *  - desliga tenants.write_enabled
 *  - desativa tenant_webhook_endpoints
 *
 * O SCHEMA permanece (tabelas + colunas + models + controllers) — pode ser
 * reativado quando NovoHubAI substituir o legado e a federação fizer sentido.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('tenant_api_credentials')->whereNull('revoked_at')->update([
            'revoked_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tenants')->where('write_enabled', 1)->update([
            'write_enabled' => 0,
            'updated_at'    => now(),
        ]);
        DB::table('tenant_webhook_endpoints')->where('active', 1)->update([
            'active'     => 0,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Sem revert: reativacao manual via UPDATE direto quando fizer sentido.
    }
};
