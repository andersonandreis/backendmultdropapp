<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ExternalVideoCallbackJob;
use App\Models\AiVideoPipeline;
use App\Services\Ai\KlingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * TOK-22 -- porta de entrada do motor de video pra pedidos de FORA do
 * seller.global (hoje so o Tokfy).
 *
 * Este controller NAO gera video. Ele so valida, registra a origem e entrega o
 * pedido pro MESMO service que o seller.global ja usa
 * (KlingService -> VeoBrowserService via binding do AppServiceProvider), com
 * uma unica diferenca: o marcador `_external_source` no payload, que faz o
 * service enfileirar na fila de prioridade mais baixa (`external-video-low`).
 *
 * Reusar o service e proposital: e nele que moram as regras de PRODUTO do Ruan
 * (garanteIdioma -> audio em pt-BR, garanteCameraNoProduto -> produto e a
 * estrela, recusa barata quando falta roteiro). Despachar o job direto daqui
 * pularia tudo isso e o video do Tokfy sairia narrado em ingles.
 */
class ExternalVideoIntakeController extends Controller
{
    /** POST /api/v1/external-video/enqueue */
    public function enqueue(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'source'       => ['required', 'string', Rule::in(['tokfy'])],
            'external_ref' => ['required', 'string', 'max:128'],
            'callback_url' => ['required', 'url', 'max:2048'],
            'priority'     => ['nullable', 'string', Rule::in(['low'])],

            'input'              => ['required', 'array'],
            'input.prompt'       => ['required', 'string', 'min:3', 'max:2400'],
            'input.image_url'    => ['nullable', 'url', 'max:2048'],
            'input.duration'     => ['nullable', 'integer', 'min:5', 'max:12'],
            'input.aspect_ratio' => ['nullable', 'string', 'max:10'],
            'input.lang'         => ['nullable', 'string', 'max:10'],
            // Percorre o caminho inteiro e para um passo antes do Generate.
            // Serve pra validar a integracao sem consumir geracao.
            'input.dry_run'      => ['nullable', 'boolean'],
        ]);

        // Idempotencia por external_ref. Sem isso, um retry do Tokfy (timeout de
        // rede, redeploy, fila) geraria o MESMO video duas vezes e cobraria duas
        // vezes do motor. Devolve o pedido original em vez de criar outro.
        $jaExiste = AiVideoPipeline::where('source', 'tokfy')
            ->where('external_ref', $dados['external_ref'])
            ->first();

        if ($jaExiste) {
            return response()->json([
                'accepted'      => true,
                'duplicate'     => true,
                'motor_job_id'  => (string) $jaExiste->id,
                'motor_task_id' => $jaExiste->render_task_id,
                'step'          => $jaExiste->step,
            ], 202);
        }

        $entrada = $dados['input'];

        $pipeline = AiVideoPipeline::create([
            'user_id'      => null,          // pedido externo nao pertence a cliente do seller.global
            'mode'         => 'tokfy_external',
            'step'         => 'queued',
            'source'       => $dados['source'],
            'external_ref' => $dados['external_ref'],
            'callback_url' => $dados['callback_url'],
            'payloads'     => ['input' => $entrada],
            'dry_run'      => (bool) ($entrada['dry_run'] ?? false),
        ]);

        $payload = [
            'prompt'       => $entrada['prompt'],
            'duration'     => (int) ($entrada['duration'] ?? 5),
            'aspect_ratio' => $entrada['aspect_ratio'] ?? '9:16',
            // Regra de produto do Ruan: TikTok Shop e pt-BR. O Tokfy pode
            // sobrescrever, mas o default nunca e ingles.
            'lang'             => $entrada['lang'] ?? 'pt-BR',
            'external_task_id' => 'tokfy_' . $dados['external_ref'],

            // ESTE campo e o unico motivo da alteracao no KlingBrowserService.
            // Ele nao muda nada da geracao -- so manda o job pra fila de tras.
            '_external_source' => 'tokfy',
        ];

        if (! empty($entrada['image_url'])) {
            // `image` e a chave que KlingBrowserGenerateJob::baixaImagens() le no
            // caminho image2video (linha ~345). Nao inventar chave nova aqui.
            $payload['image'] = $entrada['image_url'];
        }
        if (! empty($entrada['dry_run'])) {
            $payload['dry_run'] = true;
        }

        $servico = app(KlingService::class);

        try {
            $resposta = ! empty($entrada['image_url'])
                ? $servico->imageToVideo($payload)
                : $servico->textToVideo($payload);
        } catch (\InvalidArgumentException $e) {
            // Recusa barata do proprio service (ex: sem roteiro). Devolve 422 com
            // a mensagem original em vez de 500 -- o Tokfy consegue mostrar isso
            // pro usuario dele.
            $pipeline->update(['step' => 'failed', 'error_message' => $e->getMessage()]);

            return response()->json([
                'accepted' => false,
                'error'    => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            $pipeline->update([
                'step'          => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            return response()->json(['accepted' => false, 'error' => 'motor_indisponivel'], 503);
        }

        $taskId = $resposta['data']['task_id'] ?? null;

        $pipeline->update([
            'render_task_id' => $taskId,
            'step'           => 'render',
        ]);

        // Acompanhamento do resultado + callback. Vai pra fila `default`
        // (supervisor sellerapp-worker), NAO pra fila do navegador: e um job
        // curto de leitura de estado e nao pode ocupar o worker unico que gera
        // video. Delay inicial porque nada fica pronto em menos de ~2min.
        ExternalVideoCallbackJob::dispatch($pipeline->id)
            ->onQueue('default')
            ->delay(now()->addSeconds(30));

        return response()->json([
            'accepted'      => true,
            'motor_job_id'  => (string) $pipeline->id,
            'motor_task_id' => $taskId,
            'queue'         => 'external-video-low',
        ], 202);
    }

    /**
     * GET /api/v1/external-video/status/{motorJobId}
     *
     * Fallback de consulta pro caso do callback nao chegar (Tokfy fora do ar na
     * hora em que o video ficou pronto).
     */
    public function status(Request $request, string $motorJobId): JsonResponse
    {
        $pipeline = AiVideoPipeline::where('id', $motorJobId)
            ->where('source', 'tokfy')
            ->first();

        if (! $pipeline) {
            return response()->json(['message' => 'not_found'], 404);
        }

        return response()->json([
            'motor_job_id'  => (string) $pipeline->id,
            'motor_task_id' => $pipeline->render_task_id,
            'external_ref'  => $pipeline->external_ref,
            'step'          => $pipeline->step,
            'status'        => match ($pipeline->step) {
                'done'   => 'done',
                'failed' => 'failed',
                default  => 'processing',
            },
            'output_url'      => $pipeline->output_url,
            'error'           => $pipeline->error_message,
            'callback_sent_at' => $pipeline->callback_sent_at,
        ]);
    }
}
