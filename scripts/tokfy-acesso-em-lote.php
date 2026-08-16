<?php
/**
 * SEL-EMAILTOKFY — DISPARO EM LOTE do e-mail de acesso da TOKFY.
 * ==============================================================
 * NAO FOI EXECUTADO. Quem manda pros 317 e o dono, nao o agente.
 *
 * O QUE FAZ
 *   Manda o e-mail de acesso (marca Tokfy) pros clientes que PAGARAM Tokfy:
 *     subscriptions.plan_id IN (99,100,101)  (Video IA — Start/Ilimitado/Ultra)
 *     AND subscriptions.status        = 'active'
 *     AND subscriptions.pagarme_status = 'paid'
 *   Em 12/08 isso da exatamente 317 assinaturas — o numero que o dono citou.
 *
 * ORDEM
 *   Do MAIS RECENTE pro mais antigo (ordem explicita do dono: "quem comprou
 *   agora pra tras"): ORDER BY created_at DESC, id DESC.
 *
 * ONDAS
 *   Nao dispara 317 de uma vez. Dominio que nao manda volume ha tempos cai em
 *   spam se soltar tudo junto. Padrao: 25 por onda, 120s de pausa entre ondas,
 *   4s entre um e-mail e outro dentro da onda (~26 min no total). Ajustavel:
 *     --onda=25 --pausa=120 --intervalo=4
 *
 * NUNCA MANDA DUAS VEZES / PODE SER INTERROMPIDO
 *   Quem controla e a tabela email_logs (email_type='access_granted'), a MESMA
 *   que o SendAccessGrantedEmailJob usa. Antes de cada envio o job confere se
 *   aquele user ja tem log 'sent/delivered/opened/clicked' e pula. Entao:
 *     - matar o script no meio (Ctrl-C, queda de SSH) nao duplica nada;
 *     - rodar de novo continua de onde parou;
 *     - envio automatico e disparo em lote nao se atropelam.
 *   Alem disso ele grava o proprio diario em storage/logs/tokfy-acesso-lote.log.
 *
 * COMO PARAR NO MEIO
 *   Ctrl-C, ou criar o arquivo /tmp/PARAR_TOKFY_LOTE (ele confere a cada envio).
 *
 * DUAS TRAVAS PRA MANDAR DE VERDADE (as duas, juntas)
 *   1) o interruptor no banco:
 *        UPDATE settings SET value='1'
 *         WHERE `group`='billing' AND `key`='access_granted_mail_enabled';
 *   2) a flag --exec na linha de comando.
 *   Sem as duas, roda em SIMULACAO: lista quem receberia e nao manda nada.
 *   Proposital: nao existe segundo caminho escondido pra alcancar cliente real.
 *
 * USO
 *   cd /home/api.seller.global/public_html
 *   sudo -u apifrn0001 /usr/local/lsws/lsphp82/bin/php8.2 \
 *        scripts/tokfy-acesso-em-lote.php                 # simulacao
 *   sudo -u apifrn0001 /usr/local/lsws/lsphp82/bin/php8.2 \
 *        scripts/tokfy-acesso-em-lote.php --exec          # manda de verdade
 */

require '/home/api.seller.global/public_html/vendor/autoload.php';
$app = require_once '/home/api.seller.global/public_html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\EmailStatus;
use App\Jobs\SendAccessGrantedEmailJob;
use App\Models\EmailLog;
use App\Support\BrandKit;
use Illuminate\Support\Facades\DB;

/* ----------------------------- parametros ----------------------------- */
$arg = function (string $nome, $padrao) use ($argv) {
    foreach ($argv as $a) {
        if (str_starts_with($a, "--{$nome}=")) { return substr($a, strlen($nome) + 3); }
    }
    return $padrao;
};

$EXEC      = in_array('--exec', $argv, true);
$ONDA      = max(1, (int) $arg('onda', 25));        // e-mails por onda
$PAUSA     = max(0, (int) $arg('pausa', 120));      // segundos entre ondas
$INTERVALO = max(0, (int) $arg('intervalo', 4));    // segundos entre e-mails
$LIMITE    = (int) $arg('limite', 0);               // 0 = todos (util pra piloto)
$DIARIO    = storage_path('logs/tokfy-acesso-lote.log');
$PARAR     = '/tmp/PARAR_TOKFY_LOTE';

$diz = function (string $linha) use ($DIARIO) {
    $l = '[' . now()->toDateTimeString() . '] ' . $linha;
    echo $l . PHP_EOL;
    @file_put_contents($DIARIO, $l . PHP_EOL, FILE_APPEND);
};

// Ctrl-C sai limpo: o proximo run reaproveita o ledger e continua.
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT,  function () use ($diz) { $diz('INTERROMPIDO (SIGINT). Rodar de novo continua de onde parou.'); exit(130); });
    pcntl_signal(SIGTERM, function () use ($diz) { $diz('INTERROMPIDO (SIGTERM). Rodar de novo continua de onde parou.'); exit(143); });
}

// Cada e-mail sai na hora, no ritmo daqui. Sem isso o Mailable (ShouldQueue)
// cairia todo de uma vez na fila database — que esta ocupada com video — e o
// worker soltaria os 317 em rajada, exatamente o que queremos evitar.
config(['queue.default' => 'sync']);

