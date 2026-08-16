<?php

namespace App\Console\Commands;

use App\Models\AiEngine;
use App\Services\Ai\AiEnginePool;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * AUTO-CURA do POOL DE VIDEO (coracao) — roda a cada 1min pelo schedule.
 *
 * PROBLEMA que resolve: os 5 motores principais (ai_engines 23-27) rodam NO PC do
 * Ruan (config_json.remote -> tunel reverso ssh localhost:2200). Quando o PC
 * REINICIA, o tunel cai e os 5 quebram temporariamente; sobra so o motor LOCAL de
 * reserva (id 14, xvfb no servidor). Antes isso exigia intervencao manual.
 *
 * O QUE FAZ (tudo aditivo, fail-open, NUNCA lanca):
 *   1. Sonda o HOST do PC (ssh echo ok, timeout curto) e PRIMA o cache que o
 *      AiEnginePool::remoteHostHealthy() le -> o caminho de request ja pula os
 *      remotos sem sondar (queda invisivel pro cliente: cai no motor local 14).
 *   2. PC UP: reintegra os motores remotos que estavam em cooldown POR MOTIVO DE
 *      HOST (nao mexe em conta deslogada/login-wall), zera o timer de queda e, se o
 *      pool do PC esta frio (0 contas quentes), reaciona pelo mecanismo que ja
 *      existe (pool_keeper.bat no PC).
 *   3. PC DOWN: marca desde-quando, LOGA. So ALERTA o Ruan (alert:ruan) se a queda
 *      passar de ALERT_APOS_MIN (nao pinga por soluço de rede) e respeitando
 *      cooldown de alerta. Enquanto isso o video segue no motor local -> sem queda.
 *
 * FASE 2 (INERTE por padrao — flag off + sem alvo): failover ACTIVE-PASSIVE pro Mac.
 * Ver retargetFailover() — desligado ate o Mac ter tunel+contas e o Ruan habilitar.
 */
class PcPoolAutocuraCommand extends Command
{
    protected $signature = 'video:pc-autocura';
    protected $description = 'Auto-cura do pool de video do PC (probe + reintegra + alerta so se necessario)';

    /** Queda tem que passar disto (minutos) pra alertar o Ruan (anti-soluco). */
    private const ALERT_APOS_MIN = 8;
    /** Nao re-alerta antes disto (minutos). */
    private const ALERT_COOLDOWN_MIN = 30;
    /** TTL do cache de saude do host primado por este watchdog (segundos). */
    private const HOST_CACHE_TTL = 60;

    public function handle(): int
    {
        try {
            // Motores remotos ativos, agrupados por host (hoje todos = localhost:2200).
            $remotos = AiEngine::where('tool_type', 'video')
                ->whereNotNull('config_json')
                ->get()
                ->filter(fn ($e) => !empty(($e->config_json ?? [])['remote']));

            if ($remotos->isEmpty()) {
                $this->info('sem motores remotos configurados; nada a curar');
                return 0;
            }

            // Um representante por host (host:port:user:key identicos entre as 5 contas).
            $porHost = [];
            foreach ($remotos as $e) {
                $r = $e->config_json['remote'];
                $h = ($r['user'] ?? 'ruan') . '@' . ($r['host'] ?? 'localhost') . ':' . ($r['port'] ?? 2200);
                $porHost[$h] ??= ['remote' => $r, 'engines' => collect()];
                $porHost[$h]['engines']->push($e);
            }

            foreach ($porHost as $hostKey => $grp) {
                $up = $this->probe($grp['remote']);
                // Prima o cache que o request path le (evita sondar por job).
                Cache::put('ai_remote_host_up:' . $hostKey, $up, self::HOST_CACHE_TTL);

                if ($up) {
                    $this->onHostUp($hostKey, $grp);
                } else {
                    $this->onHostDown($hostKey, $grp);
                }
            }
        } catch (\Throwable $e) {
            // auto-cura NUNCA pode derrubar o schedule
            $this->log('ERRO no watchdog: ' . $e->getMessage());
            Log::warning('[AUTO-CURA] watchdog erro', ['err' => $e->getMessage()]);
        }

        return 0;
    }

