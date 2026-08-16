<?php
namespace App\Services\Ai;

use App\Contracts\VideoGeneratorContract;
use App\Models\AiEngine;
use Symfony\Component\Process\Process;

/**
 * SEL-429 -- Adapter Mac Flow para geracao de video (fallback silencioso).
 * Extraido de VideoEnginePool::runMacFlow() do SEL-425.
 * Usa google-session.json local e veo_generate.js sem CDP.
 */
class MacFlowVideoAdapter implements VideoGeneratorContract
{
    /**
     * SEL-modelo-explicito (12/08). O modelo do Flow NUNCA mais e herdado do
     * projeto. Motivo medido hoje: os projetos estavam em "Veo 3.1 - Quality"
     * (100 creditos/geracao) enquanto o worker assumia Omni Flash (12-15). Como
     * o adapter so mandava `veo_model` quando o plano pedia, ninguem via o custo
     * 8x maior -- as 4 contas do pool secaram (7 creditos cada) e a geracao parou.
     *
     * Padrao agora e o modo GRATUITO: "Veo 3.1 - Lite [Lower Priority]" custa 0
     * credito (medido: painel do Flow mostra "A geracao vai usar 0 creditos" e
     * uma conta com 7 creditos gerou video real de 8s/720x1280 sem debitar).
     * Fila mais lenta (~62s contra ~45s do Omni Flash) -- e o preco do gratis.
     *
     * Reserva: quem nao tiver o Lower Priority disponivel cai no Omni Flash (12cr).
     */
    public const MODELO_PADRAO  = 'Veo 3.1 - Lite [Lower Priority]';
    public const MODELO_RESERVA = 'Omni Flash';

    public function __construct(private AiEngine $engine) {}

