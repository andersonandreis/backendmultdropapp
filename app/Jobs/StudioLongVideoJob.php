<?php

namespace App\Jobs;

use App\Models\AiEngine;
use App\Models\AiGeneration;
use App\Models\AiVideoPipeline;
use App\Services\Ai\AiEnginePool;
use App\Services\Ai\ElevenLabsService;
use App\Services\Ai\VideoEnginePool;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * SEL-30s — Vídeo longo (até 30s) montado como UM vídeo editado com cortes de
 * câmera propositais, não 3 clipes colados.
 *
 * Ideia aprovada (Ruan 07/08) + refinamento:
 *   - O pitch é dividido em N SHOTS narrativos (1 por clipe de ~10s), cada um com
 *     ENQUADRAMENTO/ÂNGULO próprio (ex.: shot1 plano médio/gancho, shot2 close
 *     nas mãos/produto em uso, shot3 macro no produto + CTA).
 *   - TODO clipe é semeado pela MESMA foto do produto -> produto/cena/luz coerentes
 *     entre os shots, sem fingir plano-sequência. O corte na costura é um CORTE DE
 *     EDIÇÃO (jump cut / mudança de ângulo), não um corte seco sem sentido.
 *   - O corte cai na BATIDA da narração: cada clipe é aparado no tamanho da fala
 *     do seu shot (+respiro), então a emenda nunca cai no meio de uma palavra.
 *   - O áudio nativo dos clipes é DESCARTADO. Uma narração contínua (ElevenLabs,
 *     PT-BR) — montada shot a shot com o mesmo timbre — é dublada por cima e dá a
 *     coesão que disfarça as emendas.
 *   - Estilo VOICEOVER / produto em ação (sem apresentadora falando direto pra
 *     câmera), pra a voz dublada não descasar dos lábios.
 *
 * ATRÁS DA FLAG services.sel30s.enabled — só é despachado pelo
 * StudioOptionsController quando duracao>clip_seconds && flag ON && plano libera.
 * NÃO altera o fluxo de 1 clipe (StudioGenerationJob), que continua intacto.
 *
 * WHITE-LABEL: zero menção a providers nas mensagens ao cliente.
 */
class StudioLongVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3000; // até 3 clipes (~60s cada) + concat + TTS + dub

    /**
     * SEL-RESILIENCIA (12/08) — o job NAO morre mais na primeira topada.
     *
     * `$tries = 1` era a raiz do problema: qualquer tropeco NOSSO (Chrome que a
     * gente mesmo fechou pra trocar login, tunel SSH caindo com exit=255, motor
     * que sumiu do pool) queimava a unica tentativa e o cliente levava `failed`.
     *
     * Com `retryUntil()` definido o Laravel IGNORA `$tries` e passa a usar o
     * PRAZO: o job pode ser reenfileirado quantas vezes precisar dentro de 3h.
     * Isso e o que transforma "fila cheia" em ESPERA em vez de ERRO -- cada
     * release() por falta de motor volta pra fila sem gastar nada.
     *
     * O freio contra laco infinito NAO e o $tries: e o contador de falhas de
     * INFRA por pipeline (VideoResilience::MAX_TENTATIVAS_INFRA), que so conta
     * quebra de verdade, nunca espera de fila.
     */
    public int $tries         = 0;   // 0 = sem teto por contagem (quem manda e o retryUntil)
    public int $maxExceptions = 8;
    public array $backoff     = [20, 45, 90, 150];

    /** Prazo total de vida do pedido. Depois disso, failed() honesto. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(3);
    }

    /** Chave de reserva/lock atual, guardada pra parada graciosa. */
    private ?\App\Models\AiEngine $engineReservada = null;

    /**
     * SEL-ECOSSISTEMA (12/08) — CADEIA DE CUSTODIA DO VIDEO.
     *
     * Ate hoje a pipeline nao guardava EM QUE MOTOR o video foi feito: o campo saia
     * '?' em toda auditoria (medido nas 25 pipelines mais recentes). Sem isso nao
     * da pra responder a pergunta mais basica depois de um vazamento — "de quem era
     * esse arquivo?" — e a investigacao vira leitura de log de navegador.
     *
     * Aqui cada corte deposita a sua prova: motor/conta que rodou, gen-id que o
     * Google devolveu, md5 do mp4 daquele corte e os carimbos de tempo de cada
     * etapa. Vai INTEIRO pra payloads.dono, tanto no sucesso quanto no fracasso.
     */
    private array $donos = [];

    public function __construct(private int $pipelineId) {}

    public function handle(): void
    {
        if (config('app.tenant') !== 'sellerglobal') {
            Log::info('[SEL-30s] job pulado (tenant errado)', ['tenant' => config('app.tenant')]);
            return;
        }

        $pipeline = AiVideoPipeline::find($this->pipelineId);
        if (! $pipeline) {
            Log::warning('[SEL-30s] pipeline não encontrado', ['id' => $this->pipelineId]);
            return;
        }

        // Mesmo gate de assinatura do fluxo curto — chamar a API direto não contorna.
        if (\App\Services\Ai\VideoAccessGuard::barrarPipeline($this->pipelineId, 'studio')) {
            return;
        }

        if ($pipeline->dry_run) {
            $pipeline->update(['step' => 'done', 'output_url' => 'https://example.com/dry-run-long.mp4']);
            // SEL-videoready (10/08): mesmo gap do StudioGenerationJob — o caminho
            // dry_run pulava a notificacao. Idempotente/fail-open (Notifier cuida).
            \App\Services\VideoReadyNotifier::notify($pipeline->fresh() ?? $pipeline);
            return;
        }

        $p           = $pipeline->payloads ?? [];
        $clipSecs    = (int) ($p['clip_seconds'] ?? config('services.sel30s.clip_seconds', 10));
        $maxSeg      = (int) config('services.sel30s.max_segments', 3);
        $N           = max(1, min((int) ($p['n_segments'] ?? $maxSeg), $maxSeg)); // SEL-FALAREPETIDA: 1 corte é válido
        $ratio       = $p['aspect_ratio'] ?? '9:16';
        $clipPrompts = array_values((array) ($p['clip_prompts'] ?? []));
        $beats       = array_values(array_filter(array_map('trim', (array) ($p['narration_beats'] ?? []))));
        $narration   = trim((string) ($p['narration_text'] ?? ''));
        $seedUrl     = $p['image_url'] ?? null;

        if (empty($clipPrompts)) {
            $clipPrompts = [(string) ($p['prompt'] ?? 'Produto em uso, vídeo vertical 9:16, close no produto, estilo UGC brasileiro.')];
        }
        while (count($clipPrompts) < $N) {
            $clipPrompts[] = $clipPrompts[count($clipPrompts) - 1];
        }

        // SEL-RESILIENCIA (12/08) — a pasta de trabalho e DURAVEL entre tentativas.
        // Antes o `finally` apagava tudo em qualquer saida: um video de 4 cortes que
        // caia no corte 3 refazia os 3 primeiros do zero na tentativa seguinte -- 3x
        // mais tempo de motor ocupado e 3x mais chance de tropecar de novo. Agora so
        // limpa em DESFECHO FINAL (done ou failed de verdade).
        $work = storage_path('app/sel30s/' . $this->pipelineId);
        if (! is_dir($work)) { @mkdir($work, 0755, true); }

        $clipPaths = [];

        try {
            $pipeline->update(['step' => 'render']);

            // Semente única: a foto do produto. Todo shot parte dela -> produto/cena
            // coerentes; o que muda entre clipes é o enquadramento (no prompt).
            $seedPath = $this->downloadSeed($seedUrl, $work . '/seed');

            $reaproveitados = [];
            for ($i = 0; $i < $N; $i++) {
                $clipPath = $work . '/clip' . $i . '.mp4';

                // RETOMADA POR CORTE: corte que JA ficou pronto numa tentativa
                // anterior nao e regerado. O arquivo em disco e a prova -- ele so
                // e escrito depois do render terminar e do mp4 ser validado.
                if (is_file($clipPath) && filesize($clipPath) >= 10000) {
                    $reaproveitados[] = $i + 1;
                    $clipPaths[]      = $clipPath;

                    // ══ SEL-ECOSSISTEMA (12/08) — A PROVA VIAJA COM O CORTE ══════
                    //
                    // Corte reaproveitado foi gerado em OUTRA execucao do job, entao
                    // a prova dele nao esta na memoria deste processo. Sem resgatar,
                    // `exigirProvaDeDono` recusaria a entrega de um video legitimo e
                    // a pipeline entraria em LACO: retenta -> reaproveita -> recusa.
                    // Aqui a gente le de volta o registro que ficou gravado em
                    // payloads.dono na execucao anterior.
                    //
                    // Corte de ANTES desta feature nao tem registro nenhum. Esse caso
                    // e marcado `legado` e o portao o dispensa da exigencia de gen-id
                    // (exigir seria matar todo pedido que ja estava em andamento na
                    // hora do deploy). Fica registrado como legado de proposito: da
                    // pra achar por query depois quais entregas nao tem prova forte.
                    $this->donos[] = $this->provaAnteriorDoCorte($i, $clipPath);
                    $this->carimbarDono();
                    Log::info('[SEL-RESILIENCIA] corte reaproveitado da tentativa anterior', [
                        'pipeline' => $this->pipelineId,
                        'corte'    => $i + 1,
                        'bytes'    => filesize($clipPath),
                    ]);
                    \App\Services\Ai\VideoResilience::registrar('CORTE_REAPROVEITADO', [
                        'pipeline' => $this->pipelineId, 'corte' => $i + 1,
                        'de' => $N, 'bytes' => filesize($clipPath),
                    ]);
                    \App\Services\Ai\VideoResilience::batida(
                        $this->pipelineId,
                        'Retomando de onde parou: corte ' . ($i + 1) . ' de ' . $N . ' ja estava pronto.',
                        ['cortes_prontos' => $reaproveitados]
                    );
                    continue;
                }

                $this->label($pipeline, 'Gravando o corte ' . ($i + 1) . ' de ' . $N . '...');
                $this->generateClip($clipPrompts[$i], $seedPath, $clipSecs, $ratio, $i, $clipPath);
                if (! is_file($clipPath) || filesize($clipPath) < 10000) {
                    throw new \RuntimeException('clip_' . $i . '_vazio');
                }
                $clipPaths[] = $clipPath;

                // NAO PERDER O QUE O MOTOR JA ENTREGOU: registra o corte pronto na
                // hora, antes de qualquer etapa seguinte. Se o processo morrer no
                // corte seguinte, a retomada acha este aqui.
                $reaproveitados[] = $i + 1;
                \App\Services\Ai\VideoResilience::batida(
                    $this->pipelineId,
                    null,
                    ['cortes_prontos' => $reaproveitados, 'cortes_total' => $N]
                );
                \App\Services\Ai\VideoResilience::registrar('CORTE_PRONTO_GUARDADO', [
                    'pipeline' => $this->pipelineId, 'corte' => $i + 1, 'de' => $N,
                    'arquivo' => $clipPath, 'bytes' => @filesize($clipPath),
                ]);
            }

            // ── ÁUDIO: narração contínua montada shot a shot ────────────────────
            // Gera 1 TTS por beat (mesmo timbre), mede a duração e define o tamanho
            // do corte de cada clipe = fala do shot + respiro. Assim o corte de
            // câmera cai na PAUSA da narração, nunca no meio da palavra.
            $this->label($pipeline, 'Montando a narração...');
            $beatAudios = [];
            $lens       = []; // L_i por clipe (segundos)
            $eleven     = app(ElevenLabsService::class);
            $ttsOk      = $eleven->isConfigured() && ! empty($beats);

            if ($ttsOk) {
                for ($i = 0; $i < $N; $i++) {
                    $beat = $beats[min($i, count($beats) - 1)] ?? '';
                    $mp3  = $work . '/beat' . $i . '.mp3';
                    if ($beat === '' || ! $this->tts($beat, $mp3)) { $ttsOk = false; break; }
                    $clipDur = $this->probeDur($clipPaths[$i]);
                    $audDur  = $this->probeDur($mp3);
                    // corte = fala + 0.30s de respiro, limitado ao tamanho do clipe
                    $li = max(2.0, min($clipDur > 0 ? $clipDur : $clipSecs, $audDur + 0.30));
                    $beatAudios[] = $mp3;
                    $lens[]       = $li;
                }
            }

            $silent = $work . '/silent.mp4';
            $final  = $work . '/final.mp4';
            $publishSrc = null;
            $dubbed     = false;

            if ($ttsOk && count($beatAudios) === $N) {
                // vídeo: cada clipe aparado em L_i, cortes na batida
                $this->label($pipeline, 'Editando os cortes na batida da fala...');
                $this->concatVideoTrimmed($clipPaths, $lens, $ratio, $silent);
                // áudio: beats padronizados em L_i e emendados numa faixa contínua
                $voice = $work . '/voice.mp3';
                $this->concatAudioBeats($beatAudios, $lens, $voice);
                if (is_file($silent) && filesize($silent) > 10000 && is_file($voice) && filesize($voice) > 1000) {
                    if ($this->mux($silent, $voice, $final)) {
                        $publishSrc = $final;
                        $dubbed     = true;
                    }
                }
            }

            // ── FALLBACK (TTS/beats indisponíveis): concat cru + narração única ──
            if ($publishSrc === null) {
                Log::info('[SEL-30s] usando fallback (concat sem beats, áudio nativo do Flow)', ['id' => $this->pipelineId]);
                // comAudio=true: sem TTS, quem fala é o áudio nativo do próprio Flow.
                // SEL-FALAREPETIDA (12/08): aqui estava o resto do bug da duracao. O
                // controller ja calculava o tamanho de CADA corte (clip_lens) pra
                // fechar exatamente no que o cliente pediu, mas o fallback ignorava
                // e aparava todo mundo em clip_seconds -> 10s pedidos viravam 16s.
                // Medido no canario 868: dur_real=16 vs dur_pedida=10 (reprovado
                // pelo conferente). Agora respeita clip_lens quando existir.
                $lensFb = array_values(array_map('floatval', (array) ($p['clip_lens'] ?? [])));
                if (count($lensFb) !== $N) {
                    $lensFb = array_fill(0, $N, (float) $clipSecs);
                }
                $lensFb = array_map(fn ($l) => max(1.0, min($l, (float) $clipSecs)), $lensFb);
                Log::info('[SEL-30s] fallback aparando por clip_lens', [
                    'id' => $this->pipelineId, 'lens' => $lensFb, 'total' => array_sum($lensFb),
                ]);
                $this->concatVideoTrimmed($clipPaths, $lensFb, $ratio, $silent, true);
                if (! is_file($silent) || filesize($silent) < 10000) {
                    throw new \RuntimeException('concat_falhou');
                }
                $publishSrc = $silent;
                if ($narration !== '') {
                    $mp3 = $work . '/voz.mp3';
                    if ($eleven->isConfigured() && $this->tts($narration, $mp3) && $this->dub($silent, $mp3, $final)) {
                        $publishSrc = $final;
                        $dubbed     = true;
                    }
                }
            }

            // publica em storage/app/public/videos (mesmo esquema do fluxo curto)
            $publicName = 'long_' . $this->pipelineId . '_' . substr(md5((string) microtime(true)), 0, 8) . '.mp4';
            $publicDir  = storage_path('app/public/videos');
            if (! is_dir($publicDir)) { @mkdir($publicDir, 0755, true); }
            $publicDest = $publicDir . '/' . $publicName;

            // SEL-camuflagem (09/08 Ruan "nada pode vazar"): tira metadata do ffmpeg

            // (encoder=Lavf) do arquivo final costurado. Mesmo bitexact do worker do PC.

            // Fallback: se a limpeza falhar, publica o original (nao trava o video).

            $clean = $work . '/clean_' . $publicName;

            @exec('ffmpeg -y -i ' . escapeshellarg($publishSrc) . ' -map 0 -map_metadata -1 -map_metadata:s -1 -map_chapters -1 -c copy -fflags +bitexact -movflags +faststart ' . escapeshellarg($clean) . ' 2>/dev/null', $co, $cc);

            if ($cc === 0 && is_file($clean) && filesize($clean) > 10000) { $publishSrc = $clean; }

            // SEL-ECOSSISTEMA: o portao de dono roda ANTES de publicar. Nada de
            // copiar pro diretorio publico e so depois descobrir que o arquivo era
            // de outro cliente (era o que o SEL-NAOVAZA fazia — corria depois da
            // copia e tinha que apagar). Sem prova, o arquivo nunca chega a existir
            // na area publica.
            $genIdsDono = $this->exigirProvaDeDono($pipeline);

            if (! @copy($publishSrc, $publicDest)) {
                throw new \RuntimeException('publish_copy_falhou');
            }
            $url = rtrim(env('APP_URL', 'https://api.seller.global'), '/') . '/storage/videos/' . $publicName;

            // ── SEL-NAOVAZA (12/08) — dois clientes NUNCA recebem o mesmo arquivo.
            //
            // O teste em massa de hoje (17 pedidos, 11 motores em paralelo) expos o
            // defeito que o Ruan vinha reclamando ha semanas — "pede um produto e vem
            // outro". O conferente pegou DEPOIS de entregue:
            //     pipe932 (u645):  "arquivo IDENTICO ao entregue na pipeline 927"
            //     pipe933 (u2955): falou o roteiro de OUTRO cliente
            // O robo colhe o video na galeria do Flow; com varios jobs na mesma conta,
            // as vezes ele pega o que nao e dele. Todas as travas de la dependem de
            // reconhecer o video NO NAVEGADOR — e e justamente isso que falha.
            //
            // Esta trava nao depende do navegador: compara a IMPRESSAO DIGITAL do
            // arquivo. Se esse mp4 ja foi entregue a OUTRO cliente, o video e recusado
            // e volta pra fila como falha de infraestrutura (o cliente espera mais um
            // pouco, em vez de receber o video de outra pessoa).
            //
            // Quanto mais paralelo, mais isso importa — e o rumo e ficar mais paralelo.
            $md5 = @md5_file($publicDest) ?: null;
            if ($md5) {
                $dono = \Illuminate\Support\Facades\DB::table('ai_video_pipelines')
                    ->where('id', '<>', $pipeline->id)
                    ->where('user_id', '<>', $pipeline->user_id)
                    ->whereNotNull('output_url')->where('output_url', '<>', '')
                    ->where('payloads', 'like', '%"md5_saida":"' . $md5 . '"%')
                    ->value('id');

                if ($dono) {
                    @unlink($publicDest);
                    Log::error('[SEL-NAOVAZA] arquivo ja pertence a outro cliente — recusado', [
                        'pipeline'      => $pipeline->id,
                        'user'          => $pipeline->user_id,
                        'md5'           => $md5,
                        'ja_entregue_em'=> $dono,
                    ]);
                    // volta pra fila: infra, nao conteudo. O cliente nao perde o pedido.
                    throw new \RuntimeException('video_de_outro_cliente: md5=' . $md5
                        . ' ja entregue na pipeline ' . $dono . ' — recusado para nao vazar');
                }
            }

            $pipeline->refresh();   // SEL-ECOSSISTEMA: pega o payloads.dono carimbado durante os cortes
            $pl = (array) ($pipeline->payloads ?? []);
            if (is_string($pipeline->payloads)) { $pl = json_decode($pipeline->payloads, true) ?: []; }
            $pl['md5_saida'] = $md5;
            // SEL-ECOSSISTEMA: o recibo do pedido. Fica na propria pipeline, legivel
            // por query, sem depender de log de navegador que expira.
            $pl['dono'] = [
                'atualizado_em' => now()->toIso8601String(),
                'entregue_em'   => now()->toIso8601String(),
                'gen_ids'       => $genIdsDono,
                'motores'       => array_values(array_unique(array_filter(
                    array_map(fn ($d) => $d['engine_nome'] ?? null, $this->donos)
                ))),
                'md5_saida'     => $md5,
                // Mesma disciplina do carimbo: o recibo final soma o que este
                // processo fez com o que ja estava registrado (tentativas anteriores
                // do mesmo pedido), em vez de apagar a historia.
                'cortes'        => $this->mesclarCortes($pl['dono']['cortes'] ?? [], $this->donos),
            ];
            $pipeline->update(['step' => 'done', 'output_url' => $url, 'payloads' => $pl]);
            \App\Services\VideoReadyNotifier::notify($pipeline->fresh() ?? $pipeline); // SEL 08/08: push+email video pronto
            $this->registrarNaGaleria($pipeline, $url); // SEL-galeria: linha duravel em ai_generations
            Log::info('[SEL-30s] concluído', [
                'id'     => $this->pipelineId,
                'url'    => $url,
                'clips'  => $N,
                'dubbed' => $dubbed,
            ]);

            \App\Services\Ai\VideoQuotaService::cobrarPosSucesso($pipeline->user_id, $this->pipelineId);
            try {
                \App\Services\Ai\ClientAvatarHarvester::harvestFromPipeline($pipeline);
            } catch (\Throwable $e) {
                // não-fatal
            }
            \App\Services\Ai\VideoResilience::registrar('ENTREGUE', [
                'pipeline' => $this->pipelineId, 'url' => $url, 'cortes' => $N,
                'tentativas_infra' => \App\Services\Ai\VideoResilience::tentativasInfra($this->pipelineId),
            ]);
            // desfecho FINAL feliz -> agora sim pode limpar a pasta de trabalho.
            // SEL-ENTREGUE-NAO-VIRA-FALHA (16/08): a faxina roda em melhor-esforco. Antes
            // ela ficava exposta dentro do try da geracao: se tropecasse, o catch chamava
            // tratarFalha() e o video JA ENTREGUE virava `failed` na cara do cliente
            // (flagrado no #1305). Faxina nao decide desfecho de pedido.
            try {
                $this->limparTrabalho($work);
            } catch (\Throwable $eFaxina) {
                Log::warning('[SEL-ENTREGUE-NAO-VIRA-FALHA] limpeza pos-entrega falhou — o video ja e do cliente, seguindo', [
                    'id'  => $this->pipelineId,
                    'err' => mb_substr($eFaxina->getMessage(), 0, 200),
                ]);
            }

        } catch (\Throwable $e) {
            $this->tratarFalha($pipeline, $e, $work);
        }
    }

    /**
     * SEL-RESILIENCIA (12/08) — O CORACAO DA REGRA: falha de infraestrutura NAO
     * pode custar o video do cliente.
     *
     * Antes, este bloco era uma linha so: `step = failed`. Nao importava se o
     * motivo tinha sido "o Google recusou o prompt" ou "eu reiniciei o pool e
     * fechei o Chrome no meio do render" -- o cliente via o mesmo erro e perdia o
     * video. Medido hoje: 8 falhas de cliente numa hora, TODAS nossas.
     *
     * Agora:
     *   CAPACIDADE -> volta pra fila SEM contar tentativa. Nao ha motor livre;
     *                 isso e lentidao, nao erro.
     *   INFRA      -> volta pra fila contando tentativa (teto 5). Os cortes ja
     *                 prontos ficam no disco e a proxima tentativa retoma deles.
     *   CONTEUDO   -> ai sim falha na hora, com motivo honesto. Repetir nao muda
     *                 a resposta e so faz o cliente esperar mais pelo mesmo nao.
     */
    private function tratarFalha(AiVideoPipeline $pipeline, \Throwable $e, string $work): void
    {
        // ══ SEL-ENTREGUE-NAO-VIRA-FALHA (16/08) — TRAVA DURA ═══════════════════════
        //
        // Se este pedido JA TEM video, ele esta ENTREGUE, e entrega nao volta atras.
        // O erro que chegou aqui aconteceu DEPOIS do cliente ganhar o video (notificacao,
        // galeria, cobranca, faxina) e nao pode custar o video dele.
        //
        // Sem esta trava, o #1305 (primeiro 30s de cliente) foi logado como ENTREGUE as
        // 02:11:14, com o mp4 de 7,98 MB no disco, e mesmo assim o cliente viu "falhou" —
        // porque um tropeco de pos-entrega reescreveu step=failed por cima do done.
        //
        // A trava fica AQUI, na porta de entrada da falha, e nao so no ponto que quebrou:
        // assim ela cobre tambem os caminhos de pos-entrega que ninguem previu ainda.
        try {
            $atual = $pipeline->fresh() ?? $pipeline;
            if (! empty($atual->output_url) || ($atual->step ?? '') === 'done') {
                Log::error('[SEL-ENTREGUE-NAO-VIRA-FALHA] erro DEPOIS da entrega — pedido segue entregue, nao vira falha', [
                    'id'    => $this->pipelineId,
                    'step'  => $atual->step ?? null,
                    'video' => $atual->output_url ?? null,
                    'err'   => mb_substr($e->getMessage(), 0, 300),
                ]);
                \App\Services\Ai\VideoResilience::registrar('ERRO_POS_ENTREGA_IGNORADO', [
                    'pipeline' => $this->pipelineId,
                    'erro'     => mb_substr($e->getMessage(), 0, 200),
                ]);
                try { $this->limparTrabalho($work); } catch (\Throwable $eL) {}

                return;
            }
        } catch (\Throwable $eTrava) {
            // se nem consultar deu, segue o fluxo normal de falha (nao piora nada)
        }

        $erro   = $e->getMessage();
        $tipo   = \App\Services\Ai\VideoResilience::classificar($erro);
        $motivo = \App\Services\Ai\VideoResilience::motivoInfra($erro);

        // CONTEUDO: nao adianta insistir.
        if ($tipo === 'conteudo') {
            Log::error('[SEL-RESILIENCIA] falha de CONTEUDO -> failed (retry nao resolveria)', [
                'id' => $this->pipelineId, 'err' => mb_substr($erro, 0, 300),
            ]);
            \App\Services\Ai\VideoResilience::registrar('FALHA_CONTEUDO_FINAL', [
                'pipeline' => $this->pipelineId, 'erro' => mb_substr($erro, 0, 200),
            ]);
            $pipeline->update([
                'step'          => 'failed',
                'error_message' => \App\Services\Ai\VideoResilience::mensagemCliente($erro),
            ]);
            $this->limparTrabalho($work);
            return;
        }

        // CAPACIDADE: fila, nao falha. Nao conta tentativa.
        if ($tipo === 'capacidade') {
            $pos = \App\Services\Ai\VideoResilience::posicaoNaFila($this->pipelineId);
            $pipeline->update(['step' => 'queued', 'error_message' => null]);
            \App\Services\Ai\VideoResilience::marcarEspera($this->pipelineId, $pos, 0);
            Log::info('[SEL-RESILIENCIA] sem motor livre -> volta pra fila (nao e falha)', [
                'id' => $this->pipelineId, 'posicao' => $pos, 'err' => mb_substr($erro, 0, 160),
            ]);
            \App\Services\Ai\VideoResilience::registrar('FILA_SEM_MOTOR', [
                'pipeline' => $this->pipelineId, 'posicao' => $pos,
                'nota' => 'NAO conta tentativa, NAO consome teto de render',
            ]);
            $this->devolverPraFila(45);
            return;
        }

        // INFRA: culpa nossa. Volta pra fila e tenta em OUTRO motor.
        $n = \App\Services\Ai\VideoResilience::somarTentativaInfra($this->pipelineId, $erro);
        $prontos = count(glob($work . '/clip*.mp4') ?: []);

        if ($n < \App\Services\Ai\VideoResilience::MAX_TENTATIVAS_INFRA) {
            $pipeline->update(['step' => 'queued', 'error_message' => null]);
            \App\Services\Ai\VideoResilience::batida(
                $this->pipelineId,
                $prontos > 0
                    ? 'Tivemos um tropeco aqui. Retomando do corte ' . ($prontos + 1) . ' em outro motor...'
                    : 'Tivemos um tropeco aqui. Ja estamos tentando em outro motor...'
            );
            \App\Services\Ai\VideoResilience::registrar('REENFILEIRADA_POR_INFRA', [
                'pipeline' => $this->pipelineId,
                'motivo' => $motivo,
                'tentativa' => $n . '/' . \App\Services\Ai\VideoResilience::MAX_TENTATIVAS_INFRA,
                'cortes_ja_prontos' => $prontos,
                'erro' => mb_substr($erro, 0, 200),
            ]);
            Log::warning('[SEL-RESILIENCIA] falha de INFRA -> REENFILEIRA (cliente NAO ve erro)', [
                'id'             => $this->pipelineId,
                'motivo'         => $motivo,
                'tentativa'      => $n . '/' . \App\Services\Ai\VideoResilience::MAX_TENTATIVAS_INFRA,
                'cortes_prontos' => $prontos,
                'err'            => mb_substr($erro, 0, 200),
            ]);
            $this->devolverPraFila($this->backoff[min($n - 1, count($this->backoff) - 1)] ?? 60);
            return;
        }

        // esgotou: agora e failed de verdade, com mensagem honesta
        Log::error('[SEL-RESILIENCIA] INFRA esgotou as tentativas -> failed', [
            'id' => $this->pipelineId, 'motivo' => $motivo, 'err' => mb_substr($erro, 0, 300),
        ]);
        $pipeline->update([
            'step'          => 'failed',
            'error_message' => \App\Services\Ai\VideoResilience::mensagemCliente($erro),
        ]);
        $this->limparTrabalho($work);
    }

    /**
     * Ultimo recurso do Laravel: prazo (retryUntil) estourado ou maxExceptions.
     * Sem isto a pipeline ficaria "render" pra sempre e so um reaper a mataria.
     */
    public function failed(\Throwable $e): void
    {
        try {
            $p = AiVideoPipeline::find($this->pipelineId);
            if ($p && ! in_array($p->step, ['done', 'canceled'], true) && empty($p->output_url)) {
                $p->update([
                    'step'          => 'failed',
                    'error_message' => \App\Services\Ai\VideoResilience::mensagemCliente($e->getMessage()),
                ]);
            }
            $this->limparTrabalho(storage_path('app/sel30s/' . $this->pipelineId));
        } catch (\Throwable $x) {
            // nada a fazer aqui
        }
    }

    /**
     * Devolve o job pra fila. `release()` e o caminho normal (job veio da fila);
     * o re-dispatch e a rede pro caso de execucao fora da fila, em que release()
     * seria um no-op silencioso e o pedido ficaria orfao em 'queued'.
     */
    private function devolverPraFila(int $delaySegundos): void
    {
        if ($this->job) {
            $this->release($delaySegundos);
            return;
        }
        static::dispatch($this->pipelineId)
            ->onQueue('video')
            ->delay(now()->addSeconds($delaySegundos));
    }

    /** Limpa a pasta de trabalho (so em desfecho FINAL). */
    private function limparTrabalho(string $work): void
    {
        foreach ((glob($work . '/*') ?: []) as $f) { @unlink($f); }
        @rmdir($work);
    }

    /**
     * SEL-ECOSSISTEMA (12/08) — grava a cadeia de custodia NA HORA.
     *
     * Escreve em `payloads.dono` a cada evento, em vez de so no fim. Motivo medido:
     * quando o job morre no meio (worker morto, SSH caido, deploy), tudo que estava
     * so na memoria do processo se perde — e sao exatamente esses casos que a gente
     * mais precisa auditar. Secundario por construcao: erro aqui nunca derruba a
     * geracao do cliente.
     */
    /** SEL-ECOSSISTEMA: dono do pedido, em cache no processo (o rodizio pergunta por corte). */
    private ?int $userIdCache = null;

    private function userIdDaPipeline(): ?int
    {
        if ($this->userIdCache !== null) { return $this->userIdCache; }
        try {
            $u = \Illuminate\Support\Facades\DB::table('ai_video_pipelines')
                ->where('id', $this->pipelineId)->value('user_id');
            return $this->userIdCache = ($u !== null ? (int) $u : null);
        } catch (\Throwable $e) { return null; }
    }

    /**
     * SEL-ECOSSISTEMA — recupera a prova de um corte gerado em execucao anterior.
     *
     * Procura em payloads.dono.cortes o registro BEM-SUCEDIDO daquele indice. Se
     * achar, devolve ele (com o gen-id original). Se nao achar, devolve um registro
     * `legado` — corte que existia antes desta feature, sem prova possivel.
     */
    private function provaAnteriorDoCorte(int $idx, string $clipPath): array
    {
        try {
            $pl = \Illuminate\Support\Facades\DB::table('ai_video_pipelines')
                ->where('id', $this->pipelineId)->value('payloads');
            $pl = json_decode((string) $pl, true) ?: [];
            foreach (($pl['dono']['cortes'] ?? []) as $c) {
                if ((int) ($c['corte'] ?? -1) === $idx
                    && ($c['resultado'] ?? '') !== 'falhou'
                    && ! empty($c['gen_id'])) {
                    $c['reaproveitado_em'] = now()->toIso8601String();
                    return $c;
                }
            }
        } catch (\Throwable $e) { /* cai no legado */ }

        Log::warning('[SEL-ECOSSISTEMA] corte reaproveitado SEM prova de dono (gerado antes da feature)', [
            'pipeline' => $this->pipelineId, 'corte' => $idx,
        ]);

        return [
            'corte'     => $idx,
            'resultado' => 'reaproveitado',
            'legado'    => true,
            'gen_id'    => null,
            'md5_corte' => @md5_file($clipPath) ?: null,
            'bytes'     => @filesize($clipPath) ?: null,
            'nota'      => 'corte de execucao anterior, sem registro de dono; dispensado do portao de gen-id',
            'fim'       => now()->toIso8601String(),
        ];
    }

    /**
     * SEL-ECOSSISTEMA — junta o que ja estava gravado com o que este processo viu.
     * Nunca descarta linha alheia: duas execucoes do mesmo pipeline (a viva e a
     * zumbi) escrevem no mesmo lugar, e as duas historias importam pra auditoria.
     */
    private function mesclarCortes(array $existentes, array $meus): array
    {
        $chave = fn (array $c) => ($c['corte'] ?? '?') . '|' . ($c['tentativa'] ?? '?') . '|' . ($c['inicio'] ?? '?');

        $out  = [];
        $vist = [];
        foreach (array_merge(is_array($existentes) ? $existentes : [], $meus) as $c) {
            if (! is_array($c)) { continue; }
            $k = $chave($c);
            if (isset($vist[$k])) {
                // Mesma execucao gravando de novo (ex.: corte que falhou e depois
                // foi reaproveitado): fica a versao MAIS COMPLETA, a que tem gen-id.
                if (! empty($c['gen_id']) && empty($out[$vist[$k]]['gen_id'])) { $out[$vist[$k]] = $c; }
                continue;
            }
            $vist[$k] = count($out);
            $out[]    = $c;
        }

        return $out;
    }

    private function carimbarDono(): void
    {
        try {
            $p = \App\Models\AiVideoPipeline::find($this->pipelineId);
            if (! $p) { return; }
            $pl = $p->payloads ?? [];
            if (is_string($pl)) { $pl = json_decode($pl, true) ?: []; }
            // ══ MESCLA, NUNCA SUBSTITUI ═══════════════════════════════════════
            //
            // MEDIDO na p917 (12/08 23:18-23:19): a pipeline foi ENTREGUE as 23:18:31
            // por uma execucao, e 52s depois uma execucao ZUMBI do mesmo job (que
            // seguia tentando outro motor) regravou o bloco com a SUA falha. Resultado:
            // a prova da entrega — o corte que deu certo, com gen-id — sumiu do banco.
            // O registro que existe pra auditar apagou o que precisava auditar.
            //
            // Agora o bloco so CRESCE: le o que ja esta la, acrescenta o que este
            // processo viu e nunca remove nada. Chave = corte+tentativa+inicio, que
            // e unica por execucao (o `inicio` e o instante em que aquele processo
            // pegou o motor).
            $pl['dono'] = [
                'atualizado_em' => now()->toIso8601String(),
                'cortes'        => $this->mesclarCortes($pl['dono']['cortes'] ?? [], $this->donos),
            ] + (array) ($pl['dono'] ?? []);
            \Illuminate\Support\Facades\DB::table('ai_video_pipelines')
                ->where('id', $this->pipelineId)
                ->update(['payloads' => json_encode($pl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
        } catch (\Throwable $e) {
            Log::warning('[SEL-ECOSSISTEMA] nao consegui carimbar o dono', [
                'pipeline' => $this->pipelineId, 'err' => mb_substr($e->getMessage(), 0, 140),
            ]);
        }
    }

    /**
     * SEL-ECOSSISTEMA (12/08) — PORTAO DE ENTREGA: sem prova de dono, nao entrega.
     *
     * O ticket e explicito: "o video entregue tem que ser comprovadamente o daquele
     * pedido — se a prova nao existir, ele NAO e entregue, volta pra fila".
     *
     * Duas checagens, ambas independentes do navegador:
     *
     *  1. PROVA EXISTE? Todo corte precisa ter um `gen_id` (o identificador que o
     *     Google devolveu pra ESTA geracao). Corte sem gen-id significa que o mp4
     *     veio por um caminho que nao sabe provar de quem e — historicamente o
     *     fallback de galeria, origem dos 11 arquivos entregues em duplicidade.
     *
     *  2. A PROVA E NOSSA? O mesmo gen-id nao pode aparecer em pipeline de OUTRO
     *     cliente. Esta e a trava que faltava: o ledger do PC era por pipeline
     *     (usedids\<pipeline>\), entao por construcao ele nunca via vazamento
     *     ENTRE clientes. Provado no PC: o id d6f30754 estava claimado nas pastas
     *     dos pipelines 927 E 932 ao mesmo tempo — dois clientes, um arquivo.
     *
     * Recusa = excecao de INFRA (volta pra fila, cliente nao perde o pedido).
     * Devolve a lista de gen-ids desta pipeline pra ir junto no payloads.
     */
    private function exigirProvaDeDono(\App\Models\AiVideoPipeline $pipeline): array
    {
        $genIds = [];
        $semProva = [];
        $legados = [];
        foreach ($this->donos as $d) {
            if (($d['resultado'] ?? '') === 'falhou') { continue; }
            // Corte gerado antes desta feature: nao ha gen-id pra exigir. Exigir aqui
            // mataria todo pedido que ja estava em andamento no momento do deploy —
            // e pior: entraria em LACO (retenta -> reaproveita o corte -> recusa de
            // novo). Passa, mas fica marcado pra auditoria.
            if (! empty($d['legado'])) { $legados[] = 'corte' . ($d['corte'] ?? '?'); continue; }
            $g = $d['gen_id'] ?? null;
            if ($g) { $genIds[] = $g; } else { $semProva[] = 'corte' . ($d['corte'] ?? '?'); }
        }

        if ($legados !== []) {
            Log::warning('[SEL-ECOSSISTEMA] entrega com corte LEGADO (sem gen-id) — liberada, mas anotada', [
                'pipeline' => $pipeline->id, 'user' => $pipeline->user_id, 'legados' => $legados,
            ]);
        }

        // So exige prova quando ha pelo menos um corte NOVO no pedido. Pedido 100%
        // legado (todos os cortes vindos de antes) segue pela trava de md5.
        if (($genIds === [] && $legados === []) || $semProva !== []) {
            Log::error('[SEL-ECOSSISTEMA] entrega RECUSADA: corte sem prova de dono', [
                'pipeline'  => $pipeline->id,
                'user'      => $pipeline->user_id,
                'sem_prova' => $semProva,
                'com_prova' => count($genIds),
            ]);
            throw new \RuntimeException('sem_prova_de_dono: corte(s) ' . (implode(',', $semProva) ?: 'nenhum')
                . ' sem gen-id do Google — recusado para nao entregar video sem dono comprovado');
        }

        foreach ($genIds as $g) {
            $dono = \Illuminate\Support\Facades\DB::table('ai_video_pipelines')
                ->where('id', '<>', $pipeline->id)
                ->where('user_id', '<>', $pipeline->user_id)
                ->where('payloads', 'like', '%"gen_id":"' . $g . '"%')
                ->value('id');
            if ($dono) {
                Log::error('[SEL-ECOSSISTEMA] entrega RECUSADA: gen-id pertence a outro cliente', [
                    'pipeline' => $pipeline->id, 'user' => $pipeline->user_id,
                    'gen_id'   => $g, 'dono' => $dono,
                ]);
                throw new \RuntimeException('gen_id_de_outro_cliente: ' . $g
                    . ' ja registrado na pipeline ' . $dono . ' — recusado para nao vazar entre clientes');
            }
        }

        return $genIds;
    }

    /**
     * Gera 1 clipe (1 shot) pelo pool de motores (Veo). Reserva o engine ANTES
     * (lock por conta) e libera no finally — mesma disciplina do
     * KlingBrowserGenerateJob, pra dois jobs nunca usarem a mesma conta ao mesmo
     * tempo. Semeado pela foto do produto pra manter o produto coerente.
     */
    private function generateClip(string $prompt, ?string $seedPath, int $secs, string $ratio, int $idx, string $outPath): void
    {
        $pool    = app(AiEnginePool::class);
        $lastErr = null;

        // RESILIENCIA (09/08 item2): ate 3 tentativas de RENDER por clipe, cada uma
        // numa engine potencialmente DIFERENTE. Se o perfil reservado TRAVAR/falhar
        // (ex.: conta deslogou do Flow), aplica COOLDOWN nele (para de re-reservar o
        // perfil ruim) e RETENTA em outra engine SAUDAVEL. Um render que trava NAO
        // gerou mp4 -> retentar nao desperdica credito. So falha o clipe depois de
        // esgotar as tentativas. Isola perfil ruim AUTOMATICO (antes era manual).
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $reserved = $this->reservarEngineComEspera($pool, $idx);
            $engineId = $reserved->id;
            $this->engineReservada = $reserved;
            // SEL-LEASE (12/08): trava de devolucao. Cada caminho que ja devolvia o
            // motor marca aqui; o `finally` cobre TODO o resto (return novo, excecao
            // inesperada, erro no meio do rename). Sem isto, uma saida nao prevista
            // deixava o perfil preso ate o TTL de 600s do lock.
            $devolvido = false;

            // SEL-RESILIENCIA: o RELOGIO DO RENDER so comeca AGORA, com o motor de
            // fato reservado. Antes, o tempo de fila contava como tempo de render --
            // era por isso que quem chegava numa hora cheia estourava o teto dos
            // reapers e recebia "demorou demais" sem nunca ter travado.
            \App\Services\Ai\VideoResilience::marcarInicioDoRender($this->pipelineId);
            // SEL-ECOSSISTEMA: carimbo de tempo por ETAPA (o ticket pede "o carimbo
            // de tempo de cada etapa"). Este e o inicio REAL do render deste corte,
            // ja com o motor na mao — nao conta fila.
            $inicioClip = now()->toIso8601String();

            try {
                $taskId  = 'sel30s-' . $this->pipelineId . '-' . $idx . '-' . substr(md5((string) microtime(true)), 0, 6);
                $payload = [
                    'image_path'   => $seedPath,                  // MacFlowVideoAdapter lê image_path
                    'image_paths'  => $seedPath ? [$seedPath] : [],
                    'prompt'       => mb_substr($prompt, 0, 1800),
                    'duration'     => $secs,
                    'aspect_ratio' => $ratio,
                    // batida durante o render: o adapter carimba a pipeline a cada
                    // avanco de fase do worker. Quem esta renderizando de verdade
                    // aparece VIVO pros reapers; quem pendurou para de carimbar.
                    '_pipeline_id' => $this->pipelineId,
                    '_rotulo_hb'   => 'Gravando o corte ' . ($idx + 1) . '...',
                ];
                // RAIZ A (09/08): renderizar na ENGINE RESERVADA direto -- espelha o
                // caminho provado do KlingBrowserGenerateJob. VideoEnginePool::generate()
                // IGNORAVA a reserva e iterava todas as engines por saldo, fazendo 2 jobs
                // concorrentes gravitarem pro MESMO perfil de maior saldo -> profile_busy
                // no worker do PC. Usando o perfil reservado, cada job concorrente cai num
                // perfil DIFERENTE (o lock Redis passa a proteger o perfil de verdade).
                $engCfg = is_array($reserved->config_json)
                    ? $reserved->config_json
                    : (json_decode($reserved->config_json ?? '{}', true) ?: []);
                if (! empty($engCfg['remote'])) {
                    $adapter = new \App\Services\Ai\MacFlowVideoAdapter($reserved);
                    $decoded = $adapter->generate($taskId, 'image2video', $payload);
                } else {
                    // Engine reservada sem remote (ex.: "Reserva Mac" local) -> caminho antigo.
                    $decoded = app(VideoEnginePool::class)->generate($taskId, 'image2video', $payload);
                }

                $local = $decoded['output_url'] ?? null; // path local /tmp/veo_out_<task>.mp4
                if (! $local || ! is_file($local)) {
                    throw new \RuntimeException('worker_sem_mp4_clip' . $idx);
                }

                // SEL-LEASE (12/08) — FENCING: se a geracao do motor avancou, este
                // processo ficou pendurado, perdeu o lease e outro job assumiu o
                // perfil. Escrever o corte agora seria escrever por cima do dono
                // novo. Vira erro de INFRA -> proxima tentativa em outro motor.
                if (! $pool->leaseNossa($reserved)) {
                    throw new \RuntimeException(
                        'lease_vencido_clip' . $idx . ': outro job assumiu o motor ' . $engineId
                    );
                }

                if (! @rename($local, $outPath) && ! @copy($local, $outPath)) {
                    throw new \RuntimeException('mover_clip_falhou' . $idx);
                }
                @unlink($local);

                // ══ SEL-ECOSSISTEMA (12/08) — PROVA DE DONO, CORTE A CORTE ═══════
                //
                // O worker ja devolvia `video_id` (o gen-id que o Google criou pra
                // ESTA geracao) e `gen_ids`, mas ninguem guardava: morria no array
                // $decoded. Agora vira registro permanente, junto do motor que de
                // fato rodou (`engine_used` — que pode diferir do reservado quando
                // o PC cai no cofre de sessao) e do md5 do corte.
                //
                // `gen_ids_alheios` e a lista de ids que o portao anti-contaminacao
                // do worker BARROU. Quando ela vier preenchida, e prova de que dois
                // jobs se enxergaram no mesmo navegador — o sinal que a gente nao
                // tinha em 12/08 e que custou 11 arquivos entregues em duplicidade.
                $this->donos[] = [
                    'corte'         => $idx,
                    'tentativa'     => $attempt,
                    'engine_id'     => $engineId,
                    'engine_nome'   => $reserved->name ?? null,
                    'conta'         => $engCfg['conta_email'] ?? $engCfg['account_email'] ?? null,
                    'perfil'        => $engCfg['perfil_pc'] ?? $engCfg['profile'] ?? null,
                    'motor_usado'   => $decoded['engine_used'] ?? null,
                    'motor_pedido'  => $decoded['engine_wanted'] ?? null,
                    'motor_modo'    => $decoded['engine_mode'] ?? null,
                    'gen_id'        => $decoded['video_id'] ?? ($decoded['gen_id_own'] ?? null),
                    'gen_ids'       => $decoded['gen_ids'] ?? null,
                    'gen_alheios'   => $decoded['gen_ids_alheios'] ?? [],
                    'prova'         => $decoded['proof'] ?? null,
                    'md5_corte'     => @md5_file($outPath) ?: null,
                    'bytes'         => @filesize($outPath) ?: null,
                    'took_s'        => $decoded['took_s'] ?? null,
                    'inicio'        => $inicioClip,
                    'fim'           => now()->toIso8601String(),
                ];
                $this->carimbarDono();

                $pool->releaseEngine($reserved, true); // sucesso: libera lock + conta geracao
                $devolvido = true;
                $this->engineReservada = null;
                return;

            } catch (\Throwable $e) {
                $lastErr = $e->getMessage();

                // SEL-ECOSSISTEMA: o fracasso tambem entra na cadeia de custodia.
                // "Nada pode acontecer em silencio" vale principalmente pro que deu
                // errado: e a linha que diz QUAL motor derrubou QUAL pedido.
                $this->donos[] = [
                    'corte'       => $idx,
                    'tentativa'   => $attempt,
                    'engine_id'   => $engineId,
                    'engine_nome' => $reserved->name ?? null,
                    'resultado'   => 'falhou',
                    'erro'        => mb_substr($lastErr, 0, 200),
                    'classe'      => \App\Services\Ai\VideoResilience::classificar($lastErr),
                    'inicio'      => $inicioClip,
                    'fim'         => now()->toIso8601String(),
                ];
                $this->carimbarDono();

                // CONTEUDO nao melhora trocando de motor: sai na hora pro
                // tratarFalha() decidir (e ele falha limpo, sem queimar 3 contas).
                if (\App\Services\Ai\VideoResilience::classificar($lastErr) === 'conteudo') {
                    try { $pool->releaseEngine($reserved, false); $devolvido = true; } catch (\Throwable $x) {}
                    $this->engineReservada = null;
                    throw $e;
                }
                // item2: perfil travou/falhou -> COOLDOWN (tira da fila por N min +
                // derruba success_rate) pra o proximo attempt/job NAO re-reservar o
                // mesmo perfil ruim, e libera o lock. So ENTAO retenta em outra engine.
                // SEL-LEASE (12/08) — O MOTOR NAO PAGA PELO ZUMBI. `lease_vencido`
                // significa que ESTE processo chegou atrasado, nao que a conta
                // esta ruim: ela ja foi reservada por outro job e provavelmente
                // esta gerando agora. Cooldown de 10min aqui tiraria uma conta boa
                // de uma frota de 5 vivas por um erro de relogio nosso.
                if (! str_contains($lastErr, 'lease_vencido')) {
                    try { $reserved->recordFailure('clip' . $idx . ' render: ' . mb_substr($lastErr, 0, 200), 10); } catch (\Throwable $x) {}
                } else {
                    AiEnginePool::diario('ZUMBI_SEM_CASTIGO', [
                        'engine_id' => $engineId,
                        'pipeline'  => $this->pipelineId,
                        'nota'      => 'lease vencido e culpa nossa; motor segue no pool sem cooldown',
                    ]);
                }
                try { $pool->releaseEngine($reserved, false); $devolvido = true; } catch (\Throwable $x) {}
                $this->engineReservada = null;
                \App\Services\Ai\VideoResilience::registrar('TROCA_DE_MOTOR', [
                    'pipeline' => $this->pipelineId, 'corte' => $idx + 1,
                    'tentativa' => $attempt,
                    'motor_que_falhou' => $reserved->name ?? $engineId,
                    'motivo' => \App\Services\Ai\VideoResilience::motivoInfra($lastErr),
                    'erro' => mb_substr($lastErr, 0, 160),
                ]);
                Log::warning('[SEL-30s] render do clipe falhou -> cooldown na engine + retry em outra', [
                    'pipeline' => $this->pipelineId,
                    'clip'     => $idx,
                    'attempt'  => $attempt,
                    'engine'   => $reserved->name ?? $engineId,
                    'err'      => mb_substr($lastErr, 0, 160),
                ]);
                // segue pro proximo attempt -> reserva OUTRA engine (a ruim ja em cooldown)
            } finally {
                // SEL-LEASE (12/08) — rede de seguranca: qualquer saida que nao tenha
                // devolvido o motor devolve AQUI, na hora, em vez de deixar o perfil
                // preso ate o TTL. Idempotente: se ja devolveu, nao faz nada.
                if (! $devolvido) {
                    try {
                        $pool->releaseEngine($reserved, false);
                        Log::warning('[SEL-LEASE][SEL-30s] motor devolvido no finally (saida sem release explicito)', [
                            'pipeline' => $this->pipelineId, 'clip' => $idx, 'engine_id' => $engineId,
                        ]);
                    } catch (\Throwable $x) {
                        Log::error('[SEL-LEASE][SEL-30s] falhei em devolver o motor no finally', [
                            'pipeline' => $this->pipelineId, 'engine_id' => $engineId,
                            'err' => mb_substr($x->getMessage(), 0, 160),
                        ]);
                    }
                    $this->engineReservada = null;
                }
            }
        }

        throw new \RuntimeException('clip' . $idx . '_falhou_apos_retries: ' . mb_substr((string) $lastErr, 0, 150));
    }

    /**
     * Reserva uma engine de video; se TODAS estiverem ocupadas, espera com
     * paciencia (RAIZ B: retry ~18s por ~9min) antes de desistir. Transforma
     * "capacidade cheia" em LENTO, nunca ERRO -- sem release() do job (nao
     * re-consome credito de clipe ja gerado).
     */
    private function reservarEngineComEspera(AiEnginePool $pool, int $idx): AiEngine
    {
        // ══ SEL-ECOSSISTEMA (12/08) — A VEZ DO CLIENTE VEM ANTES DA VEZ DO MOTOR ══
        //
        // Antes desta linha, quem chegasse primeiro na fila levava motor, ponto. Com
        // FIFO isso significa que um cliente com 7 pedidos ocupa 7 motores e empurra
        // todo mundo pra tras (medido hoje: u1233 com 20 pedidos e u592 com 17, de 73
        // no total). Aqui o pedido so vai disputar motor se o cliente ainda estiver
        // dentro da fatia dele da frota. Ver VideoRodizio: a cota se recalcula a cada
        // pedido e nunca e menor que 1.
        //
        // Quando NAO e a vez: o pedido volta pra fila como CAPACIDADE (nao e falha,
        // nao conta tentativa, nao segura slot de worker) — e o motor que vagar vai
        // pra quem ainda nao tem nenhum.
        $vez = \App\Services\Ai\VideoRodizio::vez((int) ($this->userIdDaPipeline() ?? 0), $this->pipelineId);
        if (! ($vez['permitido'] ?? true)) {
            \App\Services\Ai\VideoResilience::registrar('REVEZAMENTO_ESPEROU', $vez);
            Log::info('[SEL-ECOSSISTEMA][rodizio] cliente no limite da cota — pedido volta pra fila', $vez);
            \App\Services\Ai\VideoResilience::marcarEspera(
                $this->pipelineId,
                \App\Services\Ai\VideoResilience::posicaoNaFila($this->pipelineId),
                0
            );
            throw new \RuntimeException('all_engines_unavailable_clip' . $idx
                . ': revezamento — o cliente ja esta com ' . ($vez['meus'] ?? '?') . ' de ' . ($vez['cota'] ?? '?')
                . ' motores da cota dele (' . ($vez['clientes'] ?? '?') . ' clientes na roda, '
                . ($vez['motores'] ?? '?') . ' motores uteis); a vaga vai pra quem nao tem nenhum');
        }

        try {
            return $pool->reserveEngine('video', 'image2video');
        } catch (\Throwable $e) {
            // SEL-ENGRENAGEM (12/08) — ESPERAR NAO E TRAVAR, e o vigia precisa saber.
            //
            // Este laco esperava ate 9 minutos MUDO: nada tocava a pipeline. Pros
            // reapers isso e indistinguivel de render pendurado -- o render-hung-heal
            // olha `updated_at < agora-10min` e o sentinela-unico olha `render` parado
            // >20min. Resultado medido hoje: pipelines que estavam so na fila de
            // capacidade (870/871/872/874/876/878/879) levaram "A geracao demorou
            // demais" na cara do cliente, entre 35 e 58 min de idade, sem NUNCA terem
            // travado de verdade. u2942 e u645 perderam video assim.
            //
            // Agora cada volta do laco carimba a pipeline (payloads.step_label +
            // updated_at). Quem esta na fila aparece como VIVO e nao e ceifado; quem
            // travou de verdade para de carimbar e continua sendo ceifado igual. O
            // cliente ainda ganha um rotulo honesto ("Aguardando um motor livre") em
            // vez de barra parada.
            //
            // Teto: 36 voltas x 15s = 9 min, o MESMO teto de antes (30x18s) -- a
            // espera nao ficou mais longa, so ficou visivel e mais granular.
            // SEL-RESILIENCIA (12/08) — FILA HONESTA NO LUGAR DE MORTE POR TIMEOUT.
            //
            // O laco antigo esperava ate 9 MINUTOS segurando um slot de worker e,
            // no fim, LEVANTAVA ERRO -- com `$tries=1` isso virava `failed` na cara
            // de quem so teve o azar de chegar numa hora cheia (5 motores, fila de
            // 20). Medido hoje: 870/871/872/874/876/878/879 morreram exatamente
            // assim, sem nunca terem travado.
            //
            // Agora a espera e CURTA e o job VOLTA PRA FILA (release) em vez de
            // segurar o slot: 90s de cortesia (a maioria das reservas libera nesse
            // tempo, render medio de 1 corte = 56-135s) e, se nao vier motor, a
            // excecao de CAPACIDADE sobe pro tratarFalha(), que reenfileira SEM
            // contar tentativa e SEM tocar no teto de render. Enquanto espera, o
            // cliente ve posicao na fila em vez de barra parada.
            $espera = 0;
            for ($t = 0; $t < 6; $t++) {
                \App\Services\Ai\VideoResilience::marcarEspera(
                    $this->pipelineId,
                    \App\Services\Ai\VideoResilience::posicaoNaFila($this->pipelineId),
                    $espera
                );
                sleep(15);
                $espera += 15;
                try {
                    return $pool->reserveEngine('video', 'image2video');
                } catch (\Throwable $ee) {
                    $e = $ee;
                }
            }

            Log::info('[SEL-RESILIENCIA] sem motor livre apos ' . $espera . 's -> devolve pra fila (NAO e falha)', [
                'pipeline' => $this->pipelineId,
                'clip'     => $idx,
                'motivo'   => mb_substr($e->getMessage(), 0, 200),
            ]);

            // prefixo `all_engines_unavailable` -> classificado como CAPACIDADE
            throw new \RuntimeException('all_engines_unavailable_clip' . $idx . ': ' . mb_substr($e->getMessage(), 0, 120));
        }
    }

    private function downloadSeed(?string $url, string $destNoExt): ?string
    {
        if (! $url) { return null; }
        $ext = strtolower((string) pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) { $ext = 'jpg'; }
        $dest = $destNoExt . '.' . $ext;
        $bin  = @file_get_contents($url);
        if ($bin === false || strlen($bin) < 500) {
            throw new \RuntimeException('seed_download_falhou');
        }
        file_put_contents($dest, $bin);
        return $dest;
    }

    private function probeDur(string $file): float
    {
        $s = [];
        @exec('ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 '
            . escapeshellarg($file), $s);
        return (float) trim($s[0] ?? '0');
    }

    /**
     * Concatena os clipes aparando cada um em L_i, normalizando pra 1080x1920 h264.
     * O trim faz o corte cair no fim da fala do shot.
     *
     * SEL-audionativo (12/08, teste 30s saiu MUDO): este concat nasceu mudo porque
     * o plano era dublar por TTS. Sem API externa (regra do Ruan: zero API), o
     * fallback publicava justamente esse arquivo mudo. Agora $comAudio=true leva
     * junto o áudio NATIVO que o Flow já entrega em cada clipe — mesmo trim, mesmo
     * corte, só que com a voz. O caminho dublado continua chamando com false.
     */
    /**
     * SEL-CORTEFALA (12/08) — acha o melhor ponto pra cortar um clipe SEM decepar
     * a fala. O corte era cego (trim=duration=X no relogio) e por isso 15 de 15
     * ressalvas do conferente em 24h eram "fala cortada no final": se a pessoa
     * ainda estava falando no instante X, a palavra era partida no meio.
     *
     * Aqui a gente pergunta ao ffmpeg ONDE tem silencio e corta na pausa mais
     * proxima do alvo, dentro de uma janela de tolerancia. Sem silencio utilizavel,
     * devolve o alvo original — fail-open, nunca piora o que ja funcionava.
     */
    private function pontoDeCorteSeguro(string $clipe, float $alvo, float $tolerancia = 1.2): float
    {
        try {
            $cmd = 'ffmpeg -i ' . escapeshellarg($clipe)
                 . ' -af silencedetect=noise=-38dB:d=0.18 -f null - 2>&1';
            $saida = (string) shell_exec($cmd);
            if ($saida === '') { return $alvo; }

            // inicios de silencio: sao os pontos onde da pra cortar sem cortar voz
            preg_match_all('/silence_start:\s*([\d.]+)/', $saida, $m);
            $pausas = array_map('floatval', $m[1] ?? []);
            if (! $pausas) { return $alvo; }

            $melhor = null; $menorDist = PHP_FLOAT_MAX;
            foreach ($pausas as $t) {
                if ($t < 1.0) { continue; }                    // silencio de abertura nao serve
                $d = abs($t - $alvo);
                if ($d <= $tolerancia && $d < $menorDist) { $menorDist = $d; $melhor = $t; }
            }
            if ($melhor === null) { return $alvo; }

            // + 0,25s de respiro pra nao cortar colado no fim da palavra
            $escolhido = round($melhor + 0.25, 2);
            Log::info('[SEL-CORTEFALA] corte movido pra pausa', [
                'clipe' => basename($clipe), 'alvo' => $alvo, 'escolhido' => $escolhido,
            ]);
            return $escolhido;
        } catch (\Throwable $e) {
            return $alvo;
        }
    }

    private function concatVideoTrimmed(array $clips, array $lens, string $ratio, string $out, bool $comAudio = false): void
    {
        $inputs  = [];
        $filters = [];
        $labels  = '';
        foreach (array_values($clips) as $i => $c) {
            $li        = max(1.0, (float) ($lens[$i] ?? 10.0));
            // SEL-CORTEFALA: so faz sentido caçar a pausa quando o audio e do
            // proprio clipe (comAudio). No caminho dublado a faixa e outra.
            if ($comAudio) { $li = max(1.0, $this->pontoDeCorteSeguro($c, $li)); }
            $inputs[]  = '-i ' . escapeshellarg($c);
            $filters[] = "[{$i}:v]trim=duration=" . sprintf('%.3f', $li) . ",setpts=PTS-STARTPTS,"
                       . "scale=1080:1920:force_original_aspect_ratio=increase,"
                       . "crop=1080:1920,setsar=1,fps=24[v{$i}]";
            $labels   .= "[v{$i}]";
            if ($comAudio) {
                // clipe sem faixa de áudio não pode faltar no concat (desalinha
                // tudo): anullsrc garante silêncio do mesmo tamanho.
                $filters[] = "[{$i}:a]atrim=duration=" . sprintf('%.3f', $li) . ",asetpts=PTS-STARTPTS,"
                           . "aresample=48000,apad=whole_dur=" . sprintf('%.3f', $li) . ",atrim=duration="
                           . sprintf('%.3f', $li) . "[a{$i}]";
                $labels  .= "[a{$i}]";
            }
        }
        $n  = count($clips);
        $fc = implode(';', $filters) . ';' . $labels
            . ($comAudio ? "concat=n={$n}:v=1:a=1[outv][outa]" : "concat=n={$n}:v=1:a=0[outv]");

        $cmd = 'ffmpeg -y ' . implode(' ', $inputs)
             . ' -filter_complex ' . escapeshellarg($fc)
             . ' -map "[outv]"' . ($comAudio ? ' -map "[outa]" -c:a aac -b:a 128k' : '')
             . ' -c:v libx264 -pix_fmt yuv420p -movflags +faststart '
             . escapeshellarg($out) . ' 2>/dev/null';
        @exec($cmd, $o, $c);
        if ($c !== 0 && $comAudio) {
            // algum clipe veio sem faixa de áudio e derrubou o filtro: refaz mudo
            // (melhor entregar sem voz do que não entregar).
            Log::warning('[SEL-30s] concat com áudio nativo falhou, refazendo mudo', ['code' => $c]);
            $this->concatVideoTrimmed($clips, $lens, $ratio, $out, false);
            return;
        }
        if ($c !== 0) {
            Log::warning('[SEL-30s] concat vídeo code!=0', ['code' => $c]);
        }
    }

    /**
     * Emenda os áudios dos beats numa faixa contínua, cada beat padronizado em L_i
     * (fala + silêncio de respiro), pra casar exatamente com os cortes do vídeo.
     */
    private function concatAudioBeats(array $beatAudios, array $lens, string $out): void
    {
        $inputs  = [];
        $filters = [];
        $labels  = '';
        foreach (array_values($beatAudios) as $i => $a) {
            $li        = max(1.0, (float) ($lens[$i] ?? 10.0));
            $inputs[]  = '-i ' . escapeshellarg($a);
            // apad+atrim -> o beat ocupa EXATAMENTE L_i (fala + silêncio até o corte)
            $filters[] = "[{$i}:a]aresample=44100,apad,atrim=duration=" . sprintf('%.3f', $li)
                       . ",asetpts=PTS-STARTPTS[a{$i}]";
            $labels   .= "[a{$i}]";
        }
        $n  = count($beatAudios);
        $fc = implode(';', $filters) . ';' . $labels . "concat=n={$n}:v=0:a=1[outa]";

        $cmd = 'ffmpeg -y ' . implode(' ', $inputs)
             . ' -filter_complex ' . escapeshellarg($fc)
             . ' -map "[outa]" -c:a libmp3lame -q:a 4 '
             . escapeshellarg($out) . ' 2>/dev/null';
        @exec($cmd, $o, $c);
        if ($c !== 0) {
            Log::warning('[SEL-30s] concat áudio code!=0', ['code' => $c]);
        }
    }

    /** Junta o vídeo mudo (já do tamanho certo) com a faixa de narração contínua. */
    private function mux(string $video, string $audio, string $out): bool
    {
        $cmd = sprintf(
            'ffmpeg -y -i %s -i %s -map 0:v -map 1:a -c:v copy -c:a aac -af apad -shortest %s 2>/dev/null',
            escapeshellarg($video),
            escapeshellarg($audio),
            escapeshellarg($out)
        );
        @exec($cmd, $o, $c);
        return $c === 0 && is_file($out) && filesize($out) > 10000;
    }

    /** UMA narração contínua (ElevenLabs, voz PT-BR padrão). Retorna true se saiu. */
    private function tts(string $text, string $mp3): bool
    {
        try {
            $eleven = app(ElevenLabsService::class);
            if (! $eleven->isConfigured()) { return false; }
            $b64 = $eleven->tts($text)['audio_base64'] ?? null;
            if (! $b64) { return false; }
            file_put_contents($mp3, base64_decode($b64));
            return is_file($mp3) && filesize($mp3) > 1000;
        } catch (\Throwable $e) {
            Log::warning('[SEL-30s] tts falhou', ['err' => mb_substr($e->getMessage(), 0, 150)]);
            return false;
        }
    }

    /**
     * Fallback: dubla uma narração única por cima do vídeo mudo. Se a voz passar do
     * vídeo, congela o último frame até a fala terminar (a fala NUNCA corta).
     */
    private function dub(string $silentVideo, string $mp3, string $out): bool
    {
        $vd = $this->probeDur($silentVideo);
        $ad = $this->probeDur($mp3);

        $vargs = '-c:v copy';
        if ($ad > 0 && $vd > 0 && ($ad + 0.5) > $vd) {
            $extra = ($ad + 0.5) - $vd;
            $vargs = sprintf('-vf tpad=stop_mode=clone:stop_duration=%.2f', $extra);
        }

        $cmd = sprintf(
            'ffmpeg -y -i %s -i %s -map 0:v -map 1:a %s -c:a aac -af apad -shortest %s 2>/dev/null',
            escapeshellarg($silentVideo),
            escapeshellarg($mp3),
            $vargs,
            escapeshellarg($out)
        );
        @exec($cmd, $o, $c);
        return $c === 0 && is_file($out) && filesize($out) > 10000;
    }

    private function registrarNaGaleria(AiVideoPipeline $pipeline, string $outputUrl): void
    {
        try {
            if ($outputUrl === '') { return; }
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
                'provider_model'   => $p['veo_model'] ?? ($p['quality_tier'] ?? 'padrao'),
                'provider_task_id' => $taskId,
                'wizard_payload'   => [
                    'product_name' => $p['product_name'] ?? ($p['produto_nome'] ?? null),
                    'price'        => $p['price'] ?? ($p['produto_preco'] ?? null),
                    'style'        => $p['estilo'] ?? null,
                    'duration'     => $p['duration'] ?? null,
                    '_source'      => 'studio_long',
                    '_pipeline_id' => $pipeline->id,
                ],
                'final_prompt'     => mb_substr((string) ($p['narration_text'] ?? ($p['prompt'] ?? '')), 0, 2000) ?: null,
                'status'           => 'succeeded',
                'output_url'       => $outputUrl,
                'credits_debited'  => 0,
                'cost_usd'         => 0,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[SEL-galeria] falha ao registrar galeria duravel (long)', ['pid' => $pipeline->id, 'err' => $e->getMessage()]);
        }
    }

    private function label(AiVideoPipeline $pipeline, string $label): void
    {
        try {
            // `hb` junto: rotulo novo tambem e prova de vida pros reapers.
            $pipeline->update(['payloads' => array_merge($pipeline->payloads ?? [], [
                'step_label' => $label,
                'hb'         => time(),
            ])]);
        } catch (\Throwable $e) {
            // não-fatal
        }
    }
}
