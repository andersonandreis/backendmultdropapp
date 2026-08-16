<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOV-fix-manual-label-route - adiciona campos para etiqueta manual.
 *
 * Endpoint POST /api/v1/orders/{id}/manual-label permite ao lojista
 * subir um PDF de etiqueta personalizada para pedidos manuais (canal 13).
 * label_url ja existe mas e usado pelo fluxo automatico (label-fetch).
 * Manter campos separados evita conflito entre etiqueta automatica e manual.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table("orders", function (Blueprint $table) {
            if (! Schema::hasColumn("orders", "manual_label_path")) {
                $table->string("manual_label_path", 500)->nullable()->after("label_url")
                    ->comment("Caminho da etiqueta manual (PDF enviado pelo lojista)");
            }
            if (! Schema::hasColumn("orders", "manual_label_uploaded_at")) {
                $table->timestamp("manual_label_uploaded_at")->nullable()->after("manual_label_path")
                    ->comment("Data/hora do upload da etiqueta manual");
            }
        });
    }

    public function down(): void
    {
        Schema::table("orders", function (Blueprint $table) {
            $table->dropColumn(["manual_label_path", "manual_label_uploaded_at"]);
        });
    }
};
