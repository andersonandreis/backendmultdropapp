<?php
/**
 * SEL-TOKFY-RECONC (12/08) — SOMENTE LEITURA. Nao escreve NADA no banco.
 * 1) monta pagantes TOKFY confirmados (paid) das DUAS contas Pagar.me
 * 2) monta tambem pagantes de OUTROS produtos (pra nao confundir "sem lastro")
 * 3) cruza com as assinaturas que a sessao principal ativou hoje
 */
require '/home/api.seller.global/public_html/vendor/autoload.php';
$app = require_once '/home/api.seller.global/public_html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$CONTAS = [
    'NOVA'   => trim((string) config('services.pagarme.api_key')),
    'ANTIGA' => 'sk_edbe0e1212d9413fbf0a1cc04822fb93',
];

function api(string $url, string $key): array {
    $arq = tempnam(sys_get_temp_dir(), 'pg');
    shell_exec('curl -s --max-time 60 -u ' . escapeshellarg($key . ':') . ' ' . escapeshellarg($url) . ' -o ' . escapeshellarg($arq));
    $j = json_decode((string) @file_get_contents($arq), true) ?: [];
    @unlink($arq);
    return $j;
}

$tokfy = []; $outro = [];
foreach ($CONTAS as $conta => $key) {
    if ($key === '') continue;
    for ($p = 1; $p <= 60; $p++) {
        $d = api("https://api.pagar.me/core/v5/orders?status=paid&size=100&page={$p}", $key);
        if (isset($d['message'])) { echo "ERRO API {$conta}: {$d['message']}\n"; break; }
        $linhas = $d['data'] ?? [];
        if (! $linhas) break;
        foreach ($linhas as $o) {
            $email = strtolower(trim((string) ($o['customer']['email'] ?? '')));
            if ($email === '') continue;
            $descs = [];
            foreach (($o['items'] ?? []) as $i) { $t = trim((string) ($i['description'] ?? '')); if ($t !== '') $descs[] = $t; }
            $desc = implode(' + ', $descs);
            $eh = false;
            foreach ($descs as $t) { if (stripos($t, 'tokfy') !== false || stripos($t, 'tokify') !== false) { $eh = true; break; } }
            $alvo = $eh ? 'tokfy' : 'outro';
            $ref  = &${$alvo};
            if (! isset($ref[$email])) $ref[$email] = ['nome' => (string) ($o['customer']['name'] ?? ''), 'total' => 0.0, 'ultimo' => '', 'order' => '', 'cust' => '', 'itens' => []];
            $ref[$email]['total'] += ((int) ($o['amount'] ?? 0)) / 100;
            $data = substr((string) ($o['created_at'] ?? ''), 0, 19);
            if ($data > $ref[$email]['ultimo']) {
                $ref[$email]['ultimo'] = $data;
                $ref[$email]['order']  = (string) ($o['id'] ?? '');
                $ref[$email]['cust']   = (string) ($o['customer']['id'] ?? '');
            }
            $ref[$email]['itens'][$desc] = true;
            unset($ref);
        }
    }
}
echo 'pagantes TOKFY (distintos): ' . count($tokfy) . ' | pagantes de OUTRO produto: ' . count($outro) . "\n";

/* ---- quem a sessao principal ativou hoje ---- */
$logEmails = [];
$log = @file('/home/api.seller.global/public_html/storage/logs/libera30.log') ?: [];
$dentroExec = false;
foreach ($log as $l) {
    if (preg_match('/^\d{4}-\d\d-\d\dT.* (EXEC|SIMULACAO)/', $l, $m)) { $dentroExec = ($m[1] === 'EXEC'); continue; }
    if ($dentroExec && preg_match('/^LIBERADO\s+(\S+)/', $l, $m)) { $logEmails[strtolower($m[1])] = true; }
}
echo 'e-mails LIBERADO no log (blocos EXEC): ' . count($logEmails) . "\n";

