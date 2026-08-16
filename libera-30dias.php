<?php
/**
 * SEL-LIBERA30 (12/08, ordem do Ruan: "cadastra, libera 30 dias, pega todos, e no
 * proximo mes cobra na tela").
 *
 * Pega TODO MUNDO que pagou no gateway + os importados, garante conta, e deixa a
 * assinatura ATIVA por 30 dias a partir de hoje. Quem ja esta ativo com prazo maior
 * NAO e rebaixado. Senha padrao 123456 so pra quem nao tem senha utilizavel.
 *
 * Roda em BRANCO por padrao. So escreve com --exec.
 */
require '/home/api.seller.global/public_html/vendor/autoload.php';
$app = require_once '/home/api.seller.global/public_html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$EXEC     = in_array('--exec', $argv, true);
$PLANO    = 100;                 // Video IA — Ilimitado (o mesmo dos importados)
$DIAS     = 30;
$SENHA    = '123456';
$LOG      = storage_path('logs/libera30.log');
$key      = (string) config('services.pagarme.api_key');

function api(string $url, string $key): array {
    $arq = tempnam(sys_get_temp_dir(), 'pg');
    shell_exec('curl -s -u ' . escapeshellarg($key . ':') . ' ' . escapeshellarg($url) . ' -o ' . escapeshellarg($arq));
    $j = json_decode((string) @file_get_contents($arq), true) ?: [];
    @unlink($arq);
    return $j;
}

/* ---------- 1. quem pagou no gateway ---------- */
$pagantes = [];
for ($pagina = 1; $pagina <= 12; $pagina++) {
    $d = api("https://api.pagar.me/core/v5/orders?status=paid&size=100&page={$pagina}", $key);
    $linhas = $d['data'] ?? [];
    if (! $linhas) break;
    foreach ($linhas as $o) {
        $email = strtolower(trim((string) ($o['customer']['email'] ?? '')));
        if ($email === '') continue;
        $pagantes[$email] = [
            'nome'  => (string) ($o['customer']['name'] ?? ''),
            'order' => (string) ($o['id'] ?? ''),
            'cust'  => (string) ($o['customer']['id'] ?? ''),
            'valor' => ((int) ($o['amount'] ?? 0)) / 100,
        ];
    }
}

/* ---------- 2. os importados (pending_release) ---------- */
$importados = DB::table('subscriptions as s')
    ->join('clients as c', 'c.id', '=', 's.client_id')
    ->join('users as u', 'u.id', '=', 'c.user_id')
    ->where('s.status', 'pending_release')
    ->select('u.email', 'u.name')
    ->get();
foreach ($importados as $r) {
    $e = strtolower(trim($r->email));
    if (! isset($pagantes[$e])) {
        $pagantes[$e] = ['nome' => (string) $r->name, 'order' => '', 'cust' => '', 'valor' => 0.0, 'origem' => 'importado'];
    }
}

echo 'universo total (pagantes do gateway + importados): ' . count($pagantes) . PHP_EOL;

$fim = now()->addDays($DIAS);
$contas = 0; $ativadas = 0; $jaOk = 0; $senhas = 0; $erros = 0;
$linhasLog = [];

foreach ($pagantes as $email => $info) {
    try {
        $user = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user) {
            $contas++;
            if ($EXEC) {
                $uid = DB::table('users')->insertGetId([
                    'name'              => $info['nome'] !== '' ? $info['nome'] : Str::before($email, '@'),
                    'email'             => $email,
                    'password'          => Hash::make($SENHA),
                    'email_verified_at' => now(),
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);
                $user = DB::table('users')->find($uid);
            } else {
                $linhasLog[] = "CRIARIA CONTA  {$email}";
                continue;
            }
        }

        $cli = DB::table('clients')->where('user_id', $user->id)->first();
        if (! $cli) {
            if ($EXEC) {
                $cid = DB::table('clients')->insertGetId([
                    'user_id'    => $user->id,
                    'name'       => $user->name,
                    'email'      => $email,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $cli = DB::table('clients')->find($cid);
            } else {
                $linhasLog[] = "CRIARIA CLIENTE {$email}";
                continue;
            }
        }

        $sub = DB::table('subscriptions')->where('client_id', $cli->id)->orderByDesc('id')->first();

        // Nao rebaixa quem ja tem prazo maior
        if ($sub && $sub->status === 'active' && $sub->current_period_end && $sub->current_period_end > $fim) {
            $jaOk++;
            continue;
        }

        // "Todos entram na nossa regra mensal" (Ruan, 12/08): o lote vai pro plano
        // MENSAL de video (100 = Video IA Ilimitado, R$149), nao pro anual que eles
        // compraram. EXCECAO: quem tem plano ATIVO de dropshipping/seller fica como
        // esta — trocar tiraria funcoes que ele usa e paga.
        $planosOutroProduto = [85, 86, 87, 94, 95, 96];
        if ($sub && $sub->status === 'active' && in_array((int) $sub->plan_id, $planosOutroProduto, true)) {
            $jaOk++;
            continue;
        }

        $ativadas++;
        if ($EXEC) {
            $dados = [
                'status'               => 'active',
                'plan_id'              => $PLANO,  // regra mensal pra todo o lote
                'current_period_start' => now(),
                'current_period_end'   => $fim,
                'payment_method'       => 'pix',
                'updated_at'           => now(),
            ];
            if ($info['order'] !== '') { $dados['external_payment_id'] = $info['order']; }
            if ($info['cust']  !== '') { $dados['pagarme_customer_id'] = $info['cust']; }
            if ($info['order'] !== '') { $dados['pagarme_status'] = 'paid'; }

            if ($sub) {
                DB::table('subscriptions')->where('id', $sub->id)->update($dados);
            } else {
                DB::table('subscriptions')->insert($dados + [
                    'client_id'  => $cli->id,
                    'created_at' => now(),
                ]);
            }

            // senha padrao so pra quem nunca definiu uma utilizavel
            if (empty($user->password) || Hash::check($SENHA, $user->password) === false && ($user->password === '' || $user->password === null)) {
                DB::table('users')->where('id', $user->id)->update(['password' => Hash::make($SENHA), 'updated_at' => now()]);
                $senhas++;
            }
        }
        $linhasLog[] = ($EXEC ? 'LIBERADO' : 'LIBERARIA') . "  {$email}  R$ " . number_format((float) $info['valor'], 2, ',', '.')
                     . '  ate ' . $fim->format('d/m/Y');
    } catch (\Throwable $e) {
        $erros++;
        $linhasLog[] = "ERRO {$email}: " . mb_substr($e->getMessage(), 0, 120);
    }
}

echo PHP_EOL . ($EXEC ? '=== EXECUTADO ===' : '=== SIMULACAO (nada foi gravado) ===') . PHP_EOL;
echo "contas a criar/criadas ....... {$contas}" . PHP_EOL;
echo "acessos a liberar/liberados .. {$ativadas}" . PHP_EOL;
echo "ja estavam ok (prazo maior) .. {$jaOk}" . PHP_EOL;
echo "senha padrao definida ........ {$senhas}" . PHP_EOL;
echo "erros ........................ {$erros}" . PHP_EOL;
echo "validade ..................... " . $fim->format('d/m/Y') . PHP_EOL;

file_put_contents($LOG, date('c') . ($EXEC ? " EXEC\n" : " SIMULACAO\n") . implode("\n", $linhasLog) . "\n", FILE_APPEND);
echo PHP_EOL . 'detalhe linha a linha em ' . $LOG . PHP_EOL;
