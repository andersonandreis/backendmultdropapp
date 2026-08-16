<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\AiVideoPipelineJob;
use App\Models\AiVideoPipeline;
use App\Services\Ai\KlingService;
use App\Services\Ai\OpenAiService;
use App\Services\Ai\VideoDirectorService;
use App\Services\TikTokMediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * SEL-321: Orquestrador de geração de vídeo perfeito (Modo A) e clone (Modo B).
 *
 * WHITE-LABEL TOTAL: zero menção a provider em respostas públicas.
 * DRY_RUN (AI_VIDEO_DRY_RUN=true): monta payloads, loga, mocka respostas.
 *
 * Rotas:
 *   POST /v1/ai/video-perfect  — Modo A: produto + avatar + pitch → multi-shot Kling
 *   POST /v1/ai/video-clone    — Modo B: vídeo viral + avatar → swap personagem
 *   GET  /v1/ai/video-pipeline/{id} — Status do pipeline (labels nossos)
 *   GET  /v1/ai/video/{id}/download — Download via URL nossa (white-label)
 *
 * DIVERGÊNCIAS CONFIRMADAS (22/07/2026):
 *   - ffmpeg NÃO instalado no servidor → audio TTS vai diretamente ao lip-sync (sem pré-mixing).
 *   - video2video (motion transfer) retorna 404 na nossa conta Kling.
 *   - Modo B usa fallback: frame via GD/Imagick do primeiro frame do MP4 (via getimagesize),
 *     e image2video multi-shot com o frame + avatar injetado via elements.
 *   - Reportado no relatório final.
 */
class AiVideoPerfectController extends Controller
{

    /**
     * SEL-MARCA-NAO-VAZA: de que marca veio este pedido de download?
     *
     * O backend atende seller.global E tokfy.io. O nome do arquivo que o cliente ve no
     * celular tem que ser o da marca que ELE comprou — arquivo chamado "sellerglobal"
     * na mao de quem pagou Tokfy vira "nao reconheco essa cobranca" num chargeback.
     *
     * Ordem: ?marca= (front novo) -> Origin/Referer (serve pro bundle ja publicado)
     * -> sellerglobal (padrao de hoje; assim o seller.global nao muda em nada).
     */
    private function marcaDoPedido(Request $request): string
    {
        $pedida = strtolower(trim((string) $request->query('marca', '')));
        if (in_array($pedida, ['tokfy', 'sellerglobal'], true)) {
            return $pedida;
        }

        $origem = (string) ($request->headers->get('Origin') ?: $request->headers->get('Referer') ?: '');

        return str_contains(strtolower($origem), 'tokfy.io') ? 'tokfy' : 'sellerglobal';
    }
    private bool $dryRun;

