<?php

namespace App\Console\Commands;

use App\Models\AiEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * SEL-ENGRENAGEM (12/08) — SINCRONIA DA FROTA + RETRATO AO VIVO.
 *
 * O problema que este comando resolve (medido 12/08 19h):
 *   - `ai_engines` dizia 12 motores de video ATIVOS.
 *   - `pool.json` no PC do Ruan dizia 4 perfis `ready` e 7 em `login_wall`.
 *   - Ninguem cruzava as duas coisas. O pool reservava motor deslogado, o worker
 *     subia, batia no login e pendurava ate o teto de 420-720s. Ai o job retentava
 *     em OUTRO motor tambem deslogado. Tres tentativas assim = 20+ minutos, e o
 *     cliente recebia "A geracao demorou demais". Os clientes u2942 e u645
 *     perderam video hoje exatamente assim (pipelines 874/879).
 *
 * O que ele faz, a cada minuto:
 *   1. LE o pool.json do PC (SSH, timeout curto) -- fonte da verdade do estado real
 *      de cada perfil de Chrome.
 *   2. ESCREVE em cada `ai_engines.config_json`: `pc_status`, `pc_port`,
 *      `pc_status_em`. O AiEnginePool::estadoDaFrota() le isso e para de reservar
 *      quem nao esta `ready`.
 *   3. CURA sozinho: perfil que voltou pra `ready` tem `precisa_reconectar` limpo e
 *      volta pro pool na hora, sem ninguem mexer.
 *   4. NAO RELIGA quem foi desligado de proposito: `fora_do_ar_motivo` /
 *      `desligado_manual` sao respeitados (mesma regra do sentinela-unico.sh). O
 *      motor 14 continua fora ate alguem APAGAR o motivo a mao.
 *   5. NAO TENTA LOGAR. Sessao do Google que caiu com pedido de senha e do Ruan --
 *      aqui a gente so registra (`pc_status=login_wall` + linha no retrato) pra o
 *      painel mostrar e o alerta existente disparar.
 *   6. PUBLICA o retrato unico da frota em storage/app/frota-video.json, juntando
 *      pool.json (estado do PC) + ai_engines (conta/saldo/nota) + ai_engine_usage
 *      (quantas gerou hoje / ociosidade) + ai_video_pipelines (o que esta gerando
 *      agora, ultimas entregas, tempo medio). E so LEITURA dos monitores que ja
 *      existem -- nao mexe em vigia-saldo/conferente/sentinela-p2p.
 *
 * Fail-open em tudo: PC fora do ar, pool.json ilegivel ou SSH lento NAO derrubam
 * motor nenhum -- a leitura velha expira sozinha (FROTA_FRESCA_S no pool) e a frota
 * volta a se comportar como antes do ticket.
 */
class VideoFrotaSync extends Command
{
    protected $signature = 'video:frota-sync
                            {--timeout=25 : segundos de teto pro SSH que le o pool.json}
                            {--so-retrato : nao escreve no banco, so republica o JSON do painel}';

    protected $description = 'Sincroniza o estado REAL dos perfis do PC (pool.json) com ai_engines e publica o retrato da frota.';

    /** Onde o painel admin le o retrato. */
    public const RETRATO = 'frota-video.json';

