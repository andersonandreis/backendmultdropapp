<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SEL-VIGIA (14/08) — pergunta do Ruan: "quem vigia e resolve pra nao acontecer de novo?"
 *
 * Resposta honesta do dia: ninguem vigiava DE VERDADE. Existiam 5 vigias
 * (video-sentinela, render-hung-heal, bia:plantao, reconcile-stuck, pulso-fabrica) e
 * todos olhavam PROCESSO DE PE. Nenhum olhava ENTREGA. Pior: o bia:plantao era a
 * PROPRIA causa da enxurrada de jobs, e como o .env esta em LOG_LEVEL=error, todo
 * Log::info/warning dele era descartado — o reenfileirador mais agressivo do sistema
 * nao deixava rastro nenhum.
 *
 * Este comando e diferente em tres coisas:
 *   1. o sinal de saude e TEMPO DE ENTREGA, nao processo vivo;
 *   2. ele conhece as regressoes de hoje PELO NOME e procura a assinatura de cada uma;
 *   3. escreve em ERROR de proposito, pra atravessar o LOG_LEVEL=error do .env.
 *      Nao e erro: e a unica forma de ser visto neste servidor.
 *
 * O que ele CONSERTA sozinho (sem pedir nada a ninguem):
 *   - reserva de motor presa sem pipeline viva (o pool secava com a fila cheia)
 *   - deposito de carteira vencido que ficou "pendente" pra sempre
 * O que ele so DENUNCIA (mexer sozinho seria pior que avisar):
 *   - entrega degradando, stdin perdido, job duplicado, video fantasma, chave vazia
 */
class VideoVigia extends Command
{
    protected $signature   = 'video:vigia {--json : devolve so o JSON, pro pulso}';
    protected $description = 'Vigia a ENTREGA de video (nao o processo) e conserta o que da pra consertar sozinho';

    /** Acima disso a entrega esta doente. Medido 14/08: mediana boa = 3 a 9 min. */
    private const MEDIANA_RUIM_MIN = 20;

    private const NAO_FINAIS = ['queued', 'render', 'processing', 'voice', 'lipsync', 'queued_wait'];

