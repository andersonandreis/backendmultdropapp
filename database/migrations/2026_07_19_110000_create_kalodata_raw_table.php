<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-256 — snapshot cru de Kalodata TikTok Shop BR.
 *
 * Endpoint público `https://www.kalodata.com/overview/rank/queryXTops` devolve
 * dados sem auth. Guardamos payload cru (JSON) por tipo/data — schema-agnostico
 * pra suportar Kalodata mudar campo sem quebrar. Frontend/dashboards consultam
 * daqui via `type + snapshot_date` (índice composto).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('kalodata_raw', function (Blueprint $t) {
            $t->id();
            $t->string('type', 32);            // products | creators | videos | lives | shops
            $t->date('snapshot_date');         // dia do sync (America/Sao_Paulo)
            $t->string('window_range', 32);    // ex: "7d" | "30d"
            $t->string('external_id', 64)->nullable(); // id do produto/criador/vídeo/live/loja
            $t->json('payload');               // objeto completo devolvido pelo Kalodata
            $t->timestamps();

            $t->index(['type', 'snapshot_date']);
            $t->index(['type', 'external_id', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kalodata_raw');
    }
};
