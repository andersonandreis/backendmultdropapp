<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INF-030 (Ruan 12/08) — "tudo na minha tela ADM": IP/localizacao/dispositivo/
 * paginas+tempo ja vem do Matomo self-hosted (track.hubai.club, site 2). O que
 * o Matomo NAO cobre e o que essa tabela guarda: % assistido do VSL (video
 * embed Bunny, sem lib externa de heatmap paga) e posicao de clique (mapa de
 * calor simples via canvas no admin). Zero API paga.
 *
 * Correlacionado com o visitorId nativo do Matomo (cookie _pk_id, lido via
 * _paq getVisitorId no client) quando disponivel; fica NULL se o Matomo tiver
 * sido bloqueado por adblock (o visitante tambem nao aparece no Matomo nesse
 * caso, entao o heatmap agregado por pagina continua util mesmo sem o join).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_analytics_events', function (Blueprint $table) {
            $table->id();
            $table->string('visitor_uid', 64)->nullable()->index();
            $table->string('event_type', 20)->index(); // click | video_progress
            $table->string('page_url', 500);
            $table->string('page_title', 255)->nullable();
            $table->string('video_id', 64)->nullable();
            $table->unsignedTinyInteger('video_pct')->nullable();
            $table->decimal('x_pct', 5, 2)->nullable();
            $table->decimal('y_pct', 5, 2)->nullable();
            $table->string('el_selector', 255)->nullable();
            $table->string('el_text', 120)->nullable();
            $table->json('meta')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_analytics_events');
    }
};
