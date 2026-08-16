<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-259 — Preferências de push por cliente.
 *
 * Uma linha por client_id. niches = JSON array de slugs de nicho
 * (ex: ["beauty-personal-care","shoes"]) — usados pra filtrar lives alertas.
 * quiet_hours: horário local do cliente pra não receber push de madrugada.
 * Anti-spam: ver AlertActiveLivesJob (máx 1 push/cliente/3h).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('push_preferences')) {
            return;
        }

        Schema::create('push_preferences', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_id')->unique();
            $t->json('niches')->nullable();
            $t->boolean('live_alerts_enabled')->default(true);
            $t->boolean('product_alerts_enabled')->default(true);
            $t->time('quiet_hours_start')->nullable();
            $t->time('quiet_hours_end')->nullable();
            $t->timestamps();

            $t->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_preferences');
    }
};
