<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * SEL-SEMAFORO-REBOOT (14/08) — o Ruan vai reiniciar o PC pra trocar o driver do
 * Parsec (bugcheck 0xD1, duas quedas hoje). Ele perguntou "deixa tudo ok".
 *
 * O risco do reinício não é a máquina: é derrubar vídeo de cliente no meio do
 * render. Hoje o PC caiu DUAS vezes e ninguém perdeu vídeo (o retry cobriu), mas
 * "provavelmente cobre" não é resposta pra dar pra quem está esperando o vídeo dele.
 *
 * Este comando responde UMA pergunta: dá pra reiniciar agora, sim ou não. Sem
 * interpretar log, sem abrir banco. É pra ele rodar, ler, e decidir.
 */
class PodeReiniciar extends Command
{
    protected $signature   = 'pc:pode-reiniciar';
    protected $description = 'Diz se da pra reiniciar o PC de render agora sem derrubar video de cliente';

    private const NAO_FINAIS = ['queued', 'render', 'processing', 'voice', 'lipsync', 'queued_wait'];

    public function handle(): int
    {
        $this->line('');
        $impedimentos = [];

        // ------------------------------------------- 1) tem cliente esperando video?
        $vivas = DB::table('ai_video_pipelines')
            ->whereIn('step', self::NAO_FINAIS)
            ->get(['id', 'user_id', 'step', 'created_at']);

        foreach ($vivas as $p) {
            $email = DB::table('users')->where('id', $p->user_id)->value('email');
            $min   = (int) round((time() - strtotime($p->created_at)) / 60);
            $this->line("   gerando: #{$p->id} ({$email}) ha {$min}min");
        }
        if ($vivas->count() > 0) {
            $impedimentos[] = $vivas->count() . ' cliente(s) com video sendo gerado agora';
        }

        // ------------------------------------------------ 2) tem fila pra estourar?
        $fila = DB::table('jobs')
            ->whereIn('queue', ['video', 'video-priority', 'video-ruan', 'kling-browser', 'kling-browser-priority'])
            ->count();
        if ($fila > 0) {
            $this->line("   fila: {$fila} pedido(s) esperando vez");
        }

        // -------------------------------- 3) as sessoes estao salvas antes de mexer?
        // o comando roda como usuario do site: o backup precisa estar onde ELE le
        // SEL-BACKUP-DIZ-A-IDADE (15/08): responder so SIM/NAO era mentira de duas
        // formas — nao achava backup que existia noutro caminho, e deixaria passar
        // calado um backup de tres semanas. Agora diz IDADE e COBERTURA.
        // SEL-BACKUP-ONDE-ELE-LE (15/08): /home/api.seller.global e drwx--x--x — o
        // usuario do site atravessa mas NAO lista, entao glob() ali volta vazio
        // mesmo com o backup no lugar. Mora em storage/, que e dele e nao e
        // servido pela web (conferido com curl: 404).
        $backups = glob(storage_path('backups-sessoes/*')) ?: [];
        $melhor = null;
        $melhorQtd = 0;
        foreach ($backups as $b) {
            $jsons = glob($b . '/*.json') ?: [];
            if (count($jsons) >= 5 && filemtime($b) >= (int) ($melhor ? filemtime($melhor) : 0)) {
                $melhor = $b;
                $melhorQtd = count(array_filter($jsons, fn ($j) => ! str_contains($j, '.meta.')));
            }
        }

        if ($melhor) {
            $horas = round((time() - filemtime($melhor)) / 3600, 1);
            $this->line("   backup das sessoes: {$melhorQtd} motor(es), {$horas}h atras");
            if ($horas > 48) {
                $impedimentos[] = "o backup das sessoes tem {$horas}h — velho demais pra confiar (refaz antes)";
            }
        } else {
            $impedimentos[] = 'NAO achei backup das sessoes do Google em /home/api.seller.global/backup-sessoes-* — sem elas a fabrica nao volta';
        }

        // ---------------------------------------------------- 4) o PC responde agora?
        $p = new Process([
            'ssh', '-i', '/home/api.seller.global/.ssh/pc-render', '-p', '2200',
            '-o', 'StrictHostKeyChecking=no', '-o', 'ConnectTimeout=10',
            'ruan@localhost', 'echo vivo',
        ]);
        $p->setTimeout(20);
        $p->run();
        if (! $p->isSuccessful()) {
            $this->line('   PC nao respondeu ao teste de conexao (pode ser o tunel piscando)');
        }

        // ------------------------------------------------------------------ veredito
        $this->line('');
        if (! $impedimentos) {
            $this->info('  ╔══════════════════════════════════════════╗');
            $this->info('  ║   PODE REINICIAR AGORA                   ║');
            $this->info('  ║   ninguem esta esperando video           ║');
            $this->info('  ╚══════════════════════════════════════════╝');
            $this->line('');
            $this->line('  Depois que voltar, rode:  php artisan pc:voltou');

            return self::SUCCESS;
        }

        $this->error('  ╔══════════════════════════════════════════╗');
        $this->error('  ║   ESPERA UM POUCO                        ║');
        $this->error('  ╚══════════════════════════════════════════╝');
        foreach ($impedimentos as $i) {
            $this->error('   · ' . $i);
        }
        $this->line('');
        $this->line('  Um video leva ~5min. Roda de novo em 5 e provavelmente ja libera.');

        return self::SUCCESS;
    }
}
