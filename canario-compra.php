<?php
/**
 * CANARIO DE COMPRA — SEL-CANARIOCOMPRA (12/08, pergunta do Ruan: "qual plano pra
 * nunca mais acontecer").
 *
 * Por que existe: hoje descobrimos que 217 pessoas pagaram e ficaram sem acesso, e
 * que o webhook decidia o plano ADIVINHANDO pelo valor (R$1,00 virava "TikTok Shop
 * Anual"). Nada disso disparou alarme — a gente so soube porque foi olhar na mao.
 * Ja existe canario de VIDEO (gera de verdade a cada 3h e mede o arquivo). Faltava o
 * de COMPRA.
 *
 * O que faz: a cada rodada simula a jornada inteira do comprador, sem gastar dinheiro
 * e sem tocar em cliente real:
 *   1. cria um cliente de teste NOSSO no gateway (e-mail @seller.global)
 *   2. cria um pedido PIX de R$ 1,00 (nunca e pago, expira sozinho em 10 min)
 *   3. entrega o evento `order.paid` no NOSSO webhook, com o payload REAL do gateway
 *   4. confere se o sistema criou usuario + cliente + assinatura ATIVA, com o plano
 *      e o prazo certos
 *   5. APAGA tudo que criou e confirma que apagou
 *
 * Se qualquer degrau falhar, grava em /root/canario-compra.log e devolve codigo 1 —
 * e o sentinela ponta a ponta passa a mostrar vermelho.
 *
 * Sem Telegram, por regra do Ruan. Sem e-mail: o teste nao dispara nada pro cliente.
 */
require '/home/api.seller.global/public_html/vendor/autoload.php';
$app = require_once '/home/api.seller.global/public_html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$LOG  = storage_path('logs/canario-compra.log');
$JSON = storage_path('logs/canario-compra.json');
$CPF  = '66696771700';                 // CPF valido de teste (Ruan passou)
$key  = (string) config('services.pagarme.api_key');

$passos = [];
$falhas = [];
$marca  = 'canario.compra.' . time();
$email  = $marca . '@seller.global';

function passo(string $nome, bool $ok, string $detalhe = ''): void {
    global $passos, $falhas;
    $passos[] = ['passo' => $nome, 'ok' => $ok, 'detalhe' => $detalhe];
    if (! $ok) { $falhas[] = "{$nome}: {$detalhe}"; }
}

function api(string $metodo, string $url, string $key, ?array $corpo = null): array {
    $arq = tempnam(sys_get_temp_dir(), 'cc');
    $cmd = 'curl -s -u ' . escapeshellarg($key . ':') . ' -X ' . $metodo
         . ' -H ' . escapeshellarg('Content-Type: application/json');
    if ($corpo !== null) { $cmd .= ' -d ' . escapeshellarg(json_encode($corpo)); }
    $cmd .= ' -o ' . escapeshellarg($arq) . ' -w %{http_code} ' . escapeshellarg($url) . ' 2>/dev/null';
    $code = (int) trim((string) shell_exec($cmd));
    $body = json_decode((string) @file_get_contents($arq), true) ?: [];
    @unlink($arq);
    return ['code' => $code, 'body' => $body];
}

/* ── 1. o gateway aceita criar cliente? ─────────────────────────────────── */
$c = api('POST', 'https://api.pagar.me/core/v5/customers', $key, [
    'name' => 'Canario Compra', 'email' => $email, 'document' => $CPF,
    'type' => 'individual', 'document_type' => 'CPF',
    'phones' => ['mobile_phone' => ['country_code' => '55', 'area_code' => '21', 'number' => '966395555']],
]);
$customerId = $c['body']['id'] ?? null;
passo('gateway_cria_cliente', $customerId !== null, "http={$c['code']}");

/* ── 2. o checkout gera PIX com QR? ─────────────────────────────────────── */
$qr = null; $orderId = null;
if ($customerId) {
    $o = api('POST', 'https://api.pagar.me/core/v5/orders', $key, [
        'customer_id' => $customerId,
        'items'    => [['description' => 'Canario de compra (teste automatico)', 'amount' => 100, 'quantity' => 1]],
        'payments' => [['payment_method' => 'pix', 'pix' => ['expires_in' => 600]]],
        'metadata' => ['canario' => '1'],
    ]);
    $orderId = $o['body']['id'] ?? null;
    $qr = $o['body']['charges'][0]['last_transaction']['qr_code'] ?? null;
    passo('checkout_gera_pix', $qr !== null, "http={$o['code']} pedido=" . ($orderId ?: '-'));
} else {
    passo('checkout_gera_pix', false, 'sem cliente no gateway');
}

