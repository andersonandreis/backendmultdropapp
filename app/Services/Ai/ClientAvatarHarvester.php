<?php

namespace App\Services\Ai;

use App\Models\AiVideoPipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * SEL-497 — ClientAvatarHarvester
 *
 * Quando um pipeline de vídeo chega em `done`, salva o ROSTO/APRESENTADOR usado
 * na geração dentro de client_video_avatars, pro cliente já ter o avatar pronto
 * na próxima vez (economiza gerar/escolher de novo). Ruan 31/07.
 *
 * REGRAS (descobertas na investigação SEL-497):
 *  - O rosto NÃO é o payloads.image_url (isso é a foto do PRODUTO). O avatar/rosto
 *    vive, nesta ordem: avatar_meta.url → avatar_url → person_url → face_url.
 *  - Modos sem rosto (video_do_zero, animar_produto, pov_so_mao) não têm nenhum
 *    desses campos → o método sai limpo, sem gravar nada.
 *  - user→client é 1:1 (clients.user_id UNIQUE).
 *  - Dedupe por client: mesma custom_avatar_url OU mesmo video_avatar_id (quando a
 *    url casa um video_avatars.image_url = avatar do pool).
 *  - NUNCA lança: é chamado dentro do finalize; falha aqui não pode derrubar um
 *    vídeo que já ficou pronto.
 *
 * SEL-avatarpersist (10/08, Ruan "cliente nao consegue reusar o mesmo rosto"):
 *  - O caminho acima (passo 1) SO funciona quando a geracao JA usou um avatar
 *    conhecido como ENTRADA (avatar_apresentando/trocar_rosto/provador_virtual).
 *    O fluxo mais comum HOJE (Studio Options, estilo=ugc, SEM avatar selecionado)
 *    nunca alimenta nenhum desses campos -> nunca aprendia avatar nenhum, mesmo
 *    a apresentadora aparecendo no video pronto. Fica pareceno "nao funciona".
 *  - FALLBACK novo (bootstrapFromVideoFrame, so quando passo 1 nao achou nada):
 *    extrai 1 frame do MP4 pronto via ffmpeg e salva como avatar — MAS SO quando
 *    o cliente ainda NAO TEM nenhum avatar ativo (bootstrap do "avatar padrao",
 *    nao acumula 1 linha nova por video -> nao lota o seletor de lixo).
 */
