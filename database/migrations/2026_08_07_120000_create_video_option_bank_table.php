<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INF-030 (07/08) — item 1 do briefing (Ruan, via /tokfy Studio): campo livre
 * em cada gancho (abertura/meio/final) e no cenário, ALÉM dos botões prontos.
 * Esta tabela só COLETA o que o cliente escreve — vira matéria-prima pra um
 * dia gerar botão novo automático (curadoria manual por enquanto, sem
 * classificação automática). Aditiva e idempotente, sem dado inventado: cada
 * linha é texto literal digitado pelo cliente em StudioOptions.tsx.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('video_option_bank')) {
            return;
        }
        Schema::create('video_option_bank', function (Blueprint $t) {
            $t->bigIncrements('id');
            // enum textual (nao FK) -- corresponde aos 4 pontos onde o campo
            // livre existe hoje em StudioOptions.tsx: abertura/meio/final
            // (Secao "Como a historia acontece?") e cenario (Secao "Onde
            // esse video acontece?").
            $t->enum('tipo', ['abertura', 'meio', 'final', 'cenario']);
            $t->string('texto', 300);
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->unsignedBigInteger('client_id')->nullable()->index();
            $t->timestamps();
            $t->index(['tipo', 'created_at'], 'vob_tipo_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_option_bank');
    }
};
