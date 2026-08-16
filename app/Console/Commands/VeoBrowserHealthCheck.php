<?php

namespace App\Console\Commands;

use App\Services\Ai\VeoBrowserService;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * SEL-486 — health check do motor Veo (Google Flow via navegador).
 *
 * Sem --live: barato, so olha config + arquivo de sessao (existe? idade?).
 * Com --live: ABRE a sessao Google no Flow (headless sob xvfb) e confere se ela
 *   esta VIVA de verdade — bate no /v1/credits autenticado e detecta login wall.
 *   E a UNICA prova real de que da pra gerar (o arquivo existir NAO garante login:
 *   o Google revoga sessao server-side com os cookies ainda no arquivo).
 *
 * Uso tipico depois que o Ruan reloga a conta Google:
 *   sudo -u apifrn0001 /usr/local/lsws/lsphp83/bin/php artisan veo-browser:health-check --live
 */
class VeoBrowserHealthCheck extends Command
{
    protected $signature = 'veo-browser:health-check {--live : abre a sessao no Flow e confere login+creditos (mais lento)}';
    protected $description = 'SEL-486: health check do motor Veo (sessao Google / Flow)';

    public function handle(VeoBrowserService $svc): int
    {
        $h = $svc->health();

        $this->info('=== Veo (Google Flow) — config ===');
        $this->line('  enabled (VEO_BROWSER_ENABLED)  : ' . ($h['enabled'] ? 'true' : 'false'));
        $this->line('  active  (VIDEO_ENGINE=veo)     : ' . ($h['active'] ? 'SIM — Veo e o motor ativo' : 'nao — motor ativo e o Kling'));
        $this->line('  session file                   : ' . $h['session_path']);
        $this->line('  session exists                 : ' . ($h['session_exists'] ? 'sim' : 'NAO'));
        $this->line('  session age                    : ' . ($h['session_age_s'] !== null ? round($h['session_age_s'] / 3600, 1) . 'h' : 'n/a'));
        $this->line('  account                        : ' . ($h['account_email'] ?? 'n/a') . ' (' . ($h['plan'] ?? '?') . ')');
        $this->line('  model                          : ' . ($h['model'] ?? '?'));
        $this->line('  project_url                    : ' . ($h['project_url'] ?: '(nao setado — VEO_PROJECT_URL)'));

        if (!$h['session_exists']) {
            $this->error('Sessao Google AUSENTE. Ruan precisa exportar/logar a conta Google no arquivo acima.');
            return self::FAILURE;
        }

        if (!$this->option('live')) {
            $this->comment("\nSessao existe, mas isto NAO prova que esta logada. Rode com --live pra confirmar.");
            return self::SUCCESS;
        }

        // --live: prova de vida real via o probe Node ja instalado no browser-worker.
        $this->info("\n=== prova de vida (--live): abrindo o Flow com a sessao ===");
        $dir = config('services.veo.browser_worker_dir') ?: '/home/api.seller.global/browser-worker';
        $proc = new Process(
            ['xvfb-run', '-a', '--server-args=-screen 0 1440x900x24', 'node', 'veo_session_probe2.js'],
            $dir,
            [
                'PLAYWRIGHT_BROWSERS_PATH' => env('PLAYWRIGHT_BROWSERS_PATH', '/opt/ms-playwright'),
                'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
                'VEO_SESSION' => $h['session_path'],
            ]
        );
        $proc->setTimeout(180);
        $proc->run();
        $out = $proc->getOutput() . "\n" . $proc->getErrorOutput();

        $loginWall = (bool) preg_match('/LOGIN_WALL=true/i', $out);
        $unauth    = (bool) preg_match('/UNAUTH_401=true/i', $out);
        $hasBox    = (bool) preg_match('/HAS_TEXTBOX=true/i', $out);
        preg_match('/CREDITS=(.*)/', $out, $mCred);
        $credits = isset($mCred[1]) ? trim($mCred[1]) : 'null';

        $this->line('  login wall  : ' . ($loginWall ? 'SIM (deslogada)' : 'nao'));
        $this->line('  /credits 401: ' . ($unauth ? 'SIM (sessao invalida)' : 'nao'));
        $this->line('  campo criar : ' . ($hasBox ? 'presente (UI do Flow carregou)' : 'ausente'));
        $this->line('  credits resp: ' . mb_substr($credits, 0, 160));

        $viva = !$loginWall && !$unauth && $hasBox;
        if ($viva) {
            $this->info("\nSESSAO VIVA COM ACESSO AO FLOW. Da pra gerar pelo Veo. "
                . ($h['active'] ? 'Motor ja ativo.' : 'Ligue com VIDEO_ENGINE=veo + VEO_BROWSER_ENABLED=true.'));
            return self::SUCCESS;
        }

        $this->error("\nSESSAO MORTA/DESLOGADA. Ruan precisa RELOGAR a conta Google e reexportar "
            . "o storageState pro arquivo {$h['session_path']}. So depois disso o Veo gera.");
        return self::FAILURE;
    }
}
