<?php

namespace App\Console\Commands;

use App\Models\AiEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * SEL-491 — SENTINELA 24/7 da geracao de video (AUTO-CONSERTA, nao so alerta).
 *
 * Consolida a rede de seguranca de video num vigia unico que roda a cada minuto.
 * Para cada pipeline recente/in-flight, ele NAO deixa o cliente preso em "95%
 * eterno": travou -> requeue forcando a proxima conta; requeuou 2x -> failed com
 * msg clara; conta deslogada/sem credito -> tira do pool e avisa; worker morto ->
 * confirma que o supervisor cobre; falhas em serie no MESMO erro -> push com o
 * erro EXATO do log do veo (nao o generico).
 *
 * TUDO que ele faz vai pra storage/logs/video-sentinela.log (o Ruan quer ver o
 * que o vigia fez) + contador diario de auto-curas na tabela settings.
 *
 * Idempotente e com cooldown por tipo de alerta (nao spamma).
 * NAO gasta credito a toa: so re-despacha geracao que o CLIENTE pediu de verdade
 * (studio_*, sem dry_run, sem output_url, travada de fato).
 */
class VideoSentinela extends Command
{
    protected $signature = 'video:sentinela
        {--stuck-min=20 : minutos travado sem avancar antes de auto-curar}
        {--max-requeue=2 : requeues antes de desistir e marcar failed}
        {--recent-hours=6 : so olha pipelines mexidas nas ultimas N horas}
        {--dry : nao age, so relata no log}
        {--no-push : executa acoes mas NAO envia push pro Ruan (uso em teste)}';

    protected $description = 'Vigia 24/7 da geracao de video: auto-conserta travamentos e avisa o Ruan.';

    /** Steps que NAO sao finais (pipeline ainda "girando"). */
    private const NAO_FINAIS = ['queued', 'render', 'processing', 'voice', 'lipsync', 'queued_wait'];

    private const LOG = '/home/api.seller.global/public_html/storage/logs/video-sentinela.log';
    private const VEO_LOG_DIR = '/tmp/veo-worker-logs';

    private int $curas = 0;
    private bool $noPush = false;

    public function handle(): int
    {
        $dry         = (bool) $this->option('dry');
        $this->noPush = (bool) $this->option('no-push');
        $stuckMin  = (int) $this->option('stuck-min');
        $maxReq    = (int) $this->option('max-requeue');
        $recentH   = (int) $this->option('recent-hours');

        // guarda: so roda no tenant certo (repo e compartilhado por 7 backends).
        if (config('app.tenant') !== 'sellerglobal') {
            return self::SUCCESS;
        }

        $this->log('==== ciclo inicio' . ($dry ? ' [DRY]' : '') . ' ====');

        try { $this->secDoneFix($dry, $recentH); }        catch (\Throwable $e) { $this->log('ERRO secDoneFix: ' . $e->getMessage()); }
        try { $this->secStuck($dry, $stuckMin, $maxReq, $recentH); } catch (\Throwable $e) { $this->log('ERRO secStuck: ' . $e->getMessage()); }
        try { $this->secEngines($dry); }                  catch (\Throwable $e) { $this->log('ERRO secEngines: ' . $e->getMessage()); }
        try { $this->secWorkers($dry); }                  catch (\Throwable $e) { $this->log('ERRO secWorkers: ' . $e->getMessage()); }
        try { $this->secSystemic($dry, $recentH); }       catch (\Throwable $e) { $this->log('ERRO secSystemic: ' . $e->getMessage()); }
        try { $this->secRessuscita($dry); }               catch (\Throwable $e) { $this->log('ERRO secRessuscita: ' . $e->getMessage()); }

        $totalHoje = $this->contadorHoje();
        $this->log("==== ciclo fim | auto-curas neste ciclo={$this->curas} | total hoje={$totalHoje} ====");
        $this->info("sentinela ok | curas ciclo={$this->curas} hoje={$totalHoje}");
        return self::SUCCESS;
    }

