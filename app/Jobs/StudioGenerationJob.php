<?php

namespace App\Jobs;

use App\Models\AiGeneration;
use App\Models\AiVideoPipeline;
use App\Services\Ai\KlingService;
use App\Services\Ai\OpenAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * SEL-360 Fase 2 — StudioGenerationJob
 *
 * Pipelines Fase 1 (mantidos):
 *   - animar_produto        : image2video kling-v1-6..v3
 *   - pov_so_mao            : kling-v3 elements com foto REAL do produto (SEL-368)
 *   - cena_com_referencias  : multi-image2video kling-v1-6
 *
 * Pipelines Fase 2 (novos):
 *   - video_do_zero          : text2video kling-v3
 *   - video_multi_cortes     : image2video + multi_shot (macro/medium/CTA)
 *   - camera_cinematografica : image2video + camera_control (pan/tilt/zoom/orbit)
 *   - avatar_apresentando    : image2video + elements (avatar + produto)
 *   - trocar_rosto           : faceSwap Kling + moderacao Vision anti-celebridade
 *   - provador_virtual       : kolors-virtual-try-on-v1-5 -> image2video (SEL-368: entrega VIDEO)
 *   - continuar_video        : videoExtend (+5s)
 *   - sincronizar_fala       : lipSync audio2video
 *   - efeitos_prontos        : templates virais (unbox/cheers/teleport/countdown)
 *   - voz_brasileira         : OpenAI TTS, sem video
 *
 * WHITE-LABEL: zero mencao a providers nas mensagens ao cliente.
 * DRY_RUN: se pipeline.dry_run=true, marca done sem chamar APIs.
 */
class StudioGenerationJob implements ShouldQueue
{
    /** SEL-SEM-SOM-PROPAGA: o cliente pediu video sem som nesta pipeline. */
    private bool $pedidoMudo = false;

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30min max (provador_virtual encadeia try-on + animacao)

