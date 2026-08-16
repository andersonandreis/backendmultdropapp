<?php

namespace App\Jobs;

use App\Models\AiVideoPipeline;
use App\Models\AiGeneration;
use App\Services\Ai\KlingService;
use App\Services\Ai\OpenAiService;
use App\Services\TikTokMediaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * SEL-321: Gerente em tempo real de pipeline de geração de vídeo.
 *
 * Etapas automáticas:
 *   queued → render (Kling image2video v3 multi-shot)
 *          → voice (OpenAI TTS PT-BR)
 *          → lipsync (Kling audio2video)
 *          → finalize (baixar resultado, persistir em tt-media)
 *          → done
 *
 * Falha: retry 1x com engine fallback (kling-v3→kling-v2-master), depois failed.
 * Polling: 15s entre polls, até 40 tentativas (~10min).
 * WHITE-LABEL: nenhum dado do provider sai nas respostas públicas.
 * DRY_RUN: job não dispara nada se pipeline.dry_run=true.
 */
class AiVideoPipelineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    // SEL-364b: tries=3 permite retomar após kill do worker (supervisor SIGKILL,
    // retry_after < timeout). Render/voice/lipsync têm resume — não regasta crédito.
    public int $tries   = 3;

    private const POLL_INTERVAL_SECONDS = 15;
    private const MAX_POLLS             = 40;
    private const FALLBACK_MODEL        = 'kling-v2-master';

    public function __construct(private int $pipelineId)
    {
        $this->onQueue('video');
    }

    public function handle(KlingService $kling, OpenAiService $openai, TikTokMediaService $media): void
    {
        // SEL-407: mesma trava do StudioGenerationJob.
        // SEL-410: barrado vira estado final COM mensagem, nunca preso em 'queued'.
        if (\App\Services\Ai\VideoAccessGuard::barrarPipeline($this->pipelineId, 'pipeline')) {
            return;
        }
        if (config('app.tenant') !== 'sellerglobal') {
            \Illuminate\Support\Facades\Log::info('[INF-BKP] AiVideoPipelineJob skipped (tenant=' . config('app.tenant', 'null') . ')');
            return;
        }
        $pipeline = AiVideoPipeline::find($this->pipelineId);
        if (! $pipeline) {
            Log::warning('[SEL-321 Pipeline] pipeline não encontrado', ['id' => $this->pipelineId]);
            return;
        }

        if ($pipeline->dry_run) {
            Log::info('[SEL-321 Pipeline] dry_run=true, ignorando execução paga', ['id' => $this->pipelineId]);
            return;
        }

        try {
            $this->runRender($pipeline, $kling);

            // SEL-332: Showcase Silencioso e POV --- sem lipsync (nunca ha rosto/
            // boca em quadro pra sincronizar). SEL-POV: POV ganhou narracao
            // OPCIONAL — se o cliente mandou pitch, sobrepoe TTS por cima do
            // video mudo via overlay de audio (sem lipsync, ver docblock de
            // runVoiceOverlayFallback: e o uso legitimo dele, nao um fallback de
            // emergencia, porque nao ha boca pra dessincronizar).
            $isPov = (bool) ($pipeline->payloads['render']['_pov_mode'] ?? false);
            if ($pipeline->mode === 'showcase' || $isPov) {
                if ($pipeline->mode === 'showcase') {
                    $this->runAudioOverlay($pipeline);
                } elseif ($isPov && ! empty($pipeline->payloads['voice']['text'])) {
                    try {
                        $this->runVoice($pipeline, $openai);
                        $this->runVoiceOverlayFallback($pipeline);
                    } catch (\Throwable $e) {
                        // Narracao e opcional — se TTS ou ffmpeg falharem, entrega o
                        // video mudo (mesmo comportamento de sempre) em vez de falhar
                        // o pipeline inteiro por causa do audio.
                        Log::warning('[SEL-POV] narracao falhou, entregando video mudo', [
                            'id'  => $this->pipelineId,
                            'err' => mb_substr($e->getMessage(), 0, 150),
                        ]);
                    }
                }
                // POV sem pitch e Showcase: pula lipsync, vai direto pro finalize
            } elseif (! $this->lipSyncDisponivel($kling)) {
                // SEL-420: o modo navegador nao faz sincronia labial. Antes o
                // pipeline seguia assim mesmo, gerava a narracao e SOBREPUNHA a
                // faixa — a boca tinha sido animada pra voz nativa do Kling e o
                // audio era outro. Saia dessincronizado, sem avisar ninguem.
                if ($this->semSincroniaLabial($pipeline, 'modo navegador nao suporta sincronia labial')) {
                    return;
                }
            } else {
                $this->runVoice($pipeline, $openai);
                try {
                    $this->runLipsync($pipeline, $kling);
                } catch (\Throwable $e) {
                    // SEL-420: aqui ficava o fallback que sobrepunha a voz por cima
                    // do video (SEL-369). O objetivo era bom — nao jogar o render
                    // fora — mas o resultado era boca dessincronizada entregue como
                    // se estivesse pronta. Trocar a faixa NAO e sincronizar.
                    Log::warning('[SEL-420 Pipeline] lipsync falhou', [
                        'id'  => $this->pipelineId,
                        'err' => mb_substr($e->getMessage(), 0, 150),
                    ]);
                    if ($this->semSincroniaLabial($pipeline, 'a sincronia labial falhou: ' . mb_substr($e->getMessage(), 0, 80))) {
                        return;
                    }
                }
            }

            $this->runFinalize($pipeline, $media);
        } catch (\Throwable $e) {
            Log::error('[SEL-321 Pipeline] falha', [
                'id'    => $this->pipelineId,
                'step'  => $pipeline->step,
                'error' => $e->getMessage(),
            ]);

            if ($pipeline->retries < 1) {
                Log::info('[SEL-321 Pipeline] retry 1x com fallback engine', ['id' => $this->pipelineId]);
                $pipeline->increment('retries');
                $payloads = $pipeline->payloads;
                if (isset($payloads['render']['model_name'])) {
                    $payloads['render']['model_name'] = self::FALLBACK_MODEL;
                    $pipeline->payloads = $payloads;
                    $pipeline->save();
                }
                // Re-disparar
                self::dispatch($this->pipelineId);
            } else {
                $pipeline->update([
                    'step'          => 'failed',
                    'error_message' => substr($e->getMessage(), 0, 500),
                ]);
            }
        }
    }

    // ─── Render (Kling image2video v3 multi-shot) ─────────────────────────

    private function runRender(AiVideoPipeline $pipeline, KlingService $kling): void
    {
        // Resume: render já concluído numa tentativa anterior — não gastar crédito de novo
        if (! empty($pipeline->payloads['render_url'])) {
            Log::info('[SEL-321 Pipeline] render já concluído, pulando', ['id' => $this->pipelineId]);
            return;
        }

        $pipeline->update(['step' => 'render']);

        $payload     = $pipeline->payloads['render'] ?? [];
        $modelName   = $payload['model_name'] ?? 'kling-v3';
        $mode        = $payload['mode'] ?? 'pro';
        $aspectRatio = $payload['aspect_ratio'] ?? '9:16';
        $duration    = $payload['duration'] ?? '10';
        $image       = $payload['image'] ?? null;
        $elements    = $payload['elements'] ?? null;
        $multiPrompt = $payload['multi_prompt'] ?? null;
        $shotType    = $payload['shot_type'] ?? 'customize';
        $negPrompt   = $payload['negative_prompt'] ?? null;
        $cfgScale    = $payload['cfg_scale'] ?? 0.5;

        $klingPayload = array_filter([
            'model_name'      => $modelName,
            'mode'            => $mode,
            'aspect_ratio'    => $aspectRatio,
            'duration'        => (string) $duration,
            'image'           => $image,
            'negative_prompt' => $negPrompt,
            'cfg_scale'       => $cfgScale,
            // SEL-418: idioma escolhido segue pro KlingBrowserService, que monta o
            // bloco IDIOMA do prompt. Sem isto o idioma some no meio do caminho e
            // o video volta pro padrao — o defeito que o SEL-417 acabou de matar.
            'lang'            => $payload['lang'] ?? null,
        ], fn ($v) => $v !== null);

        // Multi-shot (API v3: multi_shot=true + shots com index/prompt/duration).
        // API exige duration inteira em segundos, min 3 por shot.
        // Fallback pra modelos não-v3: colapsa shots num prompt único (multi-shot é v3-only).
        if ($multiPrompt && str_contains($modelName, 'v3')) {
            $idx = 1;
            $klingPayload['multi_prompt'] = array_map(function ($s) use (&$idx) {
                return [
                    'index'    => $idx++,
                    'prompt'   => mb_substr($s['prompt'] ?? '', 0, 512),
                    'duration' => (string) max(3, (int) round((float) ($s['duration'] ?? 3))),
                ];
            }, array_values($multiPrompt));
            $klingPayload['multi_shot'] = true;
            $klingPayload['shot_type']  = $shotType;
        } elseif ($multiPrompt) {
            $klingPayload['prompt'] = mb_substr(implode(' Then: ', array_map(fn ($s) => $s['prompt'] ?? '', $multiPrompt)), 0, 2500);
        } else {
            $klingPayload['prompt'] = $payload['prompt'] ?? '';
        }

        // Elements (avatar + produto)
        if ($elements) {
            $klingPayload['elements'] = $elements;
        }

        // Modo B: extrair frame real do viral local (cover_url remoto pode estar expirado)
        if ($pipeline->mode === 'clone') {
            $frameUrl = $this->extractCloneFrame($pipeline);
            if ($frameUrl) {
                $klingPayload['image'] = $frameUrl;
            }
        }

        // Kling image2video herda o aspect da imagem de entrada (aspect_ratio é
        // ignorado) — sem canvas o vídeo sai quadrado (ex.: 1440x1440 no pipeline 7)
        if (! empty($klingPayload['image'])) {
            $canvas = $this->prepareNineSixteen($klingPayload['image']);
            if ($canvas) {
                $klingPayload['image'] = $canvas;
            }
        }

        // SEL-389: video_do_zero SEM imagem → text2video (Kaloclip). Com imagem → image2video (default).
        $isText2Video = empty($klingPayload['image']) && ($pipeline->mode === 'studio_video_do_zero' || ($payload['pipeline'] ?? '') === 'video_do_zero');
        if ($isText2Video) {
            unset($klingPayload['image']);
            \Log::info('[SEL-389] Dispatching text2video', ['pipeline_id' => $pipeline->id, 'prompt_len' => strlen($klingPayload['prompt'] ?? '')]);
            $result = $kling->textToVideo($klingPayload);
        } else {
            $result = $kling->imageToVideo($klingPayload);
        }
        $taskId = $result['data']['task_id'] ?? null;

        if (! $taskId) {
            throw new \RuntimeException('Kling não retornou task_id no render');
        }

        $pipeline->update(['render_task_id' => $taskId]);

        // Poll até concluir
        $video = $this->pollVideoTask($kling, $taskId, $isText2Video ? 'text2video' : 'image2video');
        $videoUrl = $video['url'] ?? null;
        if (! $videoUrl) {
            throw new \RuntimeException('Render não produziu URL de vídeo após polling');
        }

        // Salvar URL + video_id do render nos payloads (video_id usado no lip-sync)
        $payloads                    = $pipeline->payloads;
        $payloads['render_url']      = $videoUrl;
        $payloads['render_video_id'] = $video['id'] ?? null;
        $pipeline->payloads          = $payloads;
        $pipeline->save();

        Log::info('[SEL-321 Pipeline] render concluído', ['id' => $this->pipelineId, 'url' => substr($videoUrl, 0, 80)]);
    }

    // ─── Voice (OpenAI TTS PT-BR) ─────────────────────────────────────────

    private function runVoice(AiVideoPipeline $pipeline, OpenAiService $openai): void
    {
        // Resume: voz já gerada numa tentativa anterior
        if (! empty($pipeline->voice_path)) {
            Log::info('[SEL-321 Pipeline] voice já concluído, pulando', ['id' => $this->pipelineId]);
            return;
        }

        $pipeline->update(['step' => 'voice']);

        $payload  = $pipeline->payloads['voice'] ?? [];
        $text     = $payload['text'] ?? 'Olha esse produto incrível! Aproveita agora!';
        $voiceId  = $payload['voice_id'] ?? 'nova';
        $speed    = $payload['speed'] ?? 1.0;

        // Modo B: transcrever viral + adaptar pra pitch PT-BR
        if ($text === '__TRANSCRIBED_AND_ADAPTED_TEXT__') {
            $text = $this->resolveCloneText($pipeline, $openai);
            $payloads = $pipeline->payloads;
            $payloads['voice_text_resolved'] = $text;
            $pipeline->payloads = $payloads;
            $pipeline->save();
        }

        // SEL-412: a narração passa a ser a voz Carla (ElevenLabs, pt-BR), aprovada pelo Ruan.
        // O TTS da OpenAI fica só como rede de segurança se o ElevenLabs falhar.
        $audioB64 = null;
        $eleven   = app(\App\Services\Ai\ElevenLabsService::class);

        if ($eleven->isConfigured()) {
            try {
                $elevenVoice = $payload['elevenlabs_voice_id']
                    ?? config('services.elevenlabs.default_voice_id');
                $audioB64 = $eleven->tts($text, $elevenVoice)['audio_base64'] ?? null;
            } catch (\Throwable $e) {
                Log::warning('[SEL-412 Pipeline] ElevenLabs falhou, caindo pro TTS da OpenAI', [
                    'id'  => $this->pipelineId,
                    'err' => mb_substr($e->getMessage(), 0, 150),
                ]);
            }
        }

        if (! $audioB64) {
            $ttsResult = $openai->tts($text, $voiceId, null, $speed, 'mp3');
            $audioB64  = $ttsResult['audio_base64'] ?? null;
        }

        if (! $audioB64) {
            throw new \RuntimeException('nenhum motor de voz retornou áudio');
        }

        // Salvar MP3 em tt-media
        $filename  = 'sellerglobal-voice-' . $this->pipelineId . '.mp3';
        $storagePath = 'tt-media/' . $filename;
        Storage::disk('public')->put($storagePath, base64_decode($audioB64));
        $voiceUrl  = Storage::disk('public')->url($storagePath);

        $pipeline->update(['voice_path' => $voiceUrl]);

        Log::info('[SEL-321 Pipeline] voice concluído', ['id' => $this->pipelineId]);
    }

    // ─── Lip-sync (Kling audio2video) ─────────────────────────────────────

    private function runLipsync(AiVideoPipeline $pipeline, KlingService $kling): void
    {
        // Resume: lip-sync já concluído numa tentativa anterior
        if (! empty($pipeline->payloads['lipsync_url'])) {
            Log::info('[SEL-321 Pipeline] lipsync já concluído, pulando', ['id' => $this->pipelineId]);
            return;
        }

        $pipeline->update(['step' => 'lipsync']);

        $payloads  = $pipeline->payloads;
        $videoUrl  = $payloads['render_url'] ?? null;
        $audioUrl  = $pipeline->voice_path ?? null;

        if (! $videoUrl || ! $audioUrl) {
            throw new \RuntimeException('Lip-sync requer video_url e audio_url');
        }

        // Resume: task Kling já criado numa tentativa anterior — só faz o poll
        $taskId = $pipeline->lipsync_task_id;
        if (! $taskId) {
            $result = $kling->lipSync([
                'video_id'  => $payloads['render_video_id'] ?? null,
                'video_url' => $videoUrl,
                'audio_url' => $audioUrl,
                'mode'      => 'audio2video',
            ]);

            $taskId = $result['data']['task_id'] ?? null;
            if (! $taskId) {
                throw new \RuntimeException('Kling não retornou task_id no lip-sync');
            }

            $pipeline->update(['lipsync_task_id' => $taskId]);
        }

        // Poll lip-sync
        $final    = $this->pollVideoTask($kling, $taskId, 'lip-sync');
        $finalUrl = $final['url'] ?? null;
        if (! $finalUrl) {
            throw new \RuntimeException('Lip-sync não produziu URL de vídeo após polling');
        }

        $payloads['lipsync_url'] = $finalUrl;
        $pipeline->payloads      = $payloads;
        $pipeline->save();

        Log::info('[SEL-321 Pipeline] lipsync concluído', ['id' => $this->pipelineId]);
    }

    // ─── Finalize (baixar e persistir em tt-media) ─────────────────────────

    private function runFinalize(AiVideoPipeline $pipeline, TikTokMediaService $media): void
    {
        $pipeline->update(['step' => 'finalize']);

        $payloads  = $pipeline->payloads;
        $sourceUrl = $payloads['lipsync_url'] ?? $payloads['render_url'] ?? null;

        if (! $sourceUrl) {
            throw new \RuntimeException('Finalize: sem URL de vídeo para baixar');
        }

        // Baixar vídeo do provider e persistir localmente
        $localUrl = $this->downloadVideo($sourceUrl, $this->pipelineId);
        if (! $localUrl) {
            throw new \RuntimeException('Finalize: falha ao baixar vídeo do provider');
        }

        $pipeline->update([
            'step'       => 'done',
            'output_url' => $localUrl,
        ]);

        // SEL-414: cobra só agora, com o vídeo pronto e baixado. Idempotente por
        // pipeline — este job tem tries=3 e finalize pode rodar de novo.
        \App\Services\Ai\VideoQuotaService::cobrarPosSucesso($pipeline->user_id, $pipeline->id);

        // SEL-497: salva o rosto/apresentador desta geracao na conta do cliente
        // (nao-fatal; nunca derruba um video ja pronto).
        // SEL-avatar-off (13/08, Ruan: "nao sei por que tu ta enfiando avatar na
        // conta dos outros, ninguem pediu essa porra"). O harvester salvava o
        // rosto de CADA geracao como avatar do cliente, sem ele pedir. Desligado.
        // Pra religar: descomentar a linha abaixo.
        // \App\Services\Ai\ClientAvatarHarvester::harvestFromPipeline($pipeline);

        // SEL 08/08 Ruan: avisa o cliente (push motivacional + e-mail) que o
        // video ficou pronto, mesmo se ele fechou a tela. Idempotente/nao-fatal.
        \App\Services\VideoReadyNotifier::notify($pipeline);

        // SEL-329: registrar na galeria (ai_generations) + custo real + débito wallet
        try {
            $payloads = $pipeline->payloads ?? [];
            $billing = $payloads['billing'] ?? null;

            // Calcula custo real com base na qualidade+duração escolhida no submit
            $costUsd = 0.0;
            $creditsDebited = 0;
            $source = 'unknown';
            if ($billing && isset($billing['quality'], $billing['duration_total_s'])) {
                $s = \Illuminate\Support\Facades\DB::table('settings')
                    ->where('group', 'video_billing')->pluck('value', 'key')->toArray();
                $rate = (float) ($s['usd_brl_rate'] ?? 5.50);
                $costMap = json_decode((string) ($s['internal_cost_by_quality_brl_per_s'] ?? '{}'), true) ?: [];
                $costBrl = ($costMap[$billing['quality']] ?? 0) * (int) $billing['duration_total_s'];
                $costUsd = $rate > 0 ? $costBrl / $rate : 0;
                $source = $billing['source'] ?? 'unknown';

                // Se saiu do wallet — debita créditos AGORA (após confirmação de sucesso)
                if ($source === 'wallet_credits' && !empty($billing['credits_estimated']) && $pipeline->user_id) {
                    $client = \Illuminate\Support\Facades\DB::table('clients')->where('user_id', $pipeline->user_id)->first();
                    if ($client) {
                        $newBalance = \App\Services\AiWalletService::debit(
                            (int) $client->id,
                            (float) $billing['credits_estimated'],
                            'video_generation',
                            (string) $pipeline->id,
                            'Vídeo Kling (' . $billing['quality'] . ')'
                        );
                        if ($newBalance !== false) $creditsDebited = (int) $billing['credits_estimated'];
                    }
                }
            }

            $wp = [
                'product_name' => $payloads['product_name'] ?? null,
                'overlays'     => ['ts_cart', 'ts_price', 'ts_cta'],
                'price'        => $payloads['price'] ?? null,
                '_source'      => 'video_perfect',
                '_pipeline_id' => $pipeline->id,
                '_billing'     => $billing,
            ];
            AiGeneration::create([
                'tenant_id'        => null,
                'user_id'          => $pipeline->user_id,
                'service'          => 'video',
                'provider'         => 'kling',
                'provider_model'   => $billing['quality'] ?? 'padrão',
                'provider_task_id' => $pipeline->render_task_id,
                'wizard_payload'   => $wp,
                'final_prompt'     => $payloads['voice']['text'] ?? null,
                'status'           => 'succeeded',
                'output_url'       => $localUrl,
                'credits_debited'  => $creditsDebited,
                'cost_usd'         => round($costUsd, 4),
            ]);
            Log::info('[SEL-329] video gravado com custo', [
                'pid' => $pipeline->id, 'source' => $source,
                'cost_usd' => round($costUsd, 4), 'credits_debited' => $creditsDebited,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[SEL-329] falha ao inserir na galeria', ['pid' => $pipeline->id, 'err' => $e->getMessage()]);
        }

        Log::info('[SEL-321 Pipeline] done', [
            'id'        => $this->pipelineId,
            'local_url' => $localUrl,
        ]);
    }

    // ─── Polling genérico ─────────────────────────────────────────────────

    /** @return array{url: ?string, id: ?string}|null */
    private function pollVideoTask(KlingService $kling, string $taskId, string $type): ?array
    {
        for ($i = 0; $i < self::MAX_POLLS; $i++) {
            sleep(self::POLL_INTERVAL_SECONDS);

            try {
                $result = $kling->getVideoTask($taskId, $type);
            } catch (\Throwable $e) {
                Log::warning('[SEL-321 Pipeline] poll error', ['taskId' => $taskId, 'err' => $e->getMessage()]);
                continue;
            }

            $status = $result['data']['task_status'] ?? null;

            if ($status === 'succeed') {
                // Resolver URL/id do vídeo em estrutura oficial Kling
                $videoItem = $result['data']['task_result']['videos'][0]
                    ?? $result['data']['videos'][0]
                    ?? [];
                return ['url' => $videoItem['url'] ?? null, 'id' => $videoItem['id'] ?? null];
            }

            if ($status === 'failed') {
                $msg = $result['data']['task_status_msg'] ?? 'task failed';
                throw new \RuntimeException('Provider falhou na tarefa: ' . substr($msg, 0, 200));
            }

            Log::debug('[SEL-321 Pipeline] poll', [
                'id'     => $this->pipelineId,
                'taskId' => $taskId,
                'status' => $status,
                'poll'   => $i + 1,
            ]);
        }

        throw new \RuntimeException('Timeout: tarefa não concluiu em ' . (self::MAX_POLLS * self::POLL_INTERVAL_SECONDS) . 's');
    }

    // ─── Modo B (clone): frame + transcrição do viral ─────────────────────

    /** Resolve o mp4 local em tt-media a partir da URL pública do viral. */
    private function localViralPath(AiVideoPipeline $pipeline): ?string
    {
        $videoUrl = $pipeline->payloads['transcribe']['video_url'] ?? null;
        if (! $videoUrl || ! str_contains($videoUrl, '/storage/tt-media/')) return null;
        $name = basename(parse_url($videoUrl, PHP_URL_PATH) ?: '');
        if ($name === '') return null;
        $local = Storage::disk('public')->path('tt-media/' . $name);
        return is_file($local) ? $local : null;
    }

    private function extractCloneFrame(AiVideoPipeline $pipeline): ?string
    {
        $local = $this->localViralPath($pipeline);
        if (! $local) return null;

        $framePath = 'tt-media/sellerglobal-frame-' . $this->pipelineId . '.jpg';
        $dest      = Storage::disk('public')->path($framePath);
        exec('ffmpeg -y -i ' . escapeshellarg($local) . ' -frames:v 1 -q:v 2 ' . escapeshellarg($dest) . ' 2>/dev/null', $out, $code);
        if ($code !== 0 || ! is_file($dest) || filesize($dest) < 1000) {
            Log::warning('[SEL-321 Pipeline] ffmpeg frame falhou, mantendo cover_url', ['id' => $this->pipelineId, 'code' => $code]);
            return null;
        }
        return Storage::disk('public')->url($framePath);
    }

    /** Pré-processa a imagem base num canvas 1080x1920 (fundo blur) via ffmpeg. */
    private function prepareNineSixteen(string $imageUrl): ?string
    {
        try {
            $src = null;
            $tmp = null;
            if (str_contains($imageUrl, '/storage/tt-media/')) {
                $name = basename(parse_url($imageUrl, PHP_URL_PATH) ?: '');
                $cand = $name !== '' ? Storage::disk('public')->path('tt-media/' . $name) : null;
                if ($cand && is_file($cand)) {
                    $src = $cand;
                }
            }
            if (! $src) {
                $resp = Http::timeout(30)->get($imageUrl);
                if (! $resp->successful() || strlen($resp->body()) < 1000) {
                    return null;
                }
                $tmp = sys_get_temp_dir() . '/sgcanvas-src-' . $this->pipelineId . '.img';
                file_put_contents($tmp, $resp->body());
                $src = $tmp;
            }

            $canvasPath = 'tt-media/sellerglobal-canvas-' . $this->pipelineId . '.jpg';
            $dest       = Storage::disk('public')->path($canvasPath);
            $filter     = '[0:v]split[a][b];'
                . '[a]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,boxblur=20:5[bg];'
                . '[b]scale=1080:1920:force_original_aspect_ratio=decrease[fg];'
                . '[bg][fg]overlay=(W-w)/2:(H-h)/2';
            exec('ffmpeg -y -i ' . escapeshellarg($src) . ' -filter_complex ' . escapeshellarg($filter)
                . ' -frames:v 1 -q:v 2 ' . escapeshellarg($dest) . ' 2>/dev/null', $out, $code);
            if ($tmp) {
                @unlink($tmp);
            }

            if ($code !== 0 || ! is_file($dest) || filesize($dest) < 5000) {
                Log::warning('[SEL-321 Pipeline] canvas 9:16 falhou, usando imagem original', ['id' => $this->pipelineId, 'code' => $code]);
                return null;
            }

            return Storage::disk('public')->url($canvasPath);
        } catch (\Throwable $e) {
            Log::warning('[SEL-321 Pipeline] canvas 9:16 exception', ['id' => $this->pipelineId, 'err' => $e->getMessage()]);
            return null;
        }
    }

    private function resolveCloneText(AiVideoPipeline $pipeline, OpenAiService $openai): string
    {
        $fallback = 'Olha esse achado! Qualidade absurda por um preço que não dá pra acreditar. Toca no carrinho aqui embaixo do lado esquerdo antes que acabe!';

        try {
            $local = $this->localViralPath($pipeline);
            if (! $local) return $fallback;

            $audioTmp = sys_get_temp_dir() . '/sgclone-' . $this->pipelineId . '.mp3';
            exec('ffmpeg -y -i ' . escapeshellarg($local) . ' -vn -acodec libmp3lame -q:a 4 ' . escapeshellarg($audioTmp) . ' 2>/dev/null', $out, $code);
            if ($code !== 0 || ! is_file($audioTmp) || filesize($audioTmp) < 1000) return $fallback;

            $tr = $openai->transcribe($audioTmp, 'pt');
            @unlink($audioTmp);
            $original = trim($tr['text'] ?? '');
            if ($original === '') return $fallback;

            // Vídeo gerado tem ~10s — fala precisa caber (viral de 70s gerou TTS
            // de 72s que o lip-sync cortou no meio no pipeline 9)
            $adapted = $openai->generateScript(
                'Adapte a fala abaixo, vinda de um vídeo viral, para português brasileiro natural de venda no TikTok Shop. '
                . 'MÁXIMO 25 palavras (o vídeo tem só 10 segundos). Mantenha o gancho e a energia do original '
                . 'e termine com CTA pra tocar no carrinho no canto inferior esquerdo. '
                . "Responda SOMENTE com o texto que será falado, sem emojis, sem marcações, sem títulos.\n\nFala original:\n"
                . mb_substr($original, 0, 2000)
            );
            $textOut = trim($adapted['script'] ?? '');
            if ($textOut === '') {
                return $fallback;
            }

            // Guarda-corpo: corta em FRASE COMPLETA (~30 palavras) se o modelo
            // ignorar o limite — corte seco truncava a CTA no meio ("toca no").
            $words = preg_split('/\s+/u', $textOut);
            if (count($words) > 30) {
                $slice = implode(' ', array_slice($words, 0, 32));
                if (preg_match('/^.*[.!?\x{2026}]/su', $slice, $m)) {
                    $textOut = $m[0];
                } else {
                    $textOut = rtrim(implode(' ', array_slice($words, 0, 30)), ' ,;:').'!';
                }
            }

            return $textOut;
        } catch (\Throwable $e) {
            Log::warning('[SEL-321 Pipeline] clone transcribe falhou, pitch default', ['id' => $this->pipelineId, 'err' => $e->getMessage()]);
            return $fallback;
        }
    }

    // ─── Download vídeo ───────────────────────────────────────────────────

    private function downloadVideo(string $url, int $pipelineId): ?string
    {
        $filename = 'sellerglobal-video-' . $pipelineId . '.mp4';
        $path     = 'tt-media/' . $filename;

        try {
            $resp = Http::timeout(120)->withOptions(['sink' => null])->get($url);
            if (! $resp->successful()) return null;

            $body = $resp->body();
            if (strlen($body) < 10000) return null; // muito pequeno = erro

            Storage::disk('public')->put($path, $body);
            return Storage::disk('public')->url($path);
        } catch (\Throwable $e) {
            Log::warning('[SEL-321 Pipeline] download falhou', ['err' => $e->getMessage()]);
            return null;
        }
    }

    // === SEL-332: Overlay de audio royalty-free via ffmpeg ===

    /**
     * SEL-369: fallback quando o lip-sync do Kling falha — muxa a narracao TTS
     * por cima do render via ffmpeg. Sem sincronia labial, mas o cliente recebe
     * o video com a voz certa em vez de um pipeline failed.
     */
    /**
     * SEL-420: o provider faz sincronia labial? Pergunta antes, em vez de
     * descobrir pelo erro depois.
     *
     * O KlingBrowserService responde que não. Os providers que não declaram
     * nada seguem valendo como "sim" — é o comportamento que o modo API sempre
     * teve, e não quero desligar sincronia de quem faz.
     */
    private function lipSyncDisponivel($kling): bool
    {
        return ! (method_exists($kling, 'suportaLipSync') && $kling->suportaLipSync() === false);
    }

    /**
     * SEL-420 — o que entregar quando a sincronia labial NÃO rodou.
     *
     * Ruan, 30/07: sobrepor a narração num vídeo cuja boca foi animada pra outra
     * voz entrega boca dessincronizada, e o cliente recebe sem aviso nenhum.
     * Não existe meio-termo aceitável aqui. Duas saídas, conforme o idioma:
     *
     *   espanhol (e o resto) → entrega com o áudio NATIVO do Kling. Boca e som
     *     casam, e o espanhol nativo sai bom. É o idioma do cliente.
     *
     *   português → falha com motivo legível. Não dá pra cair no nativo porque
     *     é justamente o idioma em que o áudio do Kling sai macarrônico: seria
     *     trocar um defeito por outro.
     *
     * @return bool true = o job deve dar return (pipeline encerrado aqui).
     */
    private function semSincroniaLabial(AiVideoPipeline $pipeline, string $motivo): bool
    {
        $lang = \App\Services\Ai\VideoLanguageService::normaliza($pipeline->payloads['lang'] ?? null);

        if (\App\Services\Ai\VideoLanguageService::exigeNarracaoExterna($lang)) {
            Log::warning('[SEL-420] portugues sem sincronia labial — pipeline encerrado em vez de entregar dessincronizado', [
                'id' => $this->pipelineId, 'motivo' => $motivo,
            ]);
            $pipeline->update([
                'step'          => 'failed',
                'error_message' => 'Sincronia labial indisponível neste caminho — '
                                 . 'não vou entregar um vídeo com a boca fora do áudio. '
                                 . 'Gere em espanhol ou fale com o suporte.',
            ]);
            return true;
        }

        // Sem lipsync_url, o finalize usa render_url — que é o vídeo com o áudio
        // nativo do Kling, exatamente o que queremos aqui.
        Log::info('[SEL-420] entregando com audio nativo do Kling (sem sobrepor narracao)', [
            'id' => $this->pipelineId, 'lang' => $lang, 'motivo' => $motivo,
        ]);

        return false;
    }

    /**
     * NÃO usar como substituto de sincronia labial (lipsync) — isto troca a
     * faixa de áudio do vídeo, e se a boca foi animada pra outra voz o resultado
     * sai dessincronizado; foi assim que o defeito de 30/07 nasceu (SEL-420).
     *
     * Uso legítimo (não é fallback de emergência aqui): caminhos onde NUNCA há
     * boca/rosto em quadro pra dessincronizar — Showcase com trilha ambiente e,
     * desde SEL-POV, a narração opcional do modo POV (só mão, sem rosto). Nesses
     * casos sobrepor a faixa de áudio é o comportamento certo, não um atalho.
     */
    private function runVoiceOverlayFallback(AiVideoPipeline $pipeline): void
    {
        $pipeline->refresh();
        $payloads   = $pipeline->payloads;
        $videoUrl   = $payloads['render_url'] ?? null;
        $audioLocal = Storage::disk('public')->path('tt-media/sellerglobal-voice-' . $this->pipelineId . '.mp3');

        if (! $videoUrl || ! is_file($audioLocal)) {
            Log::warning('[SEL-369 Pipeline] fallback sem render ou sem voz, video segue mudo', ['id' => $this->pipelineId]);
            return;
        }

        try {
            $resp = Http::timeout(120)->get($videoUrl);
            if (! $resp->successful() || strlen($resp->body()) < 10000) {
                Log::warning('[SEL-369 Pipeline] fallback: download do render falhou', ['id' => $this->pipelineId]);
                return;
            }
            $videoLocal = sys_get_temp_dir() . '/sg-lipfb-' . $this->pipelineId . '.mp4';
            file_put_contents($videoLocal, $resp->body());
        } catch (\Throwable $e) {
            Log::warning('[SEL-369 Pipeline] fallback: download exception', ['id' => $this->pipelineId, 'err' => $e->getMessage()]);
            return;
        }

        $outputPath  = 'tt-media/sellerglobal-voicefb-' . $this->pipelineId . '.mp4';
        $outputLocal = Storage::disk('public')->path($outputPath);

        // SEL-412: com '-shortest' puro, narração mais longa que o vídeo era cortada no meio
        // (regra dura: a fala nunca pode ser cortada). Se a voz passar do vídeo, congela o
        // último frame até a fala terminar, com 1s de respiro.
        $durOf = function (string $arquivo): float {
            $saida = [];
            exec(sprintf(
                'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s',
                escapeshellarg($arquivo)
            ), $saida);
            return (float) trim($saida[0] ?? '0');
        };
        $videoDur = $durOf($videoLocal);
        $audioDur = $durOf($audioLocal);

        $videoArgs = '-c:v copy';
        if ($audioDur > 0 && $videoDur > 0 && ($audioDur + 1) > $videoDur) {
            $extra = ($audioDur + 1) - $videoDur;
            $videoArgs = sprintf('-vf tpad=stop_mode=clone:stop_duration=%.2f', $extra);
            Log::info('[SEL-412 Pipeline] voz maior que o vídeo, esticando o último frame', [
                'id' => $this->pipelineId, 'video' => $videoDur, 'voz' => $audioDur,
            ]);
        }

        $cmd = sprintf(
            'ffmpeg -y -i %s -i %s -map 0:v -map 1:a %s -c:a aac -af apad -shortest %s 2>/dev/null',
            escapeshellarg($videoLocal),
            escapeshellarg($audioLocal),
            $videoArgs,
            escapeshellarg($outputLocal)
        );
        exec($cmd, $out, $code);

        if ($code !== 0 || ! is_file($outputLocal) || filesize($outputLocal) < 10000) {
            Log::warning('[SEL-369 Pipeline] fallback: ffmpeg falhou (code=' . $code . '), video segue mudo', ['id' => $this->pipelineId]);
            return;
        }

        $payloads['render_url']       = Storage::disk('public')->url($outputPath);
        $payloads['lipsync_fallback'] = 'voice_overlay';
        $pipeline->payloads           = $payloads;
        $pipeline->save();

        Log::info('[SEL-369 Pipeline] fallback voice overlay concluido', ['id' => $this->pipelineId]);
    }

    /**
     * Aplica trilha sonora ao video do render (showcase silencioso).
     * Fade in 0.3s / fade out 0.5s. Audio cortado no comprimento do video.
     * Se ffmpeg falhar, pipeline continua sem audio (video silencioso aceito).
     */
    private function runAudioOverlay(AiVideoPipeline $pipeline): void
    {
        $pipeline->update(['step' => 'audio_overlay']);

        $payloads  = $pipeline->payloads;
        $videoUrl  = $payloads['render_url'] ?? null;
        $audioPath = $payloads['render']['_audio_path'] ?? null;

        if (! $videoUrl || ! $audioPath) {
            Log::warning('[SEL-332 AudioOverlay] sem video_url ou audio_path, pulando overlay', [
                'id' => $this->pipelineId,
            ]);
            return;
        }

        // Localiza o video renderizado em storage/public
        $videoLocal = null;
        if (str_contains($videoUrl, '/storage/tt-media/')) {
            $name = basename(parse_url($videoUrl, PHP_URL_PATH) ?: '');
            $cand = $name !== '' ? Storage::disk('public')->path('tt-media/' . $name) : null;
            if ($cand && is_file($cand)) {
                $videoLocal = $cand;
            }
        }

        if (! $videoLocal) {
            try {
                $resp = Http::timeout(60)->get($videoUrl);
                if (! $resp->successful() || strlen($resp->body()) < 10000) {
                    Log::warning('[SEL-332 AudioOverlay] falha ao baixar video para overlay', ['id' => $this->pipelineId]);
                    return;
                }
                $videoLocal = sys_get_temp_dir() . '/sg-showcase-src-' . $this->pipelineId . '.mp4';
                file_put_contents($videoLocal, $resp->body());
            } catch (\Throwable $e) {
                Log::warning('[SEL-332 AudioOverlay] download falhou', ['id' => $this->pipelineId, 'err' => $e->getMessage()]);
                return;
            }
        }

        // Localiza o arquivo de audio
        $audioStoragePath = Storage::disk('public')->path($audioPath);
        if (! is_file($audioStoragePath)) {
            Log::warning('[SEL-332 AudioOverlay] arquivo de audio nao encontrado: ' . $audioPath, ['id' => $this->pipelineId]);
            return;
        }

        $outputPath  = 'tt-media/sellerglobal-showcase-' . $this->pipelineId . '.mp4';
        $outputLocal = Storage::disk('public')->path($outputPath);

        // ffmpeg: mix audio com fade-in 0.3s / fade-out 0.5s, trunca no comprimento do video
        $cmd = sprintf(
            'ffmpeg -y -i %s -i %s -filter_complex "[1:a]afade=in:st=0:d=0.3,afade=out:st=9.5:d=0.5[aout]" -map 0:v -map "[aout]" -c:v copy -c:a aac -shortest %s 2>/dev/null',
            escapeshellarg($videoLocal),
            escapeshellarg($audioStoragePath),
            escapeshellarg($outputLocal)
        );

        exec($cmd, $out, $code);

        if ($code !== 0 || ! is_file($outputLocal) || filesize($outputLocal) < 10000) {
            Log::warning('[SEL-332 AudioOverlay] ffmpeg overlay falhou (code=' . $code . '), usando video sem audio', [
                'id' => $this->pipelineId,
            ]);
            return;
        }

        // Atualiza render_url para apontar pro video com audio
        $overlayUrl = Storage::disk('public')->url($outputPath);
        $payloads['render_url']         = $overlayUrl;
        $payloads['audio_overlay_done'] = true;
        $pipeline->payloads             = $payloads;
        $pipeline->save();

        Log::info('[SEL-332 AudioOverlay] overlay concluido', [
            'id'  => $this->pipelineId,
            'url' => substr($overlayUrl, 0, 80),
        ]);
    }
}
