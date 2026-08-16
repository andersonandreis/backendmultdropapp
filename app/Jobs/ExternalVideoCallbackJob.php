<?php

namespace App\Jobs;

use App\Models\AiVideoPipeline;
use App\Services\Ai\KlingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TOK-22 -- acompanha um pedido externo (Tokfy) ate o fim e avisa o Tokfy.
 *
 * POR QUE UM JOB SEPARADO EM VEZ DE UM HOOK NO FIM DO KlingBrowserGenerateJob:
 * aquele arquivo estava com alteracoes NAO COMMITADAS de outra sessao no
 * servidor. Editar/commitar ele varreria trabalho alheio pra dentro deste
 * commit. Este job le o MESMO estado que o motor ja publica no cache
 * (`kling_browser:{taskId}`, via KlingService::getVideoTask) e nao exige uma
 * linha sequer no caminho de geracao do seller.global.
 *
 * Roda na fila `default` (supervisor sellerapp-worker), de proposito NUNCA na
 * fila do navegador: e um job curto e nao pode ocupar o worker unico
 * (numprocs=1) que gera os videos dos clientes pagantes.
 */
class ExternalVideoCallbackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    /** O re-agendamento e manual (ver handle), entao o retry automatico so atrapalharia. */
    public int $tries = 1;

    /** Intervalo entre consultas de estado. */
    private const INTERVALO_S = 30;

    /**
     * 4 horas de janela (30s x 480).
     *
     * Tem que ser LONGA de proposito: este job e de prioridade BAIXA, entao ele
     * pode legitimamente ficar horas na fila enquanto o seller.global usa o
     * motor — que e exatamente o comportamento desejado. Uma janela curta
     * transformaria "esperou muito" em "falhou", que e mentira.
     */
    private const MAX_CONSULTAS = 480;

    /** Tentativas de entrega do callback quando o Tokfy nao responde. */
    private const MAX_TENTATIVAS_CALLBACK = 5;

    public function __construct(
        public int $pipelineId,
        public int $consulta = 0,
        public int $tentativaCallback = 0,
    ) {}

    public function handle(): void
    {
        $pipeline = AiVideoPipeline::find($this->pipelineId);

        if (! $pipeline || $pipeline->source !== 'tokfy') {
            return;
        }

        // Ja entregue. Protege contra reprocessamento duplicado da fila.
        if ($pipeline->callback_sent_at) {
            return;
        }

        // MODO REENTREGA: o desfecho ja foi apurado e gravado, so a entrega do
        // callback falhou. Aqui NAO se reavalia o estado do motor — o cache
        // pode ter expirado nesse meio tempo e um 'done' viraria 'failed' na
        // releitura. Reenvia exatamente o que ja foi decidido.
        if (in_array($pipeline->step, ['done', 'failed'], true)) {
            $this->entregaCallback(
                $pipeline,
                $pipeline->step === 'done' ? 'done' : 'failed',
                $pipeline->output_url,
                $pipeline->error_message,
            );

            return;
        }

        if (empty($pipeline->render_task_id)) {
            $this->finaliza($pipeline, 'failed', null, 'sem_task_id_no_motor');

            return;
        }

        $estado = app(KlingService::class)->getVideoTask($pipeline->render_task_id, 'image2video');
        $status = $estado['data']['task_status'] ?? 'processing';

        // ARMADILHA: o estado do motor vive no cache com TTL de 30min contado a
        // partir do ENFILEIRAMENTO. Um job de prioridade baixa pode ficar mais
        // que isso esperando o seller.global liberar o worker — e ai
        // getVideoTask() devolve code 404 / task_status 'failed' por AUSENCIA de
        // chave, nao por falha de geracao. Tratar isso como falha reportaria
        // "failed" pro Tokfy justamente no cenario que a fila baixa existe pra
        // permitir. Enquanto a janela nao estourar, isso conta como "ainda
        // esperando".
        if (($estado['code'] ?? 0) === 404) {
            $status = 'processing';
        }

        if ($status === 'succeed') {
            $url = $estado['data']['task_result']['videos'][0]['url'] ?? null;
            $this->finaliza($pipeline, 'done', $url, null);

            return;
        }

        if ($status === 'failed') {
            $msg = $estado['data']['task_status_msg'] ?: ($estado['message'] ?? 'generation_failed');
            $this->finaliza($pipeline, 'failed', null, (string) $msg);

            return;
        }

        // Ainda gerando (submitted / processing / queued_wait). Volta pra fila.
        if ($this->consulta >= self::MAX_CONSULTAS) {
            $this->finaliza(
                $pipeline,
                'failed',
                null,
                'timeout_aguardando_motor (4h sem desfecho — o video pode ter sido gerado; conferir pelo render_task_id)'
            );

            return;
        }

        static::dispatch($this->pipelineId, $this->consulta + 1, $this->tentativaCallback)
            ->onQueue('default')
            ->delay(now()->addSeconds(self::INTERVALO_S));
    }

    /** Grava o desfecho no pipeline e entrega o callback pro Tokfy. */
    private function finaliza(AiVideoPipeline $pipeline, string $status, ?string $url, ?string $erro): void
    {
        $pipeline->update([
            'step'          => $status === 'done' ? 'done' : 'failed',
            'output_url'    => $url,
            'error_message' => $erro ? mb_substr($erro, 0, 1000) : null,
        ]);

        $this->entregaCallback($pipeline, $status, $url, $erro);
    }

    /** Entrega (ou reentrega) o callback. Nao decide desfecho, so transmite. */
    private function entregaCallback(AiVideoPipeline $pipeline, string $status, ?string $url, ?string $erro): void
    {
        $corpo = [
            'external_ref' => $pipeline->external_ref,
            'motor_job_id' => (string) $pipeline->id,
            'status'       => $status,
        ];
        if ($url) {
            $corpo['output_url'] = $url;
        }
        if ($erro) {
            $corpo['error'] = mb_substr($erro, 0, 500);
        }

        $this->log('callback_tentativa', [
            'pipeline_id' => $pipeline->id,
            'status'      => $status,
            'tentativa'   => $this->tentativaCallback + 1,
            'url'         => $pipeline->callback_url,
        ]);

        $ok = false;
        $detalhe = '';

        try {
            $resposta = Http::timeout(15)
                ->acceptJson()
                ->withHeaders([
                    'X-External-Video-Token' => (string) config('services.external_video.shared_token', ''),
                ])
                ->post($pipeline->callback_url, $corpo);

            $ok = $resposta->successful();
            $detalhe = 'http_' . $resposta->status();
        } catch (\Throwable $e) {
            // Tokfy fora do ar NAO pode derrubar nem travar o motor do
            // seller.global. Loga e segue.
            $detalhe = 'excecao: ' . mb_substr($e->getMessage(), 0, 200);
        }

        if ($ok) {
            $pipeline->forceFill(['callback_sent_at' => now()])->save();
            $this->log('callback_entregue', ['pipeline_id' => $pipeline->id, 'detalhe' => $detalhe]);

            return;
        }

        $this->log('callback_falhou', [
            'pipeline_id' => $pipeline->id,
            'detalhe'     => $detalhe,
            'tentativa'   => $this->tentativaCallback + 1,
        ]);

        if ($this->tentativaCallback + 1 < self::MAX_TENTATIVAS_CALLBACK) {
            // Reentrega com espera crescente. O pipeline ja esta com o desfecho
            // gravado, entao o /status serve de plano B enquanto isso.
            static::dispatch($this->pipelineId, self::MAX_CONSULTAS, $this->tentativaCallback + 1)
                ->onQueue('default')
                ->delay(now()->addSeconds(30 * ($this->tentativaCallback + 1)));

            return;
        }

        Log::error('[TOK-22] callback desistiu apos ' . self::MAX_TENTATIVAS_CALLBACK . ' tentativas', [
            'pipeline_id'  => $pipeline->id,
            'external_ref' => $pipeline->external_ref,
            'detalhe'      => $detalhe,
        ]);
    }

    /**
     * Log dedicado. O `.env` deste backend roda LOG_LEVEL=error, entao
     * Log::info()/warning() nao apareceriam no log da aplicacao -- e subir tudo
     * pra error() poluiria o canal de erro de verdade.
     */
    private function log(string $evento, array $ctx = []): void
    {
        try {
            Log::build([
                'driver' => 'single',
                'path'   => storage_path('logs/tok22-external-video.log'),
                'level'  => 'debug',
            ])->info($evento, $ctx);
        } catch (\Throwable $e) {
            // log nunca derruba job
        }
    }
}
