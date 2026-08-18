<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MUL-419 — carimbo de "pedido embalado".
 *
 * Ate aqui embalar e despachar eram o MESMO evento no codigo: packingComplete marcava
 * order_processing_status=shipped e shipped_at, e nao sobrava nada dizendo que o volume
 * foi fechado. Medido em 18/08/2026 nos 17.278 pedidos: separated_at, packing_photo_url
 * e external_pack_id estao todos zerados — nenhum campo registrava a embalagem.
 *
 * Coluna nova, anulavel, sem backfill: pedido antigo fica com NULL mesmo, porque de fato
 * ninguem registrou a embalagem dele. A etapa na regua acende so pra quem passar pelo
 * fluxo daqui pra frente.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table("orders", function (Blueprint $table) {
            $table->timestamp("packed_at")->nullable()->after("separated_at");
        });
    }

    public function down(): void
    {
        Schema::table("orders", function (Blueprint $table) {
            $table->dropColumn("packed_at");
        });
    }
};