    /* ---- SECAO 2: output_url preenchida mas step != done -> done ---- */
    private function secDoneFix(bool $dry, int $recentH): void
    {
        $rows = DB::table('ai_video_pipelines')
            ->whereIn('step', self::NAO_FINAIS)
            ->whereNotNull('output_url')
            ->where('output_url', '!=', '')
            ->where('updated_at', '>=', now()->subHours($recentH))
            ->get(['id', 'user_id', 'step']);

        foreach ($rows as $p) {
            if ($dry) { $this->log("[DONE-FIX] pipe={$p->id} step={$p->step} tem output_url -> marcaria done"); continue; }
            DB::table('ai_video_pipelines')->where('id', $p->id)
                ->update(['step' => 'done', 'updated_at' => now()]);
            $this->cura();
            $this->log("[DONE-FIX] pipe={$p->id} user={$p->user_id} tinha video (step={$p->step}) -> DONE");
        }
    }

    /* ---- SECAO 1: travada sem video -> requeue forcando proxima conta / failed ---- */
    private function secStuck(bool $dry, int $stuckMin, int $maxReq, int $recentH): void
    {
        $limite = now()->subMinutes($stuckMin);

        $rows = DB::table('ai_video_pipelines')
            ->whereIn('step', self::NAO_FINAIS)
            ->where(fn($q) => $q->whereNull('output_url')->orWhere('output_url', ''))
            ->where('mode', 'like', 'studio%')     // so geracao do Studio (pedido real do cliente)
            // STAGING_EXCLUIDO_2957: conta de teste/staging nao dispara alerta pro Ruan (falso positivo)
            ->where(function($q){ $q->whereNull('user_id')->orWhere('user_id', '!=', 2957); })
            ->where('dry_run', 0)
            // SEL-QUEM-MAIS-ERRA-NAO-PODE-SER-INVISIVEL: duas portas, nao uma.
            //   a) parado ha $stuckMin sem mexer  (o criterio antigo)
            //   b) OU vivo ha mais de 45min sem arquivo — porque cada tentativa renova
            //      o updated_at, e sem esta porta o pedido que MAIS erra e o unico que
            //      nunca chega aqui. Foi o #1266: 83min em 'render', nunca curado.
            ->where(function ($q) use ($limite) {
                $q->where('updated_at', '<', $limite)
                  ->orWhere('created_at', '<', now()->subMinutes(45));
            })
            ->where('updated_at', '>=', now()->subHours($recentH))
            ->orderBy('id')
            ->get(['id', 'user_id', 'mode', 'step', 'retries', 'render_task_id', 'payloads', 'created_at', 'updated_at']);

        foreach ($rows as $p) {
            // quem voltou pelo mutirao nao pode entrar aqui ja 'velho': a idade
            // dele conta do recomeco, nao do created_at original.
            $payAqui = json_decode((string) ($p->payloads ?? ''), true) ?: [];
            if (! empty($payAqui['_ressuscitado_em'])
                && (time() - strtotime($payAqui['_ressuscitado_em'])) < 45 * 60
                && strtotime((string) $p->updated_at) >= strtotime($limite)) {
                continue;
            }

            $req = (int) $p->retries;

            if ($req >= $maxReq) {
                // desistiu: erro exato do veo pra mensagem interna + push
                $veoErr = $this->erroExatoDoVeo($p->render_task_id);
                if (!$dry) {
                    DB::table('ai_video_pipelines')->where('id', $p->id)->update([
                        'step'          => 'failed',
                        'error_message' => 'Nao foi possivel gerar seu video agora. Nossa equipe ja foi avisada — tente novamente em alguns minutos.',
                        'updated_at'    => now(),
                    ]);
                    $this->alertar('stuck_desistiu',
                        'Video desistiu apos ' . $maxReq . ' tentativas',
                        "Pipeline #{$p->id} (user {$p->user_id}) travou em '{$p->step}' e falhou apos {$req} requeues. Erro do motor: " . $veoErr,
                        45);
                }
                $this->log("[STUCK->FAILED] pipe={$p->id} user={$p->user_id} step={$p->step} requeues={$req} veo_err=\"{$veoErr}\"");
                continue;
            }

            // requeue: forca a PROXIMA conta do pool antes de re-despachar
            if ($dry) {
                $this->log("[STUCK] pipe={$p->id} step={$p->step} requeues={$req} -> requeuaria (proxima conta)");
                continue;
            }

            $trocou = $this->forcarProximaConta($p->id);
            DB::table('ai_video_pipelines')->where('id', $p->id)->update([
                'step'           => 'queued',
                'render_task_id' => null,          // descarta task antiga presa
                'retries'        => $req + 1,
                'error_message'  => null,
                'updated_at'     => now(),
            ]);
            $this->reDespachar($p);
            $this->cura();
            $this->log("[STUCK->REQUEUE] pipe={$p->id} user={$p->user_id} mode={$p->mode} step={$p->step} req " . ($req + 1) . "/{$maxReq} | forcou_proxima_conta={$trocou}");
        }
    }

