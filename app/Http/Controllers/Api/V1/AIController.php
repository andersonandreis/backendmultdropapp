<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateAiImageJob;
use App\Models\AiGeneration;
use App\Services\Ai\ElevenLabsService;
use App\Services\Ai\KlingService;
use App\Services\Ai\OpenAiService;
use App\Services\Ai\PromptBuilderService;
use App\Services\Ai\PromptMasterService;
use App\Services\Ai\SeedanceCatalog;
use App\Services\Ai\SeedanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SEL-040: hub central de geracao IA — 15 ferramentas mascaradas.
 * Providers plugaveis: Seedance (video padrao), Kling (Try-On/Lip Sync),
 * ElevenLabs (audio), OpenAI (roteiro/imagem).
 */
class AIController extends Controller
{
    public function __construct(
        private SeedanceService $seedance,
        private KlingService $kling,
        private ElevenLabsService $elevenlabs,
        private OpenAiService $openai,
        private PromptBuilderService $builder,
        private PromptMasterService $promptMaster,
    ) {}

    /** SEL-053: features de IA liberadas pro user autenticado; null = sem restricao. */
    private function allowedAiFeatures(): ?array
    {
        $user = auth()->user();
        if (!$user) {
            return [];
        }
        if (in_array($user->role ?? '', ['admin', 'super_admin'], true)) {
            return null;
        }

        $client = \App\Models\Client::where('user_id', $user->id)->first();
        if (!$client) {
            return null; // suppliers/perfis internos: sem restricao
        }

        $sub = \App\Models\Subscription::where('client_id', $client->id)
            ->whereIn('status', ['active', 'trialing'])
            ->orderByDesc('id')
            ->first();
        if (!$sub || !$sub->plan_id) {
            return null;
        }

        $plan = \App\Models\Plan::find($sub->plan_id);
        if (!$plan || $plan->ai_features === null) {
            return null; // plano sem configuracao = sem restricao (compat)
        }

        $raw = $plan->ai_features;
        $allowed = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
        $extra = json_decode((string) ($user->ai_features_extra ?? ''), true) ?: [];
        $blocked = json_decode((string) ($user->ai_features_blocked ?? ''), true) ?: [];

        return array_values(array_diff(array_unique(array_merge($allowed, $extra)), $blocked));
    }

