<?php
// MUL-414 — auditoria pós-religada da varredura Bling (MUL-413). SOMENTE LEITURA.
// Roda via: php artisan tinker scripts/dados/MUL-414-reconcilia-bling.php
//
// Etapas materializadas (protocolo dados-lote):
//   EXTRAIR     -> /root/mul414-extracao.json      (API Bling, todas as contas, sem filtro de loja)
//   CLASSIFICAR -> /root/mul414-classificado.json  (cruzamento por bling_id, numeroLoja e rastreio)
//   VALIDAR     -> contabilidade impressa no fim (soma das classes == extraidos)
//   DRY-RUN     -> /root/CONFERIR-bling-nao-importados.csv (so FALTANTE e FORA_DO_FILTRO)
// Auditorias extra: filtro de lojas nos pedidos ja importados pela varredura + grupos duplicados.

use Illuminate\Support\Facades\DB;

$CUTOFF   = '2026-08-13';
$RELIGADA = '2026-08-20 00:17:00'; // flip da conta 71 na MUL-413
$EXTR     = '/tmp/mul414-extracao.json';
$CLAS     = '/tmp/mul414-classificado.json';
$CSV      = '/tmp/CONFERIR-bling-nao-importados.csv';

$accounts = \App\Models\MarketplaceAccount::where('platform', 'bling')
    ->where('status', 'active')
    ->whereNotNull('bling_access_token')
    ->get()
    ->keyBy('id');

$client = app(\App\Services\Integrations\Erps\Bling\BlingApiClient::class);

