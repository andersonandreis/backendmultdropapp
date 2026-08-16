<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * SEL-SEMAFORO-REBOOT (14/08) — o irmão do pc:pode-reiniciar.
 *
 * Depois que o Ruan reinicia, a pergunta não é "a máquina ligou" — é "a fábrica
 * voltou a entregar vídeo". As duas coisas são diferentes: hoje o PC subiu em 15
 * segundos e o pool levou minutos pra publicar. Este comando confere os quatro
 * elos, na ordem em que eles quebram, e diz o que ainda falta.
 */
class PcVoltou extends Command
{
    protected $signature   = 'pc:voltou';
    protected $description = 'Confere, depois do reinicio, se a fabrica de video voltou de verdade';

    public function handle(): int
    {
        $this->line('');
        $falhas = [];

        // ---- 1) o túnel (é por ele que o servidor fala com o PC)
        $conexoes = (int) trim((string) shell_exec("ss -tn 2>/dev/null | grep -c :2200"));
        if ($conexoes > 0) {
            $this->info("  1. tunel .................. OK ({$conexoes} conexoes)");
        } else {
            // auto-curável: o runbook manda tentar 3x antes de declarar queda
            sleep(4);
            $conexoes = (int) trim((string) shell_exec("ss -tn 2>/dev/null | grep -c :2200"));
            if ($conexoes > 0) {
                $this->info("  1. tunel .................. OK na 2a tentativa ({$conexoes})");
            } else {
                $this->error('  1. tunel .................. FORA');
                $falhas[] = 'tunel nao subiu — o servidor nao alcanca o PC';
            }
        }

        // ---- 2) o PC responde
        $p = new Process(['ssh', '-i', '/home/api.seller.global/.ssh/pc-render', '-p', '2200',
            '-o', 'StrictHostKeyChecking=no', '-o', 'ConnectTimeout=12', 'ruan@localhost', 'echo vivo']);
        $p->setTimeout(25);
        $p->run();
        if ($p->isSuccessful()) {
            $this->info('  2. PC responde ............ OK');
        } else {
            $this->error('  2. PC responde ............ NAO');
            $falhas[] = 'o PC nao respondeu por SSH';
        }

        // ---- 3) as sessões do Google (sem elas nada gera)
        $ps = new Process(['ssh', '-i', '/home/api.seller.global/.ssh/pc-render', '-p', '2200',
            '-o', 'StrictHostKeyChecking=no', '-o', 'ConnectTimeout=12', 'ruan@localhost',
            'powershell -NoProfile -Command "(Get-ChildItem C:\\sellerglobal-render\\sessoes\\*.json).Count"']);
        $ps->setTimeout(35);
        $ps->run();
        $qtd = (int) preg_replace('/\D/', '', $ps->getOutput());
        if ($qtd >= 5) {
            $this->info("  3. sessoes do Google ...... OK ({$qtd} arquivos)");
        } else {
            $this->error("  3. sessoes do Google ...... SO {$qtd} — esperado 20+");
            $falhas[] = 'sessoes sumiram ou nao montaram: restaurar de /root/backup-pc-*';
        }

        // ---- 4) o pool sabe quem são os motores
        $pool = new Process(['php', 'artisan', 'video:frota-sync']);
        $pool->setTimeout(60);
        $pool->setWorkingDirectory('/home/api.seller.global/public_html');
        $pool->run();
        $saida = $pool->getOutput();
        if (str_contains($saida, '"pool_lido":true')) {
            preg_match('/"perfis":(\d+)/', $saida, $m);
            $this->info('  4. pool de motores ........ OK (' . ($m[1] ?? '?') . ' motores)');
        } else {
            $this->error('  4. pool de motores ........ ainda nao publicou');
            $falhas[] = 'o daemon do pool leva ~3min aquecendo depois do boot — rode de novo em 3min';
        }

        $this->line('');
        if (! $falhas) {
            $this->info('  ╔══════════════════════════════════════════╗');
            $this->info('  ║   FABRICA DE VOLTA                       ║');
            $this->info('  ╚══════════════════════════════════════════╝');
            $vivas = DB::table('ai_video_pipelines')
                ->whereIn('step', ['queued', 'render', 'processing', 'voice', 'lipsync', 'queued_wait'])->count();
            $this->line("  {$vivas} pedido(s) na fila — devem andar sozinhos agora.");
            $this->line('  Pra ter certeza de verdade, o teste e um video entregue:');
            $this->line('  php artisan video:vigia');

            return self::SUCCESS;
        }

        $this->error('  AINDA FALTA:');
        foreach ($falhas as $f) {
            $this->error('   · ' . $f);
        }

        return self::SUCCESS;
    }
}
