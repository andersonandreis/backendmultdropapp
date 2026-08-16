<?php
/**
 * SEL-TOKFY-DIAG (12/08) — SOMENTE LEITURA. Nao escreve nada.
 * Le pedidos PAGOS das DUAS contas Pagar.me, filtra so produto TOKFY,
 * e cruza por e-mail com users -> clients -> subscriptions.
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

$tokfy   = [];   // email => dados agregados (SO pedidos Tokfy)
$outros  = [];   // email => descricoes de produtos nao-Tokfy
$ambig   = [];   // casos que NAO decido sozinho
$stats   = ['lidos' => 0, 'tokfy_orders' => 0, 'outros_orders' => 0, 'sem_email' => 0, 'sem_desc' => 0];
$catalogo = [];  // descricao => qtd

foreach ($CONTAS as $conta => $key) {
    if ($key === '') { echo "conta {$conta}: SEM CHAVE\n"; continue; }
    $lidosConta = 0;
    for ($pagina = 1; $pagina <= 60; $pagina++) {
        $d = api("https://api.pagar.me/core/v5/orders?status=paid&size=100&page={$pagina}", $key);
        if (isset($d['message'])) { echo "conta {$conta} pag {$pagina}: ERRO API -> {$d['message']}\n"; break; }
        $linhas = $d['data'] ?? [];
        if (! $linhas) break;
        foreach ($linhas as $o) {
            $stats['lidos']++; $lidosConta++;
            $oid   = (string) ($o['id'] ?? '');
            $email = strtolower(trim((string) ($o['customer']['email'] ?? '')));
            $nome  = trim((string) ($o['customer']['name'] ?? ''));
            $cust  = (string) ($o['customer']['id'] ?? '');
            $valor = ((int) ($o['amount'] ?? 0)) / 100;
            $data  = substr((string) ($o['created_at'] ?? ''), 0, 19);

            $descs = [];
            foreach (($o['items'] ?? []) as $i) {
                $t = trim((string) ($i['description'] ?? ''));
                if ($t !== '') { $descs[] = $t; }
            }
            $desc = implode(' + ', $descs);
            foreach ($descs as $t) { $catalogo[$t] = ($catalogo[$t] ?? 0) + 1; }

            $ehTokfy = false;
            foreach ($descs as $t) {
                if (stripos($t, 'tokfy') !== false || stripos($t, 'tokify') !== false) { $ehTokfy = true; break; }
            }

            if ($email === '') {
                $stats['sem_email']++;
                $ambig[] = ['motivo' => 'PEDIDO SEM E-MAIL', 'conta' => $conta, 'order' => $oid, 'valor' => $valor, 'data' => $data, 'desc' => $desc];
                continue;
            }
            if ($desc === '') {
                $stats['sem_desc']++;
                $ambig[] = ['motivo' => 'ITEM SEM DESCRICAO', 'conta' => $conta, 'order' => $oid, 'email' => $email, 'valor' => $valor, 'data' => $data, 'desc' => '(vazio)'];
                continue;
            }

            if (! $ehTokfy) {
                $stats['outros_orders']++;
                $outros[$email][$desc] = true;
                continue;
            }

            $stats['tokfy_orders']++;
            if (! isset($tokfy[$email])) {
                $tokfy[$email] = ['nome' => $nome, 'total' => 0.0, 'ultimo' => '', 'orders' => [], 'cust' => $cust, 'itens' => [], 'contas' => []];
            }
            $tokfy[$email]['total']   += $valor;
            $tokfy[$email]['ultimo']   = max($tokfy[$email]['ultimo'], $data);
            $tokfy[$email]['orders'][] = $oid;
            $tokfy[$email]['itens'][$desc] = true;
            $tokfy[$email]['contas'][$conta] = true;
            if ($nome !== '' && $tokfy[$email]['nome'] === '') { $tokfy[$email]['nome'] = $nome; }
            if ($cust !== '') { $tokfy[$email]['cust'] = $cust; }
        }
    }
    echo "conta {$conta}: {$lidosConta} pedidos PAGOS lidos\n";
}

echo "\n=== CATALOGO DE PRODUTOS PAGOS (descricao => qtd) ===\n";
arsort($catalogo);
foreach ($catalogo as $t => $n) {
    $flag = (stripos($t, 'tokfy') !== false || stripos($t, 'tokify') !== false) ? 'TOKFY ' : '      ';
    printf("  %s%-55s %d\n", $flag, mb_substr($t, 0, 55), $n);
}

echo "\n=== TOTAIS DE LEITURA ===\n";
foreach ($stats as $k => $v) { echo "  {$k} = {$v}\n"; }
echo '  e-mails distintos que pagaram TOKFY = ' . count($tokfy) . "\n";

/* ---------- cruzamento com o banco ---------- */
$semConta = []; $semAssin = []; $inativo = []; $jaAtivo = []; $protegido = [];
$PROTEGIDOS = [85, 86, 87, 94, 95, 96];
$fim = now()->addDays(30);