    /** Bloqueia 403 se NENHUMA das features estiver liberada no plano. */
    private function gateAi(string ...$features): void
    {
        $allowed = $this->allowedAiFeatures();
        if ($allowed === null) {
            return;
        }
        foreach ($features as $f) {
            if (in_array($f, $allowed, true)) {
                return;
            }
        }
        throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
            'error'             => 'feature_not_in_plan',
            'message'           => 'Este recurso de IA nao esta incluido no seu plano. Faca upgrade para liberar.',
            'features_required' => array_values($features),
        ], 403));
    }

    public function status()
    {
        return response()->json([
            'features_allowed' => $this->allowedAiFeatures(),
            'video_seedance'   => $this->seedance->isConfigured(),
            'video_kling'      => $this->kling->isConfigured(),
            'audio_elevenlabs' => $this->elevenlabs->isConfigured(),
            'openai'           => $this->openai->isConfigured(),
        ]);
    }

    public function voices()
    {
        return response()->json([
            'voices' => $this->elevenlabs->defaultVoicesCatalog(),
            'openai_voices' => [
                ['id' => 'nova',    'label' => 'Voz F3 - Feminina neutra',    'lang' => 'pt-BR'],
                ['id' => 'onyx',    'label' => 'Voz M3 - Masculina profunda', 'lang' => 'pt-BR'],
                ['id' => 'shimmer', 'label' => 'Voz F4 - Feminina suave',     'lang' => 'pt-BR'],
            ],
        ]);
    }

    public function promptPreview(Request $r)
    {
        $w = $r->validate([
            'style'               => 'nullable|string',
            'camera_move'         => 'nullable|string',
            'overlays'            => 'nullable|array',
            'overlays.*'          => 'string',
            'aspect_ratio'        => 'nullable|in:9:16,16:9,1:1,4:3,3:4',
            'duration'            => 'nullable|integer|between:4,15',
            'product_name'        => 'nullable|string|max:200',
            'product_description' => 'nullable|string|max:1000',
            'hook'                => 'nullable|string|max:400',
            'custom_prompt'       => 'nullable|string|max:2000',
            'negative_prompt'     => 'nullable|string|max:400',
        ]);
        return response()->json($this->builder->build($w));
    }

    public function script(Request $r)
    {
        $this->gateAi('script');
        $v = $r->validate([
            'prompt'       => 'required|string|max:4000',
            'product_name' => 'nullable|string|max:200',
            'style'        => 'nullable|in:unboxing,antes_depois,social_proof,urgencia,depoimento,comparacao,promocao,tutorial,custom',
            'max_tokens'   => 'nullable|integer|between:100,4096',
        ]);
        // SEL-220: injecao silenciosa do master de roteiro — cliente nao ve
        $enhancedScriptPrompt = $this->promptMaster->enhance($v['prompt'], 'script');
        try {
            $out = $this->openai->generateScript(
                $enhancedScriptPrompt,
                $v['product_name'] ?? null,
                $v['style'] ?? 'custom',
                null,
                $v['max_tokens'] ?? 1024
            );
        } catch (Throwable $e) { return $this->handleProviderError($e, 'script'); }
        $gen = $this->log($r, 'script', 'openai', $out['model'] ?? null, null, null, 'succeeded', $enhancedScriptPrompt, ['usage' => $out['usage'] ?? null]);
        return response()->json(['id' => $gen->id, 'script' => $out['script']]);
    }

    public function analyzeImage(Request $r)
    {
        $this->gateAi('analyze_image');
        $v = $r->validate(['image_url' => 'required|url', 'question' => 'nullable|string|max:500']);
        try { $out = $this->openai->analyzeImage($v['image_url'], $v['question'] ?? null); }
        catch (Throwable $e) { return $this->handleProviderError($e, 'analyze'); }
        $gen = $this->log($r, 'analyze', 'openai', $out['model'] ?? null, null, null, 'succeeded', $out['analysis'] ?? '');
        return response()->json(['id' => $gen->id, 'analysis' => $out['analysis']]);
    }

    public function tts(Request $r)
    {
        $this->gateAi('tts_openai', 'tts_elevenlabs');
        $v = $r->validate([
            'text'     => 'required|string|max:5000',
            'voice_id' => 'nullable|string|max:80',
            'engine'   => 'nullable|in:default,openai',
            'speed'    => 'nullable|numeric|between:0.25,4.0',
        ]);
        try {
            if (($v['engine'] ?? 'default') === 'openai') {
                $out = $this->openai->tts($v['text'], $v['voice_id'] ?? 'nova', null, $v['speed'] ?? 1.0);
                $provider = 'openai';
            } else {
                $out = $this->elevenlabs->tts($v['text'], $v['voice_id'] ?? null);
                $provider = 'elevenlabs';
            }
        } catch (Throwable $e) { return $this->handleProviderError($e, 'tts'); }
        $gen = $this->log($r, 'tts', $provider, null, null, null, 'succeeded', null, ['format' => $out['format'] ?? 'mp3']);
        return response()->json(['id' => $gen->id, 'audio_base64' => $out['audio_base64'], 'format' => $out['format']]);
    }

    public function soundEffects(Request $r)
    {
        $this->gateAi('sound_effects');
        $v = $r->validate(['text' => 'required|string|max:500', 'duration_seconds' => 'nullable|numeric|between:0.5,22']);
        try { $out = $this->elevenlabs->soundEffects($v['text'], $v['duration_seconds'] ?? null); }
        catch (Throwable $e) { return $this->handleProviderError($e, 'sound_effects'); }
        $gen = $this->log($r, 'sound_effects', 'elevenlabs', null, null, null, 'succeeded');
        return response()->json(['id' => $gen->id, 'audio_base64' => $out['audio_base64'], 'format' => $out['format']]);
    }

    public function dubbing(Request $r)
    {
        $this->gateAi('dubbing');
        $v = $r->validate([
            'source_url'   => 'required|url',
            'target_lang'  => 'required|string|max:8',
            'source_lang'  => 'nullable|string|max:8',
            'num_speakers' => 'nullable|integer|between:0,50',
        ]);
        try { $out = $this->elevenlabs->createDubbing($v['source_url'], $v['target_lang'], $v['source_lang'] ?? null, $v['num_speakers'] ?? 0); }
        catch (Throwable $e) { return $this->handleProviderError($e, 'dubbing'); }
        $taskId = $out['dubbing_id'] ?? ($out['id'] ?? null);
        $gen = $this->log($r, 'dubbing', 'elevenlabs', null, $taskId, null, 'processing');
        return response()->json(['id' => $gen->id, 'task_id' => $taskId]);
    }

    public function image(Request $r)
    {
        $this->gateAi('image');
        $v = $r->validate([
            'prompt'  => 'required|string|max:4000',
            'size'    => 'nullable|in:1024x1024,1024x1792,1792x1024',
            'quality' => 'nullable|in:standard,hd',
            'style'   => 'nullable|in:vivid,natural',
            // SEL-220: hint do frontend pra selecionar o master correto
            'mode'    => 'nullable|in:avatar_human,product_image',
        ]);
        // SEL-220: injecao silenciosa do prompt master — cliente nao ve
        $mode = $v['mode'] ?? 'avatar_human';
        $enhancedPrompt = $this->promptMaster->enhance($v['prompt'], $mode);
        try { $out = $this->openai->generateImage($enhancedPrompt, $v['size'] ?? '1024x1024', $v['quality'] ?? 'standard', $v['style'] ?? 'natural'); }
        catch (Throwable $e) { return $this->handleProviderError($e, 'image'); }
        $gen = $this->log($r, 'image', 'openai', 'dall-e-3', null, $out['url'], 'succeeded', $enhancedPrompt);
        // Retorna somente url — prompt original do cliente, nao o enhanced
        return response()->json(['id' => $gen->id, 'url' => $out['url'], 'revised_prompt' => $out['revised_prompt']]);
    }

    /**
     * SEL-303: POST /api/v1/ai/image/generate (nova rota async).
     * Retorna 202 imediatamente com task_id. DALL-E roda em background job.
     * Frontend faz polling em GET /api/v1/ai/image/tasks/{id}.
     */
    public function imageAsync(Request $r)
    {
        $this->gateAi('image');
        $v = $r->validate([
            'prompt'  => 'required|string|max:4000',
            'size'    => 'nullable|in:1024x1024,1024x1792,1792x1024',
            'quality' => 'nullable|in:standard,hd',
            'style'   => 'nullable|in:vivid,natural',
            'mode'    => 'nullable|in:avatar_human,product_image',
        ]);

        $mode    = $v['mode']    ?? 'avatar_human';
        $size    = $v['size']    ?? '1024x1024';
        $quality = $v['quality'] ?? 'standard';
        $style   = $v['style']   ?? 'natural';
        $prompt  = $v['prompt'];

        // Cria row em ai_generations com status=queued antes de dispatchar
        $gen = $this->log($r, 'image', 'openai', 'dall-e-3', null, null, 'queued', $prompt);

        GenerateAiImageJob::dispatch(
            $gen->id,
            $prompt,
            $size,
            $quality,
            $style,
            $mode,
        )->onQueue('default');

        return response()->json([
            'task_id' => $gen->id,
            'status'  => 'queued',
        ], 202);
    }

    /**
     * SEL-303: GET /api/v1/ai/image/tasks/{id} — polling de status.
     * Retorna status, url (quando done) e error (quando failed).
     */
    public function imageTask(Request $r, string $id)
    {
        $gen = AiGeneration::query()
            ->where('id', $id)
            ->where('user_id', $r->user()?->id)
            ->where('service', 'image')
            ->first();

        if (!$gen) {
            return response()->json(['error' => 'task_not_found'], 404);
        }

        // ── SEL-MSGLIMPA (12/08) — o cliente nunca le a nossa cozinha.
        //
        // Nas ultimas 24h estas mensagens sairam pra quem pediu video:
        //     12x "teste de carga cancelado — dando a vez pros clientes"   (recado MEU)
        //      1x "sel30s: clip1_falhou_apos_retries: mover_clip_falhou1"  (entranha)
        //      1x "Erro na geracao. Tente novamente ou contate o suporte."
        // A primeira e um bilhete interno que vazou; a segunda e codigo. Nenhuma das
        // duas ajuda quem esta do outro lado. Agora so passa mensagem escrita PRA
        // pessoa — o texto tecnico continua no banco, pro suporte e pra auditoria.
        $erroCru = (string) $gen->error_message;
        $erro    = null;
        if ($erroCru !== '') {
            $amigavel = preg_match('/^[A-ZÀ-Ú]/u', $erroCru)
                && ! preg_match('/(sel30s|_falhou|pipeline|clip\d|retries|timeout|exception|null|Array|::)/i', $erroCru)
                && ! str_contains(mb_strtolower($erroCru), 'teste de carga');
            $erro = $amigavel
                ? $erroCru
                : 'Não conseguimos finalizar esse vídeo. Pode gerar de novo — você não foi cobrado por essa tentativa.';
        }

        return response()->json([
            'task_id' => $gen->id,
            'status'  => $gen->status,          // queued | processing | succeeded | failed
            'url'     => $gen->output_url,
            'error'   => $erro,
        ]);
    }

    public function videoGenerate(Request $r)
    {
        // SEL-329: circuit breaker global de geracao — flag no .env / admin panel
        if (! config('services.ai_video.generation_enabled', true)) {
            return response()->json([
                'error'   => 'video_generation_disabled',
                'message' => 'Geração de vídeo temporariamente indisponível. Nossa equipe está atualizando a plataforma. Voltamos em breve.',
            ], 503);
        }

        // SEL-414: esta rota chega no Kling (KLING_MODE=browser injeta o
        // KlingBrowserService, que gasta crédito da conta do Ruan) e até agora
        // passava só pelo VideoBillingGate — que está DESLIGADO
        // (settings.video_billing.billing_enabled = 0), ou seja, liberava todo
        // mundo. Auditoria 30/07: era o único caminho de geração sem o
        // VideoAccessGuard. Não houve abuso por aqui ainda; fechando antes que haja.
        $acesso = \App\Services\Ai\VideoAccessGuard::pode($r->user()?->id);
        if (! $acesso['ok']) {
            \Illuminate\Support\Facades\Log::warning('[SEL-414] /ai/video/generate bloqueado', [
                'user_id' => $r->user()?->id, 'motivo' => $acesso['motivo'],
            ]);
            return response()->json([
                'error'   => 'video_bloqueado',
                'motivo'  => $acesso['motivo'],
                'message' => $acesso['mensagem'],
            ], 403);
        }

        $this->gateAi('video_seedance', 'animar_foto', 'video_do_zero', 'efeitos_virais', 'trocar_pessoa');
        $v = $r->validate([
            'provider'     => 'nullable|in:seedance,kling',
            'image_url'    => 'nullable|url',
            'wizard'       => 'nullable|array',
            'prompt'       => 'nullable|string|max:2500',
            'duration'     => 'nullable|integer|between:10,15', // SEL-329: mín 10s (5s não cabe pitch de venda)
            'aspect_ratio' => 'nullable|in:9:16,16:9,1:1,4:3,3:4',
            'resolution'   => 'nullable|in:480p,720p,1080p',
            'model'        => 'nullable|string|in:' . implode(',', SeedanceCatalog::ids()), // SEL-073 whitelist
            'kling_model'  => 'nullable|in:kling-v1,kling-v1-5,kling-v1-6,kling-v2-1,kling-v2-master,kling-v3',
            'kling_mode'   => 'nullable|in:std,pro',
            'engine_id'    => 'nullable|in:sg_fast,sg_balanced,sg_master,sg_realism',
        ]);
        // SEL-121: default provider agora e kling (Bearer nova API), Seedance vira fallback opcional.
        $provider = $v['provider'] ?? 'kling';
        // SEL-321 white-label: front manda engine_id sg_* neutro; mapa de modelo fica so no backend
        $engineMap = [
            'sg_fast'     => ['kling-v1-6', 'std'],
            'sg_balanced' => ['kling-v1-6', 'pro'],
            'sg_master'   => ['kling-v2-master', 'pro'],
            'sg_realism'  => ['kling-v3', 'std'],
        ];
        if (!empty($v['engine_id']) && isset($engineMap[$v['engine_id']])) {
            [$v['kling_model'], $v['kling_mode']] = $engineMap[$v['engine_id']];
            $provider = 'kling';
        }
        $built = !empty($v['wizard']) ? $this->builder->build($v['wizard']) : null;
        $rawPrompt = $v['prompt'] ?? ($built['prompt'] ?? null);
        // SEL-220: injecao silenciosa do master de video — cliente nao ve
        $prompt = $rawPrompt ? $this->promptMaster->enhance($rawPrompt, 'video_kling') : null;
        $aspect = $v['aspect_ratio'] ?? ($built['ratio'] ?? '9:16');
        $duration = max(10, (int) ($v['duration'] ?? $built['duration'] ?? 10)); // SEL-329: clamp mín 10s
        $negative = $built['negative_prompt'] ?? null;

        // SEL-329: gate anti-prejuízo — engine_id vira quality
        $engineToQuality = ['sg_fast' => 'fast', 'sg_balanced' => 'balanced', 'sg_master' => 'master', 'sg_realism' => 'master'];
        $quality = $engineToQuality[$v['engine_id'] ?? ''] ?? 'balanced';
        $gate = \App\Services\Ai\VideoBillingGate::check($r->user(), $quality, (int) $duration);
        if (! ($gate['allowed'] ?? false)) {
            return response()->json([
                'error'   => $gate['reason'] ?? 'blocked',
                'message' => $gate['message'] ?? 'Geração não autorizada.',
                'used_this_month_brl' => $gate['used_this_month_brl'] ?? null,
                'cap_brl'             => $gate['cap_brl'] ?? null,
                'credits_needed'      => $gate['credits_needed'] ?? null,
                'wallet_balance'      => $gate['wallet_balance'] ?? null,
            ], $gate['http'] ?? 403);
        }

        try {
            if ($provider === 'kling') {
                if (!$this->kling->isConfigured()) return response()->json(['error' => 'video_provider_not_configured', 'provider' => 'padrão'], 503);
                // SEL-313: sem imagem -> text2video (image2video com image vazia devolve 400 do Kling)
                $klingPayload = [
                    'prompt' => $prompt,
                    'negative_prompt' => $negative,
                    'model_name' => $v['kling_model'] ?? 'kling-v1-6',
                    'mode' => $v['kling_mode'] ?? 'std',
                    'duration' => $duration,
                    'aspect_ratio' => $aspect,
                ];
                $out = !empty($v['image_url'])
                    ? $this->kling->imageToVideo($klingPayload + ['image' => $v['image_url']])
                    : $this->kling->textToVideo($klingPayload);
                $taskId = $out['data']['task_id'] ?? ($out['task_id'] ?? null);
                $providerModel = 'padrão';
            } else {
                if (!$this->seedance->isConfigured()) return response()->json(['error' => 'video_provider_not_configured', 'provider' => 'padrão'], 503);
                $content = [];
                if ($prompt) $content[] = ['type' => 'text', 'text' => $prompt];
                if (!empty($v['image_url'])) $content[] = ['type' => 'image_url', 'image_url' => ['url' => $v['image_url']], 'role' => 'first_frame'];
                $resolution = $v['resolution'] ?? '720p';
                $out = $this->seedance->createTask($content, ['ratio' => $aspect, 'duration' => $duration, 'resolution' => $resolution, 'model' => $v['model'] ?? null]);
                $taskId = $out['id'] ?? null;
                $providerModel = $v['model'] ?? $this->seedance->providerModel();
            }
        } catch (Throwable $e) { return $this->handleProviderError($e, 'video'); }

        // SEL-073: options entram no wizard_payload pro custo real ser calculado no polling
        $gen = $this->log($r, 'video', $provider, $providerModel, $taskId, null, 'processing', $prompt, [
            'wizard' => $v['wizard'] ?? null,
            'options' => ['model' => $providerModel, 'resolution' => $v['resolution'] ?? '720p', 'duration' => $duration],
        ]);
        return response()->json(['id' => $gen->id, 'task_id' => $taskId, 'status' => 'processing']);
    }

    public function videoTask(Request $r, string $id)
    {
        $gen = AiGeneration::query()->find($id);
        $providerTaskId = $gen?->provider_task_id ?? $id;
        $provider = $gen?->provider ?? 'seedance';
        try {
            $out = $provider === 'kling' ? $this->kling->getVideoTask($providerTaskId) : $this->seedance->getTask($providerTaskId);
        } catch (Throwable $e) { return $this->handleProviderError($e, 'video_status'); }
        $status = $out['status'] ?? ($out['data']['task_status'] ?? 'processing');
        $videoUrl = $out['content']['video_url'] ?? ($out['data']['task_result']['videos'][0]['url'] ?? ($out['data']['videos'][0]['url'] ?? null));
        if ($gen && $videoUrl) {
            $update = ['status' => 'succeeded', 'output_url' => $videoUrl, 'expires_at' => now()->addHours(24)];
            // SEL-073: 1a vez que confirma sucesso -> custo real + debito de creditos (inerte com billing off)
            if ($gen->status !== 'succeeded' && $provider === 'seedance') {
                $tokens = (int) ($out['usage']['completion_tokens'] ?? 0);
                $opts = $gen->wizard_payload['_options'] ?? [];
                if ($tokens > 0) {
                    $update['usage_tokens'] = $tokens;
                    $update['cost_usd'] = SeedanceCatalog::costFromTokens(
                        $gen->provider_model, $tokens, $opts['resolution'] ?? '720p'
                    );
                }
                $debited = $this->maybeDebitVideoCredits($gen);
                if ($debited > 0) { $update['credits_debited'] = $debited; }
            }
            $gen->update($update);
        } elseif ($gen && in_array($status, ['failed', 'expired', 'cancelled'])) {
            $gen->update(['status' => $status]);
        }
        return response()->json(['id' => $gen?->id, 'task_id' => $providerTaskId, 'status' => $status, 'video_url' => $videoUrl]);
    }

    public function virtualTryOn(Request $r)
    {
        $this->gateAi('virtual_try_on');
        $v = $r->validate(['human_image' => 'required|url', 'cloth_image' => 'required|url']);
        if (!$this->kling->isConfigured()) return response()->json(['error' => 'video_provider_not_configured'], 503);
        try { $out = $this->kling->virtualTryOn($v); }
        catch (Throwable $e) { return $this->handleProviderError($e, 'try_on'); }
        $gen = $this->log($r, 'virtual_try_on', 'kling', null, $out['data']['task_id'] ?? null, null, 'processing');
        return response()->json(['id' => $gen->id, 'task_id' => $out['data']['task_id'] ?? null]);
    }

    /**
     * SEL-485 — POST /api/v1/ai/motion-control — "copiar movimento".
     *
     * Sobe um vídeo com o movimento e uma foto da pessoa; sai a pessoa da foto
     * fazendo aquele movimento.
     *
     * O `method_exists` não é paranoia: `KlingService::class` é resolvido pelo
     * container e pode cair no `KlingService` (API oficial) quando `KLING_MODE`
     * não for `browser` — e lá este método não existe. Sem a checagem, o cliente
     * levaria um erro 500 sem explicação; com ela, leva um 503 que diz o que
     * está acontecendo. Mesmo espírito do `suportaLipSync()`.
     *
     * As validações de proporção (9:16) e duração (>= 10s) NÃO cabem aqui: elas
     * dependem de abrir o arquivo. Quem faz é o worker, com ffprobe, e a recusa
     * volta como falha legível da tarefa.
     */
    public function motionControl(Request $r)
    {
        $this->gateAi('motion_control');
        $v = $r->validate([
            'video' => 'required|url',
            'image' => 'required|url',
        ]);
        if (!$this->kling->isConfigured()) return response()->json(['error' => 'video_provider_not_configured'], 503);
        if (!method_exists($this->kling, 'motionControl')) {
            return response()->json([
                'error'   => 'motion_control_indisponivel',
                'message' => 'Copiar movimento so funciona no modo navegador. Fale com o suporte.',
            ], 503);
        }
        try { $out = $this->kling->motionControl($v); }
        catch (Throwable $e) { return $this->handleProviderError($e, 'motion_control'); }
        $gen = $this->log($r, 'motion_control', 'kling', null, $out['data']['task_id'] ?? null, null, 'processing');
        return response()->json(['id' => $gen->id, 'task_id' => $out['data']['task_id'] ?? null]);
    }

    public function lipSync(Request $r)
    {
        $this->gateAi('lip_sync');
        $v = $r->validate([
            'video_url' => 'required|url',
            'mode'      => 'nullable|in:audio2video,text2video',
            'audio_url' => 'nullable|url',
            'text'      => 'nullable|string|max:500',
            'voice_id'  => 'nullable|string',
        ]);
        if (!$this->kling->isConfigured()) return response()->json(['error' => 'video_provider_not_configured'], 503);
        try { $out = $this->kling->lipSync($v); }
        catch (Throwable $e) { return $this->handleProviderError($e, 'lip_sync'); }
        $gen = $this->log($r, 'lip_sync', 'kling', null, $out['data']['task_id'] ?? null, null, 'processing');
        return response()->json(['id' => $gen->id, 'task_id' => $out['data']['task_id'] ?? null]);
    }

    /** POST /api/v1/ai/upload — arquivo do usuario vira URL publica (insumo de video/try-on/lip-sync). */
    public function upload(Request $r)
    {
        $r->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,webp,gif,mp4,mov,webm,mp3,wav,m4a,ogg|max:102400',
        ]);
        $path = $r->file('file')->store('ai-uploads/' . $r->user()->id, 'public');
        return response()->json([
            'url' => \Illuminate\Support\Facades\Storage::disk('public')->url($path),
            'path' => $path,
        ]);
    }

    public function history(Request $r)
    {
        $perPage = min((int) $r->query('per_page', 20), 100);
        $userId  = $r->user()?->id;
        $service = $r->query('service');

        // SEL-GALERIA-ADM (12/08, Ruan: "cade meus videos na minha galeria em adm").
        // A galeria filtrava SEMPRE por user_id. O Ruan tem DUAS contas admin
        // (ruanipanema14 = 592, dona de todas as geracoes; ruanipanema2 = 597, com
        // zero). Logado na 597 a galeria aparecia vazia -- estava "certa" e inutil.
        // super_admin agora ve TUDO, que e o que ele pediu ("tudo na minha tela em
        // ADM, sem precisar acessar plataforma nenhuma"). Cliente comum: sem mudanca.
        $ehAdmin = in_array((string) ($r->user()->role ?? ''), ['super_admin', 'admin'], true);

        // SEL-galeria-excluir (09/08): o que o proprio dono ocultou (DELETE
        // /v1/studio/gallery/{id}) nao pode voltar a aparecer na listagem.
        // Ver StudioGalleryController::idsOcultos.
        $ocultos     = StudioGalleryController::idsOcultos((int) $userId);
        $ocultosGen  = array_map(fn ($x) => (int) substr($x, 4), array_filter($ocultos, fn ($x) => str_starts_with($x, 'gen-')));
        $ocultosPipe = array_map(fn ($x) => (int) substr($x, 5), array_filter($ocultos, fn ($x) => str_starts_with($x, 'pipe-')));

        // SEL-435 (30/07, Ruan ao vivo: "falou que gerou e cade o video na
        // galeria"). A galeria lia SO ai_generations; o Studio grava o video em
        // ai_video_pipelines. Duas tabelas diferentes -- o video existia no
        // disco, com output_url, e nunca aparecia. O feed do admin ja unia as
        // duas; a galeria do cliente nao. Agora une tambem.
        $gen = DB::table('ai_generations')
            ->when(! $ehAdmin, fn ($q) => $q->where('user_id', $userId))
            ->when($service, fn ($q, $s) => $q->where('service', $s))
            ->when($ocultosGen, fn ($q) => $q->whereNotIn('id', $ocultosGen))
            ->select([
                DB::raw("CONCAT('gen-', id) as id"),
                'service', 'provider', 'status', 'output_url', 'wizard_payload',
                'credits_debited', 'cost_usd', 'created_at',
                DB::raw("'ai_generation' as source"),
                // SEL-435b: desempate. created_at e TIMESTAMP (precisao de 1s) e
                // existem grupos empatados em producao (5 linhas no mesmo segundo).
                // Sem criterio estavel, o corte de pagina cai no meio do empate e
                // o MySQL pode repetir um item numa pagina e sumir com ele na outra.
                DB::raw("id as ord_id"),
                DB::raw("1 as ord_fonte"),
            ]);

        $pipe = DB::table('ai_video_pipelines')
            ->when(! $ehAdmin, fn ($q) => $q->where('user_id', $userId))
            ->whereNotNull('output_url')
            ->where('output_url', '!=', '')
            // SEL-galeria (09/08): dedupe -- se ja existe linha DURAVEL em ai_generations
            // pra este pipeline (gen-*), nao lista o pipe-* tambem (senao card duplicado).
            ->whereRaw("NOT EXISTS (SELECT 1 FROM ai_generations g WHERE g.provider_task_id = CONCAT('studio-pipe-', ai_video_pipelines.id))")
            ->when($service, fn ($q, $s) => $q->where(DB::raw("'video'"), $s))
            ->when($ocultosPipe, fn ($q) => $q->whereNotIn('id', $ocultosPipe))
            ->select([
                DB::raw("CONCAT('pipe-', id) as id"),
                DB::raw("'video' as service"),
                DB::raw("'Seller Global Engine' as provider"), // SEL security 08/08: nunca expor motor real (Kling/Veo) ao cliente
                DB::raw("CASE WHEN step = 'done' THEN 'succeeded' ELSE step END as status"),
                'output_url',
                DB::raw("JSON_OBJECT('product_name', product_key, 'style', mode) as wizard_payload"),
                DB::raw("0 as credits_debited"),
                DB::raw("0 as cost_usd"),
                'created_at',
                DB::raw("'pipeline' as source"),
                DB::raw("id as ord_id"),      // SEL-435b
                DB::raw("2 as ord_fonte"),
            ]);

        $union = $gen->unionAll($pipe);
        $sub   = DB::query()->fromSub($union, 't')
            ->orderByDesc('created_at')
            ->orderByDesc('ord_id')       // SEL-435b: ordem estavel dentro do empate
            ->orderByDesc('ord_fonte');

        $total = (clone $sub)->count();
        $page  = max(1, (int) $r->query('page', 1));
        $itens = $sub->forPage($page, $perPage)->get();

        // SEL security 08/08: defesa em profundidade -- nunca deixar o nome do motor
        // real (kling/veo/flow/omni/dicloak/google) vazar pro cliente, mesmo que
        // algum registro futuro grave o provider errado direto na tabela.
        $itens = $itens->map(function ($item) {
            if (isset($item->provider) && preg_match('/kling|veo|flow|omni|dicloak|google/i', (string) $item->provider)) {
                $item->provider = 'Seller Global Engine';
            }
            return $item;
        });

        return response()->json([
            'data'         => $itens,
            'current_page' => $page,
            'per_page'     => $perPage,
            'total'        => $total,
            'last_page'    => (int) max(1, ceil($total / $perPage)),
        ]);
    }

    // ─── helpers ──

    private function log(Request $r, string $service, string $provider, ?string $providerModel, ?string $taskId, ?string $outputUrl, string $status, ?string $finalPrompt = null, array $extra = []): AiGeneration
    {
        // SEL-073: _options (model/resolution/duration) viaja dentro do wizard_payload
        // sem mudar o shape que a Galeria consome (payload continua sendo o wizard).
        $payload = is_array($extra['wizard'] ?? null) ? $extra['wizard'] : [];
        if (!empty($extra['options'])) { $payload['_options'] = $extra['options']; }

        return AiGeneration::create([
            'tenant_id'        => $r->user()?->tenant_id ?? null,
            'user_id'          => $r->user()?->id ?? null,
            'service'          => $service,
            'provider'         => $provider,
            'provider_model'   => $providerModel,
            'provider_task_id' => $taskId,
            'wizard_payload'   => $payload ?: null,
            'final_prompt'     => $finalPrompt,
            'status'           => $status,
            'output_url'       => $outputUrl,
            'credits_debited'  => 0,
            'cost_usd'         => 0,
        ]);
    }

    /**
     * SEL-073: debita creditos do user quando billing_enabled=1 e modo credits.
     * INERTE por default (billing_enabled=0 na settings) — nada muda pro cliente.
     * SEL-190: super_admin nunca debita creditos — gera video infinito sem custo de credito
     * (o custo real USD continua sendo registrado em ai_generations.cost_usd para Ruan acompanhar).
     * Retorna quantos creditos foram debitados (0 = nada).
     */
    private function maybeDebitVideoCredits(AiGeneration $gen): int
    {
        try {
            if (!$gen->user_id) { return 0; }

            // SEL-190: super_admin bypassa debito de creditos (pode gerar infinitamente).
            // Custo USD continua sendo registrado para rastreabilidade de gasto real.
            $genUser = DB::table('users')->where('id', $gen->user_id)->value('role');
            if ($genUser === 'super_admin') { return 0; }

            $s = DB::table('settings')->where('group', 'video_billing')->pluck('value', 'key');
            $enabled = filter_var($s['billing_enabled'] ?? '0', FILTER_VALIDATE_BOOLEAN);
            if (!$enabled || ($s['billing_mode'] ?? '') !== 'credits') { return 0; }
            $credits = max(1, (int) ($s['credits_per_video'] ?? 1));

            DB::transaction(function () use ($gen, $credits) {
                $u = DB::table('users')->where('id', $gen->user_id)->lockForUpdate()->first(['id', 'ai_credits_balance']);
                if (!$u) { return; }
                $newBal = max(0, (int) $u->ai_credits_balance - $credits);
                DB::table('users')->where('id', $gen->user_id)->update(['ai_credits_balance' => $newBal]);
                DB::table('ai_credit_transactions')->insert([
                    'user_id'       => $gen->user_id,
                    'generation_id' => $gen->id,
                    'delta'         => -$credits,
                    'balance_after' => $newBal,
                    'reason'        => 'debit_video',
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            });
            return $credits;
        } catch (Throwable $e) {
            Log::warning('SEL-073 debit credits falhou: ' . $e->getMessage());
            return 0;
        }
    }

    private function handleProviderError(Throwable $e, string $service)
    {
        Log::warning("[AIController.{$service}] provider_error", ['error' => $e->getMessage()]);
        $msg = $e->getMessage();
        if (str_contains($msg, 'not_configured')) {
            return response()->json(['error' => 'provider_not_configured', 'message' => 'Este recurso ainda nao esta conectado — aguardando configuracao.'], 503);
        }
        return response()->json(['error' => 'provider_error', 'message' => 'Falha temporaria no provedor de IA — tente novamente em alguns segundos.'], 502);
    }
}
