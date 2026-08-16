<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Adiciona legacy_loja_id à tabela suppliers.
 *
 * legacy_loja_id = id na tabela `loja` do legado goolhub.
 * O mapeamento vem de: loja.id WHERE loja.id_deposito = suppliers.legacy_empresa_id
 *
 * Este valor é usado pelo GoolhubBridgeService (id_loja) para:
 *   - supplier_dashboard.php
 *   - picking_packing.php
 *   - get_label.php
 *   - getDevolucoes etc.
 *
 * Antes desta migration, o controller usava config('multdrop.legacy_loja_id')
 * fixo = 565 (Multdrop), ignorando o fornecedor autenticado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->unsignedInteger('legacy_loja_id')->nullable()->after('legacy_empresa_id')
                ->comment('id na tabela loja do legado (bridge id_loja). Mapeado por loja.id_deposito = legacy_empresa_id');
        });

        // Popula com mapeamento levantado do legado (id_deposito → loja.id)
        // Fonte: SELECT id, id_deposito FROM loja WHERE id_deposito IN (...) ORDER BY id_deposito
        $mapping = [
            // legacy_empresa_id => legacy_loja_id
            11  => 75,   // B2Drop / Drop-SP
            13  => 79,   // Envio Nacional RJ
            20  => 93,   // Drop Autopeças
            25  => 97,   // Envio Nacional W
            27  => 101,  // Mundo Thata / Mix Variedades
            53  => 128,  // JT Drop
            54  => 129,  // Infinity Drop
            58  => 132,  // Titanium
            61  => 133,  // Plug Lar
            66  => 138,  // REDAGRO
            373 => 441,  // GALPAO SP DROP
            403 => 471,  // Via/Nutri
            410 => 478,  // Clique RJ
            426 => 494,  // Smart Tech
            430 => 498,  // Brás Mania (GREENDOCK)
            432 => 500,  // Drop2you RJ 1
            437 => 505,  // Drop2you SP 1
            446 => 514,  // Galpão 23 SP
            447 => 515,  // M&E Store / MStore
            465 => 532,  // Peg Comercial
            485 => 552,  // thiago teste / jt drop
            488 => 555,  // atravessadorpro
            498 => 565,  // Multdrop matriz
            500 => 567,  // LogiDrop SP
            503 => 570,  // SALES
            600 => 667,  // UnicDrop
            608 => 675,  // Letielly Shore
            773 => 840,  // Multdrop Filial
        ];

        foreach ($mapping as $legacyEmpresaId => $legacyLojaId) {
            DB::table('suppliers')
                ->where('legacy_empresa_id', $legacyEmpresaId)
                ->whereNull('legacy_loja_id')
                ->update(['legacy_loja_id' => $legacyLojaId]);
        }
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('legacy_loja_id');
        });
    }
};
