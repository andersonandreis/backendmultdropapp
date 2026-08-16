<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MUL-038: Cria o supplier MEStoreDrop com id=25.
 *
 * O comando import:legacy-history --wl=mestoredrop usa WL_CONFIG com
 * supplier_id=25 fixo. A tabela suppliers nao tinha esse registro,
 * causando FK violation em client_supplier_balances ao tentar inserir
 * balance com supplier_id=25.
 *
 * Dados do legado:
 *   empresas.id=20  -> empresa="MEStoreDrop", url="app.mestoredrop.com.br"
 *   deposito.id=447 -> descricao="M&E store atacado e drop", cidade=Itaborai, estado=RJ
 */
return new class extends Migration
{
    public function up(): void
    {
        // Evita falha se ja existe (idempotente)
        if (DB::table("suppliers")->where("id", 25)->exists()) {
            return;
        }

        DB::table("suppliers")->insert([
            "id"                 => 25,
            "user_id"            => 1,
            "legacy_id"          => 447,
            "legacy_empresa_id"  => 20,
            "type"               => "drop",
            "slug"               => "mestoredrop",
            "company_name"       => "MEStoreDrop",
            "display_name"       => "MEStoreDrop",
            "document"           => "00000000000000",
            "city"               => "Itaborai",
            "state"              => "RJ",
            "is_active"          => true,
            "allows_direct_payment" => false,
            "allows_direct_deposit" => true,
            "pix_fee"            => 0.00,
            "flex_fee"           => 0.00,
            "is_factory"         => false,
            "is_private"         => false,
            "supports_meli_flex" => false,
            "created_at"         => now(),
            "updated_at"         => now(),
        ]);

        DB::statement("ALTER TABLE suppliers AUTO_INCREMENT = 26");
    }

    public function down(): void
    {
        DB::table("suppliers")->where("id", 25)->delete();
    }
};
