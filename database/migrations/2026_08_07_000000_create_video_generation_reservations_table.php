<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-490 — cota de geração de vídeo por plano (server-side, atômica) + trilha
 * anti-revenda. Cada tentativa de geração cria UMA reserva (dentro de lock
 * MariaDB GET_LOCK), o que serve de contador atômico do limite diário E de log
 * de IP/UA/fingerprint por geração. Aditiva e idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('video_generation_reservations')) {
            return;
        }
        Schema::create('video_generation_reservations', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id')->index();
            $t->unsignedBigInteger('client_id')->nullable();
            $t->string('plan_slug', 64)->nullable();
            $t->unsignedBigInteger('pipeline_id')->nullable();
            $t->enum('status', ['reserved', 'refunded'])->default('reserved');
            $t->string('ip', 64)->nullable();
            $t->string('user_agent', 400)->nullable();
            $t->string('fingerprint', 128)->nullable();
            $t->timestamps();
            // contador do limite diário: (user, status, created_at)
            $t->index(['user_id', 'status', 'created_at'], 'vgr_user_status_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_generation_reservations');
    }
};