foreach ($tokfy as $email => $info) {
    $u = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->first();
    if (! $u) { $semConta[$email] = $info; continue; }

    $dupUsers = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->count();
    if ($dupUsers > 1) {
        $ambig[] = ['motivo' => 'E-MAIL DUPLICADO EM users', 'email' => $email, 'qtd' => $dupUsers, 'valor' => $info['total'], 'data' => $info['ultimo'], 'desc' => implode(' / ', array_keys($info['itens']))];
        continue;
    }

    $c = DB::table('clients')->where('user_id', $u->id)->first();
    $s = $c ? DB::table('subscriptions')->where('client_id', $c->id)->orderByDesc('id')->first() : null;

    $info['uid'] = $u->id; $info['cid'] = $c->id ?? null;
    $info['sub'] = $s ? ($s->status . '/plan' . $s->plan_id . '/ate ' . substr((string) $s->current_period_end, 0, 10)) : 'SEM ASSINATURA';

    if ($s && $s->status === 'active' && in_array((int) $s->plan_id, $PROTEGIDOS, true)) { $protegido[$email] = $info; continue; }
    if ($s && $s->status === 'active' && $s->current_period_end && $s->current_period_end > $fim) { $jaAtivo[$email] = $info; continue; }
    if ($s && $s->status === 'active') { $jaAtivo[$email] = $info; continue; }
    if (! $s) { $semAssin[$email] = $info; continue; }
    $inativo[$email] = $info;
}

echo "\n=== CRUZAMENTO (pagantes TOKFY x banco) ===\n";
printf("  JA TEM ACESSO ativo ................... %d\n", count($jaAtivo));
printf("  ja ativo em plano de OUTRO produto .... %d  (protegido, nao mexe)\n", count($protegido));
printf("  tem conta, assinatura NAO ativa ....... %d\n", count($inativo));
printf("  tem conta, SEM assinatura ............. %d\n", count($semAssin));
printf("  SEM CONTA (criar) ..................... %d\n", count($semConta));
printf("  --------------------------------------------\n");
printf("  A LIBERAR (inativo + sem assin + sem conta) = %d\n", count($inativo) + count($semAssin) + count($semConta));
printf("  DESSES, contas a criar ...................... %d\n", count($semConta));
printf("  AMBIGUOS (separados, nao decido) ............ %d\n", count($ambig));

$dump = function (string $titulo, array $lista, int $lim = 400) {
    if (! $lista) return;
    echo "\n--- {$titulo} (" . count($lista) . ") ---\n";
    uasort($lista, fn ($a, $b) => strcmp($b['ultimo'], $a['ultimo']));
    $n = 0;
    foreach ($lista as $e => $i) {
        if (++$n > $lim) { echo "  ... +" . (count($lista) - $lim) . "\n"; break; }
        printf("  %-40s R$ %8.2f  %s  %-34s %s\n", mb_substr($e, 0, 40), $i['total'], substr($i['ultimo'], 0, 10),
            mb_substr(implode(' / ', array_keys($i['itens'])), 0, 34), $i['sub'] ?? 'SEM CONTA');
    }
};

$dump('SEM CONTA — seria criada', $semConta);
$dump('TEM CONTA, assinatura NAO ativa', $inativo);
$dump('TEM CONTA, SEM assinatura', $semAssin);
$dump('PROTEGIDOS (plano ativo de outro produto)', $protegido);

if ($ambig) {
    echo "\n--- AMBIGUOS / PULADOS (" . count($ambig) . ") ---\n";
    foreach ($ambig as $a) {
        printf("  %-24s %-14s %-38s R$ %8.2f  %s  %s\n", $a['motivo'], $a['conta'] ?? '-', mb_substr($a['email'] ?? '(sem email)', 0, 38),
            $a['valor'] ?? 0, substr((string) ($a['data'] ?? ''), 0, 10), mb_substr((string) ($a['desc'] ?? ''), 0, 30));
    }
}

file_put_contents('/tmp/tokfy_alvo.json', json_encode([
    'sem_conta' => array_keys($semConta), 'inativo' => array_keys($inativo), 'sem_assin' => array_keys($semAssin),
], JSON_PRETTY_PRINT));
echo "\nlista alvo salva em /tmp/tokfy_alvo.json\n";
