<?php
/**
 * SEL-EMAILTOKFY — envio de TESTE. So aceita destino nosso.
 * Usa o caminho REAL (SendAccessGrantedEmailJob com overrideEmail), pra provar
 * a corrente inteira: job -> BrandKit -> mailer smtp_tokfy -> SMTP.
 * NENHUM cliente e alcancado: overrideEmail troca o destinatario e o EmailLog
 * vai como 'access_granted_test'.
 *
 *   php tokfy-mail-teste.php <subscription_id> <email_destino>
 */
require '/home/api.seller.global/public_html/vendor/autoload.php';
$app = require_once '/home/api.seller.global/public_html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\SendAccessGrantedEmailJob;

$sub     = (int) ($argv[1] ?? 0);
$destino = strtolower(trim((string) ($argv[2] ?? '')));

// Trava dura: so caixas NOSSAS. Nao existe caminho aqui pra cliente real.
$PERMITIDOS = ['suporte@tokfy.io', 'nao-responda@seller.global', 'contato@hubai.io'];
if (! in_array($destino, $PERMITIDOS, true)) {
    fwrite(STDERR, "destino nao permitido: {$destino}\n");
    exit(1);
}
if ($sub <= 0) { fwrite(STDERR, "subscription_id invalido\n"); exit(1); }

// Roda sincrono: o Mailable e ShouldQueue e cairia na fila (que esta ocupada
// com video). Assim o envio acontece agora e o erro, se houver, aparece aqui.
config(['queue.default' => 'sync']);

echo "enviando teste da assinatura {$sub} para {$destino} ...\n";
(new SendAccessGrantedEmailJob($sub, $destino))->handle();
echo "ok — job executado sem excecao\n";
