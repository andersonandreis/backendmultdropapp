<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INF-030 (Ruan 12/08, ampliação) — "gravar a tela dele". Motor: rrweb
 * (github.com/rrweb-io/rrweb, o mesmo usado por PostHog/OpenReplay por
 * baixo), embarcado no nosso próprio front — sem plataforma externa pesada
 * (servidor está com load 11 em 8 núcleos + swap cheio, ClickHouse/Kafka de
 * um PostHog/OpenReplay self-hosted não cabe).
 *
 * 1 sessão de gravação = 1 aba do visitante (session_id gerado no client via
 * crypto.randomUUID, guardado em sessionStorage — sobrevive a navegação SPA
 * dentro da mesma aba, novo tab = nova gravação). O client faz flush
 * periódico (chunks) em vez de mandar tudo de uma vez: cada linha aqui é 1
 * chunk (seq crescente), comprimido com CompressionStream('gzip') nativo do
 * browser (zero lib nova) e mandado como base64 (mais simples e seguro sobre
 * HTTP que binário cru atrás do LiteSpeed). Fallback sem gzip (browser sem
 * CompressionStream) marca is_gzip=0.
 *
 * LGPD: os eventos rrweb já chegam aqui com maskAllInputs:true ligado no
 * client (ver src/lib/tracking/sessionRecording.ts) — senha/cartão/CPF nunca
 * saem do navegador em texto puro, chegam como "*". Não é opcional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_session_recordings', function (Blueprint $table) {
            $table->id();
            $table->string('visitor_uid', 64)->nullable()->index();
            $table->string('session_id', 64);
            $table->unsignedInteger('seq')->default(0);
            $table->string('page_url', 500)->nullable();
            $table->boolean('is_gzip')->default(true);
            $table->unsignedInteger('events_count')->default(0);
            $table->unsignedInteger('raw_bytes')->default(0);
            $table->longText('payload_b64');
            $table->timestamp('created_at')->nullable()->useCurrent()->index();
            $table->unique(['session_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_session_recordings');
    }
};