    /**
     * Poe a conta da frente do pool em cooldown curto (7min) pra que o proximo
     * reserveEngine() caia numa conta DIFERENTE. So faz se sobrar >=2 contas vivas
     * (nunca zera o pool). Retorna o nome da conta afastada, ou 'nao(pool<=1)'.
     */
    private function forcarProximaConta(int $pipeId): string
    {
        // SEL-incidente 11/08 (TEMP): DESLIGADO. Este sideline de 7min estava
        // faminando o pool de engines durante o requeue-storm (222 tasks em
        // queued_wait, zero completions). Reativar depois que o incidente fechar.
        return 'desligado_temp_SEL_incidente_11ago';
        // ---- codigo original abaixo (inalcancavel enquanto desligado) ----
        $vivas = AiEngine::availableFor('video');
        if ($vivas->count() < 2) {
            return 'nao(pool=' . $vivas->count() . ')';
        }
        $frente = $vivas->first();
        $frente->update([
            'cooldown_until' => now()->addMinutes(7),
            'last_error'     => "sentinela: afastada 7min p/ forcar proxima conta (pipe {$pipeId})",
        ]);
        return $frente->name;
    }

    private function reDespachar(object $p): void
    {
        $onQueue = str_contains((string) $p->mode, 'long')
            ? ['\App\Jobs\StudioLongVideoJob', 'video']
            : ['\App\Jobs\StudioGenerationJob', 'video'];
        [$job, $q] = $onQueue;
        // prioridade se o payload marcou _priority high/admin
        $payloads = json_decode($p->payloads ?? '[]', true) ?: [];
        $prio = in_array(strtolower((string) ($payloads['_priority'] ?? '')), ['high', 'admin'], true);

        // SEL-velocidade item 1 (12/08): FILA POR TEMPO DE ESPERA. Toda pipeline que
        // chega aqui ja ficou travada >= --stuck-min. Re-despachar na fila `video`
        // jogava ela pro FIM (a fila database puxa por id ASC), ATRAS de todo pedido
        // novo -- e a cada rodada ela perdia de novo. `video-priority` e drenada ANTES
        // de `video` pelo mesmo worker (sellerapp-video-worker:
        // --queue=video-priority,video,video-worker), entao o mais ANTIGO passa na
        // frente sem worker novo e sem mexer na fila exclusiva `video-ruan`.
        // SEL-PASSIVO-NAO-PASSA-NA-FRENTE (15/08): a espera conta do RECOMECO.
        // Pedido que o mutirao ressuscitou tem created_at de dias atras; medir por ele
        // dava "esperou 4.000 minutos" e mandava o passivo pra fila de PRIORIDADE, na
        // frente de quem estava pedindo agora. Medido: vivo 29,8min x passivo 7,4min.
        $ressuscitado = ! empty($payloads['_ressuscitado']);
        $inicio = $payloads['_ressuscitado_em'] ?? ($p->created_at ?? now());

        $esperaMin = 0;
        try {
            $esperaMin = (int) \Carbon\Carbon::parse($inicio)->diffInMinutes(now());
        } catch (\Throwable $e) { $esperaMin = 0; }
        $velho = $esperaMin >= 10;

        // cinto e suspensorio: passivo NUNCA vai pra prioridade, doa o que doer a conta
        // de espera. Cliente vivo sempre primeiro.
        $fila = (! $ressuscitado && ($prio || $velho)) ? 'video-priority' : $q;
        // SEL-NAO-EMPILHA-JOB: se ja existe job na fila pra esta pipeline, nao
        // empilho outro. Cada ciclo criava mais um; 58 jobs para 6 pedidos vivos.
        $jaNaFila = DB::table('jobs')
            ->whereIn('queue', ['video', 'video-priority'])
            ->where('payload', 'like', '%i:' . $p->id . ';%')
            ->exists();

        if ($jaNaFila) {
            $this->log("[REQUEUE-PULADO] pipe={$p->id} ja tem job na fila — nao empilho outro");

            return;
        }

        $job::dispatch($p->id)->onQueue($fila);
        $this->log("[REQUEUE-FILA] pipe={$p->id} espera={$esperaMin}min prio_payload=" . ($prio ? 'sim' : 'nao') . ($ressuscitado ? ' PASSIVO' : '') . " -> fila={$fila}");
    }

