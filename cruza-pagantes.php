<?php
/**
 * SEL-CRUZA (12/08) — quem PAGOU de verdade x quem TEM acesso.
 * Le todos os pedidos PAGOS do gateway (paginado) e cruza por e-mail com o banco.
 * Nao escreve nada: so mede.
 */
require '/home/api.seller.global/public_html/vendor/autoload.php';
$app = require_once '/home/api.seller.global/public_html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$key = (string) config('services.pagarme.api_key');

function api(string $url, string $key): array {
    $arq = tempnam(sys_get_temp_dir(), 'pg');
    shell_exec('curl -s -u ' . escapeshellarg($key . ':') . ' ' . escapeshellarg($url) . ' -o ' . escapeshellarg($arq));
    $j = json_decode((string) @file_get_contents($arq), true) ?: [];
    @unlink($arq);
    return $j;
}

$pagantes = [];   // email => ['total'=>x, 'ultimo'=>data, 'itens'=>[], 'orders'=>[]]
$pagina = 1;
$lidos = 0;
while ($pagina <= 12) {
    $d = api("https://api.pagar.me/core/v5/orders?status=paid&size=100&page={$pagina}", $key);
    $linhas = $d['data'] ?? [];
    if (! $linhas) break;
    foreach ($linhas as $o) {
        $lidos++;
        $email = strtolower(trim((string) ($o['customer']['email'] ?? '')));
        if ($email === '') continue;
        $desc = [];
        foreach (($o['items'] ?? []) as $i) { $desc[] = (string) ($i['description'] ?? ''); }
        $pagantes[$email]['total']   = ($pagantes[$email]['total'] ?? 0) + ((int) ($o['amount'] ?? 0)) / 100;
        $pagantes[$email]['ultimo']  = max($pagantes[$email]['ultimo'] ?? '', (string) ($o['created_at'] ?? ''));
        $pagantes[$email]['itens'][] = implode(' + ', $desc);
        $pagantes[$email]['orders'][] = (string) ($o['id'] ?? '');
    }
    $pagina++;
}

echo "pedidos PAGOS lidos no gateway: {$lidos} | e-mails distintos que pagaram: " . count($pagantes) . PHP_EOL . PHP_EOL;

// --- os 221 importados ---
$imp = DB::table('subscriptions as s')
    ->join('clients as c', 'c.id', '=', 's.client_id')
    ->join('users as u', 'u.id', '=', 'c.user_id')
    ->where('s.status', 'pending_release')
    ->select('s.id as sub', 'u.id as uid', 'u.email')
    ->get();

$casou = 0; $naoCasou = [];
foreach ($imp as $r) {
    $e = strtolower(trim($r->email));
    if (isset($pagantes[$e])) { $casou++; } else { $naoCasou[] = $e; }
}
echo "IMPORTADOS (pending_release): " . count($imp) . PHP_EOL;
echo "  com pagamento CONFIRMADO no gateway: {$casou}" . PHP_EOL;
echo "  SEM pagamento no gateway: " . count($naoCasou) . PHP_EOL;
echo "  exemplos sem pagamento: " . implode(', ', array_slice($naoCasou, 0, 5)) . PHP_EOL . PHP_EOL;

// --- quem pagou e NAO tem acesso ativo ---
$semAcesso = []; $comAcesso = 0; $semConta = [];
foreach ($pagantes as $email => $info) {
    $u = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->first();
    if (! $u) { $semConta[] = $email; continue; }
    $c = DB::table('clients')->where('user_id', $u->id)->first();
    $s = $c ? DB::table('subscriptions')->where('client_id', $c->id)->orderByDesc('id')->first() : null;
    if ($s && $s->status === 'active') { $comAcesso++; continue; }
    $semAcesso[] = [
        'email'  => $email,
        'pago'   => $info['total'],
        'ultimo' => substr((string) $info['ultimo'], 0, 10),
        'status' => $s->status ?? 'SEM ASSINATURA',
        'item'   => substr((string) ($info['itens'][0] ?? ''), 0, 34),
    ];
}

echo "QUEM PAGOU (por e-mail):" . PHP_EOL;
echo "  com acesso ATIVO: {$comAcesso}" . PHP_EOL;
echo "  pagou e NAO tem acesso: " . count($semAcesso) . PHP_EOL;
echo "  pagou e nao tem NEM CONTA: " . count($semConta) . PHP_EOL . PHP_EOL;

usort($semAcesso, fn ($a, $b) => strcmp($b['ultimo'], $a['ultimo']));
echo "--- pagou e esta sem acesso (20 mais recentes) ---" . PHP_EOL;
foreach (array_slice($semAcesso, 0, 20) as $x) {
    printf("  %-38s R$ %7.2f  %s  %-16s %s\n", $x['email'], $x['pago'], $x['ultimo'], $x['status'], $x['item']);
}
if ($semConta) {
    echo PHP_EOL . "--- pagou e nao tem conta (" . count($semConta) . ") ---" . PHP_EOL;
    foreach (array_slice($semConta, 0, 15) as $e) { echo "  {$e}" . PHP_EOL; }
}