    /**
     * SEL-RESILIENCIA (12/08): teto por PRAZO (o retryUntil tem precedencia sobre
     * o $tries no Laravel). `$tries=1` fazia um unico tropeco de infra custar o
     * video; agora o pedido pode rotacionar de motor por ate 2h, e quem limita de
     * verdade e a classificacao do erro + o contador de tentativas de infra.
     */
    public int $tries         = 0;
    public int $maxExceptions = 8;
    public array $backoff     = [20, 45, 90];

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(2);
    }

    private const POLL_INTERVAL = 15;
    private const MAX_POLLS     = 120; // ~30min

    // Templates de efeitos prontos
    private const EFFECT_PROMPTS = [
        'unbox_2026' => 'Unboxing de produto: maos abrindo caixa, close no produto revelado, reacao de surpresa, iluminacao de estudio, 9:16, video vertical viral para redes sociais 2026.',
        'cheers'     => 'Brinde com produto levantado, confete caindo, fundo festivo, iluminacao vibrante, 9:16, celebracao, efeito cinematografico.',
        'teleport'   => 'Efeito de teletransporte: produto aparece do nada em flash de luz, depois faz pose, particulas de energia, 9:16, efeito especial VFX.',
        'countdown'  => 'Countdown 3-2-1 com produto no centro, numeros grandes pulsando, fundo escuro energico, CTA ao final, 9:16, tendencia de video vertical.',
    ];

    // Camera presets para camera_cinematografica
    private const CAMERA_PRESETS = [
        'pan_left'    => ['type' => 'simple', 'config' => ['horizontal' => -10]],
        'pan_right'   => ['type' => 'simple', 'config' => ['horizontal' => 10]],
        'tilt_up'     => ['type' => 'simple', 'config' => ['vertical' => 10]],
        'tilt_down'   => ['type' => 'simple', 'config' => ['vertical' => -10]],
        'zoom_in'     => ['type' => 'simple', 'config' => ['zoom' => 10]],
        'zoom_out'    => ['type' => 'simple', 'config' => ['zoom' => -10]],
        'orbit_left'  => ['type' => 'simple', 'config' => ['horizontal' => -8, 'zoom' => 2]],
        'orbit_right' => ['type' => 'simple', 'config' => ['horizontal' => 8, 'zoom' => 2]],
    ];

    public function __construct(
        private int   $pipelineId,
        private array $config = [],
    ) {}

    public function handle(KlingService $kling, OpenAiService $openai): void
    {
        // SEL-407: so assinante pagante gera video. Checado AQUI, no job, e nao so
        // no controller — chamar a API direto nao contorna.
        // SEL-410: barrado vira estado final COM mensagem. Antes a excecao subia
        // antes do try/catch e o pipeline ficava preso em 'queued' pra sempre.
        if (\App\Services\Ai\VideoAccessGuard::barrarPipeline($this->pipelineId, 'studio')) {
            return;
        }
        if (config('app.tenant') !== 'sellerglobal') {
            Log::info('[SEL-360 Studio] job skipped (wrong tenant)', ['tenant' => config('app.tenant')]);
            return;
        }

        $pipeline = AiVideoPipeline::find($this->pipelineId);
        if (!$pipeline) {
            Log::warning('[SEL-360 Studio] pipeline not found', ['id' => $this->pipelineId]);
            return;
        }

        // SEL-JOBZUMBI (13/08): pipeline em estado FINAL nao pode ser retentado.
        //
        // MEDIDO hoje: o pipeline 1011 virou  e o job 2002957 continuou
        // rodando — chegou na **tentativa 10**, re-reservado 1h04 depois de criado,
        // disputando motor com pedido de cliente VIVO. Como o retryUntil e de 1
        // hora, todo pedido que falha deixava um zumbi queimando a frota por mais
        // uma hora inteira. Numa queda de rede, onde muitos falham de uma vez,
        // isso vira uma segunda onda de carga em cima de uma fabrica ja doente.
        //
        // A checagem vem DEPOIS do find (precisa do registro) e ANTES de qualquer
        // chamada paga.
        $estadosFinais = ['done', 'failed', 'canceled', 'cancelled', 'removido_vazamento'];
        if (in_array((string) $pipeline->step, $estadosFinais, true)) {
            Log::info('[SEL-JOBZUMBI] pipeline ja terminou; job abortado sem gastar motor', [
                'pipeline_id' => $this->pipelineId,
                'step'        => $pipeline->step,
            ]);
            return;
        }

        // ══ SEL-NAO-RECOMECA-DO-ZERO v2 (16/08) ══════════════════════════════════════
        //
        // O job pai retenta e cada retentativa comecava a geracao DO ZERO, mesmo com a
        // tentativa anterior ainda gerando no motor. O relogio do cliente nunca fechava:
        // medido hoje, #1365 e #1366 esperaram TRES HORAS e terminaram em falha.
        //
        // (A v1 desta guarda ficava no job filho e era codigo morto: dependia de
        //  payload['_pipeline_id'] e de payloads['veo_heartbeat'], que nao existem em
        //  lugar nenhum. Nunca engatou uma vez. Removida.)
        //
        // Aqui funciona porque so uso dado que existe: se o pedido JA tem uma tarefa de
        // render e foi tocado ha pouco, tem trabalho vivo — esta tentativa sai e deixa
        // terminar. Se a anterior morreu, updated_at envelhece e a proxima assume.
        // So vale para RETENTATIVA do framework (attempts() > 1) — que e o caso descrito:
        // "o pai retenta e a retentativa recomeca do zero". O reagendamento do
        // SEL-RESILIENCIA (tropeco de infra volta pra fila em 30s) cria um job NOVO, com
        // attempts() == 1, e NAO pode ser barrado: barrar ali transformaria "volta pra
        // fila" em "pedido abandonado".
        $__tocadoHaSeg = $pipeline->updated_at ? abs(now()->diffInSeconds($pipeline->updated_at)) : null;
        if ($this->attempts() > 1 && $pipeline->render_task_id && $__tocadoHaSeg !== null && $__tocadoHaSeg < 360) {
            Log::error('[SEL-NAO-RECOMECA-DO-ZERO] ja existe geracao viva neste pedido — esta tentativa sai sem recomecar', [
                'pipeline_id'   => $this->pipelineId,
                'tarefa_viva'   => $pipeline->render_task_id,
                'step'          => $pipeline->step,
                'tocado_ha_seg' => $__tocadoHaSeg,
                'tentativa'     => $this->attempts(),
            ]);

            return;
        }

        if ($pipeline->dry_run) {
            Log::info('[SEL-360 Studio] dry_run=true, skip paid calls', ['id' => $this->pipelineId]);
            $pipeline->update(['step' => 'done', 'output_url' => 'https://example.com/dry-run-video.mp4']);
            // SEL-videoready (10/08, Ruan "cliente nao esta sendo avisado"): mesmo
            // choke point de notificacao dos demais "done" — ver finalize() abaixo.
            \App\Services\VideoReadyNotifier::notify($pipeline->fresh() ?? $pipeline);
            return;
        }

        $payloads     = $pipeline->payloads ?? [];
        // SEL-SEM-SOM-PROPAGA (14/08): o pedido de video MUDO vem no payload da
        // pipeline, mas cada chamada ao motor monta um payload NOVO com chaves fixas —
        // entao `sem_som` morria aqui e o passo que remove o audio (-an) nunca rodava.
        // Medido: o payload que chegava no motor tinha so 7 chaves, sem sem_som.
        $this->pedidoMudo = ! empty(is_array($payloads) ? ($payloads['sem_som'] ?? null) : null);
        $pipelineType = $payloads['pipeline'] ?? 'animar_produto';
        // SEL-387 F5: o padrao era kling-v1-6, que so aceita 5 ou 10 segundos. Como
        // a duracao cai pra 5 quando o valor pedido e menor que 10, sairam videos de
        // 5s que nao cabem o roteiro — Ruan reclamou disso mais de uma vez.
        // O v3 aceita 3-15s e e o unico com audio nativo sincronizado (3.0 Omni).
        // Padrao passa a ser kling-v3; quem pedir modelo explicito continua mandando.
        $gearModel    = $payloads['gear_model'] ?? 'kling-v3';
        $gearMode     = $payloads['gear_mode'] ?? 'std';
        $duration     = (int) ($payloads['duration'] ?? 12);
        // SEL-364/368: kling-v3 aceita 3-15s; demais modelos so 5 ou 10
        // (erro 1201 "duration value '15' is invalid").
        // SEL-387 F5: nunca cair pra 5s. Roteiro de 3 cortes com fala nao cabe em 5
        // segundos — a frase corta no meio e o anuncio morre. Piso e 10s nos modelos
        // antigos, e 12s no v3 (padrao que o Ruan aprovou).
        $duration     = str_starts_with($gearModel, 'kling-v3')
            ? max(10, min(15, $duration))
            : 10;
        $imageUrl     = $payloads['image_url'] ?? null;
        $imageRefs    = $payloads['image_refs'] ?? [];
        $prompt       = $payloads['prompt'] ?? null;
        $ratio        = $payloads['aspect_ratio'] ?? '9:16';

        Log::info('[SEL-360 Studio] starting', [
            'pipeline_id' => $this->pipelineId,
            'type'        => $pipelineType,
            'model'       => $gearModel,
        ]);

        try {
            $pipeline->update(['step' => 'render']);

            // SEL-364b: modelo as vezes passa URL de pagina (TikTok) como image_url —
            // Kling rejeita ("Failed to resolve the request body"). So aceita imagem real.
            if ($imageUrl && ! preg_match('/\.(jpe?g|png|webp|gif)(\?|$)/i', $imageUrl)) {
                Log::warning('[SEL-360 Studio] image_url invalida, descartando', ['id' => $this->pipelineId, 'url' => $imageUrl]);
                $imageUrl = null;
            }

            // SEL-364b: sem imagem base o image2video da Kling rejeita com 1201
            // ("image and image_tail can not be empty") — cai pra text2video.
            $needsImage = in_array($pipelineType, ['animar_produto', 'video_multi_cortes', 'camera_cinematografica'], true);
            if ($needsImage && empty($imageUrl) && ! empty($prompt)) {
                $pipelineType = 'video_do_zero';
            }

            // FASE 1: pipelines originais

            if ($pipelineType === 'animar_produto') {
                $taskId    = $this->runImageToVideo($kling, $imageUrl, $prompt, $gearModel, $gearMode, $ratio, $duration);
                $outputUrl = $this->pollVideo($kling, $taskId, $pipeline, 'image2video');
                $this->finalize($pipeline, $outputUrl);
                return;
            }

            if ($pipelineType === 'pov_so_mao') {
                // SEL-368: POV usa a foto REAL do produto via elements do kling-v3.
                // Antes gerava "mao com produto" por texto — saia produto GENERICO,
                // nunca o do cliente.
                $productImages = array_slice(array_values(array_unique(array_filter(
                    array_merge([$imageUrl], (array) $imageRefs)
                ))), 0, 4);
                $durV3 = max(3, min(15, (int) ($payloads['duration'] ?? 10)));
                $this->updateLabel($pipeline, $payloads, 'Montando cena POV com seu produto...');
                // SEL-368b: kling-v3 com first-frame = produto sozinho NUNCA introduz
                // mao (2 testes visuais). Monta a cena ANTES: editImage gera a foto
                // "mao segurando o produto" e ela vira o first-frame. Fail-open.
                $povFrame = $imageUrl;
                try {
                    $composed = $openai->editImage(
                        $imageUrl,
                        'Add a realistic human hand and forearm entering from the bottom of the frame, '
                        . 'holding this exact product in a first-person POV shot. Keep the product '
                        . 'COMPLETELY UNCHANGED (same colors, logos, texture, angle). Photorealistic skin '
                        . 'with visible pores, fine vellus hair and natural veins, soft diffused daylight, '
                        . 'DSLR 85mm shallow depth of field, vertical 9:16 composition, no face, no text.'
                    );
                    if ($composed) {
                        $povFrame = $composed;
                        Log::info('[SEL-368] cena POV montada via editImage', ['id' => $this->pipelineId]);
                    }
                } catch (\Throwable $e) {
                    Log::warning('[SEL-368] editImage falhou, seguindo com foto original', ['id' => $this->pipelineId, 'err' => $e->getMessage()]);
                }
                $this->updateLabel($pipeline, $payloads, 'Animando a cena POV...');
                $res = $kling->imageToVideo([
                    // SEL-MODELO-EM-TODO-CAMINHO (15/08): plano pagante sai da fila gratis
                    // tambem por AQUI. Medido: 8 de 12 pagantes ainda caiam no gratis porque
                    // so `runImageToVideo` levava o modelo. `modeloDoPlano()` devolve null pra
                    // quem nao paga — o gratis segue sendo o padrao, como o Ruan definiu.
                    'veo_model'    => $this->modeloDoPlano(),
                    'pipeline_id'  => $this->pipelineId,
                    'model_name'      => 'kling-v3',
                    'mode'            => 'pro',
                    'duration'        => (string) $durV3,
                    'aspect_ratio'    => $ratio,
                    'image'           => $povFrame,
                    'elements'        => [['element_id' => 'element_1', 'images' => $productImages]],
                    'multi_prompt'    => $this->povShots($durV3, $prompt, $povFrame !== $imageUrl),
                    'shot_type'       => 'customize',
                    'negative_prompt' => 'face visible, human face, avatar, full body, head, cartoon, anime, CGI skin, plastic skin, waxy skin, doll-like, distorted hands, extra fingers, warped hands, morphing, flickering, watermark, text overlay, subtitles, low quality, blurry',
                    'cfg_scale'       => 0.5,
                            // SEL-SEM-SOM-PROPAGA: viaja junto ate o job do navegador.
            'sem_som'      => $this->pedidoMudo,
        ]);
                $taskId = $res['data']['task_id'] ?? null;
                if (!$taskId) throw new \RuntimeException('kling_no_task_id_pov: ' . json_encode($res));
                $outputUrl = $this->pollVideo($kling, $taskId, $pipeline, 'image2video');
                $this->finalize($pipeline, $outputUrl);
                return;
            }

            if ($pipelineType === 'cena_com_referencias') {
                $allImages = array_values(array_filter(array_unique(array_merge([$imageUrl], $imageRefs))));
                $allImages = array_slice($allImages, 0, 7);
                if (count($allImages) < 2) throw new \RuntimeException('cena_referencias_needs_2plus_images');
                $imageList   = array_map(fn($u) => ['image' => $u], $allImages);
                $scenePrompt = $prompt ?: 'Cena de video profissional mostrando o produto em uso real, filmagem ao vivo, 9:16, vertical, vitrine de vendas online, sem slideshow.';
                $res         = $kling->multiImageToVideo([
                    'model_name'   => 'kling-v1-6',
                    'mode'         => 'std',
                    'duration'     => (string) $duration,
                    'aspect_ratio' => $ratio,
                    'image_list'   => $imageList,
                    'prompt'       => $scenePrompt,
                    'cfg_scale'    => 0.5,
                ]);
                $taskId    = $this->taskIdOuRecusa($res, 'cena_com_referencias', 'Cena com varias fotos de referencia');
                $outputUrl = $this->pollVideo($kling, $taskId, $pipeline, 'image2video');
                $this->finalize($pipeline, $outputUrl);
                return;
            }

            // FASE 2: novos pipelines

            if ($pipelineType === 'video_do_zero') {
                if (empty($prompt)) throw new \RuntimeException('video_do_zero_needs_prompt');
                // text2video nao suporta kling-v2-1 ("model is not supported") — usa v2-master
                $t2vModel = $gearModel === 'kling-v2-1' ? 'kling-v2-master' : $gearModel;
                $res    = $kling->textToVideo([
                    // SEL-MODELO-EM-TODO-CAMINHO (15/08): plano pagante sai da fila gratis
                    // tambem por AQUI. Medido: 8 de 12 pagantes ainda caiam no gratis porque
                    // so `runImageToVideo` levava o modelo. `modeloDoPlano()` devolve null pra
                    // quem nao paga — o gratis segue sendo o padrao, como o Ruan definiu.
                    'veo_model'    => $this->modeloDoPlano(),
                    'pipeline_id'  => $this->pipelineId,
                    'model_name'   => $t2vModel,
                    'mode'         => $gearMode,
                    'duration'     => (string) $duration,
                    'aspect_ratio' => $ratio,
                    'prompt'       => $prompt,
                    'cfg_scale'    => 0.5,
                            // SEL-SEM-SOM-PROPAGA: viaja junto ate o job do navegador.
            'sem_som'      => $this->pedidoMudo,
        ]);
                $taskId    = $res['data']['task_id'] ?? null;
                if (!$taskId) throw new \RuntimeException('kling_no_task_id: ' . json_encode($res));
                $outputUrl = $this->pollVideo($kling, $taskId, $pipeline, 'text2video');
                $this->finalize($pipeline, $outputUrl);
                return;
            }

            if ($pipelineType === 'video_multi_cortes') {
                $multiShot = [
                    ['shot_type' => 'macro',  'prompt' => 'Close extremo no produto, detalhes da textura, iluminacao profissional'],
                    ['shot_type' => 'medium', 'prompt' => 'Plano medio do produto sendo segurado naturalmente'],
                    ['shot_type' => 'cta',    'prompt' => 'Produto em destaque com call to action visual, fundo limpo'],
                ];
                $res    = $kling->imageToVideo([
                    // SEL-MODELO-EM-TODO-CAMINHO (15/08): plano pagante sai da fila gratis
                    // tambem por AQUI. Medido: 8 de 12 pagantes ainda caiam no gratis porque
                    // so `runImageToVideo` levava o modelo. `modeloDoPlano()` devolve null pra
                    // quem nao paga — o gratis segue sendo o padrao, como o Ruan definiu.
                    'veo_model'    => $this->modeloDoPlano(),
                    'pipeline_id'  => $this->pipelineId,
                    'model_name'   => 'kling-v3',
                    'mode'         => 'pro',
                    'duration'     => (string) $duration,
                    'aspect_ratio' => $ratio,
                    'image'        => $imageUrl,
                    'prompt'       => $prompt ?: 'Video multi-shot profissional do produto, 9:16, video vertical para redes sociais.',
                    'multi_shot'   => $multiShot,
                    'cfg_scale'    => 0.5,
                            // SEL-SEM-SOM-PROPAGA: viaja junto ate o job do navegador.
            'sem_som'      => $this->pedidoMudo,
        ]);
                $taskId    = $res['data']['task_id'] ?? null;
                if (!$taskId) throw new \RuntimeException('kling_no_task_id: ' . json_encode($res));
                $outputUrl = $this->pollVideo($kling, $taskId, $pipeline, 'image2video');
                $this->finalize($pipeline, $outputUrl);
                return;
            }

            if ($pipelineType === 'camera_cinematografica') {
                $cameraPreset  = $payloads['camera_preset'] ?? 'zoom_in';
                $cameraControl = self::CAMERA_PRESETS[$cameraPreset] ?? self::CAMERA_PRESETS['zoom_in'];
                $res    = $kling->imageToVideo([
                    // SEL-MODELO-EM-TODO-CAMINHO (15/08): plano pagante sai da fila gratis
                    // tambem por AQUI. Medido: 8 de 12 pagantes ainda caiam no gratis porque
                    // so `runImageToVideo` levava o modelo. `modeloDoPlano()` devolve null pra
                    // quem nao paga — o gratis segue sendo o padrao, como o Ruan definiu.
                    'veo_model'    => $this->modeloDoPlano(),
                    'pipeline_id'  => $this->pipelineId,
                    'model_name'     => $gearModel,
                    'mode'           => $gearMode,
                    'duration'       => (string) $duration,
                    'aspect_ratio'   => $ratio,
                    'image'          => $imageUrl,
                    'prompt'         => $prompt ?: 'Movimento de camera cinematografico sobre o produto, alta qualidade, 9:16.',
                    'camera_control' => $cameraControl,
                    'cfg_scale'      => 0.5,
                            // SEL-SEM-SOM-PROPAGA: viaja junto ate o job do navegador.
            'sem_som'      => $this->pedidoMudo,
        ]);
                $taskId    = $res['data']['task_id'] ?? null;
                if (!$taskId) throw new \RuntimeException('kling_no_task_id: ' . json_encode($res));
                $outputUrl = $this->pollVideo($kling, $taskId, $pipeline, 'image2video');
                $this->finalize($pipeline, $outputUrl);
                return;
            }

            if ($pipelineType === 'avatar_apresentando') {
                $avatarUrl = $payloads['avatar_url'] ?? null;
                if (empty($avatarUrl)) throw new \RuntimeException('avatar_apresentando_needs_avatar_url');
                $elements = [
                    ['image_type' => 'avatar',  'image' => $avatarUrl],
                    ['image_type' => 'subject', 'image' => $imageUrl],
                ];
                $res    = $kling->imageToVideo([
                    // SEL-MODELO-EM-TODO-CAMINHO (15/08): plano pagante sai da fila gratis
                    // tambem por AQUI. Medido: 8 de 12 pagantes ainda caiam no gratis porque
                    // so `runImageToVideo` levava o modelo. `modeloDoPlano()` devolve null pra
                    // quem nao paga — o gratis segue sendo o padrao, como o Ruan definiu.
                    'veo_model'    => $this->modeloDoPlano(),
                    'pipeline_id'  => $this->pipelineId,
                    'model_name'   => 'kling-v3',
                    'mode'         => 'pro',
                    'duration'     => (string) $duration,
                    'aspect_ratio' => $ratio,
                    'image'        => $avatarUrl,
                    'prompt'       => $prompt ?: 'Avatar apresentando o produto de forma natural e convincente, estilo video vertical para redes sociais, 9:16.',
                    'elements'     => $elements,
                    'cfg_scale'    => 0.5,
                            // SEL-SEM-SOM-PROPAGA: viaja junto ate o job do navegador.
            'sem_som'      => $this->pedidoMudo,
        ]);
                $taskId    = $res['data']['task_id'] ?? null;
                if (!$taskId) throw new \RuntimeException('kling_no_task_id: ' . json_encode($res));
                $outputUrl = $this->pollVideo($kling, $taskId, $pipeline, 'image2video');
                $this->finalize($pipeline, $outputUrl);
                return;
            }

            if ($pipelineType === 'trocar_rosto') {
                $faceUrl        = $payloads['face_url'] ?? null;
                $targetVideoUrl = $payloads['target_video_url'] ?? null;
                if (empty($faceUrl) || empty($targetVideoUrl)) {
                    throw new \RuntimeException('trocar_rosto_needs_face_url_and_target_video_url');
                }
                $this->updateLabel($pipeline, $payloads, 'Verificando conformidade...');
                $this->checkCelebrity($openai, $targetVideoUrl);
                $this->updateLabel($pipeline, $payloads, 'Trocando rosto...');
                $res    = $kling->faceSwap([
                    'face_reference' => $faceUrl,
                    'face_target'    => $targetVideoUrl,
                    'target_type'    => 'video',
                ]);
                $taskId    = $this->taskIdOuRecusa($res, 'trocar_rosto', 'Trocar o rosto do video');
                $outputUrl = $this->pollImageTask($kling, $taskId, $pipeline, 'face-swap');
                $this->finalize($pipeline, $outputUrl);
                return;
            }

            if ($pipelineType === 'provador_virtual') {
                $personUrl = $payloads['person_url'] ?? null;
                $clothUrl  = $payloads['cloth_url'] ?? $imageUrl;
                if (empty($personUrl) || empty($clothUrl)) {
                    throw new \RuntimeException('provador_virtual_needs_person_url_and_cloth_url');
                }
                $tryOnImage = null;

                // SEL-TRYON-FLOW (14/08, Ruan: "implementa") — MOTOR NOVO, e agora o PRIMEIRO.
                //
                // O provador virtual estava morto nos DOIS caminhos que existiam:
                //   1. Kling virtualTryOn  -> unsupportedResponse() no KlingBrowserService
                //   2. fallback gpt-image  -> OpenAiService::editImageMulti devolve null na
                //      primeira linha porque OPENAI_API_KEY esta VAZIA no .env
                // Resultado: 8 pedidos morreram hoje em "Provador virtual nao esta disponivel",
                // num recurso que os planos Ilimitado (R$149) e Ultra (R$297) VENDEM.
                //
                // O motor novo usa o que ja e nosso: Google Flow / Nano Banana pelo
                // MacFlowImageAdapter, rodando no proprio servidor com xvfb. Custo R$0.
                // O que faltava era o adapter MANDAR as fotos de referencia — o parametro
                // $refImages existia na assinatura desde 10/08 e nunca era usado, e o worker
                // dizia no cabecalho "SEM upload de foto de referencia". Os dois foram
                // corrigidos hoje (SEL-TRYON-REFS) e o anexo das 2 fotos foi provado em
                // teste seco: refs_pedidas=2, refs_anexadas=2, 0 credito.
                try {
                    $engineImg = \App\Models\AiEngine::where('is_active', 1)
                        ->where('name', 'like', '%IMAGEM%')
                        ->orderBy('id')->first();
                    if (! $engineImg) {
                        throw new \RuntimeException('sem_engine_de_imagem');
                    }
                    $this->updateLabel($pipeline, $payloads, 'Vestindo a peca na pessoa...');
                    $flow = new \App\Services\Ai\MacFlowImageAdapter($engineImg);
                    $r = $flow->generate(
                        // SEL-TRYON-PROMPT (14/08) — REGRA APRENDIDA MEDINDO, em 3 tentativas:
                        // descrever por POSICAO ("primeira foto / segunda foto") NAO funciona.
                        // O teste 2 com esse texto devolveu a MODELO da foto da roupa, nao a
                        // pessoa do cliente. Descrever por CONTEUDO E PAPEL funciona: o teste 3
                        // vestiu a pessoa certa com a peca certa. A frase decisiva e a do
                        // "cabide" — sem ela o modelo acha que a pessoa da foto do produto e
                        // quem deve aparecer.
                        'Nas fotos de referencia existe UMA PESSOA e existe UMA PECA DE ROUPA. '
                        . 'Gere ESSA MESMA PESSOA vestindo ESSA PECA. O rosto, o cabelo, a barba, '
                        . 'a pele e o corpo tem que ser os da PESSOA, identicos aos da referencia. '
                        . 'Se a peca aparecer vestida em alguem na foto do produto, IGNORE essa '
                        . 'outra pessoa: ela e so o cabide da roupa, nao pode aparecer no resultado. '
                        . 'A peca deve manter cor, estampa, tecido, caimento e detalhes exatos, '
                        . 'com dobras e sombras reais. Foto de corpo inteiro, fotorrealista, '
                        . 'fundo de estudio neutro, enquadramento vertical 9:16. '
                        . 'Sem nenhum texto, letra, numero, legenda, logo ou marca dagua.',
                        [$personUrl, $clothUrl]
                    );
                    $tryOnImage = $r['url'] ?? null;
                    if ($tryOnImage) {
                        $payloads['tryon_motor'] = 'flow_nano_banana';
                        Log::error('[SEL-TRYON-FLOW] provador virtual pelo Flow', [
                            'id' => $this->pipelineId, 'url' => $tryOnImage,
                        ]);
                    }
                } catch (Throwable $e) {
                    Log::error('[SEL-TRYON-FLOW] Flow nao vestiu — caindo pros motores antigos', [
                        'id' => $this->pipelineId, 'err' => mb_substr($e->getMessage(), 0, 200),
                    ]);
                }

                try {
                    if ($tryOnImage) { throw new \RuntimeException('__ja_vestiu__'); }
                    $res    = $kling->virtualTryOn([
                        'model_name'  => 'kolors-virtual-try-on-v1-5',
                        'human_image' => $personUrl,
                        'cloth_image' => $clothUrl,
                    ]);
                    // SEL-459: antes o try-on indisponivel so era descoberto no poll,
                    // 15s depois. Agora recusa na entrada e o fallback comeca na hora.
                    // Continua sendo Throwable, entao o catch abaixo segue valendo.
                    $taskId    = $this->taskIdOuRecusa($res, 'provador_virtual', 'Provador virtual');
                    $tryOnImage = $this->pollImageTask($kling, $taskId, $pipeline, 'kolors-virtual-try-on');
                } catch (Throwable $e) {
                    // SEL-TRYON-FLOW: o Flow ja vestiu — pular os motores antigos sem
                    // registrar falha nenhuma (nao houve falha, houve sucesso antes).
                    if ($e->getMessage() === '__ja_vestiu__') {
                        goto tryon_pronto;
                    }
                    // SEL-368: o try-on do Kling e cobrado em pacote de IMAGEM
                    // separado do de video; sem saldo devolve 429 (code 1102).
                    // Fallback: gpt-image compoe pessoa+roupa e o video segue
                    // pelo pacote de video normal.
                    Log::warning('[SEL-368 Studio] try-on Kling falhou, fallback gpt-image', [
                        'id' => $this->pipelineId, 'err' => mb_substr($e->getMessage(), 0, 150),
                    ]);
                    $openaiSvc  = app(OpenAiService::class);
                    $tryOnImage = $openaiSvc->editImageMulti(
                        [$personUrl, $clothUrl],
                        'Dress the person from the first image in the clothing item from the second image. '
                        . 'Keep the person\'s face, hair, body and pose exactly the same. The garment must keep '
                        . 'its exact color, print, fabric and details, fitting naturally with realistic drape and folds. '
                        . 'Full-body photorealistic photo, clean neutral studio background, vertical composition.'
                    );
                    if (!$tryOnImage) throw $e;
                    $payloads['tryon_fallback'] = 'gpt_image';
                }
                tryon_pronto:
                // SEL-368: trocar a modelo e ENCADEADO — swap na imagem primeiro,
                // depois anima. O cliente pediu VIDEO, nao imagem.
                $payloads['tryon_image_url'] = $tryOnImage;
                $this->updateLabel($pipeline, $payloads, 'Troca feita! Animando o video...');
                $animPrompt = $prompt ?: 'Person naturally modeling the outfit, subtle confident movement, slight turn, looking at camera, photorealistic skin texture, fashion showcase, soft studio lighting, vertical social video 9:16';
                $taskId2   = $this->runImageToVideo($kling, $tryOnImage, $animPrompt, $gearModel, $gearMode, $ratio, $duration);
                $outputUrl = $this->pollVideo($kling, $taskId2, $pipeline, 'image2video');
                $this->finalize($pipeline, $outputUrl);
                return;
            }

            if ($pipelineType === 'continuar_video') {
                $sourceVideoUrl = $payloads['source_video_url'] ?? null;
                if (empty($sourceVideoUrl)) throw new \RuntimeException('continuar_video_needs_source_video_url');
                $res    = $kling->videoExtend([
                    'video_url' => $sourceVideoUrl,
                    'prompt'    => $prompt ?: 'Continuacao natural do video, mesmo estilo e iluminacao.',
                    'cfg_scale' => 0.5,
                ]);
                $taskId    = $this->taskIdOuRecusa($res, 'continuar_video', 'Continuar um video que ja existe');
                $outputUrl = $this->pollVideoExtend($kling, $taskId, $pipeline);
                $this->finalize($pipeline, $outputUrl);
                return;
            }

            if ($pipelineType === 'sincronizar_fala') {
                $sourceVideoUrl = $payloads['source_video_url'] ?? null;
                $audioUrl       = $payloads['audio_url'] ?? null;
                $ttsText        = $payloads['tts_text'] ?? $prompt;
                if (empty($sourceVideoUrl)) throw new \RuntimeException('sincronizar_fala_needs_source_video_url');

                // SEL-459 (junto com o guard SEL-420 que ja existe no AiVideoPipelineJob):
                // pergunta a capacidade ANTES de trabalhar. Sem isto o job gerava a voz
                // no TTS, subia o mp3 e SO ENTAO descobria que o motor nao faz sincronia
                // labial — pagando por um audio que ia pro lixo. Perguntar antes e
                // diferente de descobrir pelo erro depois.
                if (method_exists($kling, 'suportaLipSync') && $kling->suportaLipSync() === false) {
                    Log::error('[SEL-459] funcao indisponivel no motor atual — recusada antes do TTS', [
                        'pipeline_id' => $this->pipelineId,
                        'funcao'      => 'sincronizar_fala',
                        'motivo'      => 'suportaLipSync()=false no motor ativo (' . get_class($kling) . ')',
                    ]);
                    throw new \RuntimeException('funcao_indisponivel:sincronizar_fala|Sincronizar a fala com o video');
                }

                if (empty($audioUrl) && !empty($ttsText)) {
                    $this->updateLabel($pipeline, $payloads, 'Gerando voz PT-BR...');
                    $pipeline->update(['step' => 'voice']);
                    $audioResult = $openai->tts($ttsText, $payloads['voice'] ?? 'nova');
                    $audioKey    = 'studio/tts/' . now()->format('Ymd') . '/' . Str::uuid() . '.mp3';
                    Storage::disk('public')->put($audioKey, base64_decode($audioResult['audio_base64']));
                    $audioUrl    = Storage::disk('public')->url($audioKey);
                    Log::info('[SEL-360 Studio] tts generated for lipsync', ['url' => $audioUrl]);
                }

                if (empty($audioUrl)) throw new \RuntimeException('sincronizar_fala_needs_audio_url_or_tts_text');

                $pipeline->update(['step' => 'lipsync']);
                $res    = $kling->lipSync([
                    'video_url' => $sourceVideoUrl,
                    'mode'      => 'audio2video',
                    'audio_url' => $audioUrl,
                ]);
                $taskId    = $this->taskIdOuRecusa($res, 'sincronizar_fala', 'Sincronizar a fala com o video');
                $outputUrl = $this->pollVideo($kling, $taskId, $pipeline, 'lip-sync');
                $this->finalize($pipeline, $outputUrl);
                return;
            }

            if ($pipelineType === 'efeitos_prontos') {
                $effectKey    = $payloads['effect_key'] ?? 'unbox_2026';
                $effectPrompt = self::EFFECT_PROMPTS[$effectKey] ?? self::EFFECT_PROMPTS['unbox_2026'];
                if (!empty($prompt)) $effectPrompt = $prompt . ' ' . $effectPrompt;
                if ($imageUrl) {
                    $res = $kling->imageToVideo([
                        // SEL-MODELO-EM-TODO-CAMINHO (15/08): plano pagante sai da fila gratis
                        // tambem por AQUI. Medido: 8 de 12 pagantes ainda caiam no gratis porque
                        // so `runImageToVideo` levava o modelo. `modeloDoPlano()` devolve null pra
                        // quem nao paga — o gratis segue sendo o padrao, como o Ruan definiu.
                        'veo_model'    => $this->modeloDoPlano(),
                        'pipeline_id'  => $this->pipelineId,
                        'model_name'   => $gearModel,
                        'mode'         => $gearMode,
                        'duration'     => (string) $duration,
                        'aspect_ratio' => $ratio,
                        'image'        => $imageUrl,
                        'prompt'       => $effectPrompt,
                        'cfg_scale'    => 0.5,
                                // SEL-SEM-SOM-PROPAGA: viaja junto ate o job do navegador.
            'sem_som'      => $this->pedidoMudo,
        ]);
                } else {
                    $res = $kling->textToVideo([
                        // SEL-MODELO-EM-TODO-CAMINHO (15/08): plano pagante sai da fila gratis
                        // tambem por AQUI. Medido: 8 de 12 pagantes ainda caiam no gratis porque
                        // so `runImageToVideo` levava o modelo. `modeloDoPlano()` devolve null pra
                        // quem nao paga — o gratis segue sendo o padrao, como o Ruan definiu.
                        'veo_model'    => $this->modeloDoPlano(),
                        'pipeline_id'  => $this->pipelineId,
                        'model_name'   => $gearModel,
                        'mode'         => $gearMode,
                        'duration'     => (string) $duration,
                        'aspect_ratio' => $ratio,
                        'prompt'       => $effectPrompt,
                        'cfg_scale'    => 0.5,
                                // SEL-SEM-SOM-PROPAGA: viaja junto ate o job do navegador.
            'sem_som'      => $this->pedidoMudo,
        ]);
                }
                $taskId    = $res['data']['task_id'] ?? null;
                if (!$taskId) throw new \RuntimeException('kling_no_task_id_effect: ' . json_encode($res));
                $taskType  = $imageUrl ? 'image2video' : 'text2video';
                $outputUrl = $this->pollVideo($kling, $taskId, $pipeline, $taskType);
                $this->finalize($pipeline, $outputUrl);
                return;
            }

            if ($pipelineType === 'voz_brasileira') {
                $ttsText = $payloads['tts_text'] ?? $prompt;
                if (empty($ttsText)) throw new \RuntimeException('voz_brasileira_needs_tts_text');
                $pipeline->update(['step' => 'voice']);
                $audioResult = $openai->tts($ttsText, $payloads['voice'] ?? 'nova');
                $audioKey    = 'studio/tts/' . now()->format('Ymd') . '/' . Str::uuid() . '.mp3';
                Storage::disk('public')->put($audioKey, base64_decode($audioResult['audio_base64']));
                $audioUrl    = Storage::disk('public')->url($audioKey);
                $this->finalize($pipeline, $audioUrl);
                return;
            }

            // SEL-fix rota: pipeline de video LONGO (studio_long_*) que caiu
            // aqui por healer antigo (render-hung-heal/video-events casavam so
            // 'studio_long_ugc') -> reencaminha pro job correto em vez de
            // matar com 'Pipeline nao reconhecido'.
            if (str_starts_with((string) $pipeline->mode, 'studio_long') || $pipelineType === 'video_longo') {
                Log::warning('[SEL-360 Studio] modo longo no job curto -> StudioLongVideoJob', ['id' => $this->pipelineId, 'mode' => $pipeline->mode]);
                \App\Jobs\StudioLongVideoJob::dispatch($this->pipelineId)->onQueue('video');
                return;
            }

            // Fallback para pipeline desconhecido
            Log::warning('[SEL-360 Studio] unknown pipeline type', ['type' => $pipelineType]);
            $pipeline->update(['step' => 'failed', 'error_message' => 'Pipeline nao reconhecido.']);

        } catch (Throwable $e) {
            $msg     = $e->getMessage();

            // SEL-RESILIENCIA (12/08) — falha NOSSA nao vira erro do cliente.
            // Antes, com $tries=1, qualquer tropeco de infraestrutura (navegador
            // fechado por reinicio do pool, tunel caido, teto de tempo) caia
            // direto no `failed` la embaixo. Agora infra/capacidade voltam pra
            // fila e so conteudo (ou o fim do prazo) vira erro de verdade.
            $tipo = \App\Services\Ai\VideoResilience::classificar($msg);
            if ($tipo !== 'conteudo') {
                $n = \App\Services\Ai\VideoResilience::tentativasInfra($this->pipelineId);
                if ($tipo === 'capacidade' || $n < \App\Services\Ai\VideoResilience::MAX_TENTATIVAS_INFRA) {
                    if ($tipo === 'infra') {
                        \App\Services\Ai\VideoResilience::somarTentativaInfra($this->pipelineId, $msg);
                    }
                    $pipeline->update(['step' => 'queued', 'error_message' => null]);
                    \App\Services\Ai\VideoResilience::batida(
                        $this->pipelineId,
                        'Tivemos um tropeco aqui. Ja estamos tentando em outro motor...'
                    );
                    Log::warning('[SEL-RESILIENCIA][Studio] tropeco de ' . $tipo . ' -> reenfileira (cliente NAO ve erro)', [
                        'pipeline_id' => $this->pipelineId,
                        'motivo'      => \App\Services\Ai\VideoResilience::motivoInfra($msg),
                        'erro'        => mb_substr($msg, 0, 200),
                    ]);
                    if ($this->job) {
                        $this->release($tipo === 'capacidade' ? 45 : 30);
                    } else {
                        static::dispatch($this->pipelineId)->onQueue('video')->delay(now()->addSeconds(30));
                    }
                    return;
                }
            }

            Log::error('[SEL-360 Studio] job failed', ['pipeline_id' => $this->pipelineId, 'error' => $msg]);
            $userMsg = match(true) {
                str_contains($msg, 'celebrity_detected')   => 'Detectei rosto de figura publica no video. Nao posso trocar rosto de celebridades. Escolhe outro video.',
                // SEL-459: "tente novamente" era mentira aqui — a funcao nao existe
                // neste motor, entao tentar de novo da no mesmo. Diz QUAL caiu.
                str_contains($msg, 'funcao_indisponivel')  => $this->mensagemFuncaoIndisponivel($msg),
                str_contains($msg, 'kling_not_configured') => 'Studio temporariamente indisponivel. Contate o suporte.',
                str_contains($msg, 'needs_')               => 'Faltam dados pra gerar. Tenta de novo informando a midia necessaria.',
                default                                    => 'Erro na geracao. Tente novamente ou contate o suporte.',
            };
            $pipeline->update(['step' => 'failed', 'error_message' => $userMsg]);
        }
    }

    /**
     * SEL-RESILIENCIA (12/08): prazo do retryUntil estourado. Sem isto a pipeline
     * ficaria orfa em 'queued' e so um reaper a encerraria.
     */
    public function failed(Throwable $e): void
    {
        try {
            $p = AiVideoPipeline::find($this->pipelineId);
            if ($p && ! in_array($p->step, ['done', 'canceled'], true) && empty($p->output_url)) {
                $p->update([
                    'step'          => 'failed',
                    'error_message' => \App\Services\Ai\VideoResilience::mensagemCliente($e->getMessage()),
                ]);
            }
        } catch (Throwable $x) {
            // nada a fazer
        }
    }

    // Poll Helpers

    /**
     * SEL-459 — le o task_status ANTES de aceitar o task_id.
     *
     * O motor por navegador responde `code=0, message='ok'` COM um task_id
     * mesmo pras funcoes que ele nao sabe fazer (KlingBrowserService::
     * unsupportedResponse). O `task_status: 'failed'` fica escondido um nivel
     * abaixo. Quem so testava `if (!$taskId) throw` — que era todo mundo aqui —
     * dava a resposta por boa, guardava um id `kbrowser_unsupported_*` e ia
     * fazer poll de uma tarefa que nunca existiu. O cliente esperava minutos
     * pra receber "Erro na geracao. Tente novamente", que e uma mentira: nao
     * adianta tentar de novo, aquela funcao nao existe neste motor.
     *
     * Hoje isso nao aparece na taxa de falha porque nenhuma dessas funcoes tem
     * botao na tela (medido em 31/07: 30 dias, zero execucao de trocar_rosto,
     * continuar_video e sincronizar_fala). Nao e uma causa — e uma mina, e ela
     * detona no dia em que alguem expuser esses botoes, que e exatamente o
     * pedido em aberto. Desarmar antes sai mais barato.
     *
     * @param  string $funcao  chave do pipeline, pro log
     * @param  string $rotulo  nome legivel da funcao, pro cliente
     */
    private function taskIdOuRecusa(array $res, string $funcao, string $rotulo): string
    {
        $status = $res['data']['task_status'] ?? null;
        $taskId = $res['data']['task_id'] ?? null;
        $motivo = $res['data']['task_status_msg'] ?? '';

        if ($status === 'failed' || empty($taskId)) {
            // Barulhento de proposito: funcao + motivo + resposta crua. Falha
            // silenciosa foi o defeito que mais custou tempo nesta base.
            Log::error('[SEL-459] funcao indisponivel no motor atual — recusada na entrada', [
                'pipeline_id' => $this->pipelineId,
                'funcao'      => $funcao,
                'task_status' => $status ?? '(ausente)',
                'motivo'      => $motivo !== '' ? $motivo : '(sem task_id na resposta)',
                'resposta'    => mb_substr(json_encode($res), 0, 400),
            ]);
            throw new \RuntimeException('funcao_indisponivel:' . $funcao . '|' . $rotulo);
        }

        return $taskId;
    }

    /** SEL-459 — recusa legivel: diz QUAL funcao caiu, sem citar provider nem custo. */
    private function mensagemFuncaoIndisponivel(string $erro): string
    {
        $rotulo = 'Esse recurso';
        if (preg_match('/funcao_indisponivel:[^|]*\|(.+)$/', $erro, $m)) {
            $rotulo = trim($m[1]);
        }

        return $rotulo . ' nao esta disponivel no momento. Os outros formatos de '
             . 'video continuam funcionando normalmente — escolhe outro que eu gero agora.';
    }

    private function pollVideo(KlingService $kling, string $taskId, AiVideoPipeline $pipeline, string $type): string
    {
        for ($i = 0; $i < self::MAX_POLLS; $i++) {
            sleep(self::POLL_INTERVAL);
            $status = $kling->getVideoStatus($taskId, $type);
            $state  = $status['data']['task_status'] ?? 'processing';
            Log::debug('[SEL-360 Studio] polling video', ['task' => $taskId, 'type' => $type, 'status' => $state, 'attempt' => $i + 1]);

            // SEL-RESILIENCIA (12/08) — BATIDA durante o poll. Este laco fica ate
            // 30min sem tocar a pipeline; pros reapers isso era indistinguivel de
            // render travado, e o pedido era ceifado no meio do caminho. Agora
            // cada volta carimba "estou vivo".
            \App\Services\Ai\VideoResilience::batida(
                $pipeline->id,
                $state === 'retrying'
                    ? 'Tivemos um tropeco aqui. Ja estamos tentando em outro motor...'
                    : null,
                ['veo_stage' => $state]
            );

            if ($state === 'succeed') {
                $url = $status['data']['task_result']['videos'][0]['url'] ?? null;
                if (!$url) throw new \RuntimeException('kling_no_output_url');
                return $url;
            }
            if ($state === 'failed') {
                throw new \RuntimeException('kling_task_failed: ' . ($status['data']['task_status_msg'] ?? 'unknown'));
            }
        }
        throw new \RuntimeException('kling_timeout_after_' . (self::MAX_POLLS * self::POLL_INTERVAL) . 's');
    }

    private function pollImageTask(KlingService $kling, string $taskId, AiVideoPipeline $pipeline, string $type): string
    {
        for ($i = 0; $i < self::MAX_POLLS; $i++) {
            sleep(self::POLL_INTERVAL);
            $status = $kling->getImageTask($taskId, $type);
            $state  = $status['data']['task_status'] ?? 'processing';
            Log::debug('[SEL-360 Studio] polling image', ['task' => $taskId, 'type' => $type, 'status' => $state, 'attempt' => $i + 1]);
            if ($state === 'succeed') {
                $url = $status['data']['task_result']['images'][0]['url'] ?? null;
                if (!$url) throw new \RuntimeException('kling_no_output_image_url');
                return $url;
            }
            if ($state === 'failed') {
                throw new \RuntimeException('kling_image_task_failed: ' . ($status['data']['task_status_msg'] ?? 'unknown'));
            }
        }
        throw new \RuntimeException('kling_image_timeout');
    }


    /**
     * SEL-CASA-NO-PAGO — este pedido e da CASA (o Ruan ou um afiliado)?
     *
     * "Casa" nao e plano, e quem trabalha aqui: o dono e os afiliados que produzem
     * material pra vender. Eles saem do modelo gratis; cliente pagante NAO — a ordem do
     * Ruan de manter tudo no gratis vale pra base inteira, so a casa e excecao.
     */
    private function ehDaCasa(): bool
    {
        try {
            $userId = \Illuminate\Support\Facades\DB::table('ai_video_pipelines')
                ->where('id', $this->pipelineId)->value('user_id');
            if (! $userId) { return false; }

            $u = \Illuminate\Support\Facades\DB::table('users')->find($userId);
            if (! $u) { return false; }

            // role do dono e super_admin, nao admin — o teste devolveu
            // "cliente -> gratis" pra ele e eu peguei ANTES de subir.
            if (in_array($u->role ?? '', ['admin', 'super_admin'], true)) { return true; }

            // `affiliates` aponta por user_id, NAO por client_id — conferido no
            // antes-de-mexer antes de escrever (o erro oposto me custou caro hoje).
            // So afiliado APROVADO/ativo entra: candidatura pendente nao e casa.
            return \Illuminate\Support\Facades\DB::table('affiliates')
                ->where('user_id', $userId)
                ->where(function ($q) {
                    $q->where('approval_status', 'approved')->orWhere('status', 'active');
                })
                ->exists();
        } catch (\Throwable $e) {
            return false;   // na duvida, gratis — nunca gasta credito por engano
        }
    }

    private function pollVideoExtend(KlingService $kling, string $taskId, AiVideoPipeline $pipeline): string
    {
        for ($i = 0; $i < self::MAX_POLLS; $i++) {
            sleep(self::POLL_INTERVAL);
            $status = $kling->getVideoExtendTask($taskId);
            $state  = $status['data']['task_status'] ?? 'processing';
            Log::debug('[SEL-360 Studio] polling extend', ['task' => $taskId, 'status' => $state, 'attempt' => $i + 1]);
            if ($state === 'succeed') {
                $url = $status['data']['task_result']['videos'][0]['url'] ?? null;
                if (!$url) throw new \RuntimeException('kling_no_extend_output_url');
                return $url;
            }
            if ($state === 'failed') {
                throw new \RuntimeException('kling_extend_task_failed: ' . ($status['data']['task_status_msg'] ?? 'unknown'));
            }
        }
        throw new \RuntimeException('kling_extend_timeout');
    }

    // Helpers

    /**
     * SEL-MODELO-CHEGA (15/08) — qual motor este pedido merece, pelo PLANO.
     *
     * Regra do Ruan, textual: "liga pro ultra e ilimitado, mantem gratis no resto".
     * O gratis ("Lite [Lower Priority]") continua sendo o padrao de todo mundo — e
     * decisao dele, ja registrada. O que muda: plano que PAGA deixa de rodar na fila
     * onde o Google atende so quando sobra capacidade.
     *
     * Le o que o proprio pedido gravou (`veo_model`, posto pelo controller a partir do
     * quality_tier). Se nao houver, cai no default de sempre — nunca inventa modelo.
     */
    private function modeloDoPlano(): ?string
    {
        // le do PROPRIO pipeline. (Tentei usar uma propriedade da classe e ela nao
        // existe — teria virado codigo morto lendo vazio e devolvendo gratis sempre,
        // que e exatamente o tipo de furo que este commit conserta.)
        try {
            $bruto = \Illuminate\Support\Facades\DB::table('ai_video_pipelines')
                ->where('id', $this->pipelineId)->value('payloads');
            $p = is_array($bruto) ? $bruto : (json_decode((string) $bruto, true) ?: []);
        } catch (\Throwable $e) {
            return null;
        }
        // SEL-RESGATE-MANUAL (15/08): resgate de UM pedido especifico sem mentir sobre
        // o plano do cliente. Nasceu do 1192 (aemdcar), que ficou ~2h na fila gratis e
        // falhou: pra tirar ele de la eu precisaria marcar o cara como "ultra", o que
        // sujaria o dado do plano dele pra sempre. Com este campo o resgate mora no
        // PEDIDO e some junto com ele.
        // SEL-TUDO-NO-GRATIS (15/08): ordem do Ruan — "mantem tudo no gratis, ja te
        // falei que preferencia e gratis". Enquanto a chave estiver ligada, plano
        // nenhum escolhe modelo: todo mundo roda no [Lower Priority], 0 credito.
        // SEL-TESTE-PODE-FORCAR-GRATIS (16/08): escolha EXPLICITA no pedido vence a regra
        // automatica. Sem isto, todo teste feito pela conta do Ruan (super_admin = casa)
        // caia no modelo PAGO por causa do SEL-CASA-NO-PAGO, mesmo com o gratis marcado no
        // payload — medido: 200 creditos gastos num teste que era pra ser de graca.
        // Pedido normal de cliente NAO traz `veo_model`, entao a regra da casa segue
        // intacta pra producao.
        // 2a tentativa: a 1a lia $this->payload, que NAO EXISTE nesta classe (e do
        // KlingBrowserGenerateJob) — a checagem nunca rodava. Agora le o PEDIDO no banco.
        $__escolhido = null;
        try {
            $__bruto = \Illuminate\Support\Facades\DB::table('ai_video_pipelines')
                ->where('id', $this->pipelineId)->value('payloads');
            $__pl = is_array($__bruto) ? $__bruto : (json_decode((string) $__bruto, true) ?: []);
            $__escolhido = $__pl['veo_model'] ?? null;
        } catch (\Throwable $e) { /* na duvida, segue a regra normal */ }
        if (is_string($__escolhido) && $__escolhido !== '') {
            // ══ SEL-GRATIS-MANDA (16/08) — conserto de um furo que fui eu que abri ══
            //
            // Este bloco nasceu hoje as 07:12 pra eu conseguir FORCAR O GRATIS num teste.
            // Só que ele aceitava QUALQUER modelo vindo no pedido — e quem carimba esse
            // campo é o controller, PELO PLANO ('ultra'/'ilimitado' => Quality). Como ele
            // roda ANTES da regra `tudo_no_gratis`, passou a empurrar CLIENTE pro modelo
            // pago: o oposto da ordem do Ruan ("mantem tudo no gratis").
            //
            // Medido antes de consertar (16/08): 16 pedidos com o modelo do plano
            // "respeitado", TODOS de cliente (casa=0); 4 chegaram ao motor como Quality.
            //
            // Regra agora: com o gratis ligado, modelo explicito so vale se for GRATIS.
            // Forcar o gratis (o objetivo legitimo) continua funcionando; sair do gratis,
            // nao. O caminho pro pago segue sendo um so: SEL-CASA-NO-PAGO, logo abaixo.
            $__ehGratis = str_contains($__escolhido, 'Lite');

            if (! config('services.veo.tudo_no_gratis', true) || $__ehGratis) {
                Log::error('[SEL-TESTE-PODE-FORCAR-GRATIS] modelo veio explicito no pedido — respeitando', [
                    'pipeline_id' => $this->pipelineId, 'modelo' => $__escolhido,
                ]);

                return $__escolhido;
            }

            Log::error('[SEL-GRATIS-MANDA] pedido trazia modelo pago, mas o gratis esta ligado — ignorando o rotulo do plano', [
                'pipeline_id' => $this->pipelineId,
                'modelo_pedido' => $__escolhido,
                'porque' => 'ordem do Ruan: tudo no gratis; pago so pela regra da casa',
            ]);
        }

        if (config('services.veo.tudo_no_gratis', true)) {
            // SEL-CASA-NO-PAGO (16/08, ordem do Ruan): "meu e dos afiliados no pago".
            // O gratis e Lite [Lower Priority] — a lentidao e da fila do GOOGLE, entao
            // prioridade nossa nao adianta (o #1295 dele estava em video-ruan/high e
            // levou 15min mesmo assim). A CASA sai do gratis; CLIENTE continua no
            // gratis, como ele mandou. Nao encosta em plano de ninguem.
            if ($this->ehDaCasa()) {
                Log::error('[SEL-CASA-NO-PAGO] pedido da casa — vai no modelo pago', [
                    'pipeline_id' => $this->pipelineId,
                ]);

                return config('services.veo.modelo_pago', 'Veo 3.1 - Quality');
            }

            return null;
        }

        // SEL-SEM-CREDITO-CAI-PRO-GRATIS (15/08): se o Flow ja recusou este pedido por
        // falta de saldo, nao adianta pedir modelo pago de novo — devolve null e ele
        // roda no gratis, que entrega. Vem ANTES de tudo, inclusive do resgate manual.
        if (! empty($p['forcar_gratis'])) {
            Log::error('[SEL-SEM-CREDITO-CAI-PRO-GRATIS] pedido marcado sem saldo — indo no gratis', [
                'pipeline_id' => $this->pipelineId,
            ]);

            return null;
        }

        $manual = trim((string) ($p['resgate_manual'] ?? ''));
        if ($manual !== '') {
            Log::error('[SEL-RESGATE-MANUAL] pedido resgatado a mao pro modelo normal', [
                'pipeline_id' => $this->pipelineId, 'modelo' => $manual,
            ]);

            return $manual;
        }

        $tier = mb_strtolower((string) ($p['quality_tier'] ?? ''));

        if (! in_array($tier, ['ultra', 'ilimitado'], true)) {
            return null;   // gratis segue mandando; o adapter usa o default
        }

        $modelo = trim((string) ($p['veo_model'] ?? ''));
        if ($modelo === '') {
            return null;
        }

        Log::error('[SEL-MODELO-CHEGA] plano pagante — subindo do gratis pro modelo do plano', [
            'pipeline_id' => $this->pipelineId,
            'tier'        => $tier,
            'modelo'      => $modelo,
        ]);

        return $modelo;
    }

    private function runImageToVideo(KlingService $kling, ?string $imageUrl, ?string $prompt, string $model, string $mode, string $ratio, int $duration): string
    {
        $defaultPrompt = 'Video realista do produto com movimento sutil e natural, alta qualidade, sem texto, sem logotipo, 9:16 vertical, video vertical para redes sociais.';
        $res    = $kling->imageToVideo([
            'model_name'   => $model,
            'mode'         => $mode,
            'duration'     => (string) $duration,
            'aspect_ratio' => $ratio,
            'image'        => $imageUrl,
            'prompt'       => $prompt ?: $defaultPrompt,
            'cfg_scale'    => 0.5,
                    // SEL-SEM-SOM-PROPAGA: viaja junto ate o job do navegador.
            'sem_som'      => $this->pedidoMudo,

            // SEL-MODELO-CHEGA (15/08, Ruan: "liga pro ultra e ilimitado, mantem gratis
            // no resto"). MEDIDO: 77 pedidos tier=ultra gravavam veo_model="Veo 3.1 -
            // Quality" e TODO worker recebia "Lite [Lower Priority]" (o gratis) — porque
            // este array era montado do zero e nao carregava o campo. Quem pagava R$297
            // rodava na mesma fila gratis que deixou um cliente 2h30 esperando.
            // O pipeline_id vai junto: faltava pelo mesmo motivo e e o que permite
            // decidir por tentativa la na frente (resgate depois de N falhas).
            'veo_model'    => $this->modeloDoPlano(),
            'pipeline_id'  => $this->pipelineId,
        ]);
        $taskId = $res['data']['task_id'] ?? null;
        if (!$taskId) throw new \RuntimeException('kling_no_task_id: ' . json_encode($res));
        return $taskId;
    }

    /**
     * Moderacao anti-celebridade via Vision GPT-4o-mini.
     * Custo ~R$0,01/check — HubAI absorve (decisao Ruan SEL-360).
     */
    private function checkCelebrity(OpenAiService $openai, string $mediaUrl): void
    {
        try {
            $question = 'Este rosto pertence a alguma celebridade, figura publica, politico, atleta ou personagem famoso? Retorne JSON: {"is_celebrity": bool, "name": string|null, "confidence": 0-1}. Seja conservador: so retorne is_celebrity=true se tiver alta certeza.';
            $result   = $openai->analyzeImage($mediaUrl, $question);
            if (is_array($result)) {
                $isCeleb    = $result['is_celebrity'] ?? false;
                $confidence = (float) ($result['confidence'] ?? 0.0);
                $name       = $result['name'] ?? null;
                Log::info('[SEL-360 Studio] celebrity check', ['is_celebrity' => $isCeleb, 'confidence' => $confidence, 'name' => $name]);
                if ($isCeleb && $confidence > 0.6) {
                    throw new \RuntimeException('celebrity_detected:' . ($name ?? 'unknown'));
                }
            }
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'celebrity_detected')) throw $e;
            // Vision falhou por outro motivo: fail open, nao bloquear cliente
            Log::warning('[SEL-360 Studio] celebrity check failed, proceeding', ['err' => $e->getMessage()]);
        }
    }

    /** SEL-368: shots POV com @Element1 = produto real. Soma das duracoes = duracao total (regra kling-v3). */
    private function povShots(int $duration, ?string $context, bool $sceneMounted = false): array
    {
        $hands = 'a real human hand with natural skin texture, visible pores and fine veins';
        $light = 'soft diffused natural light';
        $real  = 'Ultra-realistic, DSLR 85mm, shallow DOF, no CGI, no plastic skin';
        $ctx   = $context ? mb_substr($context, 0, 120) . ', ' : '';
        $firstShot = $sceneMounted
            ? "first-person POV: {$hands} already holding @Element1 product, lifting it slowly toward the camera, {$ctx}smooth confident movement, {$light}, {$real}, no face, no avatar"
            : "first-person POV: {$hands} enters the frame, reaches in and PICKS UP @Element1 product, lifting it toward the camera, {$ctx}smooth confident movement, {$light}, {$real}, no face, no avatar";
        $shots = [
            $firstShot,
            "macro close-up POV, {$hands} rotating @Element1 product to demonstrate its key feature, fingers interacting with the product, extreme texture detail, {$light}, {$real}, no face",
            "first-person POV, {$hands} holding @Element1 product up, thumb pointing to lower-left corner, {$light}, {$real}, no face",
        ];
        if ($duration <= 6) {
            $half = intdiv($duration, 2);
            return [
                ['prompt' => mb_substr($shots[0], 0, 512), 'duration' => (string) ($duration - $half)],
                ['prompt' => mb_substr($shots[1], 0, 512), 'duration' => (string) $half],
            ];
        }
        $a = intdiv($duration, 3);
        $b = intdiv($duration - $a, 2);
        $c = $duration - $a - $b;
        return [
            ['prompt' => mb_substr($shots[0], 0, 512), 'duration' => (string) $a],
            ['prompt' => mb_substr($shots[1], 0, 512), 'duration' => (string) $b],
            ['prompt' => mb_substr($shots[2], 0, 512), 'duration' => (string) $c],
        ];
    }

    private function updateLabel(AiVideoPipeline $pipeline, array $payloads, string $label): void
    {
        $pipeline->update(['payloads' => array_merge($payloads, ['step_label' => $label])]);
    }

    private function finalize(AiVideoPipeline $pipeline, string $outputUrl): void
    {
        // SEL security 08/08 (Ruan): antes de gravar, garante que a URL final NUNCA
        // carregue nome de motor externo (veo/kling/flow/omni/dicloak/google) nem
        // metadado de marca. Alguns caminhos (Veo/Flow via pool, cena_com_referencias)
        // retornam a URL crua do worker sem passar pelo rename+brandstrip que o
        // KlingBrowserGenerateJob ja faz no caminho principal -- isto fecha essa brecha
        // pra QUALQUER pipeline que caia em finalize().
        $outputUrl = $this->sanitizeOutputUrl($outputUrl, $pipeline->id);

        // SEL-guarda-longo (12/08): pipeline LONGO nunca pode ser finalizado pelo
        // fluxo curto — foi assim que o cliente do #836 pediu 15s (2 cortes) e
        // recebeu 8s de 1 clipe, sem saber. Se chegou aqui um pedido longo, e bug
        // de roteamento: loga ALTO (pra achar a origem) e devolve pro job certo.
        $plsGuarda = is_array($pipeline->payloads) ? $pipeline->payloads : (json_decode((string) $pipeline->payloads, true) ?: []);
        $ehLongo = str_starts_with((string) $pipeline->mode, 'studio_long')
            || ! empty($plsGuarda['long_video'])
            || ($plsGuarda['pipeline'] ?? null) === 'video_longo';
        if ($ehLongo) {
            Log::error('[SEL-guarda-longo] fluxo CURTO tentou finalizar pedido LONGO — reencaminhado', [
                'pipeline_id' => $this->pipelineId,
                'mode'        => $pipeline->mode,
                'duration'    => $plsGuarda['duration'] ?? null,
                'n_segments'  => $plsGuarda['n_segments'] ?? null,
                'url_barrada' => $outputUrl,
            ]);
            $pipeline->update(['step' => 'render', 'error_message' => null]);
            \App\Jobs\StudioLongVideoJob::dispatch($this->pipelineId)->onQueue('video');
            return;
        }

        $pipeline->update(['step' => 'done', 'output_url' => $outputUrl]);
        Log::info('[SEL-360 Studio] done', ['pipeline_id' => $this->pipelineId, 'output_url' => $outputUrl]);

        // SEL-videoready (10/08, Ruan "cliente nao esta sendo avisado que o video
        // ficou pronto"): StudioGenerationJob era o UNICO job "done" sem push+email
        // (AiVideoPipelineJob e StudioLongVideoJob ja chamam isto desde 08/08). Fail-
        // open e idempotente por pipeline (Cache::add dentro do proprio Notifier) —
        // nunca derruba um video ja pronto, nunca duplica aviso se o job re-rodar.
        \App\Services\VideoReadyNotifier::notify($pipeline->fresh() ?? $pipeline);

        // SEL-414: cobra só agora, com o vídeo pronto. Não lança — se a cobrança
        // falhar, o cliente não pode perder um vídeo que já existe.
        \App\Services\Ai\VideoQuotaService::cobrarPosSucesso($pipeline->user_id, $this->pipelineId);

        // SEL-497: salva o rosto/apresentador desta geracao na conta do cliente
        // (nao-fatal; nunca derruba um video ja pronto).
        \App\Services\Ai\ClientAvatarHarvester::harvestFromPipeline($pipeline);

        // SEL-galeria (09/08): registra linha DURAVEL em ai_generations (a galeria
        // une ai_generations + ai_video_pipelines). Sobrevive a reset/retry do
        // pipeline -> o video NUNCA some da galeria. Idempotente por pipeline.
        $this->registrarNaGaleria($pipeline, $outputUrl);
    }

    private function registrarNaGaleria(AiVideoPipeline $pipeline, string $outputUrl): void
    {
        try {
            if ($outputUrl === '') { return; }

            // SEL-TESTE-NAO-VAI-PRA-GALERIA (15/08): pedido criado por MIM pra testar
            // nao pode aparecer na galeria de cliente. Descobri tarde: o teste da calca
            // (#1250) foi parar na galeria do dono do pedido original, que nao pediu
            // nada. Video de teste e meu; cliente ve o que ELE mandou gerar.
            $pl = $pipeline->payloads ?? [];
            if (! empty($pl['teste_visao']) || ! empty($pl['_teste_interno'])) {
                Log::error('[SEL-TESTE-NAO-VAI-PRA-GALERIA] pedido de teste — fora da galeria do cliente', [
                    'pipeline_id' => $pipeline->id,
                ]);

                return;
            }

            $taskId = 'studio-pipe-' . $pipeline->id;
            if (AiGeneration::where('provider_task_id', $taskId)->exists()) {
                AiGeneration::where('provider_task_id', $taskId)
                    ->update(['output_url' => $outputUrl, 'status' => 'succeeded']);
                return;
            }
            $p = $pipeline->payloads ?? [];
            AiGeneration::create([
                'tenant_id'        => null,
                'user_id'          => $pipeline->user_id,
                'service'          => 'video',
                'provider'         => 'Seller Global Engine',
                'provider_model'   => $p['quality_tier'] ?? 'padrao',
                'provider_task_id' => $taskId,
                'wizard_payload'   => [
                    'product_name' => $p['product_name'] ?? ($p['produto_nome'] ?? null),
                    'price'        => $p['price'] ?? ($p['produto_preco'] ?? null),
                    'style'        => $p['estilo'] ?? null,
                    'duration'     => $p['duration'] ?? null,
                    '_source'      => 'studio_options',
                    '_pipeline_id' => $pipeline->id,
                ],
                'final_prompt'     => mb_substr((string) ($p['prompt'] ?? ''), 0, 2000) ?: null,
                'status'           => 'succeeded',
                'output_url'       => $outputUrl,
                'credits_debited'  => 0,
                'cost_usd'         => 0,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[SEL-galeria] falha ao registrar galeria duravel', ['pid' => $pipeline->id, 'err' => $e->getMessage()]);
        }
    }

    /**
     * SEL security 08/08 -- se a URL final aponta pro nosso storage mas ainda carrega
     * nome cru de motor externo (ex.: veo_out_kbrowser_*, kling_*), ou se e uma URL
     * de CDN externa (klingai.com etc), baixa/renomeia/re-remuxa (strip de TODO
     * metadado) pra convencao seller-global-<hash>.mp4 ANTES de persistir. Fail-open:
     * se algo falhar aqui, devolve a URL original (nunca derruba um video ja pronto).
     */
    private function sanitizeOutputUrl(string $url, int $pipelineId): string
    {
        try {
            $appUrl = rtrim(env('APP_URL', 'https://api.seller.global'), '/');
            $leaky  = (bool) preg_match('/veo|kling|flow|omni|dicloak|google/i', $url);
            if (!$leaky) {
                return $url;
            }
            if (str_contains($url, 'seller-global-') || str_contains($url, 'sellerglobal-video')) {
                return $url; // ja passou por rename antes, so o texto do path bateu por coincidencia
            }

            $videosDir = storage_path('app/public/videos');
            $newFilename = 'seller-global-' . substr(hash('sha256', $url . $pipelineId . 'sel-finalize-guard'), 0, 14) . '.mp4';
            $destPath = $videosDir . '/' . $newFilename;

            $srcPath = null;
            $tmpDownload = null;
            if (str_starts_with($url, $appUrl)) {
                $path = parse_url($url, PHP_URL_PATH);
                $filename = $path ? basename($path) : null;
                if ($filename) {
                    $candidate = $videosDir . '/' . $filename;
                    if (is_file($candidate)) $srcPath = $candidate;
                }
            } else {
                $tmpDownload = sys_get_temp_dir() . '/sel_finalize_guard_' . $pipelineId . '_' . uniqid() . '.mp4';
                $resp = \Illuminate\Support\Facades\Http::timeout(60)->get($url);
                if ($resp->successful() && strlen($resp->body()) > 10000) {
                    file_put_contents($tmpDownload, $resp->body());
                    $srcPath = $tmpDownload;
                }
            }

            if (!$srcPath || !is_file($srcPath)) {
                return $url; // nao conseguiu localizar/baixar -- fail-open, mantem original
            }

            $cmd = 'ffmpeg -y -i ' . escapeshellarg($srcPath)
                . ' -map 0 -c copy -map_metadata -1 -bitexact -f mp4 -movflags +faststart '
                . escapeshellarg($destPath) . ' 2>&1';
            @shell_exec($cmd);

            if (!is_file($destPath) || filesize($destPath) < 10000) {
                if ($tmpDownload) @unlink($tmpDownload);
                return $url; // strip falhou -- fail-open
            }

            if ($tmpDownload) {
                @unlink($tmpDownload);
            } elseif ($srcPath !== $destPath) {
                @unlink($srcPath);
            }

            Log::info('[SEL security 08/08] output_url saneado antes de persistir', [
                'pipeline_id' => $pipelineId, 'old' => $url, 'new' => $appUrl . '/storage/videos/' . $newFilename,
            ]);

            return $appUrl . '/storage/videos/' . $newFilename;
        } catch (\Throwable $e) {
            Log::warning('[SEL security 08/08] sanitizeOutputUrl falhou, mantendo URL original', ['err' => $e->getMessage()]);
            return $url;
        }
    }
}
