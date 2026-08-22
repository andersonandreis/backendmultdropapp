<?php
// MUL-467 — reconciliação de NF dos pedidos importados da API do Bling (protocolo dados-lote).
//
// EXTRAIR    → /tmp/mul467-extracao.json      (snapshot do universo)
// CLASSIFICAR→ /tmp/mul467-classificacao.json (classe por pedido; checkpoint a cada 100)
// VALIDAR    → invariantes 1-3 + distribuição
// DRY-RUN    → /tmp/CONFERIR-nf-pedidos-bling.csv (SEM_NF genuínos + não-encontrados)
//
// Invariantes (nota MUL-467): partição total; falha de consulta NUNCA vira SEM_NF
// (só HTTP 200 classifica); chave 44 dígitos; escrita apenas em campo VAZIO;
// fonte = pedido→notaFiscal.id→/nfe da CONTA do pedido; ≥0,4s entre chamadas + retry.
//
// Uso: sudo -u apimu2457 php -d memory_limit=512M artisan tinker scripts/dados/mul467-classifica-nf.php
// Retomável: reexecutar continua do checkpoint.

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$EX = '/tmp/mul467-extracao.json';
$CL = '/tmp/mul467-classificacao.json';
$CSV = '/tmp/CONFERIR-nf-pedidos-bling.csv';
$base = config('bling.api_base', 'https://api.bling.com.br/Api/v3');

// ---------------------------------------------------------------- EXTRAIR
if (! file_exists($EX)) {
    $rows = DB::table('orders as o')
        ->leftJoin('clients as c', 'c.id', '=', 'o.client_id')
        ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
        ->where('o.source', 'bling')->whereNotNull('o.bling_order_id')
        ->get([
            'o.id', 'o.order_number', 'o.bling_order_id', 'o.marketplace_account_id',
            'o.client_id', 'o.invoice_number', 'o.invoice_access_key', 'o.paid_at',
            'o.canonical_status', 'o.channel_name', 'o.total', 'o.marketplace_created_at',
            'u.email as seller_email',
        ])->keyBy('id')->toArray();
    file_put_contents($EX, json_encode($rows));
    echo 'EXTRAIR: ' . count($rows) . ' pedidos -> ' . $EX . PHP_EOL;
}
$ex = json_decode(file_get_contents($EX), true);
$cl = file_exists($CL) ? json_decode(file_get_contents($CL), true) : [];
echo 'universo=' . count($ex) . ' ja classificados=' . count($cl) . PHP_EOL;

// ---------------------------------------------------------------- CLASSIFICAR
$auth = app(App\Services\Integrations\Erps\Bling\BlingAuthService::class);
$tokens = [];
$n = 0;
// serial POR CONTA: ordena pendentes por conta para não intercalar contas (rate por conta)
$pendentes = array_filter($ex, fn ($r) => ! isset($cl[$r['id']]));
usort($pendentes, fn ($a, $b) => [$a['marketplace_account_id'], $a['id']] <=> [$b['marketplace_account_id'], $b['id']]);

