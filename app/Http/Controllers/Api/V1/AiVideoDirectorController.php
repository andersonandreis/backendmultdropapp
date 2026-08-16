<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\AiVideoPipelineJob;
use App\Models\AiVideoPipeline;
use App\Services\Ai\VideoDirectorService;
use App\Services\Ai\VideoBillingGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * SEL-333 Fase 1 — Video Director
 *
 * Endpoint unico que analisa o produto via Vision, escolhe o pipeline ideal
 * e despacha o AiVideoPipelineJob para executar.
 *
 * POST /api/v1/ai/video-director
 *
 * WHITE-LABEL TOTAL: zero mencao a Kling/OpenAI/provider nas respostas.
 * DRY_RUN (AI_VIDEO_DRY_RUN=true): monta payloads, loga, retorna sem cobrar.
 */
class AiVideoDirectorController extends Controller
{
    private bool $dryRun;

    public function __construct(
        private VideoDirectorService $director,
    ) {
        $this->dryRun = (bool) config('services.ai_video.dry_run', false);
    }

    /**
     * POST /api/v1/ai/video-director
     *
     * Wizard Automatico — analisa produto e escolhe pipeline automaticamente.
     *
     * @bodyParam product_key    string  required  Identificador do produto
     * @bodyParam image_main     string  required  URL da imagem principal
     * @bodyParam image_refs     array            URLs de imagens adicionais (max 3)
     * @bodyParam product_name   string           Nome do produto (contextualiza Vision)
     * @bodyParam price          numeric          Preco para compor pitch automatico
     * @bodyParam pitch          string           Pitch customizado (max 300 chars)
     * @bodyParam category_hint  string           Categoria informada pelo cliente (opcional)
     * @bodyParam avatar_id      integer          ID do avatar do pool
     * @bodyParam avatar_url     string           URL da foto do avatar (upload)
     * @bodyParam audio_id       string           Slug da trilha sonora (showcase)
     */
    public function direct(Request $request)
    {
        // Circuit breaker global
        if (! config('services.ai_video.generation_enabled', true)) {
            return response()->json([
                'error'   => 'video_generation_disabled',
                'message' => 'Geracao de video temporariamente indisponivel. Nossa equipe esta atualizando a plataforma.',
            ], 503);
        }

        $v = $request->validate([
            'product_key'   => 'required|string|max:64',
            'image_main'    => 'required|url|max:2048',
            'image_refs'    => 'nullable|array|max:3',
            'image_refs.*'  => 'url|max:2048',
            'product_name'  => 'nullable|string|max:200',
            'price'         => 'nullable|numeric|min:0',
            'pitch'         => 'nullable|string|max:300',
            'category_hint' => 'nullable|string|max:64',
            'avatar_id'     => 'nullable|integer',
            'avatar_url'    => 'nullable|url|max:2048',
            'audio_id'      => 'nullable|string|max:64',
        ]);

        // Verificar configuracao do servico de video
        if (! config('services.kling.access_key')) {
            return response()->json([
                'error'   => 'service_unavailable',
                'message' => 'Servico de geracao de video nao configurado.',
            ], 503);
        }

        $user    = $request->user();
        $quality = 'balanced';
        $dur     = 10;

        // Gate de billing (mesmo padrao dos outros modos)
        $gate = VideoBillingGate::check($user, $quality, $dur);
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

        $billingMeta = [
            'quality'            => $quality,
            'duration_total_s'   => $dur,
            'source'             => $gate['source'] ?? null,
            'cost_brl_estimated' => $gate['cost_brl'] ?? null,
            'credits_estimated'  => $gate['credits'] ?? null,
            'mode'               => 'director',
        ];

        // ── 1. Analise do produto via Vision ────────────────────────────────
        $analysis = $this->director->analyzeProduct(
            imageUrl:      $v['image_main'],
            productTitle:  $v['product_name'] ?? null,
            categoryHint:  $v['category_hint'] ?? null,
        );

        // ── 2. Escolha do pipeline ───────────────────────────────────────────
        $pipelineInfo = $this->director->choosePipeline($analysis['category']);
        $pipeline     = $pipelineInfo['pipeline'];

        // ── 3. Montar payloads para o Job ────────────────────────────────────
        $payloads = $this->director->buildPipelinePayloads(
            pipeline:     $pipeline,
            input:        $v,
            analysis:     $analysis,
            pipelineInfo: $pipelineInfo,
            billing:      $billingMeta,
        );

        Log::info('[SEL-333 Director] pipeline escolhido', [
            'user_id'    => $user?->id,
            'product_key'=> $v['product_key'],
            'category'   => $analysis['category'],
            'pipeline'   => $pipeline,
            'reason'     => $pipelineInfo['reason'],
        ]);

        if ($this->dryRun) {
            return $this->dryRunResponse($v['product_key'], $analysis, $pipelineInfo, $payloads, $user);
        }

        // ── 4. Criar pipeline e despachar Job (mesmo padrao dos outros modos) ─
        $db = AiVideoPipeline::create([
            'user_id'     => $user?->id,
            'mode'        => 'director',
            'product_key' => $v['product_key'],
            'step'        => 'queued',
            'payloads'    => $payloads,
            'dry_run'     => false,
        ]);

        // ── 5. Registrar evento para aprendizado (schema Fase 1) ─────────────
        try {
            \Illuminate\Support\Facades\DB::table('video_generation_events')->insert([
                'pipeline_id'      => $db->id,
                'user_id'          => $user?->id,
                'pipeline_used'    => $pipeline,
                'product_category' => $analysis['category'],
                'category_confidence' => $analysis['attributes']['confianca'] ?? null,
                'director_reason'  => $pipelineInfo['reason'],
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        } catch (\Throwable $e) {
            // Nao bloqueante — aprendizado e secundario
            Log::warning('[SEL-333] falha ao registrar video_generation_events', [
                'pipeline_id' => $db->id,
                'err'         => $e->getMessage(),
            ]);
        }

        AiVideoPipelineJob::dispatch($db->id);

        return response()->json([
            'pipeline_id'      => $db->id,
            'status'           => 'queued',
            'step_label'       => 'Analisando produto...',
            'pipeline_chosen'  => $pipeline,
            'pipeline_label'   => $this->pipelineLabel($pipeline),
            'category_detected'=> $analysis['category'],
            'reason'           => $pipelineInfo['reason'],
            'poll_url'         => url("/api/v1/ai/video-pipeline/{$db->id}"),
        ], 202);
    }

    // -------------------------------------------------------------------------
    // Helpers privados
    // -------------------------------------------------------------------------

    private function pipelineLabel(string $pipeline): string
    {
        return match ($pipeline) {
            'showcase_silencioso' => 'Showcase Silencioso',
            'pov'                 => 'POV - So a Mao',
            'video_perfeito'      => 'Video Perfeito com Avatar',
            default               => 'Geracao Automatica',
        };
    }

    private function dryRunResponse(
        string $productKey,
        array  $analysis,
        array  $pipelineInfo,
        array  $payloads,
        mixed  $user
    ): \Illuminate\Http\JsonResponse {
        $pipeline = $pipelineInfo['pipeline'];

        $db = AiVideoPipeline::create([
            'user_id'     => $user?->id,
            'mode'        => 'director',
            'product_key' => $productKey,
            'step'        => 'dry_run_complete',
            'payloads'    => $payloads,
            'dry_run'     => true,
            'output_url'  => null,
        ]);

        $logPath = storage_path('logs/ai-video-dryrun.log');
        $logLine = '[' . now()->toIso8601String() . '] DRY_RUN SEL-333 pipeline_id=' . $db->id
            . ' category=' . $analysis['category']
            . ' pipeline=' . $pipeline . PHP_EOL;
        file_put_contents($logPath, $logLine, FILE_APPEND | LOCK_EX);

        return response()->json([
            'dry_run'           => true,
            'pipeline_id'       => $db->id,
            'pipeline_chosen'   => $pipeline,
            'pipeline_label'    => $this->pipelineLabel($pipeline),
            'category_detected' => $analysis['category'],
            'reason'            => $pipelineInfo['reason'],
            'attributes'        => $analysis['attributes'],
            'steps'             => array_keys($payloads),
            'mock_output'       => [
                'step'       => 'done',
                'step_label' => 'Video gerado com sucesso (simulacao)',
                'output_url' => url('/storage/tt-media/sellerglobal-video-' . $db->id . '-mock.mp4'),
            ],
            'log_path'          => 'storage/logs/ai-video-dryrun.log',
            'message'           => 'Dry-run Director concluido. Pipeline selecionado sem gastar credito.',
        ]);
    }
}
