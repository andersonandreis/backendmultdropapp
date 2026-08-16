<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-MEUS-CENARIOS (14/08, ideia que os clientes deram ao Ruan na live):
 * "ele cria o cenário dele e a gente deixa armazenado na galeria pra ele usar
 * na próxima, e ele pode subir o arquivo do cenário dele também".
 *
 * Mesma forma do `client_video_avatars` (o acervo de rosto por cliente), pra
 * não inventar padrão novo: cada linha é um cenário DO cliente — ou uma foto
 * que ele subiu, ou um texto que ele escreveu, ou os dois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_video_scenes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            // foto do cenário (upload do cliente) — pode ser nula quando o
            // cenário é só descrição escrita
            $t->string('image_url', 512)->nullable();
            // o texto que vira o cenário no prompt
            $t->text('prompt')->nullable();
            $t->string('label', 120)->nullable();
            // upload | escrito | gerado (o "a gente cria pra ele", que vem depois)
            $t->string('source', 24)->default('upload');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_video_scenes');
    }
};
