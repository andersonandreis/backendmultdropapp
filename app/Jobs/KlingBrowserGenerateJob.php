<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use App\Services\Ai\VideoEnginePool;  // SEL-425: pool multi-motor
use App\Services\Ai\AiEnginePool;    // SEL-456: reserveEngine/releaseEngine

/**
 * SEL-381 -- Job que roda o worker Node (Playwright) pra gerar 1 video Kling via navegador.
 * Atualiza estado no Cache (chave kling_browser:{taskId}) que KlingBrowserService::getVideoTask le.
 *
 * SEL-456: quando VIDEO_ENGINE=veo, chama AiEnginePool::reserveEngine() ANTES de gerar
 * para garantir que dois jobs nunca usem o mesmo perfil DICloak simultaneamente.
 * Lock Redis TTL=600s; released no finally independente de sucesso/falha.
 */
class KlingBrowserGenerateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // SEL-antiwedge (09/08 Ruan "arruma de uma vez"): timeout CURTO. Um render de
    // 10s leva 3-8min; se passar de 10min esta PENDURADO (Chrome travou no server
    // lotado). Matar rapido e retentar em OUTRO motor >> pendurar 30min travando o
    // worker e a fila inteira. Era 1800 (30min) = a fonte do travamento em cadeia.
    // SEL-LEASE (12/08) — 600 -> 1200, e o motivo NAO e "deixar pendurar mais".
    //
    // Este numero e o SIGALRM do queue:work: quando ele estoura, o processo morre
    // sem rodar `finally` -- lock do perfil e reserved_count ficam vazados (36
    // reservas orfas so no dia 12/08). Ele nunca deveria ser quem corta; quem
    // corta e o `$corteTentativaS` la embaixo, que levanta excecao DENTRO do try,
    // devolve o motor e retenta em outra conta.
    //
    // Com o gratis cortando em 900s (a fila do Lower Priority leva 400-500s), um
    // teto de 600 aqui mataria o job ANTES do corte gracioso -- o pior dos dois
    // mundos: perde o video E vaza o motor. 1200 poe o SIGALRM de volta no papel
    // de ultimo recurso. E o que segura render pendurado de verdade continua sendo
    // o corte por PROGRESSO do adapter (sem saida 120s / sem progresso 300s), que
    // e mais rapido e mais preciso que qualquer relogio de duracao.
    //
    // Rede de seguranca nova: se o SIGALRM acontecer mesmo assim, o lease vence em
    // 90s e o zelador devolve o motor -- nao os 600s de antes.
    public int $timeout = 1200;
    public int $tries   = 3;   // 3 tentativas (rotaciona motor a cada falha)
    public array $backoff = [20, 45]; // retenta rapido em outro motor (nao 15-30min)

    /**
     * SEL-RESILIENCIA (12/08) — teto por PRAZO, nao por contagem.
     *
     * Com `$tries=3` puro, tres tropecos NOSSOS seguidos (pool reiniciado, tunel
     * caido, perfil ocupado) matavam o pedido. Como o retryUntil tem precedencia
     * sobre o $tries no Laravel, o job passa a poder rotacionar motor por ate 90
     * minutos. O que impede laco infinito e a CLASSIFICACAO: erro de conteudo
     * (prompt recusado, conta sem permissao) nao retenta nem uma vez.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(90);
    }

    // SEL-ptbr-global (08/08 Ruan "portugues padrao global geral"): trava de idioma
    // aplicada a TODO prompt que sai pro Flow/Veo, no chokepoint do worker. Fica no
    // TOPO do prompt (o modelo prioriza o inicio) pra NENHUM video sair em espanhol/
    // ingles, mesmo que o prompt de origem venha errado ou sem fala escrita.
    private const PTBR_LOCK = "REGRA ABSOLUTA E INEGOCIAVEL DE IDIOMA: TODA voz, narracao, "
        . "fala e audio falado deste video e OBRIGATORIAMENTE em PORTUGUES DO BRASIL (pt-BR), "
        . "com sotaque brasileiro natural do sudeste (Sao Paulo/Rio de Janeiro). E "
        . "TERMINANTEMENTE PROIBIDO falar em espanhol (espanol), ingles ou portugues de "
        . "Portugal. Se houver qualquer pessoa falando ou qualquer narracao, ela e 100% em "
        . "portugues do Brasil, nunca em outro idioma.";

    // SEL-antilogo (09/08, Ruan): a foto de referencia as vezes tem marca d'agua /
    // logo de app de IA / selo de marketplace -> o Veo copia isso pro video. Proibe.
    private const ANTI_LOGO = "REGRA VISUAL OBRIGATORIA: a imagem de referencia pode conter "
        . "marca dagua, logotipo de aplicativo de IA, texto sobreposto ou selo de marketplace. "
        . "E TERMINANTEMENTE PROIBIDO reproduzir QUALQUER logo, marca dagua, texto, selo ou marca "
        . "de IA no video. Renderize APENAS o produto limpo, sem nenhum logotipo, texto ou marca "
        . "por cima. O video final NAO tem nenhuma marca dagua nem logotipo de nada.";

    public function __construct(
        public string $taskId,
        public string $kind, // image2video | text2video
        public array $payload,
    ) {}

    public function handle(): void
    {
        $this->setState(['task_status' => 'processing', 'updated_at' => now()->toIso8601String()]);

        $imagePaths  = [];
        $videoPath   = null;
        $reservedEngineId = null; // SEL-456: ID do engine reservado (null = nao-veo path)

        try {
            // SEL-468: baixava UMA imagem (`payload['image']`), porque o worker so
            // dirigia telas de uma foto. A cena com referencias manda de 2 a 7 --
            // entao o download virou lista. `image_path` (singular) continua sendo
            // enviado pro worker quando e uma so, pra nao quebrar quem ja usa.
            $imagePaths = $this->baixaImagens();

            // SEL-485: o Motion Control e a primeira tela que precisa de um VIDEO
            // alem da foto. Ele desce separado porque vai num campo diferente da
            // tela -- misturar com as fotos faria o worker subir o video no slot
            // da imagem.
            if ($this->kind === 'motion_control') {
                $videoPath = $this->baixaVideoReferencia();
            }

            $workerInput = [
                'image_path'  => $imagePaths[0] ?? null,
                'image_paths' => $imagePaths,
                // SEL-485: nome generico, porque nas variantes do Omni esta lista
                // carrega video junto com foto. `image_paths` continua indo pra
                // nao quebrar nada que ja le esse campo.
                'file_paths'  => $imagePaths,
                // SEL-ptbr-global: trava pt-BR no TOPO de todo prompt (chokepoint global)
                'prompt'     => self::PTBR_LOCK . "\n\n" . self::ANTI_LOGO . "\n\n" . ($this->payload['prompt'] ?? ''),
                'duration'   => max(10, (int) ($this->payload['duration'] ?? 10)), // SEL 08/08 Ruan: piso 10s (nunca menos)
                'aspect_ratio' => $this->payload['aspect_ratio'] ?? '9:16',
                'model_name' => $this->payload['model_name'] ?? 'kling-v2-6',
                'external_task_id' => $this->taskId,
                'kind' => $this->kind, // SEL-381: text2video roteia pra /app/text-to-video/new sem upload
                'mode' => $this->kind,
                // SEL-468: o worker escolhe a tela pelo descritor `tool`.
                'tool' => $this->kind,
            ];
            if (!empty($this->payload['resolution'])) {
                $workerInput['resolution'] = $this->payload['resolution'];
            }
            // SEL-485: campos exclusivos do Motion Control.
            if ($videoPath !== null) {
                $workerInput['video_path'] = $videoPath;
                // A tela so aceita "Matches Video" hoje (medido 31/07: a outra
                // opcao vem `disabled` mesmo com os dois arquivos no lugar), mas
                // o campo ja viaja pra nao precisar mexer no Job quando liberar.
                $workerInput['orientacao'] = $this->payload['orientacao'] ?? 'video';
            }
            if (isset($this->payload['native_audio'])) {
                $workerInput['native_audio'] = (bool) $this->payload['native_audio'];
            }
            // SEL-468: ENSAIO. Percorre o caminho inteiro (baixa as fotos, abre a
            // tela, sobe, configura, confere, escreve o roteiro) e para um passo
            // antes do Generate. E como validar a automacao sem consumir o plano
            // -- o clique e a unica linha que gasta.
            if (!empty($this->payload['dry_run'])) {
                $workerInput['dry_run'] = true;
            }

            // SEL-425: POOL multi-motor com fallback em cadeia (DICloak -> Mac -> ...).
            // Quando VIDEO_ENGINE=veo, o VideoEnginePool seleciona engines por priority
            // com circuit-breaker (cooldown_until), garante lock Redis por perfil DICloak
            // e faz retry automatico no proximo motor em caso de falha.
            // Quando VIDEO_ENGINE=kling (default), mantem o comportamento original: Process
            // direto com generate_video.js (sem passar pelo pool).
            $engine = config('services.video_engine', 'kling');
            $isVeo  = ($engine === 'veo');

            if ($isVeo) {
                // SEL-456: reservar engine ANTES de tentar gerar.
                // Garante que 2 jobs nunca usem o mesmo perfil DICloak simultaneamente.
                // Se todas as engines estiverem locked/quota_full, pipeline fica queued_wait
                // e o job e reagendado em 60 minutos (reset diario do Google Flow ~00h UTC).
                $pool = app(AiEnginePool::class);
                try {
                    $reservedEngine   = $pool->reserveEngine('video', $this->kind);
                    $reservedEngineId = $reservedEngine->id;
                    Log::info('[SEL-456][KlingBrowserJob] engine reservado', [
                        'task_id'   => $this->taskId,
                        'engine_id' => $reservedEngineId,
                        'engine'    => $reservedEngine->name,
                    ]);
                } catch (\RuntimeException $e) {
                    // FIX_DELAY_CURTO_11AGO: distinguir TRANSIENTE (todas as
                    // engines ocupadas AGORA, liberam em ~3min) de QUOTA diaria real.
                    // Antes reagendava SEMPRE +60min -> sob carga normal todo job se
                    // jogava 1h pro futuro e a fila inteira travava (bug recorrente).
                    $msg   = $e->getMessage();
                    $quota = stripos($msg, 'quota') !== false;
                    // SEL-velocidade item 1 (12/08): FILA POR TEMPO DE ESPERA, nao por
                    // ordem de chegada. ANTES: delay ALEATORIO 60-150s + volta pra MESMA
                    // fila. Como a fila `database` do Laravel puxa por `id ASC`, o job
                    // re-despachado ganha id MAIOR que todo pedido que chegou nesse meio
                    // tempo e cai ATRAS deles -- um pedido azarado perdia a corrida vez
                    // apos vez (medido 12/08 em 7 dias: retries=0 entrega em 7,7min;
                    // retries=1 em 45,5min; retries=2 em 449,5min).
                    // AGORA, duas alavancas que so favorecem o MAIS ANTIGO:
                    //   (a) delay MONOTONICO DECRESCENTE com a espera -> quem espera ha
                    //       mais tempo volta a ficar disponivel ANTES do recem-chegado;
                    //   (b) passados 5min de espera o job sobe pra `kling-browser-priority`,
                    //       que o worker sellerapp-video-render drena ANTES da fila normal.
                    // `_wait_since` viaja no payload (mesma convencao de `_priority`/
                    // `_trial`/`_external_source`) e marca o INICIO da espera, nao a
                    // ultima tentativa -- por isso a prioridade so cresce.
                    $waitSince = (int) ($this->payload['_wait_since'] ?? time());
                    $waited    = max(0, time() - $waitSince);
                    $this->payload['_wait_since'] = $waitSince;

                    // 150s pra quem acabou de chegar -> 15s pra quem ja espera >=270s.
                    $delaySec = (int) max(15, min(150, 150 - intdiv($waited, 2)));

                    // Promocao SO a partir da fila do cliente pagante. NUNCA promove
                    // `external-video-low` (Tokfy / trial do /convite): a garantia do Ruan
                    // e que pedido externo jamais fura a fila do seller.global. Quem ja
                    // esta em `kling-browser-priority` ou `video-ruan` fica onde esta.
                    $filaAtual = $this->queue ?? 'default';
                    $fila      = ($waited >= 300 && $filaAtual === 'kling-browser')
                        ? 'kling-browser-priority'
                        : $filaAtual;

                    $this->setState([
                        'task_status'     => 'queued_wait',
                        'task_status_msg' => 'engines_indisponiveis(' . ($quota ? 'quota' : 'ocupado') . '): ' . mb_substr($msg, 0, 180),
                        'retry_after'     => now()->addSeconds($delaySec)->toIso8601String(),
                        'espera_s'        => $waited,
                        'updated_at'      => now()->toIso8601String(),
                    ]);
                    Log::warning('[SEL-velocidade][KlingBrowserJob] sem engine, reagendando por TEMPO DE ESPERA', [
                        'task_id' => $this->taskId, 'delay_s' => $delaySec, 'espera_s' => $waited,
                        'fila_de' => $filaAtual, 'fila_para' => $fila, 'quota' => $quota,
                        'reason' => mb_substr($msg, 0, 180),
                    ]);
                    static::dispatch($this->taskId, $this->kind, $this->payload)
                        ->delay(now()->addSeconds($delaySec))
                        ->onQueue($fila);
                    return; // Job atual encerra sem retry automatico
                }

                // SEL-pc-fase2 (09/08): se o motor RESERVADO tem config `remote`, o
                // render roda NO PC do Ruan (via MacFlowVideoAdapter -> SSH). Antes ia
                // pro VideoEnginePool que renderizava LOCAL no servidor lotado. Sem
                // `remote`, mantem o caminho antigo (fallback). Mesmo schema de $decoded.
                $engCfg = is_array($reservedEngine->config_json)
                    ? $reservedEngine->config_json
                    : (json_decode($reservedEngine->config_json ?? '{}', true) ?: []);
                // SEL-velocidade item 3 (12/08): CORTE AGRESSIVO POR TENTATIVA no
                // formato NORMAL (1 clipe). O teto do adapter era 720s -- MAIOR que o
                // $timeout=600s deste job, entao quem matava a tentativa pendurada era
                // sempre o SIGALRM do queue:work. Kill por SIGALRM NAO roda o finally:
                // o lock Redis do perfil e o `reserved_count` do engine ficam presos, a
                // conta parece "quota_full" e a fila inteira passa a bater em
                // `engines_indisponiveis` (medido em ai_engine_usage 10/08: engine 25 com
                // generated=8 e reserved=31 vazados).
                // Com 420s o corte volta pra DENTRO do try/catch: excecao limpa ->
                // recordFailure (cooldown na conta) -> releaseEngine -> retry imediato em
                // OUTRA conta do Flow, que e exatamente o retry que ja existia.
                // Base do numero (7 dias, 12/08): 118/118 normais saudaveis entregaram em
                // ate 11,2min PONTA-A-PONTA (96/118 em ate 3min) e o render real do Flow
                // leva 56-135s -> 420s e ~3-7x de folga sobre o render real.
                // O fluxo LONGO nao passa por aqui (StudioLongVideoJob chama o adapter
                // direto) e por isso continua com o teto historico de 720s.
                $durNormal = (int) ($this->payload['duration'] ?? 10);
                $ehNormal  = $durNormal <= 12
                    && empty($this->payload['long_video'])
                    && empty($this->payload['_long']);

                // SEL-LEASE (12/08) — O CORTE PRECISA CABER NO MODO GRATIS.
                //
                // Os 420s vieram de 7 dias de medicao do modelo PAGO (render real
                // 56-135s) -- e ali eram 3-7x de folga. No "Lite [Lower Priority]"
                // a fila do Google e outra: a geracao leva 400-500s. Ou seja, o
                // mesmo numero que era folga no pago vira GUILHOTINA no gratis,
                // cortando o render bem em cima da entrega. E o gratis e o caminho
                // que passa a sustentar o volume (o credito pago nao paga a conta).
                //
                // O proprio adapter ja sabia disso (teto interno de 1500s no
                // gratis); quem apertava era este job, sobrescrevendo com 420.
                // Agora o teto acompanha o modelo. Continua sendo um corte
                // GRACIOSO (excecao dentro do try -> finally roda -> motor volta),
                // e por isso tem que ficar bem abaixo do $timeout do job.
                $modeloAqui = (string) ($this->payload['veo_model']
                    ?? ($engCfg['veo_model'] ?? config('services.veo.model') ?? ''));
                /**
                 * SEL-GRATIS-TETO-CERTO (15/08) — o que decide o teto e o que VAI RODAR,
                 * nao o texto do campo.
                 *
                 * Regressao minha: com o SEL-TUDO-NO-GRATIS o `veo_model` passou a vir
                 * NULO. O nome ficou vazio, "lower priority" nao aparecia, e o job
                 * concluia "pago" -> cortava em 420s. Mas quem rodava era o gratis (o
                 * adapter cai no MODELO_PADRAO "[Lower Priority]"), que leva 400-500s de
                 * fila. Resultado: veo_hard_timeout_420s em cliente, 2 videos perdidos
                 * na primeira hora.
                 *
                 * Campo vazio nao significa "pago": significa "ninguem escolheu" — e sem
                 * escolha o que roda e o gratis. E se a chave global do gratis estiver
                 * ligada, entao e gratis por definicao, sem depender de string nenhuma.
                 */
                $ehGratis   = config('services.veo.tudo_no_gratis', true)
                    || trim($modeloAqui) === ''
                    || stripos($modeloAqui, 'lower priority') !== false;

                // Pago segue EXATAMENTE como estava (420 normal / 720 longo): o
                // numero dele foi medido e funciona. So o gratis muda.
                $corteTentativaS = $ehGratis ? 900 : ($ehNormal ? 420 : 720);

                // SEL-RESGATE-NAO-APERTA-O-PRAZO (15/08) — o resgate nao pode ENCURTAR
                // o prazo de quem ele esta salvando.
                //
                // Achado no 1192 (aemdcar): subir pro modelo pago tira o "lower priority"
                // do nome, $ehGratis vira false, e o teto CAI de 900s pra 420s. O pedido
                // que ja tinha falhado 2x por ser lento passou a ter MENOS tempo. Morreu
                // na 5a tentativa com "passou do tempo" e veo_stage=processing — o Google
                // ainda renderizando quando a gente cortou.
                //
                // Os 420s continuam valendo pro fluxo saudavel (118/118 em ate 11,2min,
                // render real de 56-135s). Aqui so entra quem JA penou: 3a tentativa em
                // diante ou resgate manual. Esses ficam com o teto generoso.
                $jaPenou = ((int) ($this->payload['tentativa'] ?? 1)) >= 3
                    || ! empty($this->payload['resgate_manual']);

                // SEL-JAPENOU-LE-O-PEDIDO (15/08): `resgate_manual` mora no PEDIDO, nao
                // no payload do worker — eu so tinha feito viajar `veo_model` e
                // `pipeline_id`. Sem esta consulta, um pedido resgatado a mao voltaria a
                // ser cortado em 420s e morreria no mesmo lugar de sempre.
                // `pipeline_id` chega de verdade (conferido no job das 12:35), entao da
                // pra perguntar direto pro pedido quem ele e.
                if (! $jaPenou && ! empty($this->payload['pipeline_id'])) {
                    try {
                        $bruto = \Illuminate\Support\Facades\DB::table('ai_video_pipelines')
                            ->where('id', $this->payload['pipeline_id'])->value('payloads');
                        $doPedido = is_array($bruto) ? $bruto : (json_decode((string) $bruto, true) ?: []);
                        $jaPenou = ! empty($doPedido['resgate_manual'])
                            || ((int) ($doPedido['retries'] ?? 0)) >= 2;
                    } catch (\Throwable $e) {
                        // pedido ilegivel nao pode derrubar geracao: segue com o teto normal
                    }
                }
                if ($jaPenou && $corteTentativaS < 900) {
                    Log::error('[SEL-RESGATE-NAO-APERTA-O-PRAZO] pedido ja penou — mantendo o teto generoso', [
                        'task_id' => $this->taskId,
                        'de'      => $corteTentativaS,
                        'para'    => 900,
                    ]);
                    $corteTentativaS = 900;
                }

                if (!empty($engCfg['remote'])) {
                    $adapter = new \App\Services\Ai\MacFlowVideoAdapter($reservedEngine);
                    $decoded = $adapter->generate(
                        $this->taskId,
                        $this->kind,
                        array_merge($this->payload, [
                            'image_paths'    => $imagePaths,
                            'image_path'     => $imagePaths[0] ?? null,
                            'hard_timeout_s' => $corteTentativaS,
                            // SEL-RESGATE-CONTADOR-VIAJA (15/08): o adapter decide o
                            // resgate pela 3a tentativa, e este e o UNICO contador de
                            // falhas que existe neste caminho (a coluna `retries` do
                            // pipeline nunca e tocada aqui — medido: 146/150 em zero).
                            // Sem esta linha o resgate nasce morto.
                            'tentativa'      => $this->attempts(),
                        ])
                    );
                } else {
                    $decoded = app(VideoEnginePool::class)->generate(
                        $this->taskId,
                        $this->kind,
                        array_merge($this->payload, [
                            'image_paths'    => $imagePaths,
                            'hard_timeout_s' => $corteTentativaS,
                        ])
                    );
                }

                // Cleanup arquivos temp (o pool usa os paths mas nao faz unlink)
                foreach ($imagePaths as $p) { if (is_file($p)) @unlink($p); }
                if ($videoPath !== null && is_file($videoPath)) { @unlink($videoPath); }

            } else {
                // Comportamento original Kling: Process direto
                $workerJs  = env('KLING_BROWSER_WORKER_JS', '/home/api.seller.global/browser-worker/generate_video.js');
                $workerDir = env('KLING_BROWSER_WORKER_DIR', '/home/api.seller.global/browser-worker');

                $cmd = [
                    'xvfb-run', '-a', '--server-args=-screen 0 1440x900x24',
                    'node',
                    $workerJs,
                ];
                $procEnv = [
                    'PLAYWRIGHT_BROWSERS_PATH' => env('PLAYWRIGHT_BROWSERS_PATH', '/opt/ms-playwright'),
                    'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
                ];
                $proc = new Process($cmd, $workerDir, $procEnv);
                $proc->setTimeout(900);
                $proc->setInput(json_encode($workerInput));
                $proc->run();

                // Cleanup arquivos temp
                foreach ($imagePaths as $p) { if (is_file($p)) @unlink($p); }
                if ($videoPath !== null && is_file($videoPath)) { @unlink($videoPath); }

                if (!$proc->isSuccessful()) {
                    $err = trim($proc->getErrorOutput() ?: $proc->getOutput());
                    throw new \RuntimeException('worker_failed:' . mb_substr($err, 0, 400));
                }

                // Worker imprime logs em stderr E o JSON final em stdout. xvfb-run pode
                // embaralhar linhas -- extrair APENAS a ultima linha que comeca com "{"
                $stdout = trim($proc->getOutput());
                $lines = preg_split('/\r?\n/', $stdout);
                $jsonLine = '';
                for ($i = count($lines) - 1; $i >= 0; $i--) {
                    $line = trim($lines[$i]);
                    if (strlen($line) > 0 && $line[0] === '{' && substr($line, -1) === '}') {
                        $jsonLine = $line;
                        break;
                    }
                }
                $decoded = $jsonLine ? json_decode($jsonLine, true) : null;
                if (!$decoded || empty($decoded['ok'])) {
                    throw new \RuntimeException('worker_bad_response:' . mb_substr($stdout, 0, 400));
                }
            }

            // SEL-LEASE (12/08) — FENCING antes de escrever qualquer coisa.
            //
            // Cenario real: este processo ficou pendurado, o lease dele venceu, o
            // zelador devolveu o motor e OUTRO job pegou o mesmo perfil. Se agora eu
            // gravar meu resultado, eu escrevo por cima de uma sessao que ja nao e
            // minha -- e o cliente do outro job recebe/perde video por causa disso.
            // O numero de geracao resolve: geracao avancou = eu sou zumbi, saio pelo
            // caminho de INFRA (retry em outro motor), sem tocar no estado.
            if ($reservedEngineId !== null && ! app(AiEnginePool::class)->leaseNossa($reservedEngine)) {
                throw new \RuntimeException(
                    'lease_vencido: outro job assumiu o motor ' . $reservedEngineId
                    . ' enquanto este render corria; resultado descartado pra nao atropelar'
                );
            }

            // SEL-456: liberar engine reservado (sucesso)
            if ($reservedEngineId !== null) {
                app(AiEnginePool::class)->releaseEngine($reservedEngine, success: true);
                $reservedEngineId = null; // evitar double-release no finally
            }

            // SEL-468: ensaio nao gerou video -- nao pode se passar por 'succeed'
            // (quem faz poll ficaria esperando uma URL que nunca vem).
            if (!empty($decoded['dry_run'])) {
                $this->setState([
                    'task_status'     => 'dry_run_ok',
                    'task_status_msg' => 'ensaio: tela montada e conferida, Generate NAO clicado',
                    'ensaio'          => $decoded,
                    'updated_at'      => now()->toIso8601String(),
                ]);
                Log::info('[SEL-468] ensaio concluido sem consumir plano', [
                    'task_id'    => $this->taskId,
                    'ferramenta' => $this->kind,
                    'resultado'  => $decoded,
                ]);
                return;
            }

            // AUDIT#2 -- mover MP4 do path privado do worker pro storage publico
            // e retornar URL HTTP (nao path filesystem) pro downstream (cliente/pipeline/lipsync)
            $localPath = $decoded['output_url'] ?? null;
            $publicUrl = $localPath;
            if ($localPath && is_file($localPath)) {
                $filename = "seller-global-" . substr(hash("sha256", $localPath . $this->taskId), 0, 14) . ".mp4"; // SEL 08/08 Ruan: NUNCA nome veo/google no download - marca nossa
                $publicDest = storage_path('app/public/videos/' . $filename);
                if (!is_dir(dirname($publicDest))) @mkdir(dirname($publicDest), 0755, true);
                if (@rename($localPath, $publicDest) || @copy($localPath, $publicDest)) {
                    @unlink($localPath);
                    // SEL (08/08 Ruan): deixa a duracao NATURAL do Flow (~8s), sem
                    // esticar. Video mais curto porem coerente (fala normal, sem freeze
                    // nem slow-mo). Duracao maior = juntar clipes (SEL-30s / StudioLongVideoJob).
                    // SEL (08/08 Ruan): "limpar TODOS sempre". Remove QUALQUER
                    // metadado da empresa externa do arquivo (encoder=Google etc)
                    // -> o cliente nunca ve marca de fora. Remux -c copy (rapido,
                    // sem perda) + -bitexact pra nao reescrever encoder. Fail-open.
                    try {
                        // SEL-9x16 (09/08): alem de tirar a marca externa (metadado),
                        // GARANTE saida 9:16 retrato (1080x1920). Se o motor entregou
                        // paisagem/quadrado, forca CROP CENTRAL -> nunca pad preto,
                        // nunca trecho deitado. Se ja e 9:16, mantem o remux -c copy
                        // rapido (mesmo comportamento do brandstrip anterior). Fail-open.
                        $probe = [];
                        @exec('ffprobe -v error -select_streams v:0 -show_entries stream=width,height -of csv=p=0:s=x '
                            . escapeshellarg($publicDest), $probe);
                        [$vw, $vh] = array_pad(array_map('intval', explode('x', trim($probe[0] ?? '0x0'))), 2, 0);
                        // SEL-9X16-RESPEITA-O-PEDIDO (15/08): o guarda de 09/08 forcava TODO
                        // video pra 9:16. Fazia sentido quando paisagem so podia ser erro do
                        // motor. Agora que o Ruan liberou paisagem, ele DESFAZIA a escolha do
                        // cliente: o worker gerava certo ("formato: pedido=16:9 clicou=true")
                        // e a gente cortava pra retrato aqui, calado. O cliente so descobriria
                        // abrindo o video. O guarda continua — mas medindo contra o que foi
                        // PEDIDO, nao contra um formato unico chumbado.
                        $formatoPedido = (string) ($this->payload['aspect_ratio']
                            ?? $this->payload['aspect']
                            ?? '9:16');
                        $querPaisagem = str_replace(' ', '', $formatoPedido) === '16:9';
                        $proporcaoAlvo = $querPaisagem ? (16 / 9) : (9 / 16);
                        $ehRetrato = $vh > 0 && abs(($vw / $vh) - $proporcaoAlvo) <= 0.02;
                        $clean = $publicDest . '.brand.mp4';
                        if ($ehRetrato) {
                            // SEL-MARCA-NO-VIDEO (15/08): antes era só remux `-c copy` com
                            // `-map_metadata -1` — ou seja, limpava METADADO e nunca olhava
                            // o PIXEL. MEDIDO no video 1161: a palavra "Veo" escrita no canto
                            // inferior direito, nos 4 quadros extraidos. Em 3 videos de
                            // cliente do mesmo minuto ela nao aparecia; nao descobri por que
                            // so em alguns — e justamente por isso a faixa sai de TODOS.
                            // "As vezes vaza" e o mesmo risco que "sempre vaza": a gente so
                            // descobre quando o cliente ja publicou com a marca de outro.
                            // Custa 7% da altura e compra a garantia. Mesmo remedio das
                            // fotos de referencia (SEL-MARCA-NAO-VAZA), mesmo motivo.
                            $cmdBrand = 'ffmpeg -y -i ' . escapeshellarg($publicDest)
                                . ' -vf ' . escapeshellarg('crop=iw:ih*0.93:0:0,scale=' . $vw . ':' . $vh . ':flags=lanczos,setsar=1')
                                . ' -c:v libx264 -preset veryfast -crf 20 -pix_fmt yuv420p -c:a copy'
                                . ' -map_metadata -1 -movflags +faststart '
                                . escapeshellarg($clean) . ' 2>&1';
                        } else {
                            // SEL-9X16-RESPEITA-O-PEDIDO: normaliza pro formato PEDIDO, nao
                            // pro retrato sempre. Chegar aqui agora significa "o motor entregou
                            // diferente do que o cliente escolheu" — que e o caso que o guarda
                            // sempre quis cobrir.
                            $lAlvo = $querPaisagem ? 1920 : 1080;
                            $aAlvo = $querPaisagem ? 1080 : 1920;
                            Log::warning('[SEL-9x16] saida diferente do pedido — normalizando por crop', [
                                'task' => $this->taskId, 'veio' => $vw . 'x' . $vh,
                                'pedido' => $formatoPedido, 'vai_virar' => $lAlvo . 'x' . $aAlvo,
                            ]);
                            $cmdBrand = 'ffmpeg -y -i ' . escapeshellarg($publicDest)
                                . ' -vf "crop=iw:ih*0.93:0:0,scale=' . $lAlvo . ':' . $aAlvo . ':force_original_aspect_ratio=increase,crop=' . $lAlvo . ':' . $aAlvo . ',setsar=1" '
                                . ' -c:v libx264 -pix_fmt yuv420p -c:a copy -map_metadata -1 -movflags +faststart '
                                . escapeshellarg($clean) . ' 2>&1';
                        }
                        @shell_exec($cmdBrand);
                        if (is_file($clean) && filesize($clean) > 10000) {
                            @rename($clean, $publicDest);
                        } else { @unlink($clean); }
                    } catch (\Throwable $e) { Log::warning('KlingBrowser brandstrip/9x16 falhou', ['err' => $e->getMessage()]); }
                    // URL final servida por LSWS via symlink public/storage
                    $publicUrl = rtrim(env('APP_URL', 'https://api.seller.global'), '/') . '/storage/videos/' . $filename;
                }
            }

            // SEL-MUDO-NA-MARRA (14/08) — MEDIDO no video do Ruan: mesmo com o prompt
            // proibindo falar, o arquivo saiu com trilha AAC a -16,4 dB (silencio de
            // verdade fica perto de -90 dB). Pedir silencio ao motor e torcida; aqui a
            // gente GARANTE. Quando o cliente marcou "sem som", a trilha de audio e
            // REMOVIDA do mp4 — deterministico, sem depender de o modelo obedecer.
            // O prompt continua proibindo mexer a boca, senao sai gente falando mudo.
            if (! empty($this->payload['sem_som']) && ! empty($publicDest) && is_file($publicDest)) {
                try {
                    $mudo = $publicDest . '.mudo.mp4';
                    @shell_exec('ffmpeg -y -i ' . escapeshellarg($publicDest)
                        . ' -c copy -an ' . escapeshellarg($mudo) . ' 2>&1');
                    if (is_file($mudo) && filesize($mudo) > 10000) {
                        @rename($mudo, $publicDest);
                        Log::info('[SEL-MUDO] trilha de audio removida a pedido do cliente', [
                            'task' => $this->taskId, 'arquivo' => basename($publicDest),
                        ]);
                    } else {
                        @unlink($mudo);
                        Log::warning('[SEL-MUDO] nao consegui remover o audio', ['task' => $this->taskId]);
                    }
                } catch (\Throwable $e) {
                    Log::warning('[SEL-MUDO] falha ao remover audio', ['err' => $e->getMessage()]);
                }
            }

            // ══ SEL-VOZ-NOSSA-NO-VIDEO (16/08) ═══════════════════════════════════
            //
            // O cliente escolheu "so narracao": o motor ja entregou (e o bloco acima ja
            // arrancou qualquer trilha), agora a NOSSA voz le o roteiro por cima. Voz
            // local (Piper em /opt/voz, pt-BR), sem chave, sem custo e sem mandar o texto
            // do cliente pra fora.
            //
            // ORDEM: tirar o audio do motor ANTES, por a nossa DEPOIS — senao ficam duas
            // vozes no mesmo video.
            //
            // GUARDA: se qualquer etapa falhar, o cliente fica com o video exatamente como
            // estava. Extra de pos-entrega nunca pode piorar video pronto (licao do #1305,
            // em que uma etapa de pos-entrega transformou um video entregue em "falhou").
            if (! empty($this->payload['narracao'])
                && ! empty($this->payload['narracao_texto'])
                && ! empty($publicDest) && is_file($publicDest)) {
                try {
                    $voz = app(\App\Services\Ai\NarracaoLocalService::class);
                    if ($voz->disponivel()) {
                        $mp3 = $voz->falar((string) $this->payload['narracao_texto']);
                        if ($mp3) {
                            $narrado = $voz->porNoVideo($publicDest, $mp3, $publicDest . '.narrado.mp4');
                            if ($narrado && is_file($narrado) && filesize($narrado) > 100000) {
                                @rename($narrado, $publicDest);
                                Log::error('[SEL-VOZ-NOSSA] narracao aplicada no video do cliente', [
                                    'task'    => $this->taskId,
                                    'arquivo' => basename($publicDest),
                                    'chars'   => mb_strlen((string) $this->payload['narracao_texto']),
                                ]);
                            } else {
                                @unlink((string) $narrado);
                                Log::error('[SEL-VOZ-NOSSA] nao consegui juntar — entregando o video sem narracao', [
                                    'task' => $this->taskId,
                                ]);
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    Log::error('[SEL-VOZ-NOSSA] falhou — o video segue entregue, so sem narracao', [
                        'task' => $this->taskId, 'err' => mb_substr($e->getMessage(), 0, 200),
                    ]);
                }
            }

            $this->setState([
                'task_status'  => 'succeed',
                'output_url'   => $publicUrl,               // agora URL HTTP publica
                'output_local' => $publicDest ?? $localPath, // path pra cleanup futuro
                'source_url'   => $decoded['source_url'] ?? null, // URL CDN Kling original
                'file_bytes'   => $decoded['file_bytes'] ?? null,
                'took_s'       => $decoded['took_s'] ?? null,
                'finished_at'  => now()->toIso8601String(),
                'updated_at'   => now()->toIso8601String(),
            ]);

            Log::info('KlingBrowser succeeded', ['task_id' => $this->taskId, 'took' => $decoded['took_s'] ?? null]);

            // SEL-416 F2: o worker ja mede o saldo antes/depois (patch no generate_video.js),
            // mas ninguem do lado PHP lia os campos -- o numero era impresso e jogado fora.
            // Sem isto, "quantos creditos custa um video" continua sem resposta e o preco
            // ao cliente sai de estimativa. Grava o custo MEDIDO de cada geracao.
            //
            // Nao derruba a geracao se falhar: medir e secundario, o video ja esta pronto.
            try {
                $antes  = $decoded['saldo_antes']  ?? null;
                $depois = $decoded['saldo_depois'] ?? null;
                $custo  = ($antes !== null && $depois !== null) ? ((int) $antes - (int) $depois) : null;

                Log::info('[SEL-416] custo medido da geracao', [
                    'task_id' => $this->taskId,
                    'saldo_antes' => $antes, 'saldo_depois' => $depois,
                    'custo_creditos' => $custo,
                    'modelo' => $this->payload['model_name'] ?? null,
                    'duracao_s' => $this->payload['duration'] ?? null,
                ]);

                \Illuminate\Support\Facades\DB::table('video_generation_events')->insert([
                    'pipeline_used'       => mb_substr($this->kind, 0, 50),
                    'product_category'    => 'nao_informada',
                    'cost_credits'        => $custo,
                    'kling_variant'       => mb_substr((string) ($this->payload['model_name'] ?? ''), 0, 30) ?: null,
                    'generation_time_sec' => isset($decoded['took_s']) ? (int) $decoded['took_s'] : null,
                    'director_reason'     => 'task=' . $this->taskId . ' saldo=' . var_export($antes, true) . '->' . var_export($depois, true),
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('[SEL-416] falha ao registrar custo da geracao', ['erro' => $e->getMessage()]);
            }
        } catch (\Throwable $e) {
            foreach ($imagePaths as $p) { if (is_file($p)) @unlink($p); }
            if ($videoPath !== null && is_file($videoPath)) { @unlink($videoPath); }

            // SEL-456: liberar engine reservado em caso de falha (nao incrementa generated_count)
            if ($reservedEngineId !== null) {
                // AUTO-CURA (Fase 1 item 2) — FECHA O FURO. O caminho reserve/release
                // liberava o lock mas NUNCA punha o motor em COOLDOWN: o retry (tries=3,
                // backoff 20/45s) re-reservava o MESMO motor morto (ex.: PC fora) e
                // queimava as 3 tentativas nele em vez de rotacionar. Espelha o
                // generateClip do StudioLongVideoJob: recordFailure (cooldown 10min +
                // derruba a saude 24h) ANTES do release -> o retry cai noutro motor OU no
                // motor LOCAL de reserva. Combinado com o host-probe (item 3), a falha de
                // PC fora sai da fila na hora. Fail-open: nunca atrapalha o release.
                try {
                    // SEL-LEASE (12/08) — O MOTOR NAO PAGA PELO ZUMBI.
                    //
                    // Quando a falha e `lease_vencido`, quem errou fui EU: este
                    // processo pendurou, perdeu o lease e chegou atrasado. O motor
                    // esta saudavel -- inclusive ja foi reservado por outro job e
                    // provavelmente esta gerando AGORA. Poe-lo em cooldown de 10min
                    // tiraria de circulacao uma conta boa numa frota de 5 vivas
                    // (e so uma com credito de verdade): a gente puniria a capacidade
                    // que ainda temos por causa de um erro nosso de relogio.
                    $culpaDoMotor = ! str_contains($e->getMessage(), 'lease_vencido');

                    if ($culpaDoMotor && isset($reservedEngine) && is_object($reservedEngine)) {
                        $reservedEngine->recordFailure(
                            $this->kind . ' render: ' . mb_substr($e->getMessage(), 0, 200),
                            10
                        );
                    } elseif (! $culpaDoMotor) {
                        AiEnginePool::diario('ZUMBI_SEM_CASTIGO', [
                            'engine_id' => $reservedEngineId,
                            'task_id'   => $this->taskId,
                            'nota'      => 'lease vencido e culpa nossa; motor segue no pool sem cooldown',
                        ]);
                    }
                } catch (\Throwable $rfErr) {
                    Log::warning('[AUTO-CURA][KlingBrowserJob] recordFailure falhou no catch', [
                        'engine_id' => $reservedEngineId,
                        'err'       => $rfErr->getMessage(),
                    ]);
                }
                try {
                    app(AiEnginePool::class)->releaseEngine($reservedEngine ?? $reservedEngineId, success: false);
                } catch (\Throwable $releaseErr) {
                    Log::warning('[SEL-456][KlingBrowserJob] falha ao liberar engine no catch', [
                        'engine_id' => $reservedEngineId,
                        'err'       => $releaseErr->getMessage(),
                    ]);
                }
                $reservedEngineId = null;
            }

            // SEL-RESILIENCIA (12/08) — DOIS TIPOS DE ERRO, DOIS DESTINOS.
            // Ate aqui tudo virava `failed` igual e subia pro retry igual. Agora:
            //  - CONTEUDO  -> failed DEFINITIVO, sem retry (o motor ja disse nao;
            //                 repetir 3x so faz o cliente esperar 3x pelo mesmo nao).
            //  - INFRA/CAP -> estado TRANSITORIO (`retrying`), a pipeline nao ve
            //                 failure e o job sobe a excecao pra rotacionar motor.
            $tipo = \App\Services\Ai\VideoResilience::classificar($e->getMessage());

            if ($tipo === 'conteudo') {
                $this->setState([
                    'task_status'     => 'failed',
                    'task_status_msg' => mb_substr($this->kind . ': ' . $e->getMessage(), 0, 400),
                    'erro_tipo'       => 'conteudo',
                    'updated_at'      => now()->toIso8601String(),
                ]);
                Log::error('[SEL-RESILIENCIA] KlingBrowser falhou por CONTEUDO (sem retry)', [
                    'task_id' => $this->taskId, 'ferramenta' => $this->kind, 'err' => $e->getMessage(),
                ]);
                return; // NAO relanca: retry nao mudaria a resposta
            }

            $this->setState([
                // `retrying` (nao `failed`): quem faz poll entende que ainda estamos
                // trabalhando. So o ultimo estouro do prazo vira failed de verdade.
                'task_status'      => 'retrying',
                // SEL-468: o motivo vai inteiro pro estado E pro log, com a
                // ferramenta junto. "Erro na geracao, tente novamente" nao diz
                // onde travou e obriga alguem a reproduzir pra descobrir.
                'task_status_msg'  => mb_substr($this->kind . ': ' . $e->getMessage(), 0, 400),
                'erro_tipo'        => $tipo,
                'erro_infra'       => \App\Services\Ai\VideoResilience::motivoInfra($e->getMessage()),
                'tentativa'        => $this->attempts(),
                'updated_at'       => now()->toIso8601String(),
            ]);
            /**
             * SEL-SEM-CREDITO-CAI-PRO-GRATIS (15/08): o Flow recusou por SALDO, nao por
             * defeito nosso. Insistir no mesmo modelo pago em outro motor so repete o
             * "sem credito" ate o prazo estourar — foi o que deixou um cliente PAGANTE
             * sem video enquanto o gratis entregava ao lado. Marca o pedido pra proxima
             * tentativa ir no gratis, que entrega.
             */
            if (stripos($e->getMessage(), 'veo_sem_credito') !== false
                && ! empty($this->payload['pipeline_id'])) {
                try {
                    $pid = $this->payload['pipeline_id'];
                    $bruto = \Illuminate\Support\Facades\DB::table('ai_video_pipelines')
                        ->where('id', $pid)->value('payloads');
                    $doPedido = is_array($bruto) ? $bruto : (json_decode((string) $bruto, true) ?: []);
                    $doPedido['forcar_gratis'] = true;
                    unset($doPedido['resgate_manual']);   // o resgate manual e justamente o que gasta credito
                    \Illuminate\Support\Facades\DB::table('ai_video_pipelines')
                        ->where('id', $pid)->update(['payloads' => json_encode($doPedido)]);
                    \Illuminate\Support\Facades\Log::error('[SEL-SEM-CREDITO-CAI-PRO-GRATIS] conta sem saldo — proxima tentativa vai no gratis', [
                        'pipeline_id' => $pid, 'task' => $this->taskId,
                    ]);
                } catch (\Throwable $x) {
                    // nao pode derrubar o retry por causa do registro
                }
            }

            \App\Services\Ai\VideoResilience::registrar('KLING_RETRY_INFRA', [
                'task' => $this->taskId, 'tentativa' => $this->attempts(),
                'motivo' => \App\Services\Ai\VideoResilience::motivoInfra($e->getMessage()),
                // SEL-ERRO-NAO-CORTA (15/08): eram 200 caracteres, e os 200 primeiros
                // sao cabecalho (task=, pipeline=, OUTDIR=). O motivo real do worker
                // vinha DEPOIS do corte — por isso toda falha virava 'worker_falhou'
                // e a causa era chutada em vez de lida.
                'erro' => mb_substr($e->getMessage(), 0, 1200),
            ]);
            Log::warning('[SEL-RESILIENCIA] KlingBrowser tropecou em INFRA -> retry em outro motor', [
                'task_id'    => $this->taskId,
                'ferramenta' => $this->kind,
                'tentativa'  => $this->attempts(),
                'motivo'     => \App\Services\Ai\VideoResilience::motivoInfra($e->getMessage()),
                'imagens'    => count($imagePaths),
                'err'        => $e->getMessage(),
            ]);
            throw $e; // permite retry
        } finally {
            // SEL-LEASE (12/08) — DEVOLUCAO GARANTIDA.
            //
            // Ate hoje o release morava em DOIS lugares (fim do try, e o catch). Quem
            // saisse por um caminho que nao passa por nenhum dos dois -- os `return`
            // do ensaio (dry_run) e da falha de CONTEUDO -- deixava o motor preso ate
            // o TTL de 600s. Sao 10 minutos de motor bom parado por saida limpa.
            // Aqui o motor volta em QUALQUER saida: sucesso, falha, excecao ou return.
            //
            // Isto NAO cobre morte por sinal (SIGALRM do queue:work nao roda finally)
            // -- essa e exatamente a parte que o lease+zelador cobre, e por isso as
            // duas coisas existem juntas.
            if ($reservedEngineId !== null) {
                try {
                    app(AiEnginePool::class)->releaseEngine($reservedEngine ?? $reservedEngineId, success: false);
                    Log::warning('[SEL-LEASE][KlingBrowserJob] motor devolvido no finally (saida sem release explicito)', [
                        'task_id' => $this->taskId, 'engine_id' => $reservedEngineId,
                    ]);
                } catch (\Throwable $x) {
                    Log::error('[SEL-LEASE][KlingBrowserJob] falhei em devolver o motor no finally', [
                        'task_id' => $this->taskId, 'engine_id' => $reservedEngineId,
                        'err' => mb_substr($x->getMessage(), 0, 160),
                    ]);
                }
                $reservedEngineId = null;
            }
        }
    }

    /**
     * SEL-RESILIENCIA (12/08) — so AQUI a tarefa vira `failed` de verdade: quando
     * o Laravel desistiu (prazo do retryUntil ou maxExceptions). Enquanto havia
     * tentativa sobrando o estado era `retrying`, e quem faz poll seguia esperando
     * em vez de mostrar erro pro cliente por causa de um tropeco nosso.
     */
    public function failed(\Throwable $e): void
    {
        try {
            $this->setState([
                'task_status'     => 'failed',
                'task_status_msg' => mb_substr(
                    \App\Services\Ai\VideoResilience::mensagemCliente($e->getMessage()),
                    0, 400
                ),
                'erro_bruto'  => mb_substr($this->kind . ': ' . $e->getMessage(), 0, 300),
                'erro_infra'  => \App\Services\Ai\VideoResilience::motivoInfra($e->getMessage()),
                'updated_at'  => now()->toIso8601String(),
            ]);
            Log::error('[SEL-RESILIENCIA] KlingBrowser esgotou o prazo -> failed', [
                'task_id' => $this->taskId, 'err' => mb_substr($e->getMessage(), 0, 300),
            ]);
        } catch (\Throwable $x) {
            // nada a fazer
        }
    }

    /**
     * SEL-468 -- baixa TODAS as fotos de referencia pro /tmp (o worker Node so
     * abre caminho local). Antes existia so o caso de uma imagem.
     *
     * Falha com o motivo e a POSICAO da imagem que nao veio: numa cena com 5
     * referencias, "image_download_failed" sozinho nao diz qual das cinco quebrou.
     */
    private function baixaImagens(): array
    {
        if ($this->kind === 'text2video') {
            return [];
        }

        // image2video continua sendo UMA imagem, e a MESMA de antes
        // (`payload['image']`). Sem este ramo, um payload que trouxesse
        // `image_list` junto faria a tela de uma foto usar outra foto -- mudanca
        // silenciosa de comportamento em pipeline que hoje funciona.
        // SEL-485: as variantes do Omni sobem VIDEO no mesmo campo das fotos, e a
        // lista ja vem pronta e ordenada do service (`files`). Cair no
        // `normalizaImagens` aqui perderia os videos, que ele nao conhece.
        if (in_array($this->kind, ['omni_video_ref', 'omni_instrucao'], true)) {
            $urls = array_values(array_filter((array) ($this->payload['files'] ?? [])));
        }
        // SEL-485: no Motion Control a unica imagem e a FOTO DO PERSONAGEM, e o
        // video desce em `baixaVideoReferencia()`. Sem este ramo, `normalizaImagens`
        // varreria o payload e poderia mandar a capa do produto como personagem.
        elseif ($this->kind === 'image2video' || $this->kind === 'motion_control') {
            $urls = [$this->payload['image'] ?? null];
            $urls = array_values(array_filter($urls));
        } else {
            $urls = \App\Services\Ai\KlingBrowserService::normalizaImagens($this->payload);
        }

        if (empty($urls)) {
            throw new \RuntimeException('missing_image_url');
        }
        $urls = array_slice($urls, 0, 7);

        $paths = [];
        foreach (array_values($urls) as $i => $url) {
            $ext = strtolower((string) pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
            // SEL-485: nas variantes do Omni o arquivo pode ser VIDEO. Forcar
            // ".jpg" (o que este trecho fazia pra tudo) faria o input do Kling
            // recusar o mp4 calado -- o upload nao acontece, a contagem do pool
            // nao fecha e o erro que aparece e "upload nao confirmado", que manda
            // investigar rede em vez de extensao.
            $permitidas = in_array($this->kind, ['omni_video_ref', 'omni_instrucao'], true)
                ? ['jpg', 'jpeg', 'png', 'mp4', 'mov']
                : ['jpg', 'jpeg', 'png'];
            $ext = in_array($ext, $permitidas, true) ? $ext : 'jpg';
            $dest = '/tmp/kling_browser_' . $this->taskId . '_' . $i . '.' . $ext;

            // SEL-imgproxy (Ruan 12/08): baixa com headers de navegador + Referer do proprio
            // host da imagem (fura hotlink protegido tipo Shopee) + 1 retry. Antes: get cru -> image_download_failed.
            $__host = (parse_url($url, PHP_URL_SCHEME) ?: 'https') . '://' . (parse_url($url, PHP_URL_HOST) ?: '') . '/';
            $__ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 25, 'follow_location' => 1,
                'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Safari/537.36\r\nReferer: $__host\r\nAccept: image/avif,image/webp,image/*,*/*\r\n"],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
            $bin = @file_get_contents($url, false, $__ctx);
            if ($bin === false || $bin === '') { usleep(400000); $bin = @file_get_contents($url, false, $__ctx); }
            if ($bin === false || $bin === '') {
                foreach ($paths as $p) { @unlink($p); }
                throw new \RuntimeException(
                    'image_download_failed: referencia ' . ($i + 1) . ' de ' . count($urls) . ' nao baixou (' . mb_substr($url, 0, 120) . ')'
                );
            }
            file_put_contents($dest, $bin);
            $paths[] = $dest;
        }

        Log::info('[SEL-468] referencias baixadas pro worker', [
            'task_id'    => $this->taskId,
            'ferramenta' => $this->kind,
            'quantidade' => count($paths),
        ]);

        return $paths;
    }

    /**
     * SEL-485 -- baixa o VIDEO de referencia do Motion Control pro /tmp.
     *
     * Separado de `baixaImagens()` de proposito: sao campos diferentes na tela do
     * Kling (um input aceita `.mp4,.mov`, o outro `.jpg,.jpeg,.png`) e trocar os
     * dois e o erro mais facil de cometer aqui.
     *
     * A extensao e preservada porque o `input[type=file]` do Kling filtra por
     * ela: salvar um `.mov` como `.mp4` faz a tela recusar o arquivo sem dizer
     * por que. Quem valida proporcao/duracao e o worker (ffprobe), que roda com
     * o arquivo ja em disco.
     */
    private function baixaVideoReferencia(): string
    {
        $url = $this->payload['video'] ?? $this->payload['video_url'] ?? null;
        if (!is_string($url) || trim($url) === '') {
            throw new \RuntimeException(
                'missing_video_url: copiar movimento precisa do video de referencia (campo "video")'
            );
        }

        $ext = strtolower((string) pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
        if (!in_array($ext, ['mp4', 'mov'], true)) {
            throw new \RuntimeException(
                'video_formato_invalido: a tela aceita apenas .mp4 e .mov e o arquivo enviado e "' . ($ext ?: 'sem extensao') . '"'
            );
        }

        $dest = '/tmp/kling_browser_' . $this->taskId . '_motion.' . $ext;
        $bin  = @file_get_contents($url);
        if ($bin === false || $bin === '') {
            throw new \RuntimeException(
                'video_download_failed: nao consegui baixar o video de referencia (' . mb_substr($url, 0, 120) . ')'
            );
        }
        file_put_contents($dest, $bin);

        Log::info('[SEL-485] video de referencia baixado pro worker', [
            'task_id' => $this->taskId,
            'bytes'   => strlen($bin),
            'ext'     => $ext,
        ]);

        return $dest;
    }

    protected function setState(array $patch): void
    {
        $c = \App\Services\Ai\KlingBrowserService::cache();
        $current = $c->get("kling_browser:{$this->taskId}", []);
        $c->put("kling_browser:{$this->taskId}", array_merge($current, $patch), 3600 * 2);
    }
}