/* ----------------------------- publico-alvo ---------------------------- */
$alvo = DB::table('subscriptions as s')
    ->join('clients as c', 'c.id', '=', 's.client_id')
    ->join('users as u', 'u.id', '=', 'c.user_id')
    ->join('plans as p', 'p.id', '=', 's.plan_id')
    ->whereIn('s.plan_id', BrandKit::TOKFY_PLAN_IDS)
    ->where('s.status', 'active')
    ->where('s.pagarme_status', 'paid')
    ->whereNotNull('u.email')
    ->where('u.email', '<>', '')
    ->orderByDesc('s.created_at')
    ->orderByDesc('s.id')
    ->get(['s.id as sub_id', 's.plan_id', 's.created_at', 'u.id as user_id', 'u.email', 'u.name', 'p.name as plan_name', 'p.slug as plan_slug']);

$diz(sprintf('alvo bruto: %d assinaturas (planos %s, active + paid)', $alvo->count(), implode('/', BrandKit::TOKFY_PLAN_IDS)));

/* --------- filtros de seguranca: marca certa + ainda nao recebeu -------- */
$fila = []; $jaRecebeu = 0; $marcaErrada = 0; $emailRepetido = 0; $vistos = [];

foreach ($alvo as $r) {
    // Cinto e suspensorio: so segue quem o BrandKit confirma como Tokfy.
    if (! BrandKit::isTokfy((object) ['id' => $r->plan_id, 'slug' => $r->plan_slug])) {
        $marcaErrada++;
        continue;
    }

    $email = strtolower(trim((string) $r->email));
    if (isset($vistos[$email])) { $emailRepetido++; continue; }   // 1 e-mail = 1 envio
    $vistos[$email] = true;

    $recebido = EmailLog::where('user_id', $r->user_id)
        ->where('email_type', SendAccessGrantedEmailJob::TIPO)
        ->whereIn('status', [
            EmailStatus::Sent->value, EmailStatus::Delivered->value,
            EmailStatus::Opened->value, EmailStatus::Clicked->value,
        ])->exists();

    if ($recebido) { $jaRecebeu++; continue; }

    $fila[] = $r;
}

if ($LIMITE > 0) { $fila = array_slice($fila, 0, $LIMITE); }

$diz(sprintf('descartados: %d ja receberam · %d e-mail repetido · %d marca errada', $jaRecebeu, $emailRepetido, $marcaErrada));
$diz(sprintf('A ENVIAR AGORA: %d  (ondas de %d, %ds entre ondas, %ds entre e-mails)', count($fila), $ONDA, $PAUSA, $INTERVALO));
$diz(sprintf('tempo estimado: ~%d min', (int) ceil((count($fila) * $INTERVALO + (max(0, ceil(count($fila) / $ONDA) - 1)) * $PAUSA) / 60)));

/* ----------------------- travas do envio de verdade --------------------- */
$interruptor = SendAccessGrantedEmailJob::habilitado();

if (! $EXEC || ! $interruptor) {
    $diz('MODO SIMULACAO — nada foi enviado.');
    $diz('  --exec ..................... ' . ($EXEC ? 'sim' : 'NAO'));
    $diz('  interruptor no banco ....... ' . ($interruptor ? 'LIGADO' : 'DESLIGADO'));
    if (! $interruptor) {
        $diz("  para ligar: UPDATE settings SET value='1' WHERE `group`='billing' AND `key`='access_granted_mail_enabled';");
    }
    $diz('--- primeiros 20 da fila (do mais recente pro mais antigo) ---');
    foreach (array_slice($fila, 0, 20) as $i => $r) {
        printf("  %3d  %-42s %-24s %s\n", $i + 1, mb_substr($r->email, 0, 42), mb_substr($r->plan_name, 0, 24), substr((string) $r->created_at, 0, 16));
    }
    exit(0);
}

/* ------------------------------- disparo ------------------------------- */
$diz('=== ENVIO REAL COMECANDO ===');
$enviados = 0; $falhas = 0; $n = 0;

foreach ($fila as $r) {
    if (file_exists($PARAR)) {
        $diz("parada pedida ({$PARAR}) — encerrando sem duplicar. Apague o arquivo pra poder continuar depois.");
        break;
    }

    $n++;
    try {
        // Caminho unico de envio. Ele repete a checagem de idempotencia e do
        // interruptor por dentro — nao existe atalho aqui.
        (new SendAccessGrantedEmailJob((int) $r->sub_id))->handle();
        $enviados++;
        $diz(sprintf('  [%d/%d] ok   sub=%d %s', $n, count($fila), $r->sub_id, $r->email));
    } catch (\Throwable $e) {
        $falhas++;
        $diz(sprintf('  [%d/%d] FALHA sub=%d %s -> %s', $n, count($fila), $r->sub_id, $r->email, $e->getMessage()));
    }

    if ($n % $ONDA === 0 && $n < count($fila)) {
        $diz(sprintf('--- onda de %d fechada (%d/%d). Pausa de %ds ---', $ONDA, $n, count($fila), $PAUSA));
        sleep($PAUSA);
    } elseif ($INTERVALO > 0 && $n < count($fila)) {
        sleep($INTERVALO);
    }
}

$diz(sprintf('=== FIM === enviados=%d falhas=%d de %d na fila. Diario: %s', $enviados, $falhas, count($fila), $DIARIO));