$hoje = now()->toDateString();
$ativadasHoje = DB::table('subscriptions as s')
    ->join('clients as c', 'c.id', '=', 's.client_id')
    ->join('users as u', 'u.id', '=', 'c.user_id')
    ->where('s.status', 'active')
    ->whereDate('s.updated_at', $hoje)
    ->select('s.id as sid', 's.plan_id', 's.pagarme_status', 's.external_payment_id', 's.pagarme_customer_id',
             's.current_period_start', 's.current_period_end', 'u.email', 'u.id as uid')
    ->get();
echo 'assinaturas ACTIVE com updated_at de hoje: ' . count($ativadasHoje) . "\n";
$semLastroDb = $ativadasHoje->filter(fn ($r) => $r->pagarme_status === null && $r->external_payment_id === null);
echo '  dessas, sem lastro no banco (pagarme_status e external_payment_id nulos): ' . count($semLastroDb) . "\n\n";

/* ---- classificacao ---- */
$confirmado = []; $outroProduto = []; $semLastro = []; $foraDoLog = [];
foreach ($ativadasHoje as $r) {
    $e = strtolower(trim($r->email));
    $noLog = isset($logEmails[$e]);
    $linha = ['email' => $e, 'sid' => $r->sid, 'plan' => $r->plan_id, 'noLog' => $noLog,
              'ini' => substr((string) $r->current_period_start, 0, 10), 'fim' => substr((string) $r->current_period_end, 0, 10),
              'lastro' => $r->pagarme_status ?? '(nulo)'];
    if (isset($tokfy[$e]))      { $confirmado[$e]   = $linha + ['pago' => $tokfy[$e]['total'], 'data' => substr($tokfy[$e]['ultimo'], 0, 10), 'order' => $tokfy[$e]['order'], 'cust' => $tokfy[$e]['cust'], 'item' => implode(' / ', array_keys($tokfy[$e]['itens']))]; }
    elseif (isset($outro[$e]))  { $outroProduto[$e] = $linha + ['pago' => $outro[$e]['total'], 'data' => substr($outro[$e]['ultimo'], 0, 10), 'item' => implode(' / ', array_keys($outro[$e]['itens']))]; }
    else                        { $semLastro[$e]    = $linha; }
    if (! $noLog) { $foraDoLog[$e] = $linha; }
}

echo "=== AS ATIVACOES DE HOJE, CLASSIFICADAS ===\n";
printf("  CONFIRMADOS (pagaram TOKFY paid) ............... %d\n", count($confirmado));
printf("  pagaram OUTRO produto (nao Tokfy) — AMBIGUO ... %d\n", count($outroProduto));
printf("  SEM LASTRO (nenhum pagamento no gateway) ....... %d\n", count($semLastro));
printf("  (dos ativados hoje, fora do log libera30) ...... %d\n", count($foraDoLog));

$amostra = function (string $t, array $l, int $n = 10) {
    if (! $l) return;
    echo "\n--- {$t} — amostra de " . min($n, count($l)) . " de " . count($l) . " ---\n";
    $i = 0;
    foreach ($l as $e => $x) {
        if (++$i > $n) break;
        printf("  %-42s sub#%-6s plan%-4s pago R$ %8.2f %s  %s\n", mb_substr($e, 0, 42), $x['sid'], $x['plan'],
            $x['pago'] ?? 0, $x['data'] ?? '  -       ', mb_substr((string) ($x['item'] ?? ''), 0, 30));
    }
};
$amostra('CONFIRMADOS Tokfy', $confirmado);
$amostra('PAGARAM OUTRO PRODUTO (ambiguo, nao decido)', $outroProduto, 30);
$amostra('SEM LASTRO (candidatos a reverter)', $semLastro);

file_put_contents('/tmp/reconc.json', json_encode([
    'confirmado' => array_keys($confirmado), 'outro_produto' => array_keys($outroProduto),
    'sem_lastro' => array_keys($semLastro), 'fora_do_log' => array_keys($foraDoLog),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nlistas salvas em /tmp/reconc.json\n";
