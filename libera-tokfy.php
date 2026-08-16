<?php
/**
 * SEL-TOKFY-LIBERA (12/08) — "so pagamento confirmado da Tokfy" (ordem do Ruan).
 *
 * Le pedidos PAGOS das DUAS contas Pagar.me (a nova do .env + a antiga SO LEITURA),
 * filtra SO itens cuja descricao contenha "Tokfy", e:
 *   A) grava o LASTRO (external_payment_id / pagarme_customer_id / pagarme_status)
 *      em quem ja esta ativo mas ficou sem rastro do pagamento;
 *   B) LIBERA 30 dias no plano 100 pra quem pagou Tokfy e esta sem acesso,
 *      criando conta (senha 123456) pra quem nao tem.
 *
 * NAO reverte nada. NAO manda e-mail. NAO altera o .env.
 * Roda em SIMULACAO por padrao; so grava com --exec.
 */
require '/home/api.seller.global/public_html/vendor/autoload.php';
$app = require_once '/home/api.seller.global/public_html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$EXEC       = in_array('--exec', $argv, true);
$PLANO      = 100;                       // Video IA — Ilimitado (mensal)
$DIAS       = 30;
$SENHA      = '123456';
$PROTEGIDOS = [85, 86, 87, 94, 95, 96];  // dropshipping/seller — nao rebaixar
$LOG        = storage_path('logs/libera-tokfy.log');

$CONTAS = [
    'NOVA'   => trim((string) config('services.pagarme.api_key')),
    'ANTIGA' => 'sk_edbe0e1212d9413fbf0a1cc04822fb93',   // SO LEITURA
];

function api(string $url, string $key): array {
    $arq = tempnam(sys_get_temp_dir(), 'pg');
    shell_exec('curl -s --max-time 60 -u ' . escapeshellarg($key . ':') . ' ' . escapeshellarg($url) . ' -o ' . escapeshellarg($arq));
    $j = json_decode((string) @file_get_contents($arq), true) ?: [];
    @unlink($arq);
    return $j;
}

/* ---------- 1. pagantes TOKFY confirmados (status=paid) ---------- */
$tokfy = []; $ambiguos = []; $lidos = 0; $tokfyOrders = 0;
foreach ($CONTAS as $conta => $key) {
    if ($key === '') continue;
    for ($p = 1; $p <= 60; $p++) {
        $d = api("https://api.pagar.me/core/v5/orders?status=paid&size=100&page={$p}", $key);
        if (isset($d['message'])) { echo "ERRO API {$conta}: {$d['message']}\n"; exit(1); }
        $linhas = $d['data'] ?? [];
        if (! $linhas) break;
        foreach ($linhas as $o) {
            $lidos++;
            $email = strtolower(trim((string) ($o['customer']['email'] ?? '')));
            $descs = [];
            foreach (($o['items'] ?? []) as $i) { $t = trim((string) ($i['description'] ?? '')); if ($t !== '') $descs[] = $t; }
            $desc  = implode(' + ', $descs);
            $oid   = (string) ($o['id'] ?? '');
            $valor = ((int) ($o['amount'] ?? 0)) / 100;
            $data  = substr((string) ($o['created_at'] ?? ''), 0, 19);

            $ehTokfy = false;
            foreach ($descs as $t) { if (stripos($t, 'tokfy') !== false || stripos($t, 'tokify') !== false) { $ehTokfy = true; break; } }
            if (! $ehTokfy) continue;                       // outro produto: fora desta rodada
            $tokfyOrders++;

            if ($email === '') { $ambiguos[] = "PEDIDO TOKFY SEM E-MAIL  conta={$conta} order={$oid} R$ {$valor} {$data} [{$desc}]"; continue; }
            if ($desc === '')  { $ambiguos[] = "PEDIDO SEM DESCRICAO     conta={$conta} order={$oid} {$email}"; continue; }

            if (! isset($tokfy[$email])) {
                $tokfy[$email] = ['nome' => trim((string) ($o['customer']['name'] ?? '')), 'total' => 0.0,
                                  'ultimo' => '', 'order' => '', 'cust' => '', 'itens' => [], 'qtd' => 0];
            }
            $tokfy[$email]['total'] += $valor;
            $tokfy[$email]['qtd']++;
            $tokfy[$email]['itens'][$desc] = true;
            if ($data > $tokfy[$email]['ultimo']) {
                $tokfy[$email]['ultimo'] = $data;
                $tokfy[$email]['order']  = $oid;
                $tokfy[$email]['cust']   = (string) ($o['customer']['id'] ?? '');
            }
        }
    }
}
echo "pedidos PAGOS lidos (2 contas): {$lidos} | pedidos TOKFY: {$tokfyOrders} | e-mails TOKFY distintos: " . count($tokfy) . "\n";

$fim  = now()->addDays($DIAS);
$ini  = now();
$c = ['criouUser' => 0, 'criouClient' => 0, 'liberou' => 0, 'lastro' => 0, 'protegido' => 0, 'prazoMaior' => 0, 'jaOkSemMexer' => 0, 'erro' => 0];
$linhas = [];