    /**
     * Modelo que SEMPRE vai no payload do worker. Precedencia:
     *   1) o que o chamador pediu (plano Ultra etc)
     *   2) o configurado no motor (config_json.veo_model)
     *   3) config services.veo.model
     *   4) o padrao gratuito
     * Nunca devolve vazio -- e essa a garantia de nao herdar o default do projeto.
     */
    /**
     * SEL-RESGATE-NA-3A (15/08) — quantas vezes ESTE pedido ja falhou antes de agora.
     *
     * Fonte primaria: `tentativa` (o `$this->attempts()` do job que chamou). E o
     * unico contador que existe de verdade no caminho do Studio. A v1 deste conserto
     * lia a coluna `retries` do pipeline e teria nascido morta: medido em 24h, 146 de
     * 150 pedidos tem retries=0, porque quem incrementa essa coluna e outro caminho
     * (AiVideoPipelineJob / VideoSentinela), nunca o do navegador.
     *
     * `retries` fica como segunda fonte — nos caminhos onde ele e alimentado, vale.
     */
    private function jaFalhou(array $payload): int
    {
        $tentativa = (int) ($payload['tentativa'] ?? 0);
        if ($tentativa > 0) {
            return max(0, $tentativa - 1);   // attempts() e 1 na primeira execucao
        }

        $id = $payload['pipeline_id'] ?? $payload['_pipeline_id'] ?? $payload['pipeline_id'] ?? null   /* SEL-PIPELINE-ID-PERDIDO: o job manda `pipeline_id`, o adapter lia `_pipeline_id` -> chegava null */;
        if (! $id) {
            return 0;
        }
        try {
            return (int) \Illuminate\Support\Facades\DB::table('ai_video_pipelines')
                ->where('id', $id)->value('retries');
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function modeloVeo(array $payload, array $cfg): string
    {
        // SEL-RESGATE-NA-3A (15/08) — o gratis continua o padrao; o pago e RESGATE.
        //
        // Caso Sandro Lima (1145): 3 falhas seguidas, 2h30 de espera, e o diff contra
        // um pedido dele que funcionou mostrou `veo_stage=processing` — nunca ficou
        // pronto. O Ruan explicou em uma frase: "na MAO faz e gera". Na mao e modelo
        // normal; o robo usa "[Lower Priority]", a fila gratis, que o Google atende
        // quando sobra capacidade. Nao era o conteudo da foto (cheguei a chutar isso
        // e errei).
        //
        // Depois de 2 falhas do MESMO pedido, a 3a sobe pro modelo normal. Nao encosta
        // no fluxo normal: so passa por aqui pedido que JA falhou duas vezes. O gratis
        // como padrao e decisao do Ruan ("mantem gratis no resto") e continua intacta.
        // SEL-TUDO-NO-GRATIS (15/08): o resgate sobe pro modelo PAGO, e modelo pago
        // sem saldo na conta e o que fez o Flow recusar 18x em 6h — o "resgate"
        // virava a propria causa da falha. Com a chave ligada ele nao acontece.
        $falhas = config('services.veo.tudo_no_gratis', true) ? 0 : $this->jaFalhou($payload);
        if ($falhas >= 2) {
            $resgate = trim((string) (config('services.veo.model_resgate') ?: 'Veo 3.1 - Quality'));
            \Illuminate\Support\Facades\Log::error('[SEL-RESGATE-NA-3A] pedido ja falhou '
                . $falhas . 'x na fila gratis — subindo pro modelo normal', [
                    'pipeline_id' => $payload['pipeline_id'] ?? null,
                    'tentativa'   => $payload['tentativa'] ?? null,
                    'modelo'      => $resgate,
                ]);

            return $resgate;
        }

        foreach ([$payload['veo_model'] ?? null, $cfg['veo_model'] ?? null, config('services.veo.model')] as $m) {
            if (is_string($m) && trim($m) !== '') {
                return trim($m);
            }
        }

        return self::MODELO_PADRAO;
    }

    public function generate(string $taskId, string $kind, array $payload): array
    {
        $cfg = $this->engine->config_json ?? [];

        // SEL-pc-fase2 (09/08): se o motor tem config `remote`, roda o worker NO PC
        // do Ruan (via SSH pelo tunel reverso localhost:2200) em vez de local no
        // servidor lotado. Opt-in: motores sem `remote` seguem local (nada muda).
        if (!empty($cfg['remote'])) {
            return $this->generateRemote($taskId, $kind, $payload, $cfg);
        }

        $sessionPath = $cfg['session_path']
            ?? config('services.veo.browser_session_path')
            ?? '/home/api.seller.global/storage/kling-browser/google-session.json';

        $workerJs = $cfg['worker_js']
            ?? config('services.veo.browser_worker_js')
            ?? '/home/api.seller.global/browser-worker/veo_generate.js';

        $workerDir = $cfg['worker_dir']
            ?? config('services.veo.browser_worker_dir')
            ?? '/home/api.seller.global/browser-worker';

        if (!is_file($sessionPath)) {
            throw new \RuntimeException("mac_session_missing: {$sessionPath} nao existe");
        }

        $workerInput = [
            'image_path'       => $payload['image_path'] ?? ($payload['image_paths'][0] ?? null),
            'image_paths'      => $payload['image_paths'] ?? [],
            'file_paths'       => $payload['image_paths'] ?? [],
            'prompt'           => $payload['prompt'] ?? '',
            'duration'         => (int) ($payload['duration'] ?? 8),
            'aspect_ratio'     => $payload['aspect_ratio'] ?? '9:16',
            'model_name'       => $payload['model_name'] ?? null,
            'external_task_id' => $taskId,
            'kind'             => $kind,
            'mode'             => $kind,
            'tool'             => $kind,
        ];
        // SEL-modelo-explicito (12/08): SEMPRE manda o modelo. Sem isso o worker
        // herdava o default do projeto -- foi assim que 100cr/geracao passou batido.
        $workerInput['veo_model'] = $this->modeloVeo($payload, $cfg);

        $procEnv = [
            'PLAYWRIGHT_BROWSERS_PATH' => env('PLAYWRIGHT_BROWSERS_PATH', '/opt/ms-playwright'),
            'PATH'                     => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'DISPLAY'                  => ':99',
            'VEO_SESSION'              => $sessionPath,
        ];

        if ($pu = ($cfg['project_url'] ?? config('services.veo.project_url'))) {
            $procEnv['VEO_PROJECT_URL'] = $pu;
        }
        if ($m = config('services.veo.model')) {
            $procEnv['VEO_MODEL'] = $m;
        }

        $cmd  = ['xvfb-run', '-a', '--server-args=-screen 0 1440x900x24', 'node', $workerJs];
        $proc = new Process($cmd, $workerDir, $procEnv);
        $proc->setTimeout(900);
        $proc->setInput(json_encode($workerInput));
        $proc->run();

        // SEL-490 Layer 5b: persiste STDERR/STDOUT do worker por task na arvore do
        // app (o Process descartava o STDERR no sucesso; o diagnostico de "produto
        // errado" so vivia nas screenshots efemeras de /tmp). Sobrevive a limpeza
        // do /tmp. Secundario: nunca derruba a geracao.
        try {
            $logDir = storage_path('logs/veo');
            if (!is_dir($logDir)) { @mkdir($logDir, 0775, true); }
            $safe = preg_replace('/[^\w.-]/', '_', (string) $taskId);
            @file_put_contents(
                $logDir . '/' . $safe . '.log',
                '[' . now()->toDateTimeString() . '] exit=' . var_export($proc->getExitCode(), true)
                . "\n--- STDERR ---\n" . $proc->getErrorOutput()
                . "\n--- STDOUT ---\n" . $proc->getOutput() . "\n"
            );
        } catch (\Throwable $e) { /* log e secundario, ignora */ }

        if (!$proc->isSuccessful()) {
            throw new \RuntimeException('mac_worker_failed: ' . mb_substr($proc->getErrorOutput() ?: $proc->getOutput(), 0, 400));
        }

        $lines    = preg_split('/\r?\n/', trim($proc->getOutput()));
        $jsonLine = '';
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if ($line !== '' && $line[0] === '{' && substr($line, -1) === '}') {
                $jsonLine = $line;
                break;
            }
        }
        $decoded = $jsonLine ? json_decode($jsonLine, true) : null;
        if (!$decoded || empty($decoded['ok'])) {
            throw new \RuntimeException('mac_worker_bad_response: ' . mb_substr($proc->getOutput(), 0, 400));
        }
        // Guarda o saldo da conta (pro alerta de credito baixo no Data Center).
        if (isset($decoded['saldo_depois']) && is_numeric($decoded['saldo_depois'])) {
            $cfgNow = $this->engine->config_json ?? [];
            $cfgNow['saldo'] = (int) $decoded['saldo_depois'];
            $cfgNow['saldo_em'] = now()->toDateTimeString();
            $this->engine->update(['config_json' => $cfgNow]);
        }
        return $decoded;
    }

    /**
     * SEL-pc-fase2 — roda o worker NO PC do Ruan (via SSH pelo tunel reverso
     * localhost:2200). Sobe a foto do produto, executa node veo_generate.js com o
     * perfil logado, puxa o mp4 de volta pro servidor. O worker ja limpa metadata
     * (sem Google/Veo) e trava pt-BR. Fail-open: erro aqui vira excecao -> o pool
     * (reserveEngine) tenta o proximo motor/servidor.
     */
    private function generateRemote(string $taskId, string $kind, array $payload, array $cfg): array
    {
        $r    = $cfg['remote'];
        $key  = $r['ssh_key'] ?? '/root/.ssh/pc-render';
        $port = (string) ($r['port'] ?? 2200);
        $user = $r['user'] ?? 'ruan';
        $host = $r['host'] ?? 'localhost';
        $wdir = $r['worker_dir'] ?? 'C:\\sellerglobal-render';
        $target  = $user . '@' . $host;
        $safe    = preg_replace('/[^\w.-]/', '_', (string) $taskId);
        $sshOpts = ['-i', $key, '-p', $port, '-o', 'StrictHostKeyChecking=no',
            // SEL-TUNEL-MULTIPLEX-BACKEND (14/08): reaproveita UMA conexao com o PC
            // em vez de abrir uma nova a cada chamada (era isso que estourava o
            // limite de conexoes simultaneas do sshd do Windows e derrubava o tunel).
            // SEL-MUX-NUNCA-COM-STDIN (14/08) — REGREDI a multiplexacao AQUI, e so aqui.
            // MEDIDO: mux aplicado 16:52; ate 16h foram 16 geracoes com 0 falha, depois
            // 7 falhas em 30 geracoes. Nos logs que falham o worker aparece com
            // task=t<epoch> inventado em vez do external_task_id — prova de que o JSON
            // do stdin NAO chegou nele. O sshd do Windows perde o stdin em sessao
            // multiplexada, e e por stdin que viaja o prompt inteiro do video; sem ele o
            // worker cai no cold-launch e morre com "sessao Google ausente".
            // O scp (logo abaixo) continua multiplexado: la nao passa stdin, e era ele o
            // grosso das conexoes que estourava o limite do sshd.
            '-o', 'ConnectTimeout=20', '-o', 'ServerAliveInterval=15'];
        $scpOpts = ['-i', $key, '-P', $port, '-o', 'StrictHostKeyChecking=no',
            // SEL-TUNEL-MULTIPLEX-BACKEND (14/08): reaproveita UMA conexao com o PC
            // em vez de abrir uma nova a cada chamada (era isso que estourava o
            // limite de conexoes simultaneas do sshd do Windows e derrubava o tunel).
            '-o', 'ControlMaster=auto', '-o', 'ControlPath=/tmp/.ssh-mux-backend-%r@%h-%p', '-o', 'ControlPersist=120', '-o', 'ConnectTimeout=20'];

        // ══ SEL-BUSCA-ANTES-DE-REFAZER (16/08) — PARTE B ═══════════════════════════
        //
        // Antes de gastar motor e relogio gerando de novo, pergunta o obvio: a tentativa
        // ANTERIOR deste mesmo pedido ja nao deixou o video pronto no PC? No gratis o
        // Google entrega depois do nosso corte, e o mp4 fica esquecido em
        // C:\sellerglobal-render\out_<tarefa>.mp4 enquanto a gente refaz tudo.
        //
        // Custo desta checagem: um scp que falha em segundos quando nao ha arquivo.
        // Ganho quando ha: o cliente recebe NA HORA, em vez de esperar outra geracao
        // inteira (foi o caso do #1294, que passou de 71 minutos).
        $__pipeBusca = $payload['_pipeline_id'] ?? $payload['pipeline_id'] ?? null   /* SEL-PIPELINE-ID-PERDIDO: o job manda `pipeline_id`, o adapter lia `_pipeline_id` -> chegava null */;
        if ($__pipeBusca) {
            try {
                $__linhaB = \Illuminate\Support\Facades\DB::table('ai_video_pipelines')
                    ->where('id', (int) $__pipeBusca)->value('payloads');
                $__plB  = json_decode((string) $__linhaB ?: '{}', true) ?: [];
                $__antB = array_values(array_filter((array) ($__plB['_tentativas_pc'] ?? [])));
                // mais recente primeiro: se houver mais de um, o ultimo e o mais provavel
                foreach (array_reverse($__antB) as $__tarefaVelha) {
                    if (! is_string($__tarefaVelha) || $__tarefaVelha === '' || $__tarefaVelha === $taskId) { continue; }
                    $__safeV  = preg_replace('/[^\w.-]/', '_', $__tarefaVelha);
                    $__pcV    = $wdir . '\\out_' . $__safeV . '.mp4';
                    $__localV = storage_path('app/remote-video/' . $__safeV . '.mp4');
                    if (! is_dir(dirname($__localV))) { @mkdir(dirname($__localV), 0775, true); }
                    $__pull = new Process(array_merge(['scp'], $scpOpts, [
                        $target . ':' . str_replace('\\', '/', $__pcV), $__localV,
                    ]));
                    $__pull->setTimeout(120);
                    try { $__pull->run(); } catch (\Throwable $ePull) { continue; }
                    if (is_file($__localV) && filesize($__localV) > 500000) {
                        \App\Services\Ai\VideoResilience::registrar('VIDEO_ACHADO_DE_TENTATIVA_ANTERIOR', [
                            'pipeline' => $__pipeBusca, 'tarefa_velha' => $__tarefaVelha,
                            'tarefa_nova' => $taskId, 'bytes' => @filesize($__localV),
                        ]);
                        \Illuminate\Support\Facades\Log::warning(
                            '[SEL-BUSCA-ANTES-DE-REFAZER] o video da tentativa anterior JA ESTAVA PRONTO no PC — entregando sem gerar de novo',
                            ['pipeline' => $__pipeBusca, 'de' => $__tarefaVelha, 'bytes' => @filesize($__localV)]
                        );
                        // limpa o arquivo velho no PC (nao trava se falhar)
                        try {
                            $__rmV = new Process(array_merge(['ssh'], $sshOpts, [$target, 'del /q "' . $__pcV . '"']));
                            $__rmV->setTimeout(30); $__rmV->run();
                        } catch (\Throwable $eRm) {}

                        return [
                            'ok'          => true,
                            'stage'       => 'done',
                            'output_url'  => $__localV,
                            'source_url'  => $__localV,
                            'mp4'         => $__localV,
                            'file_bytes'  => @filesize($__localV),
                            '_resgatado_de' => $__tarefaVelha,
                        ];
                    }
                    @unlink($__localV);
                }
            } catch (\Throwable $eBusca) {
                // procurar e OPORTUNIDADE, nunca obrigacao: se falhar, gera normal.
                \Illuminate\Support\Facades\Log::warning('[SEL-BUSCA-ANTES-DE-REFAZER] busca falhou, seguindo com geracao normal', [
                    'erro' => mb_substr($eBusca->getMessage(), 0, 140),
                ]);
            }
        }

        // 1) sobe a foto do produto pro PC (o worker precisa de caminho local)
        // SEL-TODAS-AS-FOTOS-VAO (16/08): antes copiava UMA foto so pro PC —
        //     $localImg = $payload['image_path'] ?? ($payload['image_paths'][0] ?? null);
        // e mandava 'image_paths' => [$pcImg]. Resultado: a foto de CENARIO que o cliente
        // sobe (e qualquer 2a referencia) era baixada, normalizada, gastava tempo do
        // pedido e morria AQUI, sem nunca atravessar o tunel. Medido: 53 pipelines com
        // image_refs>=1 desde 15/08, e 12/12 logs do worker com 1 upload.
        //
        // Agora copia TODAS (teto 7 = limite do multi-referencia) e manda a lista inteira.
        // `image_path` continua sendo a PRIMEIRA: worker antigo no PC segue funcionando
        // igual a hoje, worker novo usa a lista. Compativel nos dois sentidos.
        $listaLocal = [];
        if (! empty($payload['image_path']) && is_file($payload['image_path'])) {
            $listaLocal[] = $payload['image_path'];
        }
        foreach ((array) ($payload['image_paths'] ?? []) as $__p) {
            if (is_string($__p) && $__p !== '' && is_file($__p) && ! in_array($__p, $listaLocal, true)) {
                $listaLocal[] = $__p;
            }
        }
        $listaLocal = array_slice($listaLocal, 0, 7);

        $pcImgs = [];
        foreach ($listaLocal as $__i => $__local) {
            $__ext = pathinfo($__local, PATHINFO_EXTENSION) ?: 'jpg';
            // a 1a mantem o nome de sempre (img_<tarefa>.ext) pra nao quebrar nada que
            // dependa dele; as seguintes ganham sufixo.
            $__dest = $wdir . '\\img_' . $safe . ($__i === 0 ? '' : '_' . $__i) . '.' . $__ext;
            $__up = new Process(array_merge(['scp'], $scpOpts, [$__local, $target . ':' . str_replace('\\', '/', $__dest)]));
            $__up->setTimeout(120);
            $__up->run();
            if ($__up->isSuccessful()) {
                $pcImgs[] = $__dest;
            } elseif ($__i === 0) {
                // a PRIMEIRA e obrigatoria: sem ela nao ha o que gerar.
                throw new \RuntimeException('remote_scp_img_failed: ' . mb_substr($__up->getErrorOutput(), 0, 200));
            } else {
                // referencia extra que nao subiu nao pode derrubar o pedido.
                \Illuminate\Support\Facades\Log::warning('[SEL-TODAS-AS-FOTOS-VAO] referencia extra nao subiu, seguindo', [
                    'arquivo' => basename($__local),
                    'err'     => mb_substr($__up->getErrorOutput(), 0, 120),
                ]);
            }
        }
        if (count($pcImgs) > 1) {
            \Illuminate\Support\Facades\Log::error('[SEL-TODAS-AS-FOTOS-VAO] mandando varias fotos pro motor', [
                'quantas' => count($pcImgs), 'task' => $taskId,
            ]);
        }

        $localImg = $listaLocal[0] ?? null;
        $pcImg = $pcImgs[0] ?? null;
        if (false) {
            $ext   = pathinfo((string) $localImg, PATHINFO_EXTENSION) ?: 'jpg';
            $pcImg = $wdir . '\\img_' . $safe . '.' . $ext;
            $up = new Process(array_merge(['scp'], $scpOpts, [$localImg, $target . ':' . str_replace('\\', '/', $pcImg)]));
            $up->setTimeout(120); $up->run();
            if (!$up->isSuccessful()) { throw new \RuntimeException('remote_scp_img_failed: ' . mb_substr($up->getErrorOutput(), 0, 200)); }
        }

        // 2) input do worker (perfil logado + saida conhecida)
        $pcOut = $wdir . '\\out_' . $safe . '.mp4';
        $workerInput = [
            // SEL-TODAS-AS-FOTOS-VAO (16/08): `image_path` segue sendo a PRIMEIRA (worker
            // antigo continua igual); `image_paths`/`file_paths` levam TODAS as que subiram.
            'image_path'       => $pcImg,
            'image_paths'      => $pcImgs ?: ($pcImg ? [$pcImg] : []),
            'file_paths'       => $pcImgs ?: ($pcImg ? [$pcImg] : []),
            'prompt'           => $payload['prompt'] ?? '',
            'duration'         => (int) ($payload['duration'] ?? 8),
            'aspect_ratio'     => $payload['aspect_ratio'] ?? '9:16',
            'external_task_id' => $taskId,
            'kind'             => $kind, 'mode' => $kind, 'tool' => $kind,
            'out'              => $pcOut,
            'profile_dir'      => $r['profile_dir'] ?? '',
            'profile_name'     => $r['profile_name'] ?? 'Default',
            'headless'         => true,
        ];
        // SEL-modelo-explicito (12/08): ESTE e o caminho REALMENTE usado hoje (pool
        // remoto no PC do Ruan). Agora o modelo vai SEMPRE, nunca herdado do projeto.
        $workerInput['veo_model'] = $this->modeloVeo($payload, $cfg);

        // 3) roda no PC via SSH (stdin = JSON)
        $remoteCmd = 'cd /d "' . $wdir . '" && node veo_generate.js';
        $run = new Process(array_merge(['ssh'], $sshOpts, [$target, $remoteCmd]));
        // CAUSA 2 (09/08): cada chamada renderiza 1 clipe. Medido em produção o
        // render REAL do Flow (upload foto + prompt + gerar + baixar) leva ~56-135s;
        // 600s = ~4.5x de folga p/ absorver a cauda lenta do Google SEM matar render
        // legítimo, mas ainda corta hang real bem abaixo do render-hung-heal (12min).
        // (360s inicial matou 2 renders legítimos-porém-lentos no teste — regredido.)
        // SEL 10/08 v2 -- DETECCAO DE PROGRESSO (nao teto cego de duracao). O
        // veo_generate.js avanca de fase emitindo [veo] SNAP <stage> (loaded ->
        // newproject -> video_mode -> uploaded -> gen -> final) + iterN durante o poll.
        // Um render que AVANCA de SNAP nunca e morto; morrem so:
        //   NOOUT  (120s sem NENHUMA saida)      -> attach mudo / connectOverCDP travado.
        //   NOPROG (300s sem novo SNAP/SALVO)    -> preso no loop de poll (iterN infinito).
        //   HARD   (720s)                        -> teto de seguranca final.
        // Ao estourar: mata o processo e sobe excecao -> generateClip poe a conta em
        // cooldown e RETENTA em OUTRA. Criterio = PROGRESSO, nao duracao (o teto cego de
        // 360s matava render legitimo-lento; o idle-timeout nao pegava o preso-em-poll,
        // que emite iterN o tempo todo).
        $run->setTimeout(null);
        $run->setInput(json_encode($workerInput));
        $__lastOut = time(); $__lastProg = time(); $__stage = ''; $__salvou = false;
        // SEL-RESILIENCIA (12/08): id da pipeline viaja no payload so pra BATIDA.
        // O adapter fica ate 25min bloqueado no SSH; sem isto ninguem toca a linha
        // e os reapers leem "parado ha 20min" = travado. Progresso do worker vira
        // prova de vida.
        $__pipe   = $payload['_pipeline_id'] ?? $payload['pipeline_id'] ?? null   /* SEL-PIPELINE-ID-PERDIDO: o job manda `pipeline_id`, o adapter lia `_pipeline_id` -> chegava null */;
        // SEL-GRAVA-DE-QUEM-E-A-TAREFA (16/08): carimba na pipeline QUAL tarefa esta
        // rodando agora. Sem isto, quando a gente corta uma geracao e o Google entrega
        // depois, o mp4 fica no PC sem dono — medido: 40+ arquivos orfaos e
        // `render_task_id` NULO em 100% dos pedidos falhados das ultimas 12h, o que
        // tornou impossivel devolver qualquer um deles sem chutar.
        try {
            if ($__pipe) {
                \Illuminate\Support\Facades\DB::table('ai_video_pipelines')
                    ->where('id', (int) $__pipe)
                    ->update(['render_task_id' => $taskId]);
            }
        } catch (\Throwable $eCarimbo) { /* carimbo e secundario: nunca derruba geracao */ }
        $__rotulo = $payload['_rotulo_hb'] ?? null;
        $run->start(function ($type, $buf) use (&$__lastOut, &$__lastProg, &$__stage, &$__salvou, $__pipe, $__rotulo) {
            if ($buf === '') { return; }
            $__lastOut = time();
            // SALVO/gen-id = o motor JA entregou. Guarda o fato: mesmo que o SSH
            // caia depois disso, existe mp4 pra recuperar no PC.
            // SEL-SALVOU-MENTIROSO (14/08): o padrao `mp4=[1-9]` casava com a TELEMETRIA
            // do loop de render ("render54 ... vids=0 mp4=4"), que so conta URLs de mp4
            // VISTAS NA PAGINA — nao arquivo baixado. Resultado: 16 execucoes de hoje
            // marcaram salvou=true sem nenhuma linha SALVO e sem byte nenhum, e como a
            // espera pelo arquivo e `$voltas = $__salvou ? 18 : 3` (10s vs 5s), cada uma
            // ficou 180s esperando um arquivo que nunca ia existir, contra 15s.
            // Agora so conta prova de arquivo: a linha SALVO ou o stage done do worker.
            if (! $__salvou && preg_match('/(SALVO\(|"stage":"done")/', $buf)) { $__salvou = true; }
            // PROGRESSO REAL = mudanca de FASE nomeada (loaded->newproject->...->gen->final)
            // OU o mp4 aparecendo. O poll do gen emite "SNAP N_gen" com N CRESCENTE (nao e
            // progresso) -> normaliza pro nome ("gen"). Preso no gen com vids=0/mp4=0 =
            // SEM progresso -> NOPROG corta em 300s e retenta em OUTRA conta.
            if (preg_match_all('/SNAP\s+[0-9]*_?([A-Za-z][A-Za-z_]*)/', $buf, $mm) && ! empty($mm[1])) {
                $name = strtolower((string) end($mm[1]));
                if ($name !== $__stage) {
                    $__stage = $name; $__lastProg = time();
                    // avanco de fase = batida na pipeline (prova de vida pros reapers)
                    \App\Services\Ai\VideoResilience::batida($__pipe ? (int) $__pipe : null, $__rotulo, ['veo_stage' => $__stage]);
                }
            }
            if (preg_match('/(mp4=[1-9]|vids=[1-9]|SALVO|"stage":"done")/', $buf)) {
                $__lastProg = time();
                \App\Services\Ai\VideoResilience::batida($__pipe ? (int) $__pipe : null, $__rotulo);
            }
        });
        // SEL-velocidade item 3 (12/08): o teto HARD virou parametro do CHAMADOR.
        // Default 720s = comportamento historico -> o fluxo LONGO (StudioLongVideoJob
        // ->generateClip, que chama este adapter direto e NAO manda hard_timeout_s)
        // continua exatamente como estava, de proposito: ele demora N clipes de ~3min.
        // O fluxo NORMAL (KlingBrowserGenerateJob) manda 420s pra cortar a tentativa
        // pendurada ANTES do $timeout=600s do job -- corte limpo, com finally rodando,
        // engine liberado e retry em OUTRA conta. Clamp defensivo [180,1800].
        $__NOOUT = 120; $__NOPROG = 300; $__t0 = time();
        // SEL-TETOGRATIS (12/08, Ruan: "quero testar no limite tudo gratis"): o modo
        // Lower Priority e fila de PRIORIDADE BAIXA no Google — medido, ele submete
        // (genIds=1) mas a midia demora muito mais pra ficar pronta. Com teto de 720s
        // a gente matava a geracao antes do Google entregar, ou seja: testavamos o
        // NOSSO limite, nao o dele. No gratis o teto sobe pra 1500s (25min), abaixo
        // do timeout de 1800s do job no supervisor. Modelo pago segue em 720s.
        $__ehGratis = stripos((string) ($payload['veo_model'] ?? $payload['model'] ?? ''), 'Lower Priority') !== false;
        $__HARD  = (int) ($payload['hard_timeout_s'] ?? ($__ehGratis ? 1500 : 720));
        $__HARD  = max(180, min(1800, $__HARD));
        // SEL-RESILIENCIA (12/08) — NAO PERDER VIDEO JA ENTREGUE.
        //
        // Estes tres cortes ANTES levantavam excecao na hora. Medido: `ENVIO OK /
        // gen-id capturado` seguido de `dur=461s exit=255` -- o Google tinha
        // entregue e a gente jogava fora, porque quem estourou foi o NOSSO relogio,
        // nao o render. Agora o corte so REGISTRA o motivo e cai no bloco de
        // recuperacao la embaixo: se o mp4 existir no PC, ele e resgatado e o
        // cliente recebe o video. So vira excecao se realmente nao houver arquivo.
        $__corte = null;
        // SEL-LEASE (12/08) — BATIMENTO DO LEASE DURANTE O RENDER.
        //
        // Este laco e o unico lugar do sistema que sabe, em tempo real, que o
        // render esta ACONTECENDO. O pool nao sabe: pra ele existe so uma trava
        // com prazo de 600s, e prazo nao distingue "renderizando ha 4 minutos" de
        // "processo morto ha 4 minutos" -- os dois seguram o motor igual.
        //
        // Enquanto o processo do render vive, a gente renova o lease (e o lock
        // junto). Se este PHP morrer por sinal, a batida para: o lease vence em
        // ~90s e o zelador devolve o motor, em vez de 10 minutos parados.
        $__batidaLease = time();
        while ($run->isRunning()) {
            usleep(2000000);
            $__now = time();

            if ($__now - $__batidaLease >= \App\Services\Ai\AiEnginePool::LEASE_BATIDA_S) {
                $__batidaLease = $__now;
                try {
                    app(\App\Services\Ai\AiEnginePool::class)->heartbeatEngine($this->engine);
                } catch (\Throwable $x) { /* batida nunca derruba render */ }
            }
            if ($__now - $__t0 > $__HARD)        { $__corte = 'veo_hard_timeout_' . $__HARD . 's stage=' . $__stage; }
            elseif ($__now - $__lastOut > $__NOOUT)  { $__corte = 'veo_sem_saida_' . $__NOOUT . 's_attach_mudo stage=' . $__stage; }
            elseif ($__now - $__lastProg > $__NOPROG){ $__corte = 'veo_sem_progresso_' . $__NOPROG . 's_preso_poll stage=' . $__stage; }
            if ($__corte !== null) {
                try { $run->stop(5); } catch (\Throwable $x) {}
                break;
            }
        }
        // SEL-RESILIENCIA (12/08, medido no teste de queda): quando o canal SSH e
        // MORTO POR SINAL (kill -9 no ssh, worker derrubado, pool reiniciado), o
        // Symfony joga ProcessSignaledException DENTRO do wait() -- ou seja, antes
        // do log por tarefa e antes do resgate do mp4. Era um caminho invisivel:
        // nao gravava log nenhum e descartava video que podia estar pronto no PC.
        // Agora o sinal vira MOTIVO DE CORTE e segue o mesmo caminho dos outros.
        if ($__corte === null) {
            try {
                $run->wait();
            } catch (\Throwable $sig) {
                $__corte = 'canal_morto_por_sinal: ' . mb_substr($sig->getMessage(), 0, 120) . ' stage=' . $__stage;
            }
        }

        try {
            $logDir = storage_path('logs/veo');
            if (!is_dir($logDir)) { @mkdir($logDir, 0775, true); }
            @file_put_contents($logDir . '/' . $safe . '.remote.log',
                '[' . now()->toDateTimeString() . '] exit=' . var_export($run->getExitCode(), true)
                // SEL-velocidade item 3 (12/08): registra QUAL teto por tentativa valeu
                // nesta chamada (420s no formato normal, 720s no fluxo longo) + quanto
                // a tentativa realmente levou. E a prova duravel de que o corte agressivo
                // esta valendo, sem poluir o laravel.log (LOG_LEVEL=error).
                . ' hard=' . $__HARD . 's noprog=' . $__NOPROG . 's dur=' . (time() - $__t0) . 's'
                . ' corte=' . var_export($__corte, true) . ' salvou=' . var_export($__salvou, true)
                . "\n--- STDERR ---\n" . $run->getErrorOutput() . "\n--- STDOUT ---\n" . $run->getOutput() . "\n");
        } catch (\Throwable $e) {}

        // Parse normal: ultima linha JSON do stdout do worker.
        $decoded = null;
        $lines = preg_split('/\r?\n/', trim($run->getOutput()));
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $l = trim($lines[$i]);
            if ($l !== '' && $l[0] === '{' && substr($l, -1) === '}') { $decoded = json_decode($l, true); break; }
        }

        // SEL-concorrencia (09/08 Ruan): sob 4-5 geracoes SIMULTANEAS o canal SSH
        // pode retornar ANTES do worker terminar (exit!=0 ou stdout sem o JSON
        // final), MAS o worker AINDA grava o mp4 no PC. Descartar um video que
        // EXISTE era o bug que derrubava o teste de pico. Sem ok pelo stdout ->
        // espera o arquivo aparecer no PC (ate ~5min) e trata como sucesso. Nunca
        // joga fora um video pronto.
        $localOut = storage_path('app/remote-video/' . $safe . '.mp4');
        if (!is_dir(dirname($localOut))) { @mkdir(dirname($localOut), 0775, true); }
        // CAUSA 2 (09/08): profile_busy = o worker VIU o perfil ocupado e saiu SEM
        // gerar nada -> NÃO existe mp4 pra recuperar. Rodar o polling de 30x aqui só
        // queimava ~5min por tentativa pendurando o worker. Falha imediata pro pool
        // rotacionar/reenfileirar. NÃO afeta o caso legítimo (SSH cai cedo mas o
        // worker AINDA vai gravar o mp4), que continua caindo no polling abaixo.
        if ($decoded && ($decoded['stage'] ?? '') === 'profile_busy') {
            throw new \RuntimeException('profile_busy: perfil ocupado, sem mp4 pra recuperar');
        }

        // ══ SEL-ECOSSISTEMA (12/08) — QUEM TENTOU E VIU, MANDA ════════════════
        //
        // MEDIDO: `sellerglobal01` derrubou 9 tentativas seguidas em ~14s cada
        // (22:48 -> 23:04), todas com stage=session_expired ("login wall"). E o
        // servidor continuou reservando esse motor, porque a decisao de reserva le
        // `sessao_valida` — carimbo da SONDA, que pra essa conta era das 22:15 e
        // dizia "viva". Ou seja: a sonda de 45min atras vencia o worker que ACABOU
        // de bater a cara na tela de login.
        //
        // Regra nova: o veredito de quem TENTOU vale mais que o de quem sondou. O
        // motor sai da roda na hora e so volta quando a sonda o revalidar. Isso
        // fecha o laco de "queimar o cliente em conta morta" — cada tentativa
        // custava 14s do pedido de alguem.
        //
        // Nao marca `precisa_reconectar` de proposito: essa flag e decisao do Ruan
        // / do sync, e uma vez ligada ninguem religa sozinho. Aqui a gente so diz a
        // verdade do momento e deixa a sonda ter a palavra final.
        if ($decoded && in_array($decoded['stage'] ?? '', ['session_expired', 'loginwall', 'login_wall'], true)) {
            try {
                $cfgNow = $this->engine->config_json ?? [];
                if (!is_array($cfgNow)) { $cfgNow = json_decode((string) $cfgNow ?: '{}', true) ?: []; }
                $cfgNow['sessao_valida']   = false;
                $cfgNow['sessao_vista_em'] = now()->toDateTimeString();
                $cfgNow['sessao_motivo']   = 'worker viu login wall ao tentar gerar (task ' . $taskId . ')';
                $cfgNow['sessao_fonte']    = 'worker';
                // ══ CAMPO PROPRIO, QUE A SONDA NAO PISA ═══════════════════════
                // MEDIDO: marcar `sessao_valida=false` aqui nao segurava nada — o
                // `video:lease-guardiao` roda a cada 3min e regravava `true`, e o
                // motor voltava pra roda pra falhar de novo (sellerglobal01 falhou
                // 9x seguidas em ~14s cada, 22:48->23:04).
                //
                // A sonda e o worker NAO estao olhando pra mesma coisa, e por isso
                // os dois podem estar certos: a sonda abre o COFRE DE COOKIES
                // (sessoes\<motor>.json) e ve sessao boa; o worker entra no CHROME
                // QUENTE (porta CDP) e ve tela de login. Sao dois estados distintos
                // do mesmo motor. Entao o worker escreve num campo so dele, e o
                // portao de reserva respeita esse campo por 10 minutos.
                $cfgNow['worker_loginwall_em'] = now()->toDateTimeString();
                $cfgNow['worker_loginwall_task'] = (string) $taskId;
                \DB::table('ai_engines')->where('id', $this->engine->id)
                    ->update(['config_json' => json_encode($cfgNow, JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);
                \Illuminate\Support\Facades\Log::error('[SEL-ECOSSISTEMA] motor deslogado NA TENTATIVA — tirei da roda', [
                    'engine'    => $this->engine->name,
                    'engine_id' => $this->engine->id,
                    'task'      => $taskId,
                    'nota'      => 'sessao_valida=false ate a sonda revalidar',
                ]);
                \App\Services\Ai\VideoResilience::registrar('MOTOR_DESLOGADO_NA_TENTATIVA', [
                    'engine' => $this->engine->name, 'engine_id' => $this->engine->id, 'task' => $taskId,
                ]);
            } catch (\Throwable $e) { /* secundario: nunca derruba a geracao */ }
        }
        if (!$decoded || empty($decoded['ok'])) {
            // SEL-RESILIENCIA (12/08): quantas voltas de resgate valem a pena.
            // Se o worker chegou a imprimir SALVO/gen-id, o video EXISTE (ou vai
            // existir em segundos) -> vale esperar 3min. Sem sinal de entrega,
            // 3 voltas rapidas so pra cobrir o caso do SSH que caiu no ultimo
            // instante -- alem disso e queimar tempo do cliente a toa.
            $voltas    = $__salvou ? 18 : 3;
            $intervalo = $__salvou ? 10 : 5;
            $recovered = false;
            for ($t = 0; $t < $voltas; $t++) {
                sleep($intervalo);
                $try = new Process(array_merge(['scp'], $scpOpts, [$target . ':' . str_replace('\\', '/', $pcOut), $localOut]));
                $try->setTimeout(90);
                try { $try->run(); } catch (\Throwable $e) {}
                if (is_file($localOut) && filesize($localOut) > 500000) { $recovered = true; break; }
                @unlink($localOut);
                \App\Services\Ai\VideoResilience::batida($__pipe ? (int) $__pipe : null, $__rotulo);
            }
            if (!$recovered) {
                // SEL-BUSCA-ANTES-DE-REFAZER (16/08) — PARTE A: a gente esta desistindo
                // desta tentativa, mas o Google pode entregar depois (no gratis, "Lower
                // Priority", isso e comum: a midia sai 10-25min apos o nosso corte).
                // Anota o id da tarefa NO PEDIDO pra que a proxima tentativa saiba onde
                // procurar o mp4 antes de gerar tudo de novo. Guardamos so os 3 ultimos.
                try {
                    if ($__pipe) {
                        $__linha = \Illuminate\Support\Facades\DB::table('ai_video_pipelines')
                            ->where('id', (int) $__pipe)->value('payloads');
                        $__pl = json_decode((string) $__linha ?: '{}', true) ?: [];
                        $__ant = array_values(array_filter((array) ($__pl['_tentativas_pc'] ?? [])));
                        if (! in_array($taskId, $__ant, true)) { $__ant[] = $taskId; }
                        $__pl['_tentativas_pc'] = array_slice($__ant, -3);
                        \Illuminate\Support\Facades\DB::table('ai_video_pipelines')
                            ->where('id', (int) $__pipe)
                            ->update(['payloads' => json_encode($__pl, JSON_UNESCAPED_SLASHES)]);
                    }
                } catch (\Throwable $eAnota) { /* anotar e secundario: nunca derruba nada */ }
                \App\Services\Ai\VideoResilience::registrar('RESGATE_SEM_ARQUIVO', [
                    'task' => $taskId, 'pipeline' => $__pipe, 'corte_em' => $__corte,
                    'tinha_salvo' => $__salvou, 'voltas' => $voltas,
                ]);
                throw new \RuntimeException(
                    ($__corte ?: 'remote_worker_failed') . ': '
                    . mb_substr($run->getErrorOutput() ?: $run->getOutput(), 0, 300)
                );
            }
            \App\Services\Ai\VideoResilience::registrar('VIDEO_RESGATADO', [
                'task' => $taskId, 'pipeline' => $__pipe, 'corte_em' => $__corte,
                'tinha_salvo' => $__salvou, 'bytes' => @filesize($localOut),
            ]);
            \Illuminate\Support\Facades\Log::warning('[SEL-RESILIENCIA][MacFlow] VIDEO RESGATADO: o processo morreu mas o mp4 estava no PC', [
                'task'   => $taskId,
                'corte'  => $__corte,
                'salvou' => $__salvou,
                'bytes'  => @filesize($localOut),
            ]);
            $decoded = ['ok' => true, 'output_url' => $pcOut, 'recovered' => true];
        }

        // 4) puxa o mp4 do PC -> servidor (KlingBrowserGenerateJob move pro storage publico)
        $localOut = storage_path('app/remote-video/' . $safe . '.mp4');
        if (!is_dir(dirname($localOut))) { @mkdir(dirname($localOut), 0775, true); }
        $dl = new Process(array_merge(['scp'], $scpOpts, [$target . ':' . str_replace('\\', '/', $pcOut), $localOut]));
        $dl->setTimeout(180); $dl->run();
        if (!$dl->isSuccessful() || !is_file($localOut)) {
            throw new \RuntimeException('remote_scp_pull_failed: ' . mb_substr($dl->getErrorOutput(), 0, 200));
        }
        $decoded['output_url'] = $localOut;
        $decoded['source_url'] = $localOut;
        $decoded['mp4']        = $localOut;
        $decoded['file_bytes'] = @filesize($localOut) ?: ($decoded['file_bytes'] ?? null);

        // limpa temporarios no PC (nao trava se falhar)
        try {
            $rmCmd = 'del /q "' . $pcOut . '"' . ($pcImg ? ' "' . $pcImg . '"' : '');
            $rm = new Process(array_merge(['ssh'], $sshOpts, [$target, $rmCmd]));
            $rm->setTimeout(30); $rm->run();
        } catch (\Throwable $e) {}

        // guarda saldo (alerta de credito baixo no Data Center) — SECUNDARIO: nunca
        // derruba a geracao. Usa DB::table direto pra NAO persistir atributos runtime
        // do pool (_pool_lock/_pool_lock_key) que nao sao colunas -> era o SQLSTATE que
        // falhava o job MESMO com o video pronto (SEL-pc-fase2 fix).
        try {
            if (isset($decoded['saldo_depois']) && is_numeric($decoded['saldo_depois'])) {
                $cfgNow = $this->engine->config_json ?? [];
                if (!is_array($cfgNow)) { $cfgNow = json_decode((string) $cfgNow ?: '{}', true) ?: []; }
                $cfgNow['saldo']    = (int) $decoded['saldo_depois'];
                $cfgNow['saldo_em'] = now()->toDateTimeString();
                \DB::table('ai_engines')->where('id', $this->engine->id)
                    ->update(['config_json' => json_encode($cfgNow, JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);
            }
        } catch (\Throwable $e) { /* saldo e secundario */ }
        return $decoded;
    }
}
