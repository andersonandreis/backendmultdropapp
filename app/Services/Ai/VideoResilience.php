<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SEL-RESILIENCIA (12/08, Ruan: "nenhuma falha de infraestrutura pode custar o
 * video do cliente").
 *
 * Tres coisas moram aqui, e so tres:
 *
 *  1. CLASSIFICAR o erro. Ate hoje TUDO virava `failed` igual: navegador que a
 *     gente mesmo fechou, tunel que caiu, motor ocupado e prompt recusado pelo
 *     Google tinham exatamente o mesmo destino -- a cara do cliente. Sao coisas
 *     diferentes:
 *       - INFRA      = culpa NOSSA (Chrome fechado, exit=255, timeout, perfil
 *                      ocupado, worker sem mp4). NUNCA e failed: volta pra fila
 *                      e tenta em OUTRO motor.
 *       - CAPACIDADE = nao ha motor livre AGORA. Tambem nao e failed: e FILA.
 *                      Nao consome tentativa nem teto de render.
 *       - CONTEUDO   = o pedido em si nao passa (prompt recusado, conta sem
 *                      permissao/deslogada, imagem que nao existe). Repetir 5x
 *                      so faz o cliente esperar mais pelo mesmo "nao". Falha na
 *                      hora, com motivo honesto.
 *     O default e INFRA de proposito: na duvida a gente TENTA DE NOVO, porque o
 *     custo de um retry desnecessario e 3 minutos e o custo de um failed
 *     indevido e o cliente sem video.
 *
 *  2. BATIDA (heartbeat). Os reapers (`render-hung-heal.sh`, `sentinela-unico.sh`)
 *     mediam vida por `updated_at`. Quem estava so ESPERANDO motor, ou renderizando
 *     um video longo de 4 cortes, ficava minutos sem tocar a linha e era ceifado
 *     como "travado" -- medido hoje: 8 pipelines de cliente mortas assim entre
 *     17h e 20h (870..879), nenhuma delas travada de verdade. Agora quem esta VIVO
 *     carimba `payloads.hb` a cada volta, e os reapers so matam quem parou de
 *     carimbar. Vida = PROGRESSO, nao relogio.
 *
 *  3. Contador de tentativas de INFRA por pipeline, pra "tenta de novo" nao virar
 *     laco infinito.
 */
class VideoResilience
{
    /** Quantas falhas de INFRA um video aguenta antes de a gente admitir derrota. */
    public const MAX_TENTATIVAS_INFRA = 5;

    /** Batida no maximo a cada N segundos (nao martelar o banco). */
    private const BATIDA_INTERVALO_S = 15;

    /** @var array<int,int> ultima batida por pipeline (memoria do processo) */
    private static array $ultimaBatida = [];

    /**
     * Diario da resiliencia, em arquivo proprio.
     *
     * O laravel.log roda com LOG_LEVEL=error: todo `Log::info`/`Log::warning`
     * daqui seria descartado, e justamente os eventos que interessam (retomei,
     * reenfileirei, resgatei um video) NAO sao erro. Sem este arquivo nao ha como
     * provar depois que a rede de seguranca funcionou -- e "funcionou" sem prova
     * e exatamente o que a gente nao aceita mais.
     */
    public static function registrar(string $evento, array $dados = []): void
    {
        try {
            $arq = storage_path('logs/resiliencia-video.log');
            @file_put_contents(
                $arq,
                '[' . now()->toDateTimeString() . '] ' . $evento . ' '
                . json_encode($dados, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
                FILE_APPEND
            );
        } catch (\Throwable $e) {
            // log e secundario
        }
    }

    // ── 1. CLASSIFICACAO ─────────────────────────────────────────────────────

    /**
     * Erro de CONTEUDO/CONTA: repetir nao resolve.
     *
     * SEL-LEASE (12/08) — esta lista deixou de ser a PRIMEIRA a ser conferida.
     * Ver a nota grande em classificar(): "frota cheia com alguem deslogado"
     * casava aqui por causa de `login_wall` e o video do cliente morria como
     * "recusado", sem retry, sem ninguem saber.
     */
    private const CONTEUDO = [
        'login_wall', 'login wall', 'precisa_reconectar', 'sign in', 'signin',
        'faca login', 'sessao expirada', 'session expired', 'unauthorized', 'not_logged',
        'sem permissao', 'sem permissão', 'no permission', 'forbidden', 'access denied',
        'content policy', 'content_policy', 'policy violation', 'violat', 'nsfw',
        'prompt recusado', 'prompt_rejected', 'prompt was rejected', 'blocked_by',
        'nao permitido', 'não permitido', 'flagged', 'safety',
        'missing_image_url', 'missing_video_url', 'video_formato_invalido',
        'image_download_failed', 'seed_download_falhou',
        'video_generation_hard_block', 'sem_assinatura', 'plano_nao_libera',
    ];

    /**
     * Erro de CAPACIDADE: nao ha motor livre. E fila, nao falha.
     *
     * SEL-LEASE (12/08) — TODO TERMO AQUI TEM QUE SER INEQUIVOCO, porque esta
     * lista agora e conferida PRIMEIRO. Um termo generico demais faz o estrago
     * espelhado: um pedido que nunca ia passar (prompt recusado) viraria "fila"
     * e ficaria voltando pra sempre, queimando motor atras de motor.
     *
     * Foi exatamente o caso de `locked`: a palavra esta DENTRO de `blocked_by`,
     * que e um termo de CONTEUDO ("prompt bloqueado pelo Google"). Com a ordem
     * invertida, todo prompt bloqueado viraria capacidade e entraria em laco.
     * O produtor real do termo e o `reserveEngine`, que escreve
     * `$engine->name . '(locked)'` -- entao o termo passa a ser `(locked)`, com
     * os parenteses, que casa com o produtor e nao casa com `blocked`.
     */
    private const CAPACIDADE = [
        'all_engines_unavailable', 'sel456_no_engines', 'no_engines',
        'engines_indisponiveis', 'sem_engine', 'quota_full', 'quota esgotada',
        'saldo_', 'sem credito', 'sem crédito', '(locked)', 'host_down',
    ];

    /**
     * Erro de INFRA: culpa nossa, retentavel. Lista existe pro LOG (dizer QUAL
     * infra), nao pra decidir -- o default ja e infra.
     */
    private const INFRA = [
        'target page, context or browser has been closed' => 'navegador_fechado',
        'browser has been closed'   => 'navegador_fechado',
        'target closed'             => 'navegador_fechado',
        'session closed'            => 'navegador_fechado',
        'protocol error'            => 'navegador_fechado',
        'locator.'                  => 'navegador_fechado',
        'exit=255'                  => 'tunel_ssh',
        'connection closed'         => 'tunel_ssh',
        'connection reset'          => 'tunel_ssh',
        'broken pipe'               => 'tunel_ssh',
        'kex_exchange'              => 'tunel_ssh',
        'connect to host'           => 'tunel_ssh',
        'remote_scp'                => 'tunel_ssh',
        'ssh'                       => 'tunel_ssh',
        'veo_hard_timeout'          => 'teto_de_tempo',
        'veo_sem_saida'             => 'worker_mudo',
        'veo_sem_progresso'         => 'preso_no_poll',
        'hard_timeout'              => 'teto_de_tempo',
        'timed out'                 => 'teto_de_tempo',
        'timeout'                   => 'teto_de_tempo',
        'profile_busy'              => 'perfil_ocupado',
        'busy.lock'                 => 'perfil_ocupado',
        'not_video_mode'            => 'tela_do_flow',
        'chip'                      => 'tela_do_flow',
        'worker_sem_mp4'            => 'worker_sem_mp4',
        'remote_worker_failed'      => 'worker_falhou',
        'mac_worker_failed'         => 'worker_falhou',
        'worker_failed'             => 'worker_falhou',
        'mover_clip_falhou'         => 'disco',
        'concat_falhou'             => 'ffmpeg',
        'sqlstate'                  => 'banco',
        'deadlock'                  => 'banco',
        'gone away'                 => 'banco',
    ];

    /** @return 'infra'|'capacidade'|'conteudo' */
    public static function classificar(?string $erro): string
    {
        $e = mb_strtolower((string) $erro);
        if ($e === '') {
            return 'infra';
        }

        // SEL-LEASE (12/08) — CAPACIDADE ANTES DE CONTEUDO. Por que a ordem virou:
        //
        // A mensagem de "nao consegui reservar motor" CARREGA DENTRO DELA a lista
        // de motivos de cada motor que foi pulado:
        //   sel456_all_engines_unavailable:video:image2video tried=[... (precisa_reconectar), ... (pc_login_wall)]
        // Com CONTEUDO na frente, `precisa_reconectar` / `login_wall` casavam
        // primeiro e o pedido era rotulado "o Google recusou" -> `failed`
        // DEFINITIVO, sem retry, sem fila. Ou seja: bastava a frota estar cheia
        // COM alguem deslogado pra o cliente perder o video. O motivo de um motor
        // que nem chegou a ser usado estava decidindo o destino do pedido.
        //
        // Invertendo, quem manda e o FATO PRINCIPAL: se a mensagem diz que nao
        // havia motor, isso e fila -- o que os motores pulados tinham nao muda o
        // desfecho. Erro de conteudo de verdade nasce do RENDER, e essas mensagens
        // nao contem termo de capacidade nenhum.
        //
        // O risco espelhado (conteudo virando fila e entrando em laco) esta
        // fechado no proprio conteudo da lista CAPACIDADE: todo termo dela e
        // inequivoco. Ver a nota do `(locked)` la em cima -- era o unico que
        // colidia (dentro de `blocked_by`).
        foreach (self::CAPACIDADE as $p) {
            if (str_contains($e, $p)) {
                return 'capacidade';
            }
        }
        foreach (self::CONTEUDO as $p) {
            if (str_contains($e, $p)) {
                return 'conteudo';
            }
        }

        return 'infra'; // default deliberado: na duvida, TENTA DE NOVO
    }

    /** Rotulo curto da infra que quebrou (so pra log/diagnostico). */
    public static function motivoInfra(?string $erro): string
    {
        $e = mb_strtolower((string) $erro);
        foreach (self::INFRA as $p => $rotulo) {
            if (str_contains($e, $p)) {
                return $rotulo;
            }
        }
        return 'desconhecido';
    }

    /** Atalho: da pra tentar de novo? (infra e capacidade sim, conteudo nao) */
    public static function ehRetentavel(?string $erro): bool
    {
        return self::classificar($erro) !== 'conteudo';
    }

    /**
     * Mensagem HONESTA pro cliente quando a gente realmente desiste. Sem jargao,
     * sem nome de provider (regra white-label), sem culpar o cliente por falha
     * nossa.
     */
    public static function mensagemCliente(?string $erro): string
    {
        return match (self::classificar($erro)) {
            'conteudo'   => 'Nao conseguimos gerar esse video com essa combinacao de foto e roteiro. '
                          . 'Troque a foto do produto ou reescreva o roteiro e gere de novo.',
            'capacidade' => 'Nossos motores de video ficaram cheios por muito tempo. '
                          . 'Seu pedido nao foi cobrado — toque em Gerar novamente.',
            default      => 'Tivemos um problema do nosso lado e nao conseguimos terminar seu video. '
                          . 'Nao foi cobrado — toque em Gerar novamente.',
        };
    }

    // ── 2. BATIDA (heartbeat) ────────────────────────────────────────────────

    /**
     * Carimba "estou vivo" na pipeline. Escreve `payloads.hb` (epoch) e, quando
     * vier, `payloads.step_label`. Throttle de 15s.
     *
     * FAIL-OPEN em tudo: heartbeat que der erro NUNCA pode derrubar um render.
     */
    public static function batida(?int $pipelineId, ?string $rotulo = null, array $extra = []): void
    {
        if (! $pipelineId) {
            return;
        }
        $agora = time();
        if (($agora - (self::$ultimaBatida[$pipelineId] ?? 0)) < self::BATIDA_INTERVALO_S && $rotulo === null && ! $extra) {
            return;
        }
        self::$ultimaBatida[$pipelineId] = $agora;

        try {
            $linha = DB::table('ai_video_pipelines')->where('id', $pipelineId)->first(['payloads']);
            if (! $linha) {
                return;
            }
            $p = json_decode((string) $linha->payloads, true);
            if (! is_array($p)) {
                $p = [];
            }
            $p['hb'] = $agora;
            if ($rotulo !== null) {
                $p['step_label'] = $rotulo;
            }
            foreach ($extra as $k => $v) {
                $p[$k] = $v;
            }
            DB::table('ai_video_pipelines')->where('id', $pipelineId)->update([
                'payloads'   => json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // heartbeat e secundario: nunca derruba geracao
        }
    }

    /** Marca o instante em que o RENDER de fato comecou (motor ja reservado). */
    public static function marcarInicioDoRender(?int $pipelineId): void
    {
        self::batida($pipelineId, null, ['render_started_at' => time(), 'fila_desde' => null]);
    }

    /** Marca que a pipeline esta ESPERANDO motor (fila honesta, nao render). */
    public static function marcarEspera(?int $pipelineId, int $posicao, int $esperaS): void
    {
        $rotulo = $posicao > 1
            ? 'Na fila — posicao ' . $posicao . '. Assim que um motor liberar, seu video comeca.'
            : 'Na fila — voce e o proximo. Assim que um motor liberar, seu video comeca.';

        self::batida($pipelineId, $rotulo, [
            'fila_desde'  => time() - $esperaS,
            'fila_pos'    => $posicao,
            'fila_espera' => $esperaS,
        ]);
    }

    /** Posicao aproximada na fila (quantos pedidos mais antigos ainda nao sairam). */
    public static function posicaoNaFila(int $pipelineId): int
    {
        try {
            return 1 + (int) DB::table('ai_video_pipelines')
                ->whereIn('step', ['queued', 'render'])
                ->where(fn ($q) => $q->whereNull('output_url')->orWhere('output_url', ''))
                ->where('id', '<', $pipelineId)
                ->where('created_at', '>', now()->subHours(2))
                ->count();
        } catch (\Throwable $e) {
            return 1;
        }
    }

    // ── 3. TENTATIVAS DE INFRA ───────────────────────────────────────────────

    /** Le quantas falhas de infra esta pipeline ja levou. */
    public static function tentativasInfra(int $pipelineId): int
    {
        try {
            $linha = DB::table('ai_video_pipelines')->where('id', $pipelineId)->first(['payloads']);
            $p     = $linha ? json_decode((string) $linha->payloads, true) : [];
            return (int) ($p['tentativas_infra'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Soma 1 e devolve o total. */
    public static function somarTentativaInfra(int $pipelineId, string $erro): int
    {
        $n = self::tentativasInfra($pipelineId) + 1;
        self::batida($pipelineId, null, [
            'tentativas_infra' => $n,
            'ultima_infra'     => mb_substr($erro, 0, 200),
            'ultima_infra_em'  => now()->toDateTimeString(),
        ]);
        $ctx = [
            'pipeline'  => $pipelineId,
            'tentativa' => $n . '/' . self::MAX_TENTATIVAS_INFRA,
            'motivo'    => self::motivoInfra($erro),
            'erro'      => mb_substr($erro, 0, 200),
        ];
        Log::warning('[SEL-RESILIENCIA] falha de infra contabilizada', $ctx);
        self::registrar('FALHA_INFRA', $ctx);
        return $n;
    }
}