    public function handle(): int
    {
        $alertas   = [];
        $consertos = [];
        $pcLigadoHa = null;

        // ---------------------------------------------------------- 1) ENTREGA
        $tempos = DB::table('ai_video_pipelines')
            ->where('step', 'done')->whereNotNull('output_url')
            ->where('created_at', '>', now()->subHours(2))
            ->get(['created_at', 'updated_at'])
            ->map(fn ($p) => (strtotime($p->updated_at) - strtotime($p->created_at)) / 60)
            ->filter(fn ($m) => $m > 0 && $m < 300)
            ->sort()->values()->all();

        $mediana = count($tempos) ? round($tempos[intdiv(count($tempos), 2)], 1) : null;
        $criados = DB::table('ai_video_pipelines')->where('created_at', '>', now()->subHour())->count();

        if ($mediana !== null && $mediana > self::MEDIANA_RUIM_MIN) {
            $alertas[] = "entrega lenta: mediana {$mediana}min nas ultimas 2h (saudavel e ate " . self::MEDIANA_RUIM_MIN . 'min)';
        }
        if ($criados > 0 && count($tempos) === 0) {
            $alertas[] = "{$criados} pedidos na ultima hora e NENHUM entregue";
        }

        // ------------------------------------------- 2) pedido esperando demais
        $presos = DB::table('ai_video_pipelines')
            ->whereIn('step', self::NAO_FINAIS)
            ->where('created_at', '<', now()->subMinutes(45))
            ->get(['id', 'user_id', 'step', 'created_at']);
        foreach ($presos as $p) {
            $min   = (int) round((time() - strtotime($p->created_at)) / 60);
            $email = DB::table('users')->where('id', $p->user_id)->value('email');
            $alertas[] = "pedido #{$p->id} ({$email}) parado ha {$min}min em {$p->step}";
        }

        // ------------- 3) SEL-STDIN-SEM-CORRIDA: o worker perdeu o JSON do prompt
        // Assinatura: o worker aparece com task=t<epoch> inventado no lugar do id real.
        $dir = storage_path('logs/veo');
        $stdinPerdido = 0;
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.remote.log') ?: [] as $f) {
                if (filemtime($f) < time() - 1800) {
                    continue;   // so a ultima meia hora
                }
                $cabeca = (string) @file_get_contents($f, false, null, 0, 400);
                if (preg_match('/task=t\d{10,}/', $cabeca)) {
                    $stdinPerdido++;
                }
            }
        }
        if ($stdinPerdido > 0) {
            $alertas[] = "REGRESSAO SEL-STDIN-SEM-CORRIDA: {$stdinPerdido} geracoes sem o JSON do prompt em 30min "
                . '(o worker voltou a desistir do stdin — conferir readStdin no veo_generate.js)';
        }

        // ----------- 4) SEL-PLANTAO-IDEMPOTENTE: job empilhado pra mesma pipeline
        $porPipeline = [];
        foreach (DB::table('jobs')->whereIn('queue', ['video', 'video-priority', 'video-ruan'])->get(['payload']) as $j) {
            if (preg_match('/i:(\d{3,6});/', $j->payload, $m)) {
                $porPipeline[$m[1]] = ($porPipeline[$m[1]] ?? 0) + 1;
            }
        }
        foreach ($porPipeline as $pid => $qtd) {
            if ($qtd >= 4) {
                $alertas[] = "REGRESSAO SEL-PLANTAO-IDEMPOTENTE: pipeline {$pid} com {$qtd} jobs empilhados";
            }
        }

        // ------------------------- 5) CONSERTA: reserva de motor presa sem dono
        $vivas = DB::table('ai_video_pipelines')->whereIn('step', self::NAO_FINAIS)->count();
        if ($vivas === 0) {
            $presas = DB::table('ai_engine_usage')
                ->where('date', now()->toDateString())
                ->where('reserved_count', '>', 0)
                ->where('updated_at', '<', now()->subMinutes(15))
                ->get();
            foreach ($presas as $u) {
                DB::table('ai_engine_usage')->where('id', $u->id)->update([
                    'reserved_count' => 0,
                    'updated_at'     => now(),
                ]);
                $consertos[] = "motor {$u->engine_id} estava reservado com a fila VAZIA ha 15min — soltei";
            }
        }

        // --------------------------------- 6) CONSERTA: deposito de PIX vencido
        if (DB::getSchemaBuilder()->hasTable('affiliate_wallet_deposits')) {
            $vencidos = DB::table('affiliate_wallet_deposits')
                ->where('status', 'pending')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now())
                ->update(['status' => 'expired', 'updated_at' => now()]);
            if ($vencidos) {
                $consertos[] = "{$vencidos} deposito(s) de carteira venceram sem pagamento — marquei expirado";
            }
        }

        // ------------------------------ 6b) SEL-VIGIA-PC-CAIU: o PC de render reiniciou?
        // 14/08: o PC do Ruan travou DUAS vezes em 34 minutos (18:48:42 e 19:22:12),
        // as duas com Kernel-Power 41 — desligamento nao esperado, com 33GB de RAM
        // livre (ou seja: nao foi memoria). Ninguem viu na hora; o Ruan e que avisou.
        // O PC e a fabrica inteira: se ele cai, toda geracao para. Agora o vigia
        // pergunta ha quanto tempo o Windows esta ligado e denuncia reboot recente.
        try {
            $p = new \Symfony\Component\Process\Process([
                'ssh', '-i', '/home/api.seller.global/.ssh/pc-render', '-p', '2200',
                '-o', 'StrictHostKeyChecking=no', '-o', 'ConnectTimeout=10',
                'ruan@localhost',
                'powershell -NoProfile -Command "[int]((Get-Date)-(Get-CimInstance Win32_OperatingSystem).LastBootUpTime).TotalMinutes"',
            ]);
            $p->setTimeout(25);
            $p->run();
            $ligadoHa = (int) trim(preg_replace('/\D/', '', $p->getOutput()));
            if ($p->isSuccessful() && $ligadoHa > 0) {
                $pcLigadoHa = $ligadoHa;
                if ($ligadoHa < 15) {
                    $alertas[] = "PC DE RENDER REINICIOU ha {$ligadoHa} min — se nao foi o Ruan, foi trava (Kernel-Power). A fabrica inteira depende dele.";
                }
            } elseif (! $p->isSuccessful()) {
                $alertas[] = 'PC de render NAO respondeu ao vigia (tunel ou maquina fora do ar)';
            }
        } catch (\Throwable $e) {
            // vigia nunca derruba por causa do proprio diagnostico
        }

        // ---------------------------------------- 7) video fantasma (done sem url)
        $fantasmas = DB::table('ai_video_pipelines')
            ->where('step', 'done')->whereNull('output_url')
            ->where('created_at', '>', now()->subDay())->count();
        if ($fantasmas > 0) {
            $alertas[] = "{$fantasmas} video(s) marcados PRONTOS sem arquivo nenhum (fantasma)";
        }

        // ------------------------ 8) SEL-VIGIA-LLM-REAL: o roteirista RESPONDE mesmo?
        // Eu tinha posto aqui uma checagem de chave VAZIA no .env, e ela gritou a noite
        // inteira por nada: o motor que de fato escreve os roteiros e o registro #15 da
        // tabela ai_engines, com chave PROPRIA no banco — o .env nunca foi a fonte.
        // Alarme falso e pior que alarme nenhum: ensina a ignorar, e ai o alerta de
        // verdade passa batido junto. Agora eu nao pergunto se existe chave; eu PERGUNTO
        // AO ROTEIRISTA e vejo se ele responde. Cache de 10min pra nao pesar a cada 5.
        try {
            $ultimo = \Illuminate\Support\Facades\Cache::get('vigia_llm_ok_em');
            if (! $ultimo || now()->diffInMinutes($ultimo) >= 10) {
                $resposta = app(\App\Services\Ai\AiEnginePool::class)
                    ->for('llm')->chat([['role' => 'user', 'content' => 'Responda apenas: OK']], 0.2, 1024);
                if (trim((string) $resposta) === '') {
                    $alertas[] = 'ROTEIRISTA MUDO: o motor de texto respondeu VAZIO — o cliente vai receber roteiro generico';
                } else {
                    \Illuminate\Support\Facades\Cache::put('vigia_llm_ok_em', now(), 900);
                }
            }
        } catch (\Throwable $e) {
            $alertas[] = 'ROTEIRISTA FORA DO AR: ' . mb_substr($e->getMessage(), 0, 90);
        }

        $resumo = [
            'quando'         => now()->toDateTimeString(),
            'mediana_min'    => $mediana,
            'criados_1h'     => $criados,
            'entregues_2h'   => count($tempos),
            'fila_jobs'      => array_sum($porPipeline),
            'pedidos_vivos'  => $vivas,
            'pc_ligado_ha_min' => $pcLigadoHa ?? null,
            'consertos'      => $consertos,
            'alertas'        => $alertas,
        ];

        // ERROR de proposito: o .env esta em LOG_LEVEL=error e info/warning some.
        if ($alertas) {
            Log::error('[SEL-VIGIA] fabrica de video com problema', $resumo);
        } elseif ($consertos) {
            Log::error('[SEL-VIGIA] consertei sozinho', $resumo);
        }

        @file_put_contents('/var/log/pulso-vigia.log',
            json_encode($resumo, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

        if ($this->option('json')) {
            $this->line(json_encode($resumo, JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("mediana={$mediana}min · criados1h={$criados} · vivos={$vivas}");
        foreach ($consertos as $c) {
            $this->line("  CONSERTEI: {$c}");
        }
        foreach ($alertas as $a) {
            $this->error("  ALERTA: {$a}");
        }
        if (! $alertas && ! $consertos) {
            $this->info('  tudo certo');
        }

        return self::SUCCESS;
    }
}
