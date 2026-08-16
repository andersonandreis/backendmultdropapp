<?php

namespace App\Console\Commands;

use App\Services\Ai\KlingBrowserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * SEL-381 AUDIT#9+#11 — health check periódico do Kling Browser.
 * Rodar via scheduler a cada 1h. Alerta Telegram Ruan se:
 * - Sessão >72h de idade (pode expirar)
 * - Worker >6h sem rodar E há job na fila (worker travado)
 * - Créditos plano Pro <20% do limite mensal (proxy: se contar >2400 gerações no mês)
 * - Rota /pause marca fila pausada há >1h (esquecido pausado)
 */
class KlingBrowserHealthCheck extends Command
{
    protected $signature = 'kling-browser:health-check';
    protected $description = 'AUDIT#9+11 SEL-381: health check + alertas Telegram';

    public function handle(KlingBrowserService $svc): int
    {
        $alerts = [];
        $health = $svc->health();

        // 1. Sessão velha
        if (($health['session_age_s'] ?? 0) > 72 * 3600) {
            $alerts[] = "⚠️ Sessão Kling browser tem " . round($health['session_age_s']/3600, 1) . "h de idade — pode expirar. Reexportar do PC do Ruan.";
        }

        // 2. Fila pausada há muito tempo
        $pauseFile = env('KLING_BROWSER_PAUSE_FILE', '/home/api.seller.global/storage/kling-browser/PAUSED');
        if (is_file($pauseFile)) {
            $pausedAge = time() - filemtime($pauseFile);
            if ($pausedAge > 3600) {
                $alerts[] = "⏸️ Fila Kling browser pausada há " . round($pausedAge/3600, 1) . "h — POST /pause com action=resume pra retomar.";
            }
        }

        // 3. Uso mensal (~80% do plano Pro).
        //
        // SEL-416: este alerta estava MUDO desde que foi escrito. Ele contava
        // ai_generations WHERE provider='kling_browser' — valor que nenhum ponto
        // do código grava: os providers que existem na tabela são 'kling' e
        // 'openai'. Conferido em 30/07: o alarme via 0 gerações no mês enquanto
        // a realidade eram 79. Ou seja, o único aviso de "crédito acabando" que
        // o Ruan tinha nunca ia disparar — nem quando o saldo caiu a 30.100.
        //
        // A fonte de verdade da geração por navegador é ai_video_pipelines com
        // output_url preenchida: é o que prova que o vídeo saiu de verdade
        // (falha do worker aborta antes de gastar crédito — 132 falhas em 28-30/07
        // com captured_mp4s=0, nenhuma queimou crédito).
        //
        // Os números 150/120 são os mesmos de antes, não inventei teto novo: eles
        // vêm da premissa de ~20 créditos por vídeo que já estava aqui. Enquanto
        // o custo real por geração não for medido (item pendente), isso segue
        // sendo estimativa — e está dito no texto do alerta, de propósito, pra
        // ninguém tratar como número medido.
        try {
            $monthTotal = \DB::table('ai_video_pipelines')
                ->whereYear('created_at', now()->year)
                ->whereMonth('created_at', now()->month)
                ->whereNotNull('output_url')
                ->count();
            if ($monthTotal >= 120) {
                $alerts[] = "💳 Plano Kling Pro em {$monthTotal} vídeos no mês (estimativa ~20 créditos/vídeo → teto ~150). Considere upgrade OU pacote créditos ({$health['topup_url']}).";
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[SEL-416] falha ao contar uso mensal Kling', ['erro' => $e->getMessage()]);
        }

        // 4. Worker sem rodar há muito tempo E há job pendente
        try {
            $pending = \DB::table('jobs')->where('queue', 'kling-browser')->orWhere('queue', 'kling-browser-priority')->count();
            $lastRun = $health['last_run_age_s'] ?? PHP_INT_MAX;
            if ($pending > 0 && $lastRun > 6 * 3600) {
                $alerts[] = "🚨 {$pending} jobs pendentes na fila kling-browser mas worker sem rodar há " . round($lastRun/3600, 1) . "h. Ver supervisorctl status.";
            }
        } catch (\Throwable $e) {}

        if (empty($alerts)) {
            $this->info('OK — nada a alertar. Sessão ' . round(($health['session_age_s']??0)/3600, 1) . 'h, worker last=' . round(($health['last_run_age_s']??0)/60) . 'min');
            return self::SUCCESS;
        }

        $msg = "🤖 Kling Browser health check (" . now()->format('H:i') . "):\n\n" . implode("\n\n", $alerts);
        $this->warn($msg);

        // SEL-411: o token vinha chumbado aqui tambem (este por acaso era o vivo, o que
        // so escondia o problema). Agora sai por config/services.php, igual ao resto —
        // e o servico centraliza o aviso de "alarme mudo" em vez de cada comando montar
        // a chamada HTTP na mao.
        try {
            app(\App\Services\TelegramNotificationService::class)->send($msg);
        } catch (\Throwable $e) {
            $this->error('Telegram fail: ' . $e->getMessage());
        }
        return self::SUCCESS;
    }
}