foreach ($pendentes as $r) {
    $id = $r['id'];
    if ($r['invoice_number']) {
        $cl[$id] = ['classe' => 'JA_PREENCHIDO'];
    } else {
        $aid = $r['marketplace_account_id'];
        if (! array_key_exists($aid, $tokens)) {
            $acc = App\Models\MarketplaceAccount::find($aid);
            $tokens[$aid] = $acc ? $auth->getValidToken($acc) : null;
        }
        if (! $tokens[$aid]) {
            $cl[$id] = ['classe' => 'ERRO_CONSULTA', 'detalhe' => 'sem_token_conta_' . $aid];
        } else {
            usleep(400000);
            $rp = Http::withToken($tokens[$aid])->retry(3, 2000, throw: false)->timeout(20)
                ->get($base . '/pedidos/vendas/' . $r['bling_order_id']);
            if ($rp->status() === 404) {
                $cl[$id] = ['classe' => 'PEDIDO_NAO_ENCONTRADO'];
            } elseif ($rp->status() !== 200) {
                $cl[$id] = ['classe' => 'ERRO_CONSULTA', 'detalhe' => 'pedido_http_' . $rp->status()];
            } else {
                $det = $rp->json()['data'] ?? [];
                $nfeId = $det['notaFiscal']['id'] ?? null;
                if (! $nfeId) {
                    $cl[$id] = ['classe' => 'SEM_NF_NO_BLING', 'situacao' => json_encode($det['situacao'] ?? null)];
                } else {
                    usleep(400000);
                    $rn = Http::withToken($tokens[$aid])->retry(3, 2000, throw: false)->timeout(20)
                        ->get($base . '/nfe/' . $nfeId);
                    $nf = $rn->status() === 200 ? ($rn->json()['data'] ?? null) : null;
                    if (! is_array($nf) || empty($nf['numero'])) {
                        $cl[$id] = ['classe' => 'ERRO_CONSULTA', 'detalhe' => 'nfe_http_' . $rn->status()];
                    } else {
                        // escrita SO em campo vazio (invariante 4)
                        $o = App\Models\Order::find($id);
                        $up = array_filter([
                            'invoice_number'     => empty($o->invoice_number) ? ($nf['numero'] ?? null) : null,
                            'invoice_series'     => empty($o->invoice_series) && isset($nf['serie']) ? (string) $nf['serie'] : null,
                            'invoice_access_key' => empty($o->invoice_access_key) ? ($nf['chaveAcesso'] ?? null) : null,
                            'invoice_issued_at'  => empty($o->invoice_issued_at) ? ($nf['dataEmissao'] ?? null) : null,
                            'invoice_status'     => empty($o->invoice_status) && in_array((int) ($nf['situacao'] ?? 0), [5, 6, 7], true) ? 'authorized' : null,
                            'tracking_number'    => empty($o->tracking_number) ? ($det['transporte']['volumes'][0]['codigoRastreamento'] ?? null) : null,
                        ], fn ($v) => $v !== null && $v !== '');
                        if ($up !== []) {
                            $o->updateQuietly($up);
                        }
                        $cl[$id] = ['classe' => 'NF_ENCONTRADA', 'numero' => $nf['numero'], 'chave' => $nf['chaveAcesso'] ?? ''];
                    }
                }
            }
        }
    }
    $n++;
    if (($n % 100) === 0) {
        file_put_contents($CL, json_encode($cl));
        echo 'checkpoint: ' . count($cl) . '/' . count($ex) . PHP_EOL;
    }
}
file_put_contents($CL, json_encode($cl));

// ---------------------------------------------------------------- VALIDAR
$dist = [];
foreach ($cl as $c) { $dist[$c['classe']] = ($dist[$c['classe']] ?? 0) + 1; }
ksort($dist);
echo 'VALIDAR — distribuição: ' . json_encode($dist) . PHP_EOL;
echo 'contabilidade: ' . array_sum($dist) . ' == ' . count($ex) . ' -> ' . (array_sum($dist) === count($ex) ? 'OK' : 'VIOLADA') . PHP_EOL;
$ruins = DB::table('orders')->where('source', 'bling')->whereNotNull('invoice_access_key')
    ->whereRaw("(length(invoice_access_key) != 44 or invoice_access_key regexp '[^0-9]')")->count();
echo 'invariante chave-44: violações = ' . $ruins . PHP_EOL;

// ---------------------------------------------------------------- DRY-RUN (CSV)
$fh = fopen($CSV, 'w');
fwrite($fh, "\xEF\xBB\xBF");
fputcsv($fh, ['order_id', 'pedido', 'seller', 'canal', 'data_pedido', 'valor', 'pago', 'status', 'bling_order_id', 'classe', 'situacao_bling', 'CONFERIR'], ';');
$linhas = 0;
foreach ($cl as $id => $c) {
    if (! in_array($c['classe'], ['SEM_NF_NO_BLING', 'PEDIDO_NAO_ENCONTRADO'], true)) {
        continue;
    }
    $r = $ex[$id];
    fputcsv($fh, [
        $id, $r['order_number'], $r['seller_email'], $r['channel_name'],
        substr((string) $r['marketplace_created_at'], 0, 10),
        number_format((float) $r['total'], 2, '.', ''),
        $r['paid_at'] ? 'SIM' : 'nao', $r['canonical_status'], $r['bling_order_id'],
        $c['classe'], $c['situacao'] ?? '', '',
    ], ';');
    $linhas++;
}
fclose($fh);
echo 'CSV: ' . $CSV . ' (' . $linhas . ' linhas)' . PHP_EOL;