    /** Sonda o host via ssh echo ok (mesma logica do AiEnginePool). */
    private function probe(array $remote): bool
    {
        $host = $remote['host'] ?? 'localhost';
        $port = (string) ($remote['port'] ?? 2200);
        $user = $remote['user'] ?? 'ruan';
        $key  = $remote['ssh_key'] ?? '/home/api.seller.global/.ssh/pc-render';
        try {
            $p = new Process(['ssh', '-i', $key, '-p', $port,
                '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=no',
            // SEL-TUNEL-MULTIPLEX-BACKEND (14/08): reaproveita UMA conexao com o PC
            // em vez de abrir uma nova a cada chamada (era isso que estourava o
            // limite de conexoes simultaneas do sshd do Windows e derrubava o tunel).
            '-o', 'ControlMaster=auto', '-o', 'ControlPath=/tmp/.ssh-mux-backend-%r@%h-%p', '-o', 'ControlPersist=120',
                '-o', 'ConnectTimeout=6', '-o', 'ServerAliveInterval=3',
                $user . '@' . $host, 'echo ok']);
            $p->setTimeout(12);
            $p->run();
            return $p->isSuccessful() && str_contains($p->getOutput(), 'ok');
        } catch (\Throwable $e) {
            // spawn falhou -> trata como UP (fail-open, nao derruba remotos as cegas)
            return true;
        }
    }

    private function onHostUp(string $hostKey, array $grp): void
    {
        $eraDown = $this->getSetting('pc_pool_down_since:' . $hostKey);
        if ($eraDown) {
            $min = Carbon::parse($eraDown)->diffInMinutes(now());
            $this->log("RECUPERADO {$hostKey} apos ~{$min}min fora");
            $this->delSetting('pc_pool_down_since:' . $hostKey);
        }

        // Reintegra motores em cooldown POR MOTIVO DE HOST (nao toca em login-wall).
        $reintegrados = [];
        foreach ($grp['engines'] as $e) {
            $e->refresh();
            if (!$e->is_active) { continue; } // deslogada (precisa Ruan) -> nao mexe
            if (($e->config_json['precisa_reconectar'] ?? false) === true) { continue; }
            if ($e->cooldown_until && $e->cooldown_until->isFuture() && $this->erroDeHost($e->last_error)) {
                $e->update(['cooldown_until' => null]);
                $reintegrados[] = $e->name;
            }
        }
        if ($reintegrados) {
            $this->log('REINTEGRADOS (host voltou): ' . implode(', ', $reintegrados));
        }

        // Pool frio? (0 contas quentes) -> reaciona pelo mecanismo existente do PC.
        $this->garantePoolQuente($grp['remote']);

        $this->info("host {$hostKey} UP" . ($reintegrados ? ' | reintegrei ' . count($reintegrados) : ''));
    }

    private function onHostDown(string $hostKey, array $grp): void
    {
        $key = 'pc_pool_down_since:' . $hostKey;
        $since = $this->getSetting($key);
        if (!$since) {
            $this->setSetting($key, now()->toDateTimeString());
            $since = now()->toDateTimeString();
        }
        $min = Carbon::parse($since)->diffInMinutes(now());
        $this->log("PC FORA {$hostKey} ha ~{$min}min (video segue no motor local de reserva)");
        $this->warn("host {$hostKey} DOWN ha ~{$min}min");

        // FASE 2 (inerte por padrao): tenta failover pro Mac se habilitado + confirmado.
        $this->retargetFailover($hostKey, $grp, $min);

        // So alerta se a queda for REAL (passou do limiar) e fora do cooldown de alerta.
        if ($min < self::ALERT_APOS_MIN) { return; }

        $lastAlert = $this->getSetting('pc_pool_last_alert:' . $hostKey);
        if ($lastAlert && Carbon::parse($lastAlert)->gt(now()->subMinutes(self::ALERT_COOLDOWN_MIN))) {
            return; // em cooldown de alerta
        }

        try {
            Artisan::call('alert:ruan', [
                'title'   => 'Data Center do video (PC) fora do ar',
                'body'    => "O no principal esta fora ha ~{$min}min. O video segue no no local de reserva, mas com capacidade menor. Se o PC reiniciou, ele volta sozinho; se nao, ligar/religar.",
                'url'     => '/admin',
                '--email' => 'ruanipanema2@gmail.com',
            ]);
            $this->setSetting('pc_pool_last_alert:' . $hostKey, now()->toDateTimeString());
            $this->log("ALERTEI Ruan: PC fora ha ~{$min}min");
        } catch (\Throwable $e) {
            $this->log('falha ao alertar: ' . $e->getMessage());
        }
    }

