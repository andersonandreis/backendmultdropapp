<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INF-030 (07/08) — pedido extra do Ruan via main: favoritar (⭐) modelos e
 * formatos no Studio (estilo/gancho/avatar/cenario/formato) pra reusar rápido
 * na próxima geração, sem reconfigurar tudo do zero. Aditiva e idempotente.
 * `valor` guarda JSON.stringify(...) do dado escolhido (id, ou {sub,texto},
 * ou {id,url,nome} do avatar) — o front decide o shape por `tipo`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('video_favorites')) {
            return;
        }
        Schema::create('video_favorites', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->enum('tipo', ['estilo', 'gancho', 'avatar', 'cenario', 'formato']);
            $t->string('valor', 255);
            $t->string('label', 160)->nullable();
            $t->unsignedBigInteger('user_id')->index();
            $t->unsignedBigInteger('client_id')->nullable()->index();
            $t->timestamps();
            $t->unique(['user_id', 'tipo', 'valor'], 'vf_user_tipo_valor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_favorites');
    }
};