    public function handle(): int
    {
        $pool = $this->lerPoolDoPc((int) $this->option('timeout'));

        $mudou = [];
        if (! $this->option('so-retrato') && $pool !== null) {
            $mudou = $this->sincronizar($pool);
        }

        $this->publicarRetrato($pool, $mudou);

        $this->info(json_encode([
            'pool_lido' => $pool !== null,
            'perfis'    => $pool ? count($pool) : 0,
            'mudancas'  => $mudou,
        ], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    // -- 1) leitura do PC ------------------------------------------------------

    /**
     * Devolve [perfil => ['status'=>..., 'port'=>...]] ou NULL se nao deu pra ler.
     * NULL e diferente de vazio: NULL = "nao sei" -> nao mexe em nada.
     */
    private function lerPoolDoPc(int $timeout): ?array
    {
        // Reaproveita o `remote` que os proprios motores ja usam -- zero config nova.
        $remote = null;
        foreach (AiEngine::where('tool_type', 'video')->get() as $e) {
            $cfg = is_array($e->config_json) ? $e->config_json : (json_decode($e->config_json ?? '{}', true) ?: []);
            if (! empty($cfg['remote']['host'])) { $remote = $cfg['remote']; break; }
        }
        if (! $remote) {
            Log::warning('[SEL-ENGRENAGEM][frota-sync] nenhum motor com config remote; nada a sincronizar');
            return null;
        }

        $wdir = $remote['worker_dir'] ?? 'C:\\sellerglobal-render';
        $cmd  = 'type "' . $wdir . '\\pool.json"';

        $p = new Process(array_merge(['ssh'], [
            '-i', $remote['ssh_key'] ?? '/home/api.seller.global/.ssh/pc-render',
            '-p', (string) ($remote['port'] ?? 2200),
            '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=no',
            // SEL-TUNEL-MULTIPLEX-BACKEND (14/08): reaproveita UMA conexao com o PC
            // em vez de abrir uma nova a cada chamada (era isso que estourava o
            // limite de conexoes simultaneas do sshd do Windows e derrubava o tunel).
            '-o', 'ControlMaster=auto', '-o', 'ControlPath=/tmp/.ssh-mux-backend-%r@%h-%p', '-o', 'ControlPersist=120',
            '-o', 'ConnectTimeout=8', '-o', 'ServerAliveInterval=5',
            ($remote['user'] ?? 'ruan') . '@' . ($remote['host'] ?? 'localhost'),
            $cmd,
        ]));
        $p->setTimeout(max(8, $timeout));

        // SEL-TUNEL-RETRY (14/08): o tunel reverso pro PC e AUTO-CURAVEL — cai e volta
        // sozinho em segundos. O runbook manda um humano tentar 3 VEZES antes de dizer
        // que caiu; aqui o codigo faz o mesmo, em vez de gritar no log a cada piscada.
        // Medido hoje: 37 erros numa hora, quase todos transitorios. Multiplexar cortou
        // pra ~4/h; o retry cobre o resto. So vira ERROR se as 3 tentativas falharem.
        $tentativas = 0;
        $ultimoErro = '';
        while ($tentativas < 3) {
            $tentativas++;
            try {
                $p->run();
                if ($p->isSuccessful()) { break; }
                $ultimoErro = 'exit ' . $p->getExitCode() . ': ' . mb_substr($p->getErrorOutput(), 0, 120);
            } catch (\Throwable $e) {
                $ultimoErro = mb_substr($e->getMessage(), 0, 120);
            }
            // SEL-MUX-SOCKET-VELHO (14/08): efeito colateral da multiplexacao — quando a
            // conexao MESTRE morre, o socket fica orfao e toda chamada seguinte falha com
            // "mux_client_request_session", mesmo com o tunel ja de volta. Medido 1x em
            // 30min. Apagar o socket faz a proxima tentativa abrir conexao nova e curar.
            if (str_contains($ultimoErro, 'mux_client_request_session') || str_contains($ultimoErro, 'control socket')) {
                foreach (glob('/tmp/.ssh-mux-backend-*') ?: [] as $socketVelho) {
                    @unlink($socketVelho);
                }
                Log::info('[SEL-MUX-SOCKET-VELHO] socket de multiplexacao orfao removido; proxima tentativa abre conexao nova');
            }
            if ($tentativas < 3) { usleep(1500000); }   // 1,5s e o tunel costuma voltar
        }

        if (! $p->isSuccessful()) {
            Log::error('[SEL-ENGRENAGEM][frota-sync] PC nao devolveu pool.json apos 3 tentativas', [
                'erro' => $ultimoErro,
            ]);
            return null;
        }

        $j = json_decode(trim($p->getOutput()), true);
        if (! is_array($j) || empty($j['engines'])) {
            return null;
        }

        // heartbeat velho = daemon parado -> a leitura nao vale (fail-open)
        try {
            $hb = \Illuminate\Support\Carbon::parse($j['heartbeat'] ?? $j['updated'] ?? 'now');
            if ($hb->lt(now()->subMinutes(10))) {
                Log::error('[SEL-ENGRENAGEM][frota-sync] pool.json velho, ignorando', ['heartbeat' => (string) $hb]);
                return null;
            }
        } catch (\Throwable $e) { /* sem data legivel -> aceita */ }

        $mapa = [];
        foreach ($j['engines'] as $e) {
            if (empty($e['profile'])) { continue; }
            $mapa[(string) $e['profile']] = [
                'status' => (string) ($e['status'] ?? 'desconhecido'),
                'port'   => $e['port'] ?? null,
            ];
        }
        return $mapa ?: null;
    }

    // -- 2/3/4) sincronia com o banco -----------------------------------------

    private function sincronizar(array $pool): array
    {
        $mudou = [];

        foreach (AiEngine::where('tool_type', 'video')->get() as $engine) {
            $cfg = is_array($engine->config_json) ? $engine->config_json : (json_decode($engine->config_json ?? '{}', true) ?: []);

            // Motor desligado a mao NAO e tocado -- nem pra marcar status, nem pra curar.
            if (! empty($cfg['fora_do_ar_motivo']) || ! empty($cfg['desligado_manual'])) {
                continue;
            }

            $perfil = $cfg['perfil_pc'] ?? $cfg['profile'] ?? null;
            if (! $perfil || ! isset($pool[$perfil])) {
                continue; // motor sem perfil no PC (ex.: Mac) -> nao e assunto deste sync
            }

            $novo    = $pool[$perfil]['status'];
            $antigo  = $cfg['pc_status'] ?? null;
            $anotado = false;

            $cfg['pc_status']    = $novo;
            $cfg['pc_port']      = $pool[$perfil]['port'];
            $cfg['pc_status_em'] = now()->toDateTimeString();

            // CURA: voltou a ficar pronto -> limpa a flag que o mantinha fora do pool.
            if ($novo === 'ready' && ! empty($cfg['precisa_reconectar'])) {
                $cfg['precisa_reconectar'] = false;
                $cfg['reconectou_em']      = now()->toDateTimeString();
                $mudou[] = $engine->name . ': voltou (login recuperado)';
                $anotado = true;
            }

            // QUEDA: pediu senha -> registra. NAO tenta logar (regra dura: so o Ruan).
            // SEL-SONDAMANDA-SYNC (12/08) — o pool.json do PC nao pode carimbar
            // "precisa relogar" em cima de conta que a NOSSA SONDA acabou de ver logada.
            //
            // O pool_daemon marca um perfil como `login_wall` e NUNCA re-verifica
            // (`if (e.status === 'login_wall') continue;`) — fica esperando o Ruan pra
            // sempre. Medido hoje: o Ruan relogou 5 contas, a sonda confirmou as 5 pela
            // API do proprio Flow, eu limpei o carimbo, e este sync repos um minuto
            // depois lendo o retrato velho. Os 5 motores ficavam de fora eternamente.
            //
            // Regra: a sonda (que pergunta ao Flow e recebe o e-mail) manda mais que o
            // retrato do PC. Sem leitura fresca da sonda, o comportamento e o de antes.
            $sondaViva = ! empty($cfg['sessao_valida']) && ! empty($cfg['sessao_email'])
                && ! empty($cfg['sessao_vista_em'])
                // SEL-DIVIDA (13/08): janela unificada com o pool. Era subMinutes(15)
                // contra os 600s do AiEnginePool — mesma leitura, dois vereditos.
                && \Illuminate\Support\Carbon::parse($cfg['sessao_vista_em'])
                    ->gt(now()->subSeconds(\App\Services\Ai\AiEnginePool::SESSAO_FRESCA_S));

            if ($novo === 'login_wall' && $sondaViva) {
                $cfg['precisa_reconectar'] = false;
                $cfg['pc_status']          = 'ready';   // o retrato do PC esta velho
                $cfg['pc_status_fonte']    = 'sonda (' . $cfg['sessao_email'] . ') venceu pool.json';
                $mudou[] = $engine->name . ': PC dizia deslogado, sonda achou ' . $cfg['sessao_email'] . ' logado -> liberado';
            } elseif ($novo === 'login_wall' && empty($cfg['precisa_reconectar'])) {
                $cfg['precisa_reconectar'] = true;
                $cfg['deslogou_em']        = now()->toDateTimeString();
                $mudou[] = $engine->name . ': deslogou (login_wall no PC, precisa do Ruan)';
                Log::error('[SEL-ENGRENAGEM][frota-sync] motor deslogado, fora do pool ate o Ruan relogar', [
                    'engine' => $engine->name, 'perfil' => $perfil,
                ]);
                $anotado = true;
            }

            if ($novo !== $antigo && ! $anotado) {
                $mudou[] = $engine->name . ': ' . ($antigo ?? 'sem_leitura') . " -> {$novo}";
            }

            $engine->update(['config_json' => $cfg]);
        }

        return $mudou;
    }

    // -- 6) retrato unico da frota --------------------------------------------

    private function publicarRetrato(?array $pool, array $mudou): void
    {
        $hoje = now()->utc()->toDateString();

        $uso = DB::table('ai_engine_usage')->where('date', $hoje)
            ->get(['engine_id', 'generated_count', 'reserved_count', 'last_used_at'])
            ->keyBy('engine_id');

        // O que cada motor esta gerando AGORA: pipeline em render que carimbou este motor.
        $gerandoAgora = DB::table('ai_video_pipelines')
            ->whereIn('step', ['render', 'queued', 'processing'])
            ->where('updated_at', '>', now()->subHour())
            ->get(['id', 'user_id', 'mode', 'step', 'payloads', 'created_at', 'updated_at']);

        // ══ SEL-ECOSSISTEMA (12/08) — INDICE DE CADEIA DE CUSTODIA ════════════
        //
        // `payloads.dono` (StudioLongVideoJob) passou a guardar, corte a corte, o
        // motor que rodou, o gen-id do Google, o md5 e os carimbos de tempo. Aqui
        // a gente vira esse dado do avesso: de "pipeline -> motores" pra
        // "motor -> o que ele fez". E o que responde, sem abrir log de navegador:
        // o que ESTE motor esta fazendo agora, o que ele entregou e onde tropecou.
        $porMotor = [];
        $agoraPorMotor = [];
        try {
            $recentes = DB::table('ai_video_pipelines')
                ->where('updated_at', '>', now()->subHours(6))
                ->orderByDesc('id')->limit(400)
                ->get(['id', 'user_id', 'step', 'updated_at', 'payloads']);

            foreach ($recentes as $p) {
                $pl = json_decode($p->payloads ?? '{}', true) ?: [];
                foreach (($pl['dono']['cortes'] ?? []) as $c) {
                    $eid = $c['engine_id'] ?? null;
                    if (! $eid) { continue; }
                    $reg = [
                        'pipeline' => $p->id,
                        'user_id'  => $p->user_id,
                        'corte'    => $c['corte'] ?? null,
                        'gen_id'   => $c['gen_id'] ?? null,
                        'quando'   => $c['fim'] ?? null,
                        'took_s'   => $c['took_s'] ?? null,
                    ];
                    if (($c['resultado'] ?? '') === 'falhou') {
                        $reg['erro']   = $c['erro'] ?? null;
                        $reg['classe'] = $c['classe'] ?? null;
                        $porMotor[$eid]['falhas'][] = $reg;
                    } else {
                        $porMotor[$eid]['entregas'][] = $reg;
                    }
                    // "o que esta fazendo AGORA": corte carimbado por uma pipeline
                    // que segue em render e bateu ha pouco.
                    if ($p->step === 'render' && strtotime($p->updated_at) > time() - 300) {
                        $agoraPorMotor[$eid] = ['pipeline' => $p->id, 'user_id' => $p->user_id];
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[SEL-ECOSSISTEMA][frota-sync] indice de custodia falhou (retrato segue sem ele)', [
                'err' => mb_substr($e->getMessage(), 0, 140),
            ]);
        }

        $motores = [];
        foreach (AiEngine::where('tool_type', 'video')->orderBy('id')->get() as $e) {
            $cfg = is_array($e->config_json) ? $e->config_json : (json_decode($e->config_json ?? '{}', true) ?: []);
            $u   = $uso[$e->id] ?? null;

            $estado = 'livre';
            if (! empty($cfg['fora_do_ar_motivo']) || ! empty($cfg['desligado_manual'])) {
                $estado = 'desligado_manual';
            } elseif (! empty($cfg['precisa_reconectar']) || (($cfg['pc_status'] ?? '') !== '' && ($cfg['pc_status'] ?? '') !== 'ready')) {
                $estado = 'caido';
            } elseif ($e->cooldown_until && \Illuminate\Support\Carbon::parse($e->cooldown_until)->gt(now())) {
                $estado = 'cooldown';
            } elseif ($this->estaTrancado($e->id)) {
                $estado = 'gerando';
            }

            $motores[] = [
                'engine_id'      => $e->id,
                'nome'           => $e->name,
                'conta'          => $cfg['conta_email'] ?? $cfg['account_email'] ?? null,
                'perfil_pc'      => $cfg['perfil_pc'] ?? $cfg['profile'] ?? null,
                'porta_cdp'      => $cfg['pc_port'] ?? $cfg['porta'] ?? null,
                'estado'         => $estado,
                'pc_status'      => $cfg['pc_status'] ?? null,
                'pc_status_em'   => $cfg['pc_status_em'] ?? null,
                'modelo'         => $cfg['veo_model'] ?? null,
                'gratis'         => stripos((string) ($cfg['veo_model'] ?? ''), 'lower priority') !== false,
                'saldo'          => $cfg['saldo'] ?? null,
                'nota_saude'     => $e->success_rate_24h,
                'ultima_falha'   => $e->last_failure_at ? (string) $e->last_failure_at : null,
                'ultimo_erro'    => $e->last_error ? mb_substr($e->last_error, 0, 140) : null,
                'gerou_hoje'     => $u ? (int) $u->generated_count : 0,
                'reservado_hoje' => $u ? (int) $u->reserved_count : 0,
                'ocioso_desde'   => $u->last_used_at ?? null,
                'motivo_fora'    => $cfg['fora_do_ar_motivo'] ?? $cfg['desligado_manual'] ?? null,

                // ══ SEL-ECOSSISTEMA — o que o ticket pediu, motor a motor ══
                // "sessão válida até": o veredito e a validade vem da sonda
                // (video:lease-guardiao), nao do palpite do pool.json.
                'sessao_valida'    => $cfg['sessao_valida'] ?? null,
                'sessao_expira'    => $cfg['sessao_expira'] ?? null,
                'sessao_vista_em'  => $cfg['sessao_vista_em'] ?? null,
                'sessao_conta'     => $cfg['sessao_email'] ?? null,
                'sessao_motivo'    => $cfg['sessao_motivo'] ?? null,
                // "há quanto tempo" naquele estado: o retrato so dizia QUAL era o
                // estado, nunca DESDE QUANDO — e "caído há 2min" e "caído há 3h"
                // sao problemas diferentes.
                'estado_desde'     => $estadoDesde = ($cfg['pc_status_em'] ?? $cfg['sessao_vista_em'] ?? null),
                'estado_ha_s'      => $estadoDesde ? max(0, time() - strtotime((string) $estadoDesde)) : null,
                // "o que está fazendo": pedido e cliente que estao neste motor agora.
                'fazendo_agora'    => $agoraPorMotor[$e->id] ?? null,
                // "últimas entregas" e "últimas falhas" DESTE motor.
                'ultimas_entregas' => array_slice(array_reverse($porMotor[$e->id]['entregas'] ?? []), 0, 5),
                'ultimas_falhas'   => array_slice(array_reverse($porMotor[$e->id]['falhas'] ?? []), 0, 5),
                'entregas_6h'      => count($porMotor[$e->id]['entregas'] ?? []),
                'falhas_6h'        => count($porMotor[$e->id]['falhas'] ?? []),
            ];
        }

        // Tempo medio + ultimas entregas (24h), da mesma tabela que o cliente ve.
        $entregas = DB::table('ai_video_pipelines')
            ->where('step', 'done')->whereNotNull('output_url')
            ->where('updated_at', '>', now()->subDay())
            ->orderByDesc('id')->limit(15)
            ->get(['id', 'user_id', 'mode', 'created_at', 'updated_at']);

        $duracoes = [];
        foreach ($entregas as $d) {
            $duracoes[] = max(0, strtotime($d->updated_at) - strtotime($d->created_at));
        }

        $falhas24 = DB::table('ai_video_pipelines')->where('step', 'failed')
            ->where('updated_at', '>', now()->subDay())->count();
        $ok24 = DB::table('ai_video_pipelines')->where('step', 'done')
            ->whereNotNull('output_url')->where('updated_at', '>', now()->subDay())->count();

        $retrato = [
            'quando'   => now()->toIso8601String(),
            'fonte'    => 'video:frota-sync',
            'pc'       => [
                'lido'    => $pool !== null,
                'perfis'  => $pool ? count($pool) : 0,
                'prontos' => $pool ? count(array_filter($pool, fn($p) => $p['status'] === 'ready')) : null,
            ],
            'resumo'   => [
                'motores_total'    => count($motores),
                'motores_no_pool'  => count(array_filter($motores, fn($m) => in_array($m['estado'], ['livre', 'gerando'], true))),
                'gerando'          => count(array_filter($motores, fn($m) => $m['estado'] === 'gerando')),
                'caidos'           => count(array_filter($motores, fn($m) => $m['estado'] === 'caido')),
                'desligados'       => count(array_filter($motores, fn($m) => $m['estado'] === 'desligado_manual')),
                'entregues_24h'    => $ok24,
                'falhas_24h'       => $falhas24,
                'sucesso_24h_pct'  => ($ok24 + $falhas24) > 0 ? round($ok24 * 100 / ($ok24 + $falhas24), 1) : null,
                'tempo_medio_s'    => $duracoes ? (int) round(array_sum($duracoes) / count($duracoes)) : null,
            ],
            'motores'  => $motores,
            'na_fila'  => $gerandoAgora->map(fn($p) => [
                'pipeline'  => $p->id,
                'user_id'   => $p->user_id,
                'modo'      => $p->mode,
                'step'      => $p->step,
                'rotulo'    => data_get(json_decode($p->payloads ?? '{}', true), 'step_label'),
                'ha_s'      => max(0, time() - strtotime($p->created_at)),
            ])->values()->all(),
            'entregas' => $entregas->map(fn($d) => [
                'pipeline' => $d->id, 'user_id' => $d->user_id, 'modo' => $d->mode,
                'levou_s'  => max(0, strtotime($d->updated_at) - strtotime($d->created_at)),
                'quando'   => $d->updated_at,
            ])->values()->all(),
            'mudancas' => $mudou,
            'vizinhos' => $this->lerMonitoresExistentes(),

            // ══ SEL-ECOSSISTEMA (12/08) — o que faltava pro retrato ser "ao vivo" ══
            // Revezamento: quem esta na roda, quantos motores cada cliente segura e
            // qual e a cota do momento. Sem isso o painel mostra a frota ocupada e
            // nao explica POR QUE o pedido de alguem nao anda.
            'revezamento' => \App\Services\Ai\VideoRodizio::retrato(),
            // Eventos: a fita do que aconteceu (comecou / terminou / falhou / motor
            // caiu / motor voltou / troca de motor / recusa por dono). "Nada pode
            // acontecer em silencio" — entao o mesmo endpoint que mostra o estado
            // mostra a historia recente que levou ate ele.
            'eventos' => $this->ultimosEventos(40),
        ];

        try {
            // Caminho FIXO (nao Storage::disk) porque o painel e os scripts de root leem
            // este arquivo por path; disk local muda de pasta entre versoes do Laravel.
            @file_put_contents(
                storage_path('app/' . self::RETRATO),
                json_encode($retrato, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        } catch (\Throwable $e) {
            Log::warning('[SEL-ENGRENAGEM][frota-sync] nao consegui gravar o retrato', ['err' => $e->getMessage()]);
        }
    }

    /**
     * SEL-ECOSSISTEMA (12/08) — a fita de eventos, legivel por API.
     *
     * A trilha ja existia em storage/logs/resiliencia-video.log (VideoResilience::
     * registrar), mas so dava pra ler por SSH. Aqui ela entra no mesmo retrato que
     * o painel consome, ja parseada, mais nova primeiro. Fail-open: se o arquivo
     * sumir ou estiver ilegivel, o retrato sai sem eventos em vez de nao sair.
     */
    private function ultimosEventos(int $quantos): array
    {
        try {
            $arq = storage_path('logs/resiliencia-video.log');
            if (! is_readable($arq)) { return []; }

            // Le so a cauda do arquivo: ele cresce o dia inteiro e o retrato roda
            // a cada minuto — carregar tudo seria desperdicio garantido.
            $tam = (int) @filesize($arq);
            $fh  = @fopen($arq, 'rb');
            if (! $fh) { return []; }
            $bytes = 120 * 1024;
            if ($tam > $bytes) { fseek($fh, -$bytes, SEEK_END); fgets($fh); }
            $linhas = [];
            while (($l = fgets($fh)) !== false) { $linhas[] = rtrim($l, "\r\n"); }
            fclose($fh);

            $out = [];
            foreach (array_reverse($linhas) as $l) {
                if ($l === '' || ! preg_match('/^\[([\d\- :]+)\] (\S+) (\{.*\})$/', $l, $m)) { continue; }
                $out[] = [
                    'quando' => $m[1],
                    'evento' => $m[2],
                    'dados'  => json_decode($m[3], true) ?: [],
                ];
                if (count($out) >= $quantos) { break; }
            }

            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** O motor esta com o lock do pool tomado (= tem job rodando nele agora)? */
    private function estaTrancado(int $engineId): bool
    {
        try {
            // SEL-LEASE (12/08): faltava o prefixo do CACHE. A chave real e
            // <prefixo_database><prefixo_cache>ai_engine_lock:<id> -- o cliente Redis
            // poe o do database sozinho, o do cache e por nossa conta. Sem ele o
            // exists() dava 0 SEMPRE: o retrato da frota nunca mostrou "gerando",
            // nenhum motor ocupado aparecia ocupado no painel.
            return \Illuminate\Support\Facades\Cache::store('redis')
                ->getStore()->getRedis()
                ->exists((string) config('cache.prefix') . 'ai_engine_lock:' . $engineId) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Le (SO LE) os retratos que os monitores do Ruan ja publicam, pra o painel ter
     * tudo numa tela. Nao escreve neles, nao depende deles.
     */
    private function lerMonitoresExistentes(): array
    {
        $saida = [];
        // Espelho legivel (/root e 700). O espelha-monitores.sh copia pra ca a cada
        // minuto; se o espelho faltar, tenta o original -- nunca escreve em nenhum.
        $dir = '/home/api.seller.global/storage/monitores/';
        foreach ([
            'saldo'         => [$dir . 'vigia-saldo.json', '/root/vigia-saldo.json'],
            'ponta_a_ponta' => [$dir . 'sentinela-p2p.json', '/root/sentinela-p2p.json'],
            'conferente'    => [$dir . 'conferente-video.json', '/root/conferente-video.json'],
        ] as $nome => $caminhos) {
            $caminho = null;
            foreach ($caminhos as $c) { if (is_readable($c)) { $caminho = $c; break; } }
            $caminho = $caminho ?? $caminhos[0];
            try {
                if (is_readable($caminho)) {
                    $saida[$nome] = json_decode((string) file_get_contents($caminho), true);
                } else {
                    $saida[$nome] = ['erro' => 'sem permissao de leitura (roda como root)'];
                }
            } catch (\Throwable $e) {
                $saida[$nome] = ['erro' => mb_substr($e->getMessage(), 0, 100)];
            }
        }
        return $saida;
    }
}