    /* ---- SECAO 6: falha que e culpa NOSSA tenta de novo sozinha ----
     *
     * SEL-FALHOU-TENTA-DE-NOVO-SOZINHO. Antes, 'failed' era ponto final: o pedido
     * morria no banco e so voltava se o CLIENTE clicasse de novo. Em 15/08 o
     * limasandro129 levou 3 recusas por um teto que foi removido horas depois — e os
     * 3 pedidos continuaram mortos. Quem viu foi o Ruan, no olho. Isso e o vigia
     * empurrando o trabalho pro dono.
     *
     * Agora: se a causa era nossa, o proprio pedido tenta outra vez.
     */
    private function secRessuscita(bool $dry): void
    {
        // FREIO DE OCIOSIDADE: passivo so anda com a fabrica folgada. Sao 11 motores;
        // se ja tem 6+ pedidos girando, quem esta esperando AGORA tem a vez. Sem isto,
        // 147 pedidos velhos entrariam na frente de cliente vivo.
        $girando = DB::table('ai_video_pipelines')
            ->whereIn('step', self::NAO_FINAIS)
            ->count();
        // SEL-PASSIVO-NAO-PASSA-NA-FRENTE (15/08): medido que com o freio em 6 o
        // passivo entregava em 7,4min e o cliente VIVO em 29,8min — o contrario do
        // projetado. Enquanto eu nao entender por que o velho ganha do novo na fila,
        // o passivo so anda com a fabrica COMPLETAMENTE vazia.
        // SEL-MUTIRAO-REALISTA: o gargalo nao e quantos pipelines estao girando, e
        // quantos JOBS estao empilhados esperando motor. Dava pra ter 3 girando e 40
        // na fila, e o excedente morria de "passou do tempo" antes de rodar.
        $naFila = DB::table('jobs')->whereIn('queue', ['video', 'video-priority'])->count();
        if ($naFila >= 8) {
            $this->log("[RESSUSCITA] {$naFila} job(s) esperando motor — passivo espera esvaziar");

            return;
        }

        if ($girando >= 6) {
            $this->log("[RESSUSCITA] fabrica com {$girando} pedido(s) girando — passivo espera a folga");
            return;
        }

        // Erros que NAO se reprocessa, e por que:
        //   pedido do cliente  -> repetir da o mesmo erro (faltou foto, formato indisponivel)
        //   regra de plano     -> "exclusiva para assinante" nao e defeito: e a regra
        //   trava do Ruan      -> "pausada"/"desligada" foi decisao dele, nao bug meu
        $naoReprocessa = [
            'faltam dados', 'nao esta disponivel', 'midia necessaria',
            'exclusiva para assinante', 'temporariamente pausa', 'desligada no momento',
            'inclusos no plano',
        ];

        $mortos = DB::table('ai_video_pipelines')
            ->where('step', 'failed')
            ->whereNull('output_url')
            ->where('dry_run', 0)
            ->where('mode', 'like', 'studio%')
            ->where('created_at', '>=', now()->subDays(30))
            ->orderByDesc('id')
            ->limit(60)
            ->get(['id', 'user_id', 'mode', 'error_message', 'payloads', 'created_at']);

        $feitos = 0;
        foreach ($mortos as $p) {
            if ($feitos >= 3) { break; }   // no maximo 3 por ciclo: nao vira tempestade

            $erro = mb_strtolower((string) $p->error_message);
            $doCliente = false;
            foreach ($naoReprocessa as $frase) {
                if (str_contains($erro, $frase)) { $doCliente = true; break; }
            }
            if ($doCliente) { continue; }

            $pay = json_decode((string) $p->payloads, true) ?: [];
            if (! empty($pay['_ressuscitado'])) { continue; }   // ja teve a vez dele

            // Sem foto do produto nao existe video: ressuscitar e falha garantida.
            // Marco pra nunca mais tentar, em vez de gastar motor e poluir a conta
            // de falhas (37 das 38 falhas da hora eram exatamente isso).
            // SEL-MUTIRAO-SEM-VIDEO-LONGO: video longo (30s) fica de fora do mutirao.
            // Medido: os 54 gate_videomode das 22h vieram de DOIS pipelines longos
            // ressuscitados (#841 18x, #854 12x) — cada clipe que falha reenfileira o
            // pipeline inteiro, entao um so pedido vira dezenas de linhas de erro e
            // segura motor por muito tempo. Foi o que me fez caçar uma causa que nao
            // existia. Cliente NOVO continua podendo pedir 30s normalmente.
            if (str_contains((string) $p->mode, 'long') || str_contains((string) $p->mode, '30s')) {
                if (! $dry) {
                    $pay['_ressuscitado'] = 1;
                    $pay['_pulado_video_longo'] = 1;
                    DB::table('ai_video_pipelines')->where('id', $p->id)
                        ->update(['payloads' => json_encode($pay), 'updated_at' => now()]);
                }
                $this->log("[RESSUSCITA-PULADO] pipe={$p->id} video LONGO — fora do mutirao (custo alto, rendimento baixo)");
                continue;
            }

            $foto = trim((string) ($pay['image_url'] ?? ''));
            $precisaFoto = ! str_contains((string) $p->mode, 'zero');
            if ($precisaFoto && $foto === '') {
                if (! $dry) {
                    $pay['_ressuscitado'] = 1;
                    $pay['_sem_foto'] = 1;
                    DB::table('ai_video_pipelines')->where('id', $p->id)
                        ->update(['payloads' => json_encode($pay), 'updated_at' => now()]);
                }
                $this->log("[RESSUSCITA-PULADO] pipe={$p->id} sem foto do produto — nao da pra gerar");
                continue;
            }

            // SEL-PASSIVO-VELHO-SO-PRA-QUEM-FICOU: acima de 7 dias, so refaz pra quem
            // ainda e cliente. Video de 3 semanas atras na galeria de quem ja saiu nao
            // ajuda ninguem e ocupa motor. Abaixo de 7 dias a regra NAO vale: falha
            // fresca se conserta pra todo mundo (foi assim com o limasandro129, que
            // esta em teste vencido e mesmo assim tinha que receber).
            $diasAtras = (time() - strtotime((string) $p->created_at)) / 86400;
            if ($diasAtras > 7) {
                $temPlano = DB::table('subscriptions')
                    ->where('client_id', $p->user_id)
                    ->where('status', 'active')
                    ->exists();
                if (! $temPlano) { continue; }
            }

            if ($dry) {
                $this->log("[RESSUSCITA] pipe={$p->id} user={$p->user_id} -> tentaria de novo");
                $feitos++;
                continue;
            }

            $pay['_ressuscitado'] = 1;
            // hora do recomeco: os vigias medem a espera do cliente a partir daqui,
            // senao o pedido volta pra fila ja parecendo travado ha horas.
            $pay['_ressuscitado_em'] = now()->toDateTimeString();
            DB::table('ai_video_pipelines')->where('id', $p->id)->update([
                'step'          => 'queued',
                'error_message' => null,
                'payloads'      => json_encode($pay),
                'updated_at'    => now(),
            ]);

            $novo = DB::table('ai_video_pipelines')->find($p->id);
            $this->reDespachar($novo);

            $this->curas++;
            $feitos++;
            $this->log("[RESSUSCITA] pipe={$p->id} user={$p->user_id} falhou por culpa nossa (\"" . mb_substr($erro, 0, 50) . "\") -> re-enfileirado sozinho");
        }
    }