    public function __construct(
        private KlingService $kling,
        private OpenAiService $openai,
        private TikTokMediaService $media,
        private VideoDirectorService $director,
    ) {
        $this->dryRun = (bool) config('services.ai_video.dry_run', false);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MODO A — POST /v1/ai/video-perfect
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cria pipeline Modo A: produto + avatar + pitch → multi-shot Kling v3 + TTS + lip-sync.
     */
    public function videoPerfect(Request $request)
    {
        // SEL-329: circuit breaker global — ligado no admin panel
        if (! config('services.ai_video.generation_enabled', true)) {
            return response()->json([
                'error'   => 'video_generation_disabled',
                'message' => 'Geração de vídeo temporariamente indisponível. Nossa equipe está atualizando a plataforma. Voltamos em breve.',
            ], 503);
        }

        $v = $request->validate([
            'product_key'      => 'required|string|max:64',
            'image_main'       => 'required|url|max:2048',
            'image_refs'       => 'nullable|array|max:8',
            'image_refs.*'     => 'url|max:2048',
            'avatar_id'        => 'nullable|integer',
            'avatar_url'       => 'nullable|url|max:2048',
            'pitch'            => 'nullable|string|max:300',
            'price'            => 'nullable|numeric|min:0',
            'shots'            => 'nullable|array',
            'shots.*.prompt'   => 'nullable|string|max:512',
            'shots.*.duration' => 'nullable|numeric|min:1|max:10',
            // SEL-323: preset por nicho vindo do wizard (panela→cozinha etc.)
            'scene_hint'       => 'nullable|string|max:200',
            'voice_gender'     => 'nullable|in:F,M',
            'voice_id'         => 'nullable|string|max:32',
            // SEL-329: qualidade escolhida pelo cliente + duração total do vídeo
            'quality'          => 'nullable|in:fast,balanced,master',
            'duration_total_s' => 'nullable|integer|between:10,30', // SEL-329: mín 10s (5s não cabe pitch de venda)
            // SEL-355: contexto extra do produto para enriquecer prompt Kling
            'product_category' => 'nullable|string|max:100',
            'product_rating'   => 'nullable|numeric|min:0|max:5',
            'product_sales'    => 'nullable|integer|min:0',
        ]);

        if (! $this->kling->isConfigured()) {
            return response()->json([
                'error'   => 'service_unavailable',
                'message' => 'Serviço de geração de vídeo não configurado.',
            ], 503);
        }

        $user = $request->user();

        // SEL-329: gate anti-prejuízo (plano Pro + cap R$50/mês + saldo wallet)
        $quality = $v['quality'] ?? \Illuminate\Support\Facades\DB::table('settings')
            ->where(['group' => 'video_billing', 'key' => 'quality_default'])->value('value') ?? 'balanced';
        $totalDurationS = max(10, (int) ($v['duration_total_s'] ?? 10)); // SEL-329: clamp mín 10s
        $gate = \App\Services\Ai\VideoBillingGate::check($user, $quality, $totalDurationS);
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
        // Guardar decisão de billing no pipeline pra usar no runFinalize (débito + custo)
        $billingMeta = [
            'quality'            => $quality,
            'duration_total_s'   => $totalDurationS,
            'source'             => $gate['source'] ?? null,
            'cost_brl_estimated' => $gate['cost_brl'] ?? null,
            'credits_estimated'  => $gate['credits'] ?? null,
        ];
        $avatar = $this->resolveAvatar($v);
        $shots  = $this->buildShots($v, $avatar !== null);

        // Montar payload Kling image2video v3 multi-shot com elements
        $renderPayload = $this->buildRenderPayload($v, $avatar, $shots);

        // Montar payload TTS (pitch) — SEL-369: voz coerente com o gênero do avatar
        $pitch        = $v['pitch'] ?? $this->buildDefaultPitch($v['product_key']);
        $avatarGender = $this->avatarGender($avatar, $v);
        $ttsPayload   = $this->buildTtsPayload($pitch, $v, $avatarGender);

        // Montar payload lip-sync (placeholder — video_url preenchido depois do render)
        $lipsyncPayload = [
            'mode'      => 'audio2video',
            'video_url' => '__RENDER_OUTPUT_URL__',
            'audio_url' => '__VOICE_OUTPUT_URL__',
        ];

        $payloads = [
            'render'  => $renderPayload,
            'voice'   => $ttsPayload,
            'lipsync' => $lipsyncPayload,
            'billing' => $billingMeta, // SEL-329: usado no runFinalize
            // SEL-369: auditoria — de onde veio o avatar e qual gênero decidiu a voz
            'avatar_meta' => [
                'source' => $avatar['source'] ?? 'none',
                'gender' => $avatarGender,
                'url'    => $avatar['url'] ?? null,
            ],
        ];

        if ($this->dryRun) {
            return $this->dryRunResponse('perfect', $v['product_key'], $payloads, $user);
        }

        // Criar pipeline e disparar job
        $pipeline = AiVideoPipeline::create([
            'user_id'     => $user?->id,
            'mode'        => 'perfect',
            'product_key' => $v['product_key'],
            'step'        => 'queued',
            'payloads'    => $payloads,
            'dry_run'     => false,
        ]);

        AiVideoPipelineJob::dispatch($pipeline->id);

        return response()->json([
            'pipeline_id' => $pipeline->id,
            'status'      => 'queued',
            'step_label'  => 'Na fila de processamento',
            'poll_url'    => url("/api/v1/ai/video-pipeline/{$pipeline->id}"),
        ], 202);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MODO B — POST /v1/ai/video-clone
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cria pipeline Modo B: vídeo viral + avatar → swap de personagem.
     *
     * DIVERGÊNCIA REPORTADA: endpoint video2video (motion transfer) retorna 404
     * na nossa conta Kling. Fallback implementado: image2video multi-shot a partir
     * de frame estático + elements avatar. Ruan deve liberar o endpoint v3 motion
     * transfer quando disponível na conta.
     */
    public function videoClone(Request $request)
    {
        $v = $request->validate([
            'viral_id'         => 'nullable|integer',
            'source_video_url' => 'nullable|url|max:2048',
            'avatar_id'        => 'nullable|integer',
            'avatar_url'       => 'nullable|url|max:2048',
            'voice'            => 'nullable|string|in:m,f',
        ]);
        if (empty($v['viral_id']) && empty($v['source_video_url'])) {
            return response()->json(['message' => 'Informe o vídeo viral (viral_id ou source_video_url).'], 422);
        }

        if (! $this->kling->isConfigured()) {
            return response()->json([
                'error'   => 'service_unavailable',
                'message' => 'Serviço de geração de vídeo não configurado.',
            ], 503);
        }

        // Buscar vídeo viral (por id ou por URL do tt-media)
        $viral = null;
        if (!empty($v['viral_id'])) {
            $viral = DB::table('tiktok_viral_videos')->where('id', $v['viral_id'])->first();
        } elseif (!empty($v['source_video_url'])) {
            $url   = $v['source_video_url'];
            $viral = DB::table('tiktok_viral_videos')
                ->where('play_url_hd', $url)
                ->first();
            if (! $viral && preg_match('/viralvid_ext_(\d+)\.mp4/', $url, $m)) {
                $viral = DB::table('tiktok_viral_videos')->where('external_video_id', $m[1])->first();
            }
        }
        if (! $viral) {
            return response()->json(['message' => 'Vídeo viral não encontrado.'], 404);
        }

        $videoUrl = $viral->play_url_hd ?? $viral->play_url ?? null;
        if (! $videoUrl || ! str_contains($videoUrl, '/storage/tt-media')) {
            return response()->json([
                'message' => 'Este vídeo ainda está sendo preparado para clonagem. Tente novamente em alguns minutos.',
            ], 422);
        }

        $user   = $request->user();
        $avatar = $this->resolveAvatar($v);
        // SEL-369: sem escolha explícita, voz segue o gênero do avatar
        $voice  = $v['voice'] ?? ($this->avatarGender($avatar, []) === 'M' ? 'm' : 'f');

        // DIVERGÊNCIA: motion transfer não disponível — usar fallback image2video multi-shot
        // com elements do avatar. Frame do vídeo seria extraído via ffmpeg (não instalado).
        // Fallback: usar cover_url do viral como imagem base.
        $frameUrl    = $viral->cover_url ?? null;
        $payloads    = [
            'render' => [
                '_divergence_note' => 'motion_transfer_unavailable_fallback_image2video',
                'model_name'       => 'kling-v3',
                'mode'             => 'pro',
                'aspect_ratio'     => '9:16',
                'duration'         => '10',
                'image'            => $frameUrl,
                'elements'         => $avatar ? [
                    ['element_id' => 'element_1', 'images' => [$avatar['url']]],
                ] : [],
                'multi_prompt' => [
                    [
                        'prompt'   => 'Wide establishing shot, person visible in scene, natural movement',
                        'duration' => 3,
                    ],
                    [
                        'prompt'   => 'Close-up of product, macro detail, cinematic lighting',
                        'duration' => 3,
                    ],
                    [
                        'prompt'   => '@Element1 holds product naturally, speaking to camera, points to bottom-left',
                        'duration' => 4,
                    ],
                ],
                'shot_type'       => 'customize',
                'negative_prompt' => 'cartoon, anime, CGI skin, distorted hands, extra fingers',
                'cfg_scale'       => 0.5,
            ],
            'transcribe' => [
                '_note'     => 'whisper_not_available_use_translated_pitch',
                'provider'  => 'openai_whisper',
                'video_url' => $videoUrl,
            ],
            'voice' => [
                'engine'   => 'openai_tts',
                'voice_id' => $voice === 'm' ? 'onyx' : 'nova',
                'text'     => '__TRANSCRIBED_AND_ADAPTED_TEXT__',
            ],
            'lipsync' => [
                'mode'      => 'audio2video',
                'video_url' => '__RENDER_OUTPUT_URL__',
                'audio_url' => '__VOICE_OUTPUT_URL__',
            ],
        ];

        if ($this->dryRun) {
            return $this->dryRunResponse('clone', (string) ($v['viral_id'] ?? $viral->id), $payloads, $user);
        }

        $pipeline = AiVideoPipeline::create([
            'user_id'     => $user?->id,
            'mode'        => 'clone',
            'product_key' => null,
            'step'        => 'queued',
            'payloads'    => $payloads,
            'dry_run'     => false,
        ]);

        AiVideoPipelineJob::dispatch($pipeline->id);

        return response()->json([
            'pipeline_id' => $pipeline->id,
            'status'      => 'queued',
            'step_label'  => 'Na fila de processamento',
            'poll_url'    => url("/api/v1/ai/video-pipeline/{$pipeline->id}"),
        ], 202);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /v1/ai/video-pipeline/{id}
    // ─────────────────────────────────────────────────────────────────────────

    public function pipelineStatus(Request $request, int $id)
    {
        $pipeline = AiVideoPipeline::where('id', $id)
            ->where('user_id', $request->user()?->id)
            ->first();

        if (! $pipeline) {
            return response()->json(['message' => 'Pipeline não encontrado.'], 404);
        }

        // ── SEL-FILAHONESTA (12/08) — o cliente passa a saber ONDE ele esta.
        //
        // Medido hoje: o video mais rapido saiu em 93s, a mediana em 4min, e o mais
        // lento levou 75 MINUTOS. Setenta e cinco minutos sem nenhum aviso e onde
        // nasce "a plataforma nao funciona" — ele nao sabe se esta na fila, se
        // travou, ou se deu errado. Agora ele ve a posicao e a previsao.
        //
        // A previsao usa o tempo real das ultimas entregas, nao um numero chutado.
        $posicao = null; $espera = null;
        if (in_array($pipeline->step, ['queued', 'pending', 'render'], true)) {
            $posicao = (int) \Illuminate\Support\Facades\DB::table('ai_video_pipelines')
                ->whereIn('step', ['queued', 'pending', 'render'])
                ->where('id', '<', $pipeline->id)
                ->count() + 1;

            // media real das ultimas 20 entregas; sem historico, 240s (mediana medida).
            // Feito em duas etapas de proposito: AVG junto com ORDER BY + LIMIT e SQL
            // invalido no MySQL ("Mixing of GROUP columns...") e derrubava ESTE
            // endpoint — que e justamente o que o cliente fica consultando.
            $ultimos = \Illuminate\Support\Facades\DB::table('ai_video_pipelines')
                ->whereNotNull('output_url')->where('output_url', '<>', '')
                ->where('created_at', '>=', now()->subHours(6))
                ->orderByDesc('id')->limit(20)
                ->selectRaw('TIMESTAMPDIFF(SECOND, created_at, updated_at) seg')
                ->pluck('seg')->filter(fn ($x) => $x > 0)->all();
            $medio = $ultimos ? (int) round(array_sum($ultimos) / count($ultimos)) : 240;

            $motores = max(1, (int) \App\Models\AiEngine::where('tool_type', 'video')
                ->where('is_active', 1)->count());
            $espera  = (int) max(30, ceil($posicao / $motores) * $medio);
        }

        $minutos = $espera ? max(1, (int) round($espera / 60)) : null;

        return response()->json([
            'pipeline_id' => $pipeline->id,
            'mode'        => $pipeline->mode,
            'step'        => $pipeline->step,
            'step_label'  => $this->stepLabel($pipeline->step),
            'output_url'  => $pipeline->output_url,
            'retries'     => $pipeline->retries,
            'error'       => $pipeline->error_message ? 'Nossos servidores encontraram um problema. Tentando novamente.' : null,
            // ── SEL-FILASEMPOSICAO (12/08, ordem do Ruan) — previsão SIM, posição NÃO.
            //
            // A primeira versão dizia "você é o 40º da fila". O dono cortou, e com
            // razão: quem vê a posição descobre o tamanho da operação. Um concorrente
            // pede um vídeo e lê quantas pessoas estão gerando junto.
            //
            // Fica o que ajuda o cliente (quanto tempo leva) e some o que só informa
            // terceiro (quantos somos). E o principal: ele NÃO precisa ficar olhando —
            // pode fechar a tela que a gente avisa por e-mail e notificação.
            //
            // `posicao_fila` continua saindo pro ADMIN por outro caminho; aqui, não.
            'espera_segundos' => $espera,
            'fila_texto'      => $posicao
                ? 'Seu vídeo está sendo gravado — leva cerca de ' . $minutos . ' min. '
                  . 'Pode fechar essa tela: a gente te avisa por e-mail e notificação assim que ficar pronto.'
                : null,
            'created_at'  => $pipeline->created_at,
            'updated_at'  => $pipeline->updated_at,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /v1/ai/video/{id}/download
    // ─────────────────────────────────────────────────────────────────────────

    public function download(Request $request, int $id)
    {
        $pipeline = AiVideoPipeline::where('id', $id)
            ->where('user_id', $request->user()?->id)
            ->where('step', 'done')
            ->first();

        if (! $pipeline || ! $pipeline->output_url) {
            return response()->json(['message' => 'Vídeo ainda não disponível.'], 404);
        }

        // SEL (09/08 Ruan URGENTE): NUNCA redirect — no celular isso abre o
        // player em vez de baixar. Sempre transmite o arquivo com
        // Content-Disposition attachment + CORS liberado, seja local OU remoto
        // (arquivo remoto e baixado no servidor e repassado via stream, sem
        // carregar tudo em memoria). filename nunca expoe "kling"/"veo" (regra
        // dura white-label).
        $filename = $this->marcaDoPedido($request) . '-video-' . $id . '.mp4';
        $headers  = [
            'Content-Type'                 => 'video/mp4',
            'Content-Disposition'          => 'attachment; filename="' . $filename . '"',
            'Access-Control-Allow-Origin'  => '*',
            'Access-Control-Expose-Headers'=> 'Content-Disposition',
        ];

        // 1) Arquivo ja local — transmite direto do disco
        $localPath = $this->extractStoragePath($pipeline->output_url);
        if ($localPath && Storage::disk('public')->exists($localPath)) {
            return response()->download(
                Storage::disk('public')->path($localPath),
                $filename,
                $headers
            );
        }

        // 2) Arquivo remoto (URL do provider, nunca exposta ao client) — baixa
        // no servidor e faz stream pro cliente em chunks (nao guarda tudo em
        // memoria). Isso garante o header attachment mesmo quando o arquivo
        // ainda nao foi persistido localmente.
        $remoteUrl = $pipeline->output_url;
        return response()->streamDownload(function () use ($remoteUrl) {
            try {
                $resp = Http::withOptions(['stream' => true])->timeout(120)->get($remoteUrl);
                $body = $resp->toPsrResponse()->getBody();
                while (! $body->eof()) {
                    echo $body->read(524288); // 512KB por chunk
                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }
                    flush();
                }
            } catch (\Throwable $e) {
                Log::error('[SEL download stream] falha ao transmitir video remoto', [
                    'url' => $remoteUrl,
                    'err' => $e->getMessage(),
                ]);
            }
        }, $filename, $headers);
    }


    // ─────────────────────────────────────────────────────────────────────────
    // MODO POV — POST /v1/ai/video-pov
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cria pipeline POV: produto + cenario -> 3 shots mao-sem-rosto Kling v3 / Veo.
     * SEM avatar, SEM lipsync (nunca ha boca visivel pra sincronizar).
     * Prompt variants: config services.ai_video.pov_prompt_variant (1..5)
     *   1=Base  2=ASMR  3=Demo(padrao)  4=Unboxing  5=CTA
     *
     * SEL-POV:
     *   - Detecta via Vision se a foto principal JA mostra uma mao segurando o
     *     produto (`VideoDirectorService::detectHand`). Se sim, o prompt instrui
     *     o motor a CONTINUAR aquela mao; se nao, instrui a INJETAR uma mao
     *     realista entrando em quadro pra pegar o produto.
     *   - Se o cliente mandar `pitch` (o que quer falar sobre o produto), o
     *     pipeline gera narracao (TTS) e sobrepoe o audio no video mudo via
     *     ffmpeg (SEM lipsync — como nunca ha rosto/boca em quadro, nao ha o que
     *     dessincronizar; ver AiVideoPipelineJob::runVoiceOverlayFallback).
     *     Sem pitch, o video sai mudo como sempre saiu.
     */
    public function downloadGeneration(Request $request, int $id)
    {
        // SEL-DOWNLOAD-GEN (14/08) — cliente reclamou que nao consegue baixar.
        //
        // MEDIDO: 7 dias -> 390 videos de 54 clientes vem de `ai_generations`
        // (id "gen-*" na galeria) contra 344 de pipelines. Ou seja, MAIS DA METADE da
        // producao. E a rota de download so servia pipeline: pro resto o site caia no
        // arquivo cru, que nao manda CORS, o blob morria e o navegador so ABRIA o video
        // (no celular, so tocava). Era exatamente a reclamacao.
        //
        // Tentei antes liberar CORS no proprio arquivo, via .htaccess: NAO FUNCIONA —
        // provei que o LiteSpeed nao aplica `Header` em arquivo estatico (meu cabecalho
        // de teste nao apareceu nem passando por fora do Cloudflare). Revertido.
        // Entao o caminho certo e este: a MESMA mecanica ja provada do download(),
        // apontando pra outra tabela.
        $pipeline = \App\Models\AiGeneration::where('id', $id)
            ->where('user_id', $request->user()?->id)
            ->first();

        if (! $pipeline || ! $pipeline->output_url) {
            return response()->json(['message' => 'Vídeo ainda não disponível.'], 404);
        }

        // SEL (09/08 Ruan URGENTE): NUNCA redirect — no celular isso abre o
        // player em vez de baixar. Sempre transmite o arquivo com
        // Content-Disposition attachment + CORS liberado, seja local OU remoto
        // (arquivo remoto e baixado no servidor e repassado via stream, sem
        // carregar tudo em memoria). filename nunca expoe "kling"/"veo" (regra
        // dura white-label).
        $filename = $this->marcaDoPedido($request) . '-video-gen' . $id . '.mp4';
        $headers  = [
            'Content-Type'                 => 'video/mp4',
            'Content-Disposition'          => 'attachment; filename="' . $filename . '"',
            'Access-Control-Allow-Origin'  => '*',
            'Access-Control-Expose-Headers'=> 'Content-Disposition',
        ];

        // 1) Arquivo ja local — transmite direto do disco
        $localPath = $this->extractStoragePath($pipeline->output_url);
        if ($localPath && Storage::disk('public')->exists($localPath)) {
            return response()->download(
                Storage::disk('public')->path($localPath),
                $filename,
                $headers
            );
        }

        // 2) Arquivo remoto (URL do provider, nunca exposta ao client) — baixa
        // no servidor e faz stream pro cliente em chunks (nao guarda tudo em
        // memoria). Isso garante o header attachment mesmo quando o arquivo
        // ainda nao foi persistido localmente.
        $remoteUrl = $pipeline->output_url;
        return response()->streamDownload(function () use ($remoteUrl) {
            try {
                $resp = Http::withOptions(['stream' => true])->timeout(120)->get($remoteUrl);
                $body = $resp->toPsrResponse()->getBody();
                while (! $body->eof()) {
                    echo $body->read(524288); // 512KB por chunk
                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }
                    flush();
                }
            } catch (\Throwable $e) {
                Log::error('[SEL download stream] falha ao transmitir video remoto', [
                    'url' => $remoteUrl,
                    'err' => $e->getMessage(),
                ]);
            }
        }, $filename, $headers);
    }


    // ─────────────────────────────────────────────────────────────────────────
    // MODO POV — POST /v1/ai/video-pov
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cria pipeline POV: produto + cenario -> 3 shots mao-sem-rosto Kling v3 / Veo.
     * SEM avatar, SEM lipsync (nunca ha boca visivel pra sincronizar).
     * Prompt variants: config services.ai_video.pov_prompt_variant (1..5)
     *   1=Base  2=ASMR  3=Demo(padrao)  4=Unboxing  5=CTA
     *
     * SEL-POV:
     *   - Detecta via Vision se a foto principal JA mostra uma mao segurando o
     *     produto (`VideoDirectorService::detectHand`). Se sim, o prompt instrui
     *     o motor a CONTINUAR aquela mao; se nao, instrui a INJETAR uma mao
     *     realista entrando em quadro pra pegar o produto.
     *   - Se o cliente mandar `pitch` (o que quer falar sobre o produto), o
     *     pipeline gera narracao (TTS) e sobrepoe o audio no video mudo via
     *     ffmpeg (SEM lipsync — como nunca ha rosto/boca em quadro, nao ha o que
     *     dessincronizar; ver AiVideoPipelineJob::runVoiceOverlayFallback).
     *     Sem pitch, o video sai mudo como sempre saiu.
     */

    public function videoPov(Request $request)
    {
        if (! config('services.ai_video.generation_enabled', true)) {
            return response()->json([
                'error'   => 'video_generation_disabled',
                'message' => 'Geracao de video temporariamente indisponivel.',
            ], 503);
        }

        $v = $request->validate([
            'product_key'   => 'required|string|max:64',
            'image_main'    => 'required|url|max:2048',
            'image_refs'    => 'nullable|array|max:3',
            'image_refs.*'  => 'url|max:2048',
            'scene_id'      => 'nullable|string|max:32',
            'scene_hint'    => 'nullable|string|max:300',
            'lighting_hint' => 'nullable|string|max:200',
            'pitch'         => 'nullable|string|max:200',
            'price'         => 'nullable|numeric|min:0',
            'product_name'  => 'nullable|string|max:200',
            'aspect_ratio'  => 'nullable|string|in:9:16,1:1,16:9',
            'duration'      => 'nullable|integer|between:5,10',
        ]);

        if (! $this->kling->isConfigured()) {
            return response()->json([
                'error'   => 'service_unavailable',
                'message' => 'Servico de geracao de video nao configurado.',
            ], 503);
        }

        $user     = $request->user();
        $quality  = 'balanced';
        $totalDur = (int) ($v['duration'] ?? 10);

        $gate = \App\Services\Ai\VideoBillingGate::check($user, $quality, $totalDur);
        if (! ($gate['allowed'] ?? false)) {
            return response()->json([
                'error'               => $gate['reason'] ?? 'blocked',
                'message'             => $gate['message'] ?? 'Geracao nao autorizada.',
                'used_this_month_brl' => $gate['used_this_month_brl'] ?? null,
                'cap_brl'             => $gate['cap_brl'] ?? null,
                'credits_needed'      => $gate['credits_needed'] ?? null,
                'wallet_balance'      => $gate['wallet_balance'] ?? null,
            ], $gate['http'] ?? 403);
        }

        $scene    = ! empty($v['scene_hint'])    ? mb_substr($v['scene_hint'],    0, 300) : 'neutral clean studio background';
        $lighting = ! empty($v['lighting_hint']) ? mb_substr($v['lighting_hint'], 0, 200) : 'soft diffused natural light';
        $variant  = (int) config('services.ai_video.pov_prompt_variant', 3);

        // SEL-POV: a foto principal JA tem mao segurando o produto, ou o motor
        // precisa injetar uma? O render (imageToVideo) so envia `image_main` pro
        // Veo/Kling — image_refs nao chega no worker — entao a analise usa
        // exatamente a foto que o motor vai ver.
        $handInfo = $this->director->detectHand($v['image_main']);

        $productImages = [$v['image_main']];
        if (! empty($v['image_refs'])) {
            foreach ($v['image_refs'] as $ref) { $productImages[] = $ref; }
        }
        foreach ($this->augmentProductImages($v['product_key']) as $extra) {
            $productImages[] = $extra;
        }
        $productImages = array_slice(array_values(array_unique($productImages)), 0, 4);

        $multiPrompt = $this->buildPovShots($scene, $lighting, $variant, $handInfo['mao_presente']);

        $renderPayload = [
            '_pov_mode'       => true,
            '_variant'        => $variant,
            'model_name'      => 'kling-v3',
            'mode'            => 'pro',
            'aspect_ratio'    => $v['aspect_ratio'] ?? '9:16',
            'duration'        => (string) ($v['duration'] ?? 10),
            'image'           => $v['image_main'],
            'elements'        => [[
                'element_id' => 'element_1',
                'images'     => $productImages,
            ]],
            'multi_prompt'    => $multiPrompt,
            'shot_type'       => 'customize',
            'negative_prompt' => 'face visible, human face, person, avatar, full body, head, CGI skin, cartoon, anime, distorted hands, extra fingers, watermark, low quality',
            'cfg_scale'       => 0.5,
        ];

        // SEL-POV: pitch presente = cliente quer narracao. TTS entra por cima do
        // video mudo (sem lipsync, ver docblock do metodo). `voice_id` segue o
        // mesmo default (nova) que os outros modos usam quando o cliente nao
        // escolhe genero.
        $pitch    = ! empty($v['pitch']) ? mb_substr($v['pitch'], 0, 200) : null;
        $hasVoice = $pitch !== null;

        $payloads = [
            'render'  => $renderPayload,
            'billing' => [
                'quality'            => $quality,
                'duration_total_s'   => $totalDur,
                'source'             => $gate['source'] ?? null,
                'cost_brl_estimated' => $gate['cost_brl'] ?? null,
                'credits_estimated'  => $gate['credits'] ?? null,
                'mode'               => 'pov',
                'no_tts'             => ! $hasVoice,
                'no_lipsync'         => true, // POV nunca tem lipsync: nunca ha rosto/boca em quadro
            ],
            'meta' => [
                'pitch'          => $pitch,
                'scene_id'       => $v['scene_id'] ?? 'neutral',
                'product_name'   => $v['product_name'] ?? null,
                'price'          => $v['price'] ?? null,
                'hand_detected'  => $handInfo['mao_presente'],
                'hand_position'  => $handInfo['posicao_mao'],
                'hand_confianca' => $handInfo['confianca'],
            ],
        ];

        if ($hasVoice) {
            $payloads['voice'] = [
                'text'     => $pitch,
                'voice_id' => 'nova',
                'speed'    => 1.0,
            ];
        }

        if ($this->dryRun) {
            return $this->dryRunResponse('pov', $v['product_key'], $payloads, $user);
        }

        $pipeline = AiVideoPipeline::create([
            'user_id'     => $user?->id,
            'mode'        => 'pov',
            'product_key' => $v['product_key'],
            'step'        => 'queued',
            'payloads'    => $payloads,
            'dry_run'     => false,
        ]);

        AiVideoPipelineJob::dispatch($pipeline->id);

        return response()->json([
            'pipeline_id' => $pipeline->id,
            'status'      => 'queued',
            'step_label'  => 'Na fila de processamento',
            'poll_url'    => url("/api/v1/ai/video-pipeline/{$pipeline->id}"),
        ], 202);
    }

    /**
     * 3 shots POV conforme variante (1-5). Padrão: 3 (demo).
     *
     * SEL-POV: o primeiro shot muda conforme `$handAlreadyPresent` —
     *   true  -> a foto de referencia JA tem uma mao no produto: instrui o motor
     *            a CONTINUAR aquela mesma mao (nao inventar uma segunda).
     *            false -> a foto so tem o produto sozinho: instrui o motor a
     *            INJETAR uma mao realista entrando em quadro pra pegar o produto.
     *
     * O `{STYLE}` (estilo live/POV/sem apresentador) entra no shot 1 e se
     * propaga pro texto final porque KlingBrowserService::imageToVideo() junta
     * os 3 shots num prompt so (`. `) antes de mandar pro Veo — o Veo NAO tem
     * campo de negative_prompt (so le `prompt`, ver veo_generate.js), entao toda
     * restricao de "sem rosto/sem pessoa" tem que estar embutida no texto
     * positivo, nao so no campo `negative_prompt` (que so o Kling API real
     * respeitaria).
     */
    private function buildPovShots(string $scene, string $lighting, int $variant, bool $handAlreadyPresent = false): array
    {
        $hands = 'human hands with natural skin texture, realistic veins and natural nails';
        $rep   = fn (string $p) => str_replace(
            ['{LIGHT}', '{SCENE}', '{HANDS}', '{HANDACTION}'],
            [$lighting, $scene, $hands, $handAlreadyPresent
                ? 'the same hand already holding the product in the reference photo naturally continues to hold and show it'
                : 'a realistic human hand enters the frame from off-screen and picks up the product resting on the surface'],
            $p
        );

        // Ancora de estilo — POV de live de TikTok Shop, camera em primeira
        // pessoa, so a mao aparece, sem apresentador. Vai no comeco do shot 1 pra
        // sobreviver a junção em prompt unico que o Veo recebe.
        $style = 'POV camera, first-person perspective like a live-selling TikTok Shop stream held in one hand — ';

        $sets = [
            1 => [
                $style . '{HANDACTION}, {HANDS}, no face visible, no presenter, nobody appears, {LIGHT}, {SCENE}, photorealistic, no CGI',
                'extreme close-up POV, {HANDS} turning @Element1 product to show details, {LIGHT}, {SCENE}, macro quality, no face, no person',
                'first-person POV, {HANDS} holding @Element1 product, thumb pointing lower-left corner TikTok cart, {LIGHT}, {SCENE}, no face, no avatar, no person',
            ],
            2 => [
                $style . '{HANDACTION} gently and slowly, {HANDS}, no face, no presenter, nobody appears, {LIGHT}, {SCENE}, ASMR feel, ultra-realistic',
                'macro POV, {HANDS} examining @Element1 product surface texture, {LIGHT}, {SCENE}, satisfying slow motion, no face, no person',
                'first-person POV, {HANDS} holding @Element1 product toward camera, {LIGHT}, {SCENE}, ASMR reveal, no face, natural skin pores, no person',
            ],
            3 => [
                $style . '{HANDACTION}, only {HANDS} visible in wide shot, smooth confident movement like showing a product live, {LIGHT}, {SCENE}, photorealistic skin, no face, no presenter, no full body, nobody appears',
                'macro close-up first-person POV, {HANDS} demonstrating key feature of @Element1 product, {LIGHT}, {SCENE}, extreme texture detail, no face, no person',
                'first-person POV, {HANDS} holding @Element1 product, thumb pointing confidently to lower-left TikTok cart button, {LIGHT}, {SCENE}, no face, no avatar, no person, photorealistic',
            ],
            4 => [
                $style . 'POV unboxing, {HANDACTION}, {HANDS} lifting @Element1 product out of packaging, no face, no presenter, nobody appears, {LIGHT}, {SCENE}, tissue paper reveal, slow motion, ultra-realistic',
                'close-up POV, {HANDS} turning @Element1 product over after unboxing, {LIGHT}, {SCENE}, natural skin, macro detail, no face, no person',
                'POV finale, {HANDS} presenting @Element1 product to camera then pointing lower-left TikTok cart, {LIGHT}, {SCENE}, no face, no person',
            ],
            5 => [
                $style . '{HANDACTION}, {HANDS} at eye level, no face, no presenter, nobody appears, {LIGHT}, {SCENE}, natural hand skin detail, no CGI',
                'close-up POV, both {HANDS} using @Element1 product, {LIGHT}, {SCENE}, smooth natural movement, no face, ultra-realistic, no person',
                'first-person POV, {HANDS} holding @Element1 product, thumb pointing lower-left TikTok Shop cart, {LIGHT}, {SCENE}, no face, no avatar, no person, photorealistic',
            ],
        ];

        $triplet = $sets[$variant] ?? $sets[3];

        return [
            ['prompt' => mb_substr($rep($triplet[0]), 0, 512), 'duration' => 3.0],
            ['prompt' => mb_substr($rep($triplet[1]), 0, 512), 'duration' => 3.5],
            ['prompt' => mb_substr($rep($triplet[2]), 0, 512), 'duration' => 3.5],
        ];
    }
    // ─────────────────────────────────────────────────────────────────────────
    // Helpers privados
    // ─────────────────────────────────────────────────────────────────────────

    private function resolveAvatar(array $v): ?array
    {
        if (! empty($v['avatar_url'])) {
            return ['url' => $v['avatar_url'], 'source' => 'upload', 'gender' => null];
        }
        if (! empty($v['avatar_id'])) {
            $avatar = DB::table('video_avatars')
                ->where('id', $v['avatar_id'])
                ->where('is_active', true)
                ->first();
            if ($avatar) {
                return ['url' => $avatar->image_url, 'id' => $avatar->id, 'source' => 'pool', 'gender' => $avatar->gender ?? null];
            }
        }
        // SEL-329 (23/07 Ruan): fallback pro avatar salvo do cliente em
        // client_video_avatars (o que o AvatarPicker persiste após "Gerar avatar
        // com IA"). Sem esse fallback, Kling ignora o avatar e gera pessoa
        // aleatória — bug diagnosticado no video #19 do user 1494.
        $userId = auth()->id();
        if ($userId) {
            $client = DB::table('clients')->where('user_id', $userId)->first();
            if ($client) {
                $mine = DB::table('client_video_avatars')
                    ->where('client_id', $client->id)
                    ->orderByDesc('updated_at')
                    ->first();
                if ($mine) {
                    if (! empty($mine->custom_avatar_url)) {
                        return ['url' => $mine->custom_avatar_url, 'source' => 'client_saved', 'gender' => null];
                    }
                    if (! empty($mine->video_avatar_id)) {
                        $pool = DB::table('video_avatars')
                            ->where('id', $mine->video_avatar_id)
                            ->where('is_active', true)
                            ->first();
                        if ($pool) {
                            return ['url' => $pool->image_url, 'id' => $pool->id, 'source' => 'client_pool', 'gender' => $pool->gender ?? null];
                        }
                    }
                }
            }
        }
        // SEL-369: sem avatar = Kling inventava pessoa sintética (P36: homem cartoon
        // com voz feminina, @Element1 órfão). Fallback final: avatar aleatório do pool
        // — sempre foto REAL de referência e gênero conhecido pra voz.
        $random = DB::table('video_avatars')->where('is_active', true)->inRandomOrder()->first();
        if ($random) {
            return ['url' => $random->image_url, 'id' => $random->id, 'source' => 'pool_random', 'gender' => $random->gender ?? null];
        }
        return null;
    }

    private function buildShots(array $v, bool $hasAvatar = true): array
    {
        // SEL-323: cenário por nicho do produto (wizard manda em inglês)
        $scene = ! empty($v['scene_hint'])
            ? mb_substr($v['scene_hint'], 0, 200)
            : 'modern Brazilian home setting';

        // SEL-369: NUNCA referenciar @ElementN sem element correspondente no payload
        // (P36: elements=1 com @Element1+@Element2 nos prompts → Kling inventou
        // pessoa sintética cartoon). Sem avatar = shots só de produto (@Element1).
        if (! $hasAvatar) {
            $defaults = [
                ['prompt' => 'Slow dolly-in wide shot, ' . $scene . ', @Element1 displayed as hero product on clean surface, warm afternoon window light, shot on ARRI Alexa 35mm ISO 800, teal-orange grade, photorealistic, negative space bottom-left corner uncluttered', 'duration' => 3],
                ['prompt' => 'Cut to macro close-up of @Element1, camera slowly orbiting, extreme texture detail — material surface, logo, craftsmanship. Real physics: reflections, fabric drape. Shallow depth of field, cinematic bokeh, ISO 800', 'duration' => 3],
                ['prompt' => 'Cut to hero shot: @Element1 product rotating slowly under soft studio light, premium product photography style, sharp focus, bottom-left corner clear for TikTok cart overlay', 'duration' => 4],
            ];
            if (! empty($v['shots'])) {
                foreach ($v['shots'] as $i => $shot) {
                    if (isset($defaults[$i]) && ! empty($shot['duration'])) $defaults[$i]['duration'] = $shot['duration'];
                }
            }
            return $defaults;
        }

        // Shots do briefing SEL-321 — editáveis via 'shots' input
        $defaults = [
            [
                'prompt'   => 'Slow dolly-in wide shot, ' . $scene . ', @Element2 displayed as hero product on clean surface, @Element1 standing in soft background focus, warm afternoon window light, shot on ARRI Alexa 35mm ISO 800, teal-orange grade, photorealistic, negative space bottom-left corner uncluttered',
                'duration' => 3,
            ],
            [
                'prompt'   => 'Cut to macro close-up of @Element2, camera slowly orbiting, extreme texture detail — material surface, logo, craftsmanship. Real physics: reflections, steam if applicable, fabric drape. Shallow depth of field, cinematic bokeh, ISO 800',
                'duration' => 3,
            ],
            [
                'prompt'   => 'Cut to medium shot: @Element1 full body visible, holds @Element2 naturally with both hands, speaking enthusiastically to camera with clear mouth articulation, confident smile, visible skin pores and natural skin texture, individual hair strands, DSLR 85mm shallow DOF, no CGI. Then points down to bottom-left corner of frame with natural hand motion. Keep bottom-left corner clear for TikTok cart overlay.',
                'duration' => 4,
            ],
        ];

        if (! empty($v['shots'])) {
            foreach ($v['shots'] as $i => $shot) {
                if (isset($defaults[$i])) {
                    if (! empty($shot['prompt'])) $defaults[$i]['prompt']   = $shot['prompt'];
                    if (! empty($shot['duration'])) $defaults[$i]['duration'] = $shot['duration'];
                }
            }
        }

        return $defaults;
    }

    private function buildRenderPayload(array $v, ?array $avatar, array $shots): array
    {
        $elements = [];

        // @Element1 = avatar
        if ($avatar) {
            $elements[] = [
                'element_id' => 'element_1',
                'images'     => [$avatar['url']],
            ];
        }

        // @Element2 = produto (principal + refs do wizard + auto-augment do banco)
        $productImages = [$v['image_main']];
        if (! empty($v['image_refs'])) {
            foreach ($v['image_refs'] as $ref) {
                $productImages[] = $ref;
            }
        }
        foreach ($this->augmentProductImages($v['product_key']) as $extra) {
            $productImages[] = $extra;
        }
        // SEL-369: numeração consistente com os prompts — com avatar o produto é
        // @Element2; sem avatar os shots referenciam o produto como @Element1.
        $elements[] = [
            'element_id' => $avatar ? 'element_2' : 'element_1',
            'images'     => array_slice(array_values(array_unique($productImages)), 0, 4),
        ];

        $multiPrompt = array_map(fn ($s) => [
            'prompt'   => mb_substr($s['prompt'], 0, 512),
            'duration' => (float) $s['duration'],
        ], $shots);

        // SEL-355: enriquecer primeiro shot com contexto do produto (categoria, rating, vendas)
        // Kling usa o multi_prompt[0] como âncora de estilo — contexto melhora a geração
        $contextParts = [];
        if (! empty($v['product_category'])) {
            $contextParts[] = 'product category: ' . mb_substr($v['product_category'], 0, 60);
        }
        if (! empty($v['product_rating']) && $v['product_rating'] > 0) {
            $stars = round((float) $v['product_rating'], 1);
            $contextParts[] = "rated {$stars}/5 by verified buyers";
        }
        if (! empty($v['product_sales']) && $v['product_sales'] > 100) {
            $salesK = $v['product_sales'] >= 1000
                ? round($v['product_sales'] / 1000, 1) . 'k'
                : (string) $v['product_sales'];
            $contextParts[] = "{$salesK} units sold";
        }
        if (! empty($contextParts) && isset($multiPrompt[0])) {
            $multiPrompt[0]['prompt'] = mb_substr(
                '[' . implode(', ', $contextParts) . '] ' . $multiPrompt[0]['prompt'],
                0, 512
            );
        }

        return [
            'model_name'      => 'kling-v3',
            'mode'            => 'pro',
            'aspect_ratio'    => '9:16',
            'duration'        => '10',
            'image'           => $v['image_main'],
            'elements'        => $elements,
            'multi_prompt'    => $multiPrompt,
            'shot_type'       => 'customize',
            // SEL-369: negative anti-cartoon validado no pipeline Tokfy
            'negative_prompt' => 'cartoon, anime, illustration, 3d render, CGI look, CGI skin, plastic skin, waxy skin, doll-like, airbrushed, beauty filter, perfect symmetry, distorted hands, extra fingers, warped face, deformed face, morphing, flickering, low quality, blur, watermark, text, subtitles, slow motion, overexposed, flat lighting',
            'cfg_scale'       => 0.5,
        ];
    }

    /**
     * Fotos extras persistidas do produto (scrape anúncio/avaliações/catálogo),
     * melhores primeiro, descartando miniaturas (< 400px no menor lado).
     */
    private function augmentProductImages(string $productKey): array
    {
        try {
            $rows = DB::table('tiktok_product_images')
                ->where('product_key', $productKey)
                ->whereNotNull('url_local')
                ->where('url_original', '!=', '__scrape_queued__')
                ->orderByDesc('quality_score')
                ->limit(12)
                ->pluck('url_local');

            $good = [];
            foreach ($rows as $url) {
                $path = null;
                if (str_contains($url, '/storage/tt-media/')) {
                    $name = basename(parse_url($url, PHP_URL_PATH) ?: '');
                    $cand = $name !== '' ? Storage::disk('public')->path('tt-media/' . $name) : null;
                    if ($cand && is_file($cand)) {
                        $path = $cand;
                    }
                }
                if ($path) {
                    $dim = @getimagesize($path);
                    if (! $dim || min($dim[0], $dim[1]) < 400) {
                        continue;
                    }
                }
                $good[] = $url;
                if (count($good) >= 6) {
                    break;
                }
            }

            return $good;
        } catch (\Throwable $e) {
            Log::warning('[SEL-321] augmentProductImages falhou', ['key' => $productKey, 'err' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * SEL-369: gênero do apresentador manda na voz. 'M'|'F'|null.
     * Ordem: gender do avatar (pool) → voice_gender explícito do request →
     * vision no avatar custom (fail-open) → null.
     */
    private function avatarGender(?array $avatar, array $v): ?string
    {
        $g = strtolower((string) ($avatar['gender'] ?? ''));
        if ($g === 'male') return 'M';
        if ($g === 'female') return 'F';
        if (! empty($v['voice_gender'])) return $v['voice_gender'];
        if (! empty($avatar['url'])) {
            try {
                $ans = $this->openai->analyzeImage(
                    $avatar['url'],
                    'Is the presenter in this image male or female? Answer with exactly one word: male or female.'
                );
                $txt = strtolower(is_array($ans) ? json_encode($ans) : (string) $ans);
                if (str_contains($txt, 'male') && ! str_contains($txt, 'female')) return 'M';
                if (str_contains($txt, 'female')) return 'F';
            } catch (\Throwable $e) {
                Log::warning('[SEL-369] avatar gender vision falhou', ['err' => $e->getMessage()]);
            }
        }
        return null;
    }

    private function buildTtsPayload(string $pitch, array $v = [], ?string $avatarGender = null): array
    {
        $allowed = ['nova', 'onyx', 'alloy', 'echo', 'fable', 'shimmer'];
        $voice   = $v['voice_id'] ?? null;
        if (! in_array($voice, $allowed, true)) {
            // SEL-369: sem voice_id explícito, a voz segue o GÊNERO do avatar real
            // (P36 saiu homem com voz 'nova' porque o default era voice_gender ?? 'F').
            $voice = $avatarGender === 'M' ? 'onyx' : 'nova';
        }
        return [
            'engine'   => 'openai',
            'voice_id' => $voice,
            'text'     => mb_substr($pitch, 0, 300),
            'format'   => 'mp3',
            'speed'    => 1.0,
        ];
    }

    private function buildDefaultPitch(string $productKey): string
    {
        $row = DB::table('kalodata_raw')
            ->where('type', 'products')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.id')) = ?", [$productKey])
            ->orderByDesc('snapshot_date')
            ->first();

        if ($row) {
            $p     = json_decode($row->payload, true);
            $title = $p['product_title'] ?? '';
            $price = $p['unit_price'] ?? '';
            $short = mb_substr($title, 0, 60);
            return "Olha esse achado incrível: {$short} por apenas {$price}! Aproveita agora, tá no carrinho aqui embaixo!";
        }

        return 'Olha esse produto incrível que eu encontrei! Corre lá no carrinho antes que acabe!';
    }

    private function dryRunResponse(string $mode, string $key, array $payloads, $user): \Illuminate\Http\JsonResponse
    {
        $pipeline = AiVideoPipeline::create([
            'user_id'     => $user?->id,
            'mode'        => $mode,
            'product_key' => $key,
            'step'        => 'dry_run_complete',
            'payloads'    => $payloads,
            'dry_run'     => true,
            'output_url'  => null,
        ]);

        $logPath = storage_path('logs/ai-video-dryrun.log');
        $logLine = '[' . now()->toIso8601String() . '] DRY_RUN pipeline_id=' . $pipeline->id . ' mode=' . $mode . PHP_EOL;
        foreach ($payloads as $step => $payload) {
            $logLine .= "  [{$step}] " . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
        }
        file_put_contents($logPath, $logLine . PHP_EOL, FILE_APPEND | LOCK_EX);

        Log::info('[SEL-321 DRY_RUN] pipeline montado', [
            'pipeline_id' => $pipeline->id,
            'mode'        => $mode,
            'steps'       => array_keys($payloads),
        ]);

        // Resposta mockada — simula pipeline completo sem chamar provider
        return response()->json([
            'dry_run'     => true,
            'pipeline_id' => $pipeline->id,
            'mode'        => $mode,
            'steps'       => array_keys($payloads),
            'payloads'    => $payloads,
            'mock_output' => [
                'step'       => 'done',
                'step_label' => 'Vídeo gerado com sucesso (simulação)',
                'output_url' => url('/storage/tt-media/sellerglobal-video-' . $pipeline->id . '-mock.mp4'),
            ],
            'log_path'    => 'storage/logs/ai-video-dryrun.log',
            'message'     => 'Dry-run concluído. Todos os payloads validados. Zero crédito gasto.',
        ]);
    }

    private function stepLabel(string $step): string
    {
        return match ($step) {
            'queued'           => 'Na fila de processamento',
            'render'           => 'Renderizando cenas',
            'voice'            => 'Aplicando voz',
            'lipsync'          => 'Sincronizando fala',
            'audio_overlay'    => 'Aplicando trilha sonora',
            'finalize'         => 'Finalizando vídeo',
            'done'             => 'Vídeo pronto!',
            'failed'           => 'Nossos servidores estão reprocessando seu vídeo',
            'dry_run_complete' => 'Simulação concluída',
            default            => 'Processando',
        };
    }

    private function extractStoragePath(string $url): ?string
    {
        // Extrai o path relativo a partir de /storage/
        if (preg_match('#/storage/(.+)$#', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MODO SHOWCASE SILENCIOSO — POST /v1/ai/video-showcase      (SEL-332)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cria pipeline Showcase Silencioso: avatar apresenta produto SEM FALA.
     * Kling image2video v3 com negative prompt reforçado bloqueando lipsync.
     * Após render, overlay de áudio via ffmpeg com trilha escolhida.
     *
     * 4 vibes:
     *   estetico_calmo      — soft window light, slow deliberate movement, neutral tones
     *   try_haul_divertido  — quick cuts, playful gestures, colorful backdrop
     *   asmr_closeup        — extreme macro, texture emphasis, ring light, ultra-detailed
     *   hipnotico_loop      — 360 rotation, seamless loop, plain gradient background
     *
     * Negative prompt global: talking, speaking, lip movement, mouth open,
     *   mouth articulation, saying words, singing, subtitles, text overlay
     *
     * O job (AiVideoPipelineJob) detecta mode="showcase" e pula voice+lipsync,
     * executando em vez disso o overlay de áudio via ffmpeg antes do finalize.
     */
    public function videoShowcase(Request $request)
    {
        if (! config('services.ai_video.generation_enabled', true)) {
            return response()->json([
                'error'   => 'video_generation_disabled',
                'message' => 'Geracao de video temporariamente indisponivel.',
            ], 503);
        }

        $v = $request->validate([
            'product_id'   => 'nullable|integer',
            'avatar_id'    => 'nullable|integer',
            'vibe'         => 'required|string|in:estetico_calmo,try_haul_divertido,asmr_closeup,hipnotico_loop',
            'audio_id'     => 'required|string|max:64',
            'pitch'        => 'nullable|string|max:300',
            'image_main'   => 'nullable|url|max:2048',
            'image_refs'   => 'nullable|array|max:3',
            'image_refs.*' => 'url|max:2048',
            'product_key'  => 'nullable|string|max:64',
            'product_name' => 'nullable|string|max:200',
            'aspect_ratio' => 'nullable|string|in:9:16,1:1,16:9',
            'duration'     => 'nullable|integer|between:5,10',
        ]);

        if (! $this->kling->isConfigured()) {
            return response()->json([
                'error'   => 'service_unavailable',
                'message' => 'Servico de geracao de video nao configurado.',
            ], 503);
        }

        $user     = $request->user();
        $quality  = 'balanced';
        $totalDur = (int) ($v['duration'] ?? 10);

        $gate = \App\Services\Ai\VideoBillingGate::check($user, $quality, $totalDur);
        if (! ($gate['allowed'] ?? false)) {
            return response()->json([
                'error'               => $gate['reason'] ?? 'blocked',
                'message'             => $gate['message'] ?? 'Geracao nao autorizada.',
                'used_this_month_brl' => $gate['used_this_month_brl'] ?? null,
                'cap_brl'             => $gate['cap_brl'] ?? null,
                'credits_needed'      => $gate['credits_needed'] ?? null,
                'wallet_balance'      => $gate['wallet_balance'] ?? null,
            ], $gate['http'] ?? 403);
        }

        // Valida trilha sonora
        $audioTrack = \Illuminate\Support\Facades\DB::table('showcase_audio_library')
            ->where('slug', $v['audio_id'])
            ->where('active', true)
            ->first();

        if (! $audioTrack) {
            return response()->json([
                'error'   => 'audio_not_found',
                'message' => 'Trilha sonora nao encontrada: ' . $v['audio_id'],
            ], 422);
        }

        // Imagem principal: prioriza image_main passada, senao busca por product_key
        $imageMain  = $v['image_main'] ?? null;
        $productKey = $v['product_key'] ?? ('showcase-' . ($v['product_id'] ?? 'p'));

        if (! $imageMain && ! empty($v['product_id'])) {
            $productImages = $this->augmentProductImages((string) $v['product_id']);
            $imageMain = $productImages[0] ?? null;
        }

        if (! $imageMain) {
            return response()->json([
                'error'   => 'image_required',
                'message' => 'Imagem do produto obrigatoria. Envie image_main ou product_id com imagens cadastradas.',
            ], 422);
        }

        $multiPrompt = $this->buildShowcaseShots($v['vibe'], $v['product_name'] ?? 'produto');

        $negativePrompt = 'talking, speaking, lip movement, mouth open, mouth articulation, saying words, singing, subtitles, text overlay, captions, watermark, low quality, blurry, CGI skin, cartoon, anime';

        $productImages = [$imageMain];
        if (! empty($v['image_refs'])) {
            foreach ($v['image_refs'] as $ref) { $productImages[] = $ref; }
        }
        foreach ($this->augmentProductImages($productKey) as $extra) {
            $productImages[] = $extra;
        }
        $productImages = array_slice(array_values(array_unique($productImages)), 0, 4);

        $renderPayload = [
            '_showcase_mode' => true,
            '_vibe'          => $v['vibe'],
            '_audio_slug'    => $v['audio_id'],
            '_audio_path'    => $audioTrack->file_path,
            'model_name'     => 'kling-v3',
            'mode'           => 'pro',
            'aspect_ratio'   => $v['aspect_ratio'] ?? '9:16',
            'duration'       => (string) $totalDur,
            'image'          => $imageMain,
            'elements'       => [[
                'element_id' => 'element_1',
                'images'     => $productImages,
            ]],
            'multi_prompt'   => $multiPrompt,
            'shot_type'      => 'customize',
            'negative_prompt'=> $negativePrompt,
            'cfg_scale'      => 0.5,
        ];

        $payloads = [
            'render'  => $renderPayload,
            'billing' => [
                'quality'            => $quality,
                'duration_total_s'   => $totalDur,
                'source'             => $gate['source'] ?? null,
                'cost_brl_estimated' => $gate['cost_brl'] ?? null,
                'credits_estimated'  => $gate['credits'] ?? null,
                'mode'               => 'showcase',
                'no_tts'             => true,
                'no_lipsync'         => true,
                'has_audio_overlay'  => true,
            ],
            'meta' => [
                'vibe'         => $v['vibe'],
                'audio_id'     => $v['audio_id'],
                'pitch'        => $v['pitch'] ?? null,
                'product_name' => $v['product_name'] ?? null,
                'product_id'   => $v['product_id'] ?? null,
            ],
        ];

        if ($this->dryRun) {
            return $this->dryRunResponse('showcase', $productKey, $payloads, $user);
        }

        $pipeline = \App\Models\AiVideoPipeline::create([
            'user_id'     => $user?->id,
            'mode'        => 'showcase',
            'product_key' => $productKey,
            'step'        => 'queued',
            'payloads'    => $payloads,
            'dry_run'     => false,
        ]);

        \App\Jobs\AiVideoPipelineJob::dispatch($pipeline->id);

        return response()->json([
            'pipeline_id' => $pipeline->id,
            'status'      => 'queued',
            'step_label'  => 'Na fila de processamento',
            'poll_url'    => url("/api/v1/ai/video-pipeline/{$pipeline->id}"),
        ], 202);
    }

    /**
     * Biblioteca de audio para o Showcase Silencioso.
     * GET /v1/ai/audio-library?mood=chill
     */
    public function audioLibrary(Request $request)
    {
        $mood  = $request->query('mood');
        $query = \Illuminate\Support\Facades\DB::table('showcase_audio_library')
            ->where('active', true)
            ->select('id', 'slug', 'title', 'mood', 'duration_sec', 'license');

        if ($mood && in_array($mood, ['chill', 'upbeat', 'epic', 'lofi'], true)) {
            $query->where('mood', $mood);
        }

        $tracks = $query->orderBy('mood')->orderBy('slug')->get();

        return response()->json([
            'data'  => $tracks,
            'total' => $tracks->count(),
        ]);
    }

    /**
     * 3 shots Showcase Silencioso por vibe. SEM FALA, SEM LIPSYNC.
     *
     * Negative prompt aplicado globalmente no payload Kling.
     * Durations: shot 1 (4s) + shot 2 (3s) + shot 3 (3s) = 10s.
     */
    private function buildShowcaseShots(string $vibe, string $productName): array
    {
        $p = mb_substr($productName, 0, 60);

        $sets = [
            'estetico_calmo' => [
                [
                    'prompt'   => "@Element1 {$p} presented in silent showcase, soft window light, slow deliberate movement, neutral tones, minimal clean background, NO SPEAKING, NO LIP MOVEMENT, natural body language, 9:16 vertical TikTok Shop, photorealistic 4K",
                    'duration' => '4',
                ],
                [
                    'prompt'   => "extreme close-up of @Element1 {$p} texture and details, hands gently holding product, soft ring light, slow reveal, NO SPEAKING, NO MOUTH MOVEMENT, ASMR aesthetic, ultra-detailed fabric texture",
                    'duration' => '3',
                ],
                [
                    'prompt'   => "avatar holding @Element1 {$p} toward camera in calm elegant pose, soft diffused light, neutral minimal studio, NO SPEAKING, natural smile closed lips, product in full frame, TikTok Shop cart corner clear",
                    'duration' => '3',
                ],
            ],
            'try_haul_divertido' => [
                [
                    'prompt'   => "avatar revealing @Element1 {$p} with playful try-haul energy, quick dynamic movement, colorful backdrop, NO SPEAKING, natural excited expression closed mouth, upbeat body language, 9:16 TikTok Shop vertical",
                    'duration' => '4',
                ],
                [
                    'prompt'   => "avatar modeling @Element1 {$p} with fun hand gestures, haul video style, bright warm lighting, NO LIP MOVEMENT, NO TALKING, genuine smile closed lips, playful outfit reveal movement",
                    'duration' => '3',
                ],
                [
                    'prompt'   => "avatar giving thumbs up holding @Element1 {$p}, colorful energetic background, NO SPEAKING, happy closed-mouth expression, pointing to product with both hands, TikTok haul aesthetic",
                    'duration' => '3',
                ],
            ],
            'asmr_closeup' => [
                [
                    'prompt'   => "extreme macro close-up of @Element1 {$p} surface texture, avatar hands with natural skin detail slowly touching product, ring light reflection, NO SPEAKING NO VOICE, ultra-detailed ASMR aesthetic, 4K photorealistic",
                    'duration' => '4',
                ],
                [
                    'prompt'   => "ASMR focus shot: @Element1 {$p} material texture in extreme detail, avatar fingertips gently exploring surface, soft diffused light, NO MOUTH MOVEMENT, satisfying slow motion, ultra-macro",
                    'duration' => '3',
                ],
                [
                    'prompt'   => "avatar holding @Element1 {$p} very close to camera lens, ASMR reveal, ring light bokeh, NO SPEAKING, calm focused expression neutral lips, product fills 80 percent of frame, sensory detail emphasis",
                    'duration' => '3',
                ],
            ],
            'hipnotico_loop' => [
                [
                    'prompt'   => "@Element1 {$p} rotating 360 degrees presented by avatar, plain gradient background, seamless smooth motion, NO SPEAKING, NO FACE FOCUS, product center frame, hypnotic loop aesthetic, studio lighting even exposure",
                    'duration' => '4',
                ],
                [
                    'prompt'   => "slow hypnotic spin of @Element1 {$p}, avatar hands rotating product smoothly, clean white grey gradient bg, NO LIP MOVEMENT, mesmerizing looping motion, product photography style, sharp focus",
                    'duration' => '3',
                ],
                [
                    'prompt'   => "avatar presenting @Element1 {$p} with hypnotic circular motion, seamless 360 product reveal, neutral background bokeh, NO SPEAKING, product in spotlight, TikTok Shop loop-ready vertical format",
                    'duration' => '3',
                ],
            ],
        ];

        return $sets[$vibe] ?? $sets['estetico_calmo'];
    }

    // -------------------------------------------------------------------------
    // SEL-336: POST /api/v1/ai/video-modelar-viral
    //
    // Recebe {video_id, product_id} (ou product_key + image_main + product_name).
    // Busca a estrutura do video em video_analysis e gera um novo video Kling
    // seguindo o mesmo hook/timing/vibe do video viral, adaptado ao produto.
    //
    // WHITE-LABEL TOTAL: zero referencia a Kling/OpenAI/provider.
    // -------------------------------------------------------------------------
    public function videoModelarViral(\Illuminate\Http\Request $request)
    {
        if (! config('services.ai_video.generation_enabled', true)) {
            return response()->json([
                'error'   => 'video_generation_disabled',
                'message' => 'Geracao de video temporariamente indisponivel.',
            ], 503);
        }

        $v = $request->validate([
            'video_id'     => 'required|string|max:64',   // kalodata_video_id
            'product_key'  => 'nullable|string|max:64',   // chave do produto cliente
            'image_main'   => 'nullable|url|max:2048',    // imagem principal do produto
            'product_name' => 'nullable|string|max:200',  // nome do produto
            'country'      => 'nullable|string|max:2',    // BR | US
            'avatar_id'    => 'nullable|integer',
            'avatar_url'   => 'nullable|url|max:2048',
        ]);

        $country = strtoupper($v['country'] ?? 'BR');

        // Busca analise do video modelo
        $analysis = \Illuminate\Support\Facades\DB::table('video_analysis')
            ->where('kalodata_video_id', $v['video_id'])
            ->where('country', $country)
            ->first();

        if (!$analysis) {
            return response()->json([
                'error'   => 'analysis_not_ready',
                'message' => 'Este video ainda nao foi analisado. Tente novamente amanha apos 07:00 BRT.',
                'video_id' => $v['video_id'],
            ], 422);
        }

        $user        = $request->user();
        $productKey  = $v['product_key'] ?? $v['video_id'];
        $productName = $v['product_name'] ?? 'Produto';
        $imageMain   = $v['image_main'] ?? null;

        // Monta pitch baseado na estrutura viral (hook + cta do video modelo)
        $hook = $analysis->hook_0_3s ?: '';
        $cta  = $analysis->cta ?: 'Compra no link';
        $pitch = trim("{$hook} — {$productName}. {$cta}");
        $pitch = mb_substr($pitch, 0, 300);

        // Resolve avatar
        $avatar = null;
        if (!empty($v['avatar_url'])) {
            $avatar = ['url' => $v['avatar_url'], 'source' => 'upload'];
        } elseif (!empty($v['avatar_id'])) {
            $avatarRow = \Illuminate\Support\Facades\DB::table('video_avatars')
                ->where('id', $v['avatar_id'])
                ->where('is_active', true)
                ->first(['preview_url', 'pool_url']);
            if ($avatarRow) {
                $avatar = ['url' => $avatarRow->pool_url ?? $avatarRow->preview_url, 'source' => 'pool'];
            }
        }

        if (!$imageMain && $productKey) {
            $augmented = $this->augmentProductImages($productKey);
            $imageMain = $augmented[0] ?? null;
        }

        if (!$imageMain) {
            return response()->json([
                'error'   => 'image_required',
                'message' => 'Imagem do produto obrigatoria para gerar o video.',
            ], 422);
        }

        // Determina modo de geracao baseado no vibe do video viral
        $vibe = $analysis->vibe ?? 'outro';
        $mode = match ($vibe) {
            'review', 'reacao' => 'video_perfeito',   // avatar falando + produto
            'showcase'         => 'showcase',           // sem fala, so avatar
            'unboxing'         => 'video_perfeito',
            'tutorial'         => 'video_perfeito',
            default            => 'automagico',
        };

        // Monta shots baseado na duracao do video modelo (replicar timing)
        $durationSec = (int) ($analysis->duration_sec ?? 15);
        $shots = $this->buildModelarViralShots($productName, $imageMain, $analysis, $durationSec);

        $payload = [
            'mode'         => 'modelar_viral',
            'source_video' => $v['video_id'],
            'vibe'         => $vibe,
            'product_key'  => $productKey,
            'product_name' => $productName,
            'image_main'   => $imageMain,
            'pitch'        => $pitch,
            'shots'        => $shots,
            'avatar'       => $avatar,
            'dry_run'      => $this->dryRun,
        ];

        if ($this->dryRun) {
            return $this->dryRunResponse('modelar_viral', $productKey, ['main' => $payload], $user);
        }

        // Verifica config Kling
        if (! config('services.kling.access_key')) {
            return response()->json([
                'error'   => 'service_unavailable',
                'message' => 'Servico de geracao de video nao configurado.',
            ], 503);
        }

        // Dispara job de geracao (mesmo pipeline do video_perfeito)
        $pipeline = \App\Models\AiVideoPipeline::create([
            'user_id'      => $user?->id,
            'product_key'  => $productKey,
            'mode'         => 'modelar_viral',
            'status'       => 'queued',
            'payload'      => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        \App\Jobs\AiVideoPipelineJob::dispatch($pipeline->id, $payload);

        return response()->json([
            'pipeline_id'  => $pipeline->id,
            'status'       => 'queued',
            'mode'         => 'modelar_viral',
            'source_video' => $v['video_id'],
            'vibe'         => $vibe,
            'pitch'        => $pitch,
            'message'      => 'Video em producao. Acompanhe em /v1/ai/video-pipeline/' . $pipeline->id,
            'poll_url'     => '/api/v1/ai/video-pipeline/' . $pipeline->id,
        ]);
    }

    /** SEL-336: Monta shots replicando timing e estrutura do video viral modelo. */
    private function buildModelarViralShots(string $productName, string $imageMain, object $analysis, int $durationSec): array
    {
        $p    = mb_substr($productName, 0, 60);
        $hook = mb_substr($analysis->hook_0_3s ?? '', 0, 100);
        $sol  = mb_substr($analysis->solution ?? '', 0, 100);
        $cta  = mb_substr($analysis->cta ?? 'Compra agora', 0, 80);

        // Distribui duracao total em 3 shots (hook / beneficio / cta)
        $dur1 = max(3, (int) round($durationSec * 0.3)); // 30% gancho
        $dur2 = max(3, (int) round($durationSec * 0.5)); // 50% beneficio
        $dur3 = max(3, (int) round($durationSec * 0.2)); // 20% CTA

        return [
            [
                'prompt'   => "Avatar presenting @Element1 {$p} with hook: {$hook}. Close face + product shot, energetic expression, natural daylight, vertical 9:16 TikTok Shop format",
                'duration' => (string) $dur1,
            ],
            [
                'prompt'   => "@Element1 {$p} product benefit highlight: {$sol}. Avatar demonstrating with enthusiastic gesture, warm indoor lighting, hands visible, product center frame",
                'duration' => (string) $dur2,
            ],
            [
                'prompt'   => "Avatar pointing to camera with confident smile holding @Element1 {$p}, CTA energy: {$cta}. Direct eye contact, brand colors background, TikTok Shop closing frame",
                'duration' => (string) $dur3,
            ],
        ];
    }

}