    /**
     * Pool do PC frio (0 contas quentes) -> reaciona pelo pool_keeper.bat (mecanismo
     * que JA existe no PC). Fail-open: qualquer erro so loga. So roda com host UP.
     */
    private function garantePoolQuente(array $remote): void
    {
        try {
            $host = $remote['host'] ?? 'localhost';
            $port = (string) ($remote['port'] ?? 2200);
            $user = $remote['user'] ?? 'ruan';
            $key  = $remote['ssh_key'] ?? '/home/api.seller.global/.ssh/pc-render';
            $wdir = $remote['worker_dir'] ?? 'C:\\sellerglobal-render';
            $base = ['ssh', '-i', $key, '-p', $port, '-o', 'BatchMode=yes',
                     '-o', 'StrictHostKeyChecking=no',
            // SEL-TUNEL-MULTIPLEX-BACKEND (14/08): reaproveita UMA conexao com o PC
            // em vez de abrir uma nova a cada chamada (era isso que estourava o
            // limite de conexoes simultaneas do sshd do Windows e derrubava o tunel).
            '-o', 'ControlMaster=auto', '-o', 'ControlPath=/tmp/.ssh-mux-backend-%r@%h-%p', '-o', 'ControlPersist=120', '-o', 'ConnectTimeout=10',
                     $user . '@' . $host];

            $read = new Process(array_merge($base, ['type ' . $wdir . '\\pool.json']));
            $read->setTimeout(25);
            $read->run();
            $json = trim(str_replace("\r", '', $read->getOutput()));
            if ($json === '') { return; } // nao leu -> nao cura as cegas

            $ready = substr_count($json, '"status": "ready"') + substr_count($json, '"status":"ready"');
            if (str_contains($json, 'engines') && $ready >= 1) { return; } // ja quente

            $this->log("pool frio (0 quentes) -> disparando pool_keeper.bat");
            $keep = new Process(array_merge($base, ['cmd /c ' . $wdir . '\\pool_keeper.bat']));
            $keep->setTimeout(25);
            $keep->run();
        } catch (\Throwable $e) {
            $this->log('garantePoolQuente falhou (secundario): ' . $e->getMessage());
        }
    }

    /**
     * FASE 2 — failover ACTIVE-PASSIVE pro Mac. INERTE por padrao.
     *
     * As 5 contas Google/Flow sao COMPARTILHADAS (1 sessao por conta): PC e Mac NUNCA
     * geram ao mesmo tempo. O failover NAO cria motores novos -> ele TROCA o alvo
     * (config_json.remote host/port) das engines 23-27 de PC pro Mac, e o Mac (standby
     * FRIO) loga as contas so nesse momento.
     *
     * DESLIGADO ate: (a) o Mac ter tunel reverso pro servidor (porta 2201) + script de
     * warm-up que loga as 5 contas; (b) settings 'pc_pool_failover_enabled'='1' +
     * 'pc_pool_failover_host'/'pc_pool_failover_port' preenchidos. Anti-flap: so troca
     * depois de FLAP_GUARD min de queda confirmada. Enquanto nao habilitado/configurado,
     * este metodo retorna imediatamente (nada acontece).
     */
    private function retargetFailover(string $hostKey, array $grp, int $downMin): void
    {
        if ($this->getSetting('pc_pool_failover_enabled') !== '1') { return; }         // flag off
        $macHost = $this->getSetting('pc_pool_failover_host');
        $macPort = $this->getSetting('pc_pool_failover_port');
        if (!$macHost || !$macPort) { return; }                                        // sem alvo
        // Anti-flap: so troca apos queda CONFIRMADA (nao num soluco de rede).
        $flapGuard = (int) ($this->getSetting('pc_pool_failover_flap_min') ?: 6);
        if ($downMin < $flapGuard) { return; }
        // (implementacao do switch de alvo + warm-up do Mac entra aqui quando o Mac
        //  estiver pronto e o Ruan habilitar — NAO executa nada hoje.)
        $this->log("FASE2: failover elegivel (down {$downMin}min) mas switch nao executado — ver plano");
    }

    private function erroDeHost(?string $err): bool
    {
        if (!$err) { return true; } // sem erro registrado -> trata como host (seguro reintegrar)
        // login/sessao NAO conta como host: essas precisam do Ruan relogar.
        if (preg_match('/session_expired|login.?wall|sessao Google|desconect|deslog|invalid.?auth|401/i', $err)) {
            return false;
        }
        return (bool) preg_match('/scp|ssh|connect|refused|timed?.?out|posix_spawn|permission denied|no route|closed by remote|tunnel|2200|remote_/i', $err);
    }

    // -- settings helpers (mesma tabela que VideoHealthMonitorCommand usa) ----------

    private function getSetting(string $key): ?string
    {
        return DB::table('settings')->where('key', $key)->value('value');
    }

    private function setSetting(string $key, string $value): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $value, 'group' => 'video', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    private function delSetting(string $key): void
    {
        DB::table('settings')->where('key', $key)->delete();
    }

    private function log(string $msg): void
    {
        // storage/logs e gravavel pelo apifrn0001 (o schedule roda como ele); o dir
        // /home/api.seller.global/logs e de outro dono e so aceita write via root.
        @file_put_contents(storage_path('logs/pc-autocura.log'),
            '[' . now()->toDateTimeString() . '] ' . $msg . "\n", FILE_APPEND);
    }
}