    /* ---- SECAO 3: saude das contas (loginwall / sem credito / 401) ---- */
    private function secEngines(bool $dry): void
    {
        $video = AiEngine::where('tool_type', 'video')->get();

        $mortas = $video->filter(function ($e) {
            $cfg = is_array($e->config_json) ? $e->config_json : [];
            return !$e->is_active || !empty($cfg['precisa_reconectar']);
        });

        $vivas = AiEngine::availableFor('video');
        $nVivas = $vivas->count();

        if ($mortas->isNotEmpty()) {
            $nomes = $mortas->pluck('name')->implode(', ');
            $this->log("[ENGINES] fora do pool ({$mortas->count()}): {$nomes} | vivas={$nVivas}");
            if (!$dry) {
                $this->alertar('engines_fora',
                    'Conta(s) de video fora do pool',
                    "Fora: {$nomes}. Contas vivas agora: {$nVivas}. Reconecte o Google dessas contas.",
                    60);
            }
        } else {
            $this->log("[ENGINES] pool ok | vivas={$nVivas}");
        }

        if ($nVivas <= 1 && !$dry) {
            $this->alertar('engines_urgente',
                'URGENTE: pool de video quase zerado',
                "So {$nVivas} conta(s) de video viva(s). Se cair, a geracao PARA. Reconecte contas ja.",
                15);
            $this->log("[ENGINES][URGENTE] so {$nVivas} conta viva -> push urgente");
        }
    }