class ClientAvatarHarvester
{
    /** Salva o avatar da geração na conta do cliente (idempotente, não-fatal). */
    public static function harvestFromPipeline(AiVideoPipeline $pipeline): void
    {
        try {
            $payloads = $pipeline->payloads ?? [];

            // 1. Resolve a url do rosto/apresentador na ordem descoberta.
            //    NUNCA usa image_url (é a foto do PRODUTO).
            $avatarUrl = $payloads['avatar_meta']['url']
                ?? $payloads['avatar_url']
                ?? $payloads['person_url']
                ?? $payloads['face_url']
                ?? null;

            // Modos sem rosto guardam literalmente a string "null" em alguns campos.
            if (! is_string($avatarUrl) || $avatarUrl === '' || strtolower($avatarUrl) === 'null') {
                $avatarUrl = null;
            }
            if ($avatarUrl && ! filter_var($avatarUrl, FILTER_VALIDATE_URL)) {
                $avatarUrl = null;
            }
            if (! $pipeline->user_id) {
                return;
            }

            // 2. client (1:1 com user).
            $client = DB::table('clients')->where('user_id', $pipeline->user_id)->first();
            if (! $client) {
                return;
            }

            // SEL-avatarpersist: nao veio avatar conhecido no payload -> tenta o
            // bootstrap por frame (so se o cliente ainda nao tem avatar nenhum).
            // Sai (return) sempre: ou bootstrapou, ou nao tinha condicao — nada
            // mais a fazer neste pipeline sem avatarUrl conhecido.
            if (! $avatarUrl) {
                self::bootstrapFromVideoFrame($pipeline, $client, $payloads);
                return;
            }

            // 3. A url casa um avatar do POOL? Então linkamos video_avatar_id em vez
            //    de copiar a url (mantém o vínculo ao acervo do admin).
            $pool = DB::table('video_avatars')->where('image_url', $avatarUrl)->first();
            $poolId = $pool->id ?? null;

            // 4. Dedupe por client_id: mesma url custom OU mesmo avatar de pool.
            $exists = DB::table('client_video_avatars')
                ->where('client_id', $client->id)
                ->where(function ($q) use ($avatarUrl, $poolId) {
                    $q->where('custom_avatar_url', $avatarUrl);
                    if ($poolId) {
                        $q->orWhere('video_avatar_id', $poolId);
                    }
                })
                ->exists();

            if ($exists) {
                Log::info('[SEL-497 auto_geracao] avatar já existe, dedupe', [
                    'pipeline_id'     => $pipeline->id,
                    'client_id'       => $client->id,
                    'video_avatar_id' => $poolId,
                ]);
                return;
            }

            // 5. Insere. Pool → grava video_avatar_id; rosto avulso → custom_avatar_url.
            $avatarId = DB::table('client_video_avatars')->insertGetId([
                'client_id'              => $client->id,
                'video_avatar_id'        => $poolId ?: null,
                'custom_avatar_url'      => $poolId ? null : $avatarUrl,
                'label'                  => $poolId ? ($pool->label ?? 'Meu avatar') : 'Meu avatar',
                'source'                 => 'auto_geracao',
                'is_exclusive_to_client' => $poolId ? 0 : 1,
                'is_active'              => 1,
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);

            Log::info('[SEL-497 auto_geracao] avatar salvo da geração', [
                'pipeline_id'     => $pipeline->id,
                'client_id'       => $client->id,
                'avatar_id'       => $avatarId,
                'video_avatar_id' => $poolId,
                'field'           => $poolId ? 'pool' : 'custom',
            ]);
        } catch (Throwable $e) {
            Log::warning('[SEL-497 auto_geracao] falha ao salvar avatar do pipeline', [
                'pipeline_id' => $pipeline->id ?? null,
                'err'         => $e->getMessage(),
            ]);
        }
    }

    /**
     * SEL-avatarpersist — bootstrap do avatar "padrao" do cliente extraindo 1 frame
     * do MP4 pronto, pra geracoes SEM avatar_url conhecido no payload (o caso mais
     * comum: Studio Options estilo=ugc sem avatar selecionado). So roda quando:
     *   - o cliente AINDA NAO TEM nenhum avatar ativo (bootstrap unico, nao acumula
     *     1 linha por video — senao o seletor de avatar vira uma lista enorme);
     *   - o estilo tem apresentadora em quadro de verdade (so 'ugc' — pov so mostra
     *     maos, showcase nao tem pessoa, video_do_zero nao garante nada confiavel);
     *   - existe output_url pronto pra extrair frame.
     * ffmpeg com timeout curto (10s) — nao pode pesar no servidor/atrasar fila.
     * Fail-open total: qualquer erro so loga warning, nunca propaga (chamador ja
     * esta dentro de outro try/catch, mas isolamos aqui tambem por clareza).
     */
    private static function bootstrapFromVideoFrame(AiVideoPipeline $pipeline, object $client, array $payloads): void
    {
        try {
            $estilo = $payloads['estilo'] ?? null;
            if ($estilo !== 'ugc') {
                return; // pov/showcase/video_do_zero: sem garantia de rosto em quadro
            }

            $videoUrl = $pipeline->output_url ?? null;
            if (! $videoUrl || ! filter_var($videoUrl, FILTER_VALIDATE_URL)) {
                return;
            }

            // ja tem avatar ativo (qualquer fonte)? bootstrap so acontece 1x na vida
            // do cliente — nao substitui escolha dele nem acumula linha por video.
            $jaTemAvatar = DB::table('client_video_avatars')
                ->where('client_id', $client->id)
                ->where('is_active', 1)
                ->exists();
            if ($jaTemAvatar) {
                return;
            }

            $duracao = (float) ($payloads['duration'] ?? 10);
            $seekSec = max(1.0, min($duracao - 1.0, $duracao * 0.4));

            $tmpFrame = tempnam(sys_get_temp_dir(), 'avatar_frame_') . '.jpg';
            $process  = new Process([
                'ffmpeg', '-y',
                '-ss', (string) round($seekSec, 1),
                '-i', $videoUrl,
                '-frames:v', '1',
                '-q:v', '3',
                $tmpFrame,
            ]);
            $process->setTimeout(10);
            $process->run();

            if (! $process->isSuccessful() || ! is_file($tmpFrame) || filesize($tmpFrame) < 1024) {
                Log::warning('[SEL-avatarpersist] ffmpeg nao gerou frame valido', [
                    'pipeline_id' => $pipeline->id,
                    'exit_code'   => $process->getExitCode(),
                ]);
                @unlink($tmpFrame);
                return;
            }

            $path = 'avatars/auto/' . date('Y/m/d') . '/' . $pipeline->id . '_' . uniqid() . '.jpg';
            Storage::disk('public')->put($path, file_get_contents($tmpFrame));
            @unlink($tmpFrame);
            $frameUrl = Storage::disk('public')->url($path);

            $avatarId = DB::table('client_video_avatars')->insertGetId([
                'client_id'              => $client->id,
                'video_avatar_id'        => null,
                'custom_avatar_url'      => $frameUrl,
                'label'                  => 'Avatar do seu vídeo',
                'source'                 => 'auto_geracao_frame',
                'is_exclusive_to_client' => 1,
                'is_active'              => 1,
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);

            Log::info('[SEL-avatarpersist] avatar bootstrapado via frame do video', [
                'pipeline_id' => $pipeline->id,
                'client_id'   => $client->id,
                'avatar_id'   => $avatarId,
                'seek_sec'    => $seekSec,
                'frame_url'   => $frameUrl,
            ]);
        } catch (Throwable $e) {
            Log::warning('[SEL-avatarpersist] bootstrap por frame falhou', [
                'pipeline_id' => $pipeline->id ?? null,
                'err'         => $e->getMessage(),
            ]);
        }
    }
}