foreach ($tokfy as $email => $i) {
    try {
        $pago  = number_format($i['total'], 2, ',', '.');
        $quando = substr($i['ultimo'], 0, 10);
        $item  = implode(' / ', array_keys($i['itens']));
        $novoUser = false;

        $user = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->first();
        if (! $user) {
            $novoUser = true; $c['criouUser']++;
            if ($EXEC) {
                $uid = DB::table('users')->insertGetId([
                    'name'              => $i['nome'] !== '' ? $i['nome'] : Str::before($email, '@'),
                    'email'             => $email,
                    'password'          => Hash::make($SENHA),
                    'email_verified_at' => now(),
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);
                $user = DB::table('users')->find($uid);
                $linhas[] = "CONTA CRIADA   {$email}  R$ {$pago}  pago em {$quando}  [{$item}]";
            } else {
                $linhas[] = "CRIARIA CONTA  {$email}  R$ {$pago}  pago em {$quando}  [{$item}]";
            }
        }

        $cli = $user ? DB::table('clients')->where('user_id', $user->id)->orderBy('id')->first() : null;
        if ($user && ! $cli) {
            $c['criouClient']++;
            if ($EXEC) {
                $novoCli = ['user_id' => $user->id, 'service' => 'hubai', 'created_at' => now(), 'updated_at' => now()];
                if ($i['cust'] !== '') { $novoCli['pagarme_customer_id'] = $i['cust']; }
                $cid = DB::table('clients')->insertGetId($novoCli);
                $cli = DB::table('clients')->find($cid);
            }
        }
        if (! $EXEC && ! $cli) {   // simulacao de conta nova: nao da pra seguir sem id real
            $c['liberou']++;
            $linhas[] = "LIBERARIA      {$email}  R$ {$pago}  pago em {$quando}  ate " . $fim->format('d/m/Y') . "  [{$item}]";
            continue;
        }

        $sub = DB::table('subscriptions')->where('client_id', $cli->id)->orderByDesc('id')->first();

        $lastro = [];
        if ($i['order'] !== '') { $lastro['external_payment_id'] = $i['order']; $lastro['pagarme_status'] = 'paid'; }
        if ($i['cust']  !== '') { $lastro['pagarme_customer_id'] = $i['cust']; }

        // --- protecoes: nao rebaixar ---
        if ($sub && $sub->status === 'active' && in_array((int) $sub->plan_id, $PROTEGIDOS, true)) {
            $c['protegido']++;
            $linhas[] = "PROTEGIDO      {$email}  plano ativo {$sub->plan_id} (outro produto) — nao mexido";
            continue;
        }
        if ($sub && $sub->status === 'active' && $sub->current_period_end && $sub->current_period_end > $fim) {
            $c['prazoMaior']++;
            $linhas[] = "PRAZO MAIOR    {$email}  ativo ate " . substr((string) $sub->current_period_end, 0, 10) . " — nao mexido";
            continue;
        }

        // --- ja ativo dentro da janela: so carimba o lastro do pagamento ---
        if ($sub && $sub->status === 'active') {
            if ($lastro && ($sub->external_payment_id === null || $sub->pagarme_status === null)) {
                $c['lastro']++;
                if ($EXEC) { DB::table('subscriptions')->where('id', $sub->id)->update($lastro + ['updated_at' => now()]); }
                $linhas[] = ($EXEC ? 'LASTRO GRAVADO ' : 'GRAVARIA LASTRO') . " {$email}  R$ {$pago}  pago em {$quando}  order {$i['order']}";
            } else {
                $c['jaOkSemMexer']++;
            }
            continue;
        }

        // --- libera 30 dias ---
        $c['liberou']++;
        if ($EXEC) {
            $dados = $lastro + [
                'status'               => 'active',
                'plan_id'              => $PLANO,
                'current_period_start' => $ini,
                'current_period_end'   => $fim,
                'payment_method'       => 'pix',
                'updated_at'           => now(),
            ];
            if ($sub) { DB::table('subscriptions')->where('id', $sub->id)->update($dados); }
            else      { DB::table('subscriptions')->insert($dados + ['client_id' => $cli->id, 'created_at' => now()]); }
        }
        $de = $sub ? "(era {$sub->status}/plano{$sub->plan_id})" : '(sem assinatura)';
        $linhas[] = ($EXEC ? 'LIBERADO   ' : 'LIBERARIA  ') . "    {$email}  R$ {$pago}  pago em {$quando}  ate "
                  . $fim->format('d/m/Y') . "  plano {$PLANO} {$de}" . ($novoUser ? ' [conta nova]' : '') . "  [{$item}]";
    } catch (\Throwable $e) {
        $c['erro']++;
        $linhas[] = "ERRO {$email}: " . mb_substr($e->getMessage(), 0, 160);
    }
}

echo "\n" . ($EXEC ? '=== EXECUTADO ===' : '=== SIMULACAO (nada gravado) ===') . "\n";
printf("contas criadas/a criar ............. %d\n", $c['criouUser']);
printf("clients criados/a criar ............ %d\n", $c['criouClient']);
printf("acessos liberados/a liberar ........ %d\n", $c['liberou']);
printf("lastro gravado/a gravar ............ %d\n", $c['lastro']);
printf("protegidos (outro produto) ......... %d\n", $c['protegido']);
printf("ja ativos com prazo maior .......... %d\n", $c['prazoMaior']);
printf("ja ok, nada a fazer ................ %d\n", $c['jaOkSemMexer']);
printf("ambiguos separados (nao decidi) .... %d\n", count($ambiguos));
printf("erros .............................. %d\n", $c['erro']);
printf("validade ........................... %s\n", $fim->format('d/m/Y'));

if ($ambiguos) { echo "\n--- AMBIGUOS (pulados de proposito) ---\n"; foreach ($ambiguos as $a) echo "  {$a}\n"; }

file_put_contents($LOG, "\n===== " . date('c') . ($EXEC ? ' EXEC' : ' SIMULACAO') . " (SO TOKFY PAGO CONFIRMADO) =====\n"
    . implode("\n", $linhas) . "\n" . ($ambiguos ? "AMBIGUOS:\n  " . implode("\n  ", $ambiguos) . "\n" : ''), FILE_APPEND);
echo "\nlog linha a linha: {$LOG}\n";