    /* ---- SECAO 4: workers vivos (AUTO-RESSUSCITA via sudo supervisorctl start) ----
     *
     * O socket do supervisor e 0700 root: apifrn0001 NAO le 'status'. Mas o
     * drop-in /etc/sudoers.d/apifrn0001-supervisor da NOPASSWD SOMENTE para
     * 'sudo /usr/bin/supervisorctl start sellerapp-{video-render,video-worker}:*'.
     * Como 'start' e idempotente, a PROPRIA saida dele e o detector:
     *   parado -> "...:_00: started"        (nos ressuscitamos)
     *   rodando -> "...: ERROR (already started)" (ja estava vivo, no-op)
     * Assim consertamos sem precisar de permissao de 'status' e sem rodar
     * artisan/supervisorctl como root. O cron root (a cada 2min) continua de backup.
     */
    private function secWorkers(bool $dry): void
    {
        // SEL-velocidade (12/08): o grupo 'sellerapp-kling-browser' NAO EXISTE MAIS
        // (virou 'sellerapp-video-render'; sobraram so os .conf.bak). O sentinela
        // gritava 'ERROR (no such group)' a cada minuto no log -- ruido que mascarava
        // alerta de verdade -- e o auto-ressuscitar do grupo de render era no-op.
        // O drop-in /etc/sudoers.d/apifrn0001-supervisor foi atualizado junto.
        $alvos = ['sellerapp-video-render', 'sellerapp-video-worker'];
        $ressuscitados = [];

        foreach ($alvos as $a) {
            $grupo = $a . ':*';

            if ($dry) {
                $this->log("[WORKERS] [DRY] rodaria: sudo /usr/bin/supervisorctl start {$grupo}");
                continue;
            }

            $out = $this->supervisorSudoStart($grupo);
            if ($out === null) {
                // sudo negou (sem drop-in?) ou erro: nao trava, cron root */2 cobre.
                $this->log("[WORKERS] {$a}: sudo start indisponivel; resurrect coberto pelo cron root */2");
                continue;
            }

            if (preg_match('/:\s*started\b/i', $out)) {
                $ressuscitados[] = $a;
                $this->cura();
                $this->log("[WORKERS] ressuscitei {$a}");
            } else {
                // "ERROR (already started)" = ja estava RUNNING; qualquer outra coisa logamos crua.
                $resumo = preg_replace('/\s+/', ' ', trim($out));
                $this->log('[WORKERS] ' . $a . ' ja RUNNING (' . mb_substr($resumo, 0, 120) . ')');
            }
        }

        if (!empty($ressuscitados) && !$dry) {
            $this->alertar('worker_down',
                'Worker de video caiu (auto-ressuscitado)',
                'Estavam DOWN e o sentinela subiu de volta: ' . implode(', ', $ressuscitados) . '. Cron root */2 tambem cobre.',
                30);
        }
    }