/* ── 3. o webhook libera o acesso sozinho? ──────────────────────────────── */
if ($orderId) {
    // busca a order REAL e entrega no nosso webhook do jeito que o gateway entrega
    $ord = api('GET', "https://api.pagar.me/core/v5/orders/{$orderId}", $key);
    $dados = $ord['body'];
    $dados['status'] = 'paid';                       // simula o pagamento confirmado
    $envelope = json_encode(['type' => 'order.paid', 'data' => $dados]);

    $arq = tempnam(sys_get_temp_dir(), 'wh');
    $code = (int) trim((string) shell_exec(
        'curl -s -o ' . escapeshellarg($arq) . ' -w %{http_code} -X POST '
        . '-H ' . escapeshellarg('Content-Type: application/json')
        . ' -d ' . escapeshellarg($envelope)
        . ' https://api.seller.global/api/webhooks/pagarme 2>/dev/null'
    ));
    @unlink($arq);
    passo('webhook_responde', $code === 200, "http={$code}");

    sleep(4);

    $u = DB::table('users')->whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
    passo('criou_usuario', $u !== null, $u ? "user_id={$u->id}" : 'usuario NAO foi criado');

    if ($u) {
        $cli = DB::table('clients')->where('user_id', $u->id)->first();
        passo('criou_cliente', $cli !== null, $cli ? "client_id={$cli->id}" : 'client NAO foi criado');

        if ($cli) {
            $sub = DB::table('subscriptions')->where('client_id', $cli->id)->orderByDesc('id')->first();
            passo('assinatura_ativa', $sub && $sub->status === 'active',
                  $sub ? "sub={$sub->id} status={$sub->status} plano=" . ($sub->plan_id ?: 'NULO') : 'assinatura NAO criada');
            passo('assinatura_tem_plano', $sub && ! empty($sub->plan_id),
                  $sub ? 'plan_id=' . ($sub->plan_id ?: 'NULO — o sistema nao soube qual plano') : '-');
            passo('assinatura_tem_prazo', $sub && ! empty($sub->current_period_end),
                  $sub ? 'ate ' . ($sub->current_period_end ?: 'SEM PRAZO') : '-');
            passo('gravou_lastro', $sub && ! empty($sub->external_payment_id),
                  $sub ? 'pagamento=' . ($sub->external_payment_id ?: 'ORFAO — sem lastro') : '-');
        }
    }
} else {
    passo('webhook_responde', false, 'sem pedido pra testar');
}

/* ── 4. limpeza: o canario nao pode deixar sujeira ──────────────────────── */
$u = DB::table('users')->whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
if ($u) {
    $cli = DB::table('clients')->where('user_id', $u->id)->first();
    if ($cli) {
        DB::table('subscriptions')->where('client_id', $cli->id)->delete();
        DB::table('clients')->where('id', $cli->id)->delete();
    }
    DB::table('personal_access_tokens')->where('tokenable_id', $u->id)->delete();
    DB::table('users')->where('id', $u->id)->delete();
}
$sobrou = DB::table('users')->whereRaw('LOWER(email) = ?', [strtolower($email)])->count();
passo('limpou_o_teste', $sobrou === 0, $sobrou === 0 ? 'nada ficou pra tras' : 'SOBROU registro de teste');

/* ── resultado ──────────────────────────────────────────────────────────── */
$ok = count(array_filter($passos, fn ($p) => $p['ok']));
$res = ['quando' => date('c'), 'ok' => $ok, 'total' => count($passos),
        'falhas' => $falhas, 'passos' => $passos, 'email_teste' => $email];
file_put_contents($JSON, json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

if ($falhas) {
    file_put_contents($LOG, date('c') . "\n  " . implode("\n  ", $falhas) . "\n", FILE_APPEND);
    echo "CANARIO DE COMPRA: {$ok}/" . count($passos) . " — PROBLEMAS:\n  " . implode("\n  ", $falhas) . "\n";
    exit(1);
}
echo "CANARIO DE COMPRA OK: {$ok}/" . count($passos) . " passos da jornada do comprador\n";