// ---------------------------------------------------------------- EXTRAIR
if (! file_exists($EXTR)) {
    $all = [];
    $erros = [];
    foreach ($accounts as $acc) {
        $page = 1;
        while (true) {
            try {
                $resp = $client->listOrders($acc, $page, $CUTOFF, null); // SEM idLoja: todos os canais
            } catch (\Throwable $e) {
                $erros[] = ['account_id' => $acc->id, 'page' => $page, 'erro' => substr($e->getMessage(), 0, 200)];
                break;
            }
            $orders = $resp['data'] ?? [];
            if (empty($orders)) break;
            foreach ($orders as $o) {
                $all[] = [
                    'account_id' => $acc->id,
                    'account'    => $acc->account_name,
                    'client_id'  => $acc->client_id,
                    'bling_id'   => $o['id'] ?? null,
                    'numero'     => $o['numero'] ?? null,
                    'numeroLoja' => $o['numeroLoja'] ?? null,
                    'data'       => $o['data'] ?? null,
                    'total'      => $o['total'] ?? null,
                    'situacao'   => $o['situacao']['id'] ?? null,
                    'loja_id'    => $o['loja']['id'] ?? null,
                    'contato'    => $o['contato']['nome'] ?? null,
                ];
            }
            $page++;
            if ($page > 300) { $erros[] = ['account_id' => $acc->id, 'erro' => 'trava de 300 paginas']; break; }
            usleep(400000); // throttle da regra: leitura educada, <=2.5/s
        }
    }
    file_put_contents($EXTR, json_encode(['extraido_em' => now()->toDateTimeString(), 'erros' => $erros, 'pedidos' => $all], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo 'EXTRAIR: ' . count($all) . " pedidos, " . count($erros) . " erros de API\n";
} else {
    echo "EXTRAIR: usando intermediario existente\n";
}

$ex = json_decode(file_get_contents($EXTR), true);
$pedidos = $ex['pedidos'];
$totalExtraido = count($pedidos);

// dedup da propria extracao (mesmo bling_id 2x na mesma conta = pagina repetida)
$vistos = [];
$dupExtracao = 0;
$pedidos = array_values(array_filter($pedidos, function ($p) use (&$vistos, &$dupExtracao) {
    $k = $p['account_id'] . ':' . $p['bling_id'];
    if (isset($vistos[$k])) { $dupExtracao++; return false; }
    $vistos[$k] = true;
    return true;
}));

// ---------------------------------------------------------------- CLASSIFICAR
$classificado = [];
$detalhesBuscados = 0;
foreach ($pedidos as $p) {
    $acc = $accounts[$p['account_id']];
    $allowed = $acc->allowed_integrations; // cast array|null
    $sel = is_array($allowed) && $allowed !== [] && in_array($p['loja_id'], $allowed);
    $p['loja_selecionada'] = $sel ? 'S' : 'N';
    $p['rastreio'] = null;
    $p['pedido_local_id'] = null;
    $p['origem_local'] = null;

    // chave 1: id do pedido no Bling
    $local = DB::table('orders')->where('client_id', $p['client_id'])
        ->where('source', 'bling')
        ->where('external_order_id', (string) $p['bling_id'])
        ->first(['id', 'source']);
    if ($local) {
        $p['classe'] = 'JA_IMPORTADO';
        $p['pedido_local_id'] = $local->id;
        $p['origem_local'] = 'bling';
        $classificado[] = $p;
        continue;
    }

    // chave 2: numeroLoja (id do pedido no marketplace) — qualquer origem
    if (! empty($p['numeroLoja'])) {
        $local = DB::table('orders')->where('client_id', $p['client_id'])
            ->where(function ($q) use ($p) {
                $q->where('marketplace_order_id', $p['numeroLoja'])
                  ->orWhere('external_order_id', $p['numeroLoja']);
            })
            ->first(['id', 'source']);
        if ($local) {
            $p['classe'] = 'JA_EXISTE_OUTRA_ORIGEM';
            $p['pedido_local_id'] = $local->id;
            $p['origem_local'] = $local->source;
            $classificado[] = $p;
            continue;
        }
    }

    // chave 3: rastreio — so agora vale buscar o detalhe (custo de API)
    try {
        $det = $client->getOrder($acc, (int) $p['bling_id'])['data'] ?? [];
        $detalhesBuscados++;
        usleep(400000);
    } catch (\Throwable $e) {
        $det = [];
    }
    foreach (($det['transporte']['volumes'] ?? []) as $vol) { // mesmo criterio do extractTracking do sync
        if (! empty($vol['codigoRastreamento'])) { $p['rastreio'] = $vol['codigoRastreamento']; break; }
    }
    if (empty($p['numeroLoja']) && ! empty($det['numeroLoja'])) $p['numeroLoja'] = $det['numeroLoja'];
    $p['itens'] = count($det['itens'] ?? []);

    if ($p['rastreio']) {
        $local = DB::table('orders')->where('client_id', $p['client_id'])
            ->where('tracking_number', $p['rastreio'])
            ->first(['id', 'source']);
        if ($local) {
            $p['classe'] = 'JA_EXISTE_RASTREIO';
            $p['pedido_local_id'] = $local->id;
            $p['origem_local'] = $local->source;
            $classificado[] = $p;
            continue;
        }
    }

    $p['classe'] = $sel ? 'FALTANTE' : 'FORA_DO_FILTRO';
    $classificado[] = $p;
}

file_put_contents($CLAS, json_encode($classificado, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

// ---------------------------------------------------------------- VALIDAR
$porClasse = [];
foreach ($classificado as $p) $porClasse[$p['classe']] = ($porClasse[$p['classe']] ?? 0) + 1;
ksort($porClasse);
echo "CLASSIFICAR: " . count($classificado) . " pedidos | detalhes buscados: $detalhesBuscados\n";
foreach ($porClasse as $c => $n) echo "  $c: $n\n";
$soma = array_sum($porClasse);
echo 'VALIDAR: extraidos ' . $totalExtraido . ' = classificados ' . $soma . ' + dup_extracao ' . $dupExtracao
    . ' -> ' . (($soma + $dupExtracao) === $totalExtraido ? 'OK' : 'QUEBROU') . "\n";

// ---------------------------------------------------------------- DRY-RUN (CSV)
$fh = fopen($CSV, 'w');
fwrite($fh, "\xEF\xBB\xBF");
fputcsv($fh, ['classe', 'acao_proposta', 'conta', 'account_id', 'client_id', 'loja_id', 'loja_selecionada',
    'bling_id', 'numero_bling', 'id_marketplace', 'rastreio', 'data', 'situacao_bling', 'total', 'itens', 'comprador', 'CONFERIR'], ';');
$linhas = 0;
foreach ($classificado as $p) {
    if (! in_array($p['classe'], ['FALTANTE', 'FORA_DO_FILTRO'])) continue;
    fputcsv($fh, [
        $p['classe'],
        $p['classe'] === 'FALTANTE' ? 'IMPORTAR' : 'so importa se o Ruan marcar a loja',
        $p['account'], $p['account_id'], $p['client_id'], $p['loja_id'], $p['loja_selecionada'],
        $p['bling_id'], $p['numero'], $p['numeroLoja'], $p['rastreio'], $p['data'],
        $p['situacao'], $p['total'], $p['itens'] ?? '', $p['contato'], '',
    ], ';');
    $linhas++;
}
fclose($fh);
echo "DRY-RUN: $CSV com $linhas linhas\n";

// ------------------------------------------------ AUDITORIA 1: filtro de lojas nos ja importados
echo "\nAUDITORIA filtro de lojas (pedidos criados pela varredura religada):\n";
$vazamentos = 0;
DB::table('orders')->where('source', 'bling')->where('created_at', '>=', $RELIGADA)
    ->orderBy('id')->chunk(200, function ($chunk) use ($accounts, &$vazamentos) {
        foreach ($chunk as $o) {
            $payload = json_decode($o->capture_payload ?? 'null', true);
            $loja = $payload['loja']['id'] ?? null;
            $acc = $accounts[$o->marketplace_account_id] ?? null;
            $allowed = $acc?->allowed_integrations;
            if ($loja === null || ! $acc) continue;
            if (! is_array($allowed) || ! in_array($loja, $allowed)) {
                $vazamentos++;
                echo "  VAZOU: order {$o->id} conta {$o->marketplace_account_id} loja $loja allowed " . json_encode($allowed) . "\n";
            }
        }
    });
echo "  vazamentos: $vazamentos\n";

// ------------------------------------------------ AUDITORIA 2: grupos duplicados na janela
echo "\nAUDITORIA duplicatas (source=bling, created_at >= $CUTOFF):\n";
foreach ([
    'bling_id (client_id+external_order_id)' => ['external_order_id', "source = 'bling'"],
    'id marketplace (client_id+marketplace_order_id)' => ['marketplace_order_id', "source = 'bling'"],
    'rastreio (client_id+tracking_number)' => ['tracking_number', "source = 'bling'"],
] as $nome => [$col, $where]) {
    $g = DB::select("SELECT client_id, $col chave, COUNT(*) n, GROUP_CONCAT(id) ids FROM orders
        WHERE $where AND created_at >= ? AND $col IS NOT NULL AND $col != ''
        GROUP BY client_id, $col HAVING COUNT(*) > 1 LIMIT 10", [$CUTOFF]);
    echo '  por ' . $nome . ': ' . count($g) . " grupos\n";
    foreach ($g as $r) echo "    client {$r->client_id} chave {$r->chave} -> orders {$r->ids}\n";
}
// cross-origem: bling novo duplicando pedido nativo (nivel 3 deveria ter anexado)
$g = DB::select("SELECT o1.client_id, o1.marketplace_order_id chave, o1.id bling_order, o2.id nativo, o2.source
    FROM orders o1 JOIN orders o2 ON o2.client_id = o1.client_id AND o2.id != o1.id
        AND o2.source != 'bling' AND o1.marketplace_order_id != ''
        AND (o2.marketplace_order_id = o1.marketplace_order_id OR o2.external_order_id = o1.marketplace_order_id)
    WHERE o1.source = 'bling' AND o1.created_at >= ? LIMIT 10", [$RELIGADA]);
echo '  cross-origem (bling novo x nativo, mesmo id de marketplace): ' . count($g) . " pares\n";
foreach ($g as $r) echo "    client {$r->client_id} {$r->chave}: bling {$r->bling_order} x {$r->source} {$r->nativo}\n";

echo "\nFIM (somente leitura — nada foi escrito em orders)\n";