    /* ---- SECAO 5: falhas em serie no MESMO erro -> push com erro EXATO ---- */
    private function secSystemic(bool $dry, int $recentH): void
    {
        $recent = DB::table('ai_video_pipelines')
            ->where('mode', 'like', 'studio%')
            // STAGING_EXCLUIDO_2957: conta de teste/staging nao dispara alerta pro Ruan (falso positivo)
            ->where(function($q){ $q->whereNull('user_id')->orWhere('user_id', '!=', 2957); })
            ->where('updated_at', '>=', now()->subMinutes(30))
            ->orderByDesc('id')->limit(8)
            ->get(['id', 'step', 'error_message', 'render_task_id']);

        $fails = $recent->where('step', 'failed');
        if ($fails->count() < 3) {
            return;
        }

        // assinatura do erro do log do veo (nao o generico do cliente)
        $assinaturas = [];
        foreach ($fails as $f) {
            $assinaturas[] = $this->erroExatoDoVeo($f->render_task_id);
        }
        $contagem = array_count_values(array_map(fn($s) => mb_substr($s, 0, 60), $assinaturas));
        arsort($contagem);
        $topErro = array_key_first($contagem);
        $topQtd  = $contagem[$topErro] ?? 0;

        $this->log("[SISTEMICO] {$fails->count()} falhas/30min | erro dominante ({$topQtd}x): \"{$topErro}\"");

        if ($topQtd >= 3 && !$dry) {
            $this->alertar('sistemico',
                'Falhas de video em serie (bug sistemico?)',
                "{$fails->count()} geracoes falharam em 30min. Erro dominante {$topQtd}x do motor: {$topErro}",
                30);
        }
    }

    /* ================= helpers ================= */

    /** Le o log do veo da task e devolve a linha de erro mais informativa. */
    private function erroExatoDoVeo(?string $taskId): string
    {
        $file = null;
        if ($taskId) {
            $cand = self::VEO_LOG_DIR . "/kbrowser_{$taskId}.log";
            if (is_file($cand)) $file = $cand;
        }
        if (!$file) {
            $logs = glob(self::VEO_LOG_DIR . '/kbrowser_*.log') ?: [];
            if ($logs) {
                usort($logs, fn($a, $b) => filemtime($b) <=> filemtime($a));
                $file = $logs[0] ?? null;
            }
        }
        if (!$file || !is_readable($file)) {
            return 'sem log do motor';
        }

        $linhas = array_slice(array_filter(explode("\n", (string) @file_get_contents($file))), -120);
        $padroes = '/session_expired|login.?wall|HARD-GATE|composer_thumbs=0|sem credito|no credit|credit|quota|429|401|403|timeout|Erro|error|ERR|exit|abort|not.?attached|falha/i';
        $achou = null;
        foreach (array_reverse($linhas) as $l) {
            if (preg_match($padroes, $l)) { $achou = trim($l); break; }
        }
        $out = $achou ?: trim(end($linhas) ?: '');
        return mb_substr(preg_replace('/\s+/', ' ', $out), 0, 180) ?: 'log vazio';
    }

    /**
     * 'sudo -n supervisorctl start <grupo>' — unica acao privilegiada do sentinela.
     * Autorizada SOMENTE via /etc/sudoers.d/apifrn0001-supervisor (NOPASSWD, so 'start'
     * dos 2 grupos de video). Idempotente: 'start' num worker vivo e no-op.
     * Retorna a saida do supervisorctl, ou null se o sudo foi negado / deu erro.
     */
    private function supervisorSudoStart(string $grupo): ?string
    {
        try {
            // Caminho ABSOLUTO ('/usr/bin/sudo') + cwd EXPLICITO (base_path). Sob o
            // cron 'sudo -u apifrn0001 php artisan' (sem cd), a CWD herdada e /root,
            // onde apifrn0001 nao entra -> proc_open/posix_spawn falha com EACCES.
            // Fixar a cwd num diretorio acessivel (a raiz do app) resolve isso.
            $p = new Process(['/usr/bin/sudo', '-n', '/usr/bin/supervisorctl', 'start', $grupo], base_path());
            $p->setTimeout(30);
            $p->run();
            $out = trim($p->getOutput() . "\n" . $p->getErrorOutput());
            $low = strtolower($out);
            // sudo recusou (sem regra NOPASSWD, precisa senha, ou comando nao permitido)
            if (str_contains($low, 'a password is required')
                || str_contains($low, 'a terminal is required')
                || str_contains($low, 'not allowed to execute')
                || str_contains($low, 'may not run')
                || str_contains($low, 'no tty present')) {
                return null;
            }
            return $out;
        } catch (\Throwable $e) {
            $this->log('[WORKERS] supervisorSudoStart(' . $grupo . ') excecao: ' . $e->getMessage());
            return null;
        }
    }

    /** Alerta com cooldown por tipo (evita spam). */
    private function alertar(string $tipo, string $titulo, string $corpo, int $cooldownMin): void
    {
        // Modo teste: registra a intencao de alertar mas NAO toca o telefone do Ruan
        // e NAO grava cooldown (pra nao mascarar um evento real depois).
        if ($this->noPush) {
            $this->log("[ALERT:{$tipo}] (SUPRIMIDO --no-push, NENHUM push enviado): {$titulo} | {$corpo}");
            return;
        }

        $key = "video_sentinela_alert:{$tipo}";
        $last = DB::table('settings')->where('key', $key)->value('value');
        if ($last && \Carbon\Carbon::parse($last)->gt(now()->subMinutes($cooldownMin))) {
            $this->log("[ALERT:{$tipo}] em cooldown ({$cooldownMin}min), nao re-alerta");
            return;
        }
        try {
            Artisan::call('alert:ruan', [
                'title'   => $titulo,
                'body'    => $corpo,
                'url'     => '/admin',
                '--email' => 'ruanipanema2@gmail.com',
            ]);
        } catch (\Throwable $e) {
            $this->log("[ALERT:{$tipo}] falha ao enviar push: " . $e->getMessage());
        }
        DB::table('settings')->updateOrInsert(
            ['key' => $key],
            ['value' => now()->toDateTimeString(), 'group' => 'video', 'updated_at' => now(), 'created_at' => now()]
        );
        $this->log("[ALERT:{$tipo}] PUSH enviado: {$titulo} | {$corpo}");
    }

    private function cura(): void
    {
        $this->curas++;
        $key = 'video_sentinela_curas:' . now()->toDateString();
        // cron single + withoutOverlapping => sem concorrencia, select-then-write e seguro.
        $row = DB::table('settings')->where('key', $key)->first();
        if ($row) {
            DB::table('settings')->where('key', $key)
                ->update(['value' => ((int) $row->value) + 1, 'updated_at' => now()]);
        } else {
            DB::table('settings')->insert([
                'key' => $key, 'value' => 1, 'group' => 'video',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function contadorHoje(): int
    {
        $key = 'video_sentinela_curas:' . now()->toDateString();
        return (int) DB::table('settings')->where('key', $key)->value('value');
    }

    private function log(string $msg): void
    {
        $line = '[' . now()->toDateTimeString() . '] ' . $msg . "\n";
        @file_put_contents(self::LOG, $line, FILE_APPEND);
    }
}
