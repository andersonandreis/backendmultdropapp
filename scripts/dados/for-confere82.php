<?php
use App\Models\PixTransaction;
use App\Models\Supplier;
use App\Services\Integrations\Factories\PaymentGatewayFactory;

$alvos = PixTransaction::where('status','paid')->whereNotNull('order_id')->whereNotNull('external_id')
    ->whereHas('order', fn($q) => $q->whereNull('wallet_paid_at'))
    ->with('order')->orderBy('id')->get();

echo "transacoes a conferir: " . $alvos->count() . "\n\n";

$gws = [];
$linhas = [];
$st = ['paid' => 0, 'outro' => 0, 'erro' => 0];

foreach ($alvos as $t) {
    if (!isset($gws[$t->supplier_id])) {
        try { $gws[$t->supplier_id] = PaymentGatewayFactory::makeForSupplier(Supplier::find($t->supplier_id)); }
        catch (\Throwable $e) { $gws[$t->supplier_id] = null; }
    }
    $gw = $gws[$t->supplier_id];
    $shipay = 'ERRO';
    if ($gw) {
        try { $shipay = (string) $gw->getPaymentStatus($t->external_id); }
        catch (\Throwable $e) { $shipay = 'ERRO: ' . mb_substr($e->getMessage(), 0, 60); }
    }
    if ($shipay === 'paid') $st['paid']++;
    elseif (str_starts_with($shipay, 'ERRO')) $st['erro']++;
    else $st['outro']++;

    $o = $t->order;
    $linhas[] = [
        $t->id, $t->order_id, $o?->external_order_id, $o?->canonical_status,
        $o?->hubai_order_id ?: 0, $t->amount, $o?->supplier_total,
        $t->updated_at?->format('d/m/Y H:i'), $shipay, $t->external_id,
    ];
    usleep(200000);
}

echo "SHIPAY confirmou 'paid' : {$st['paid']}\n";
echo "outro status            : {$st['outro']}\n";
echo "erro na consulta        : {$st['erro']}\n";

$fp = fopen('/root/CONFERIR-for115-pagamentos.csv', 'w');
fwrite($fp, "\xEF\xBB\xBF");
fputcsv($fp, ['pix_id','pedido_local','pedido_marketplace','status_pedido','hubai_order_id',
              'valor_pago','custo_do_pedido','pago_em','STATUS_NO_SHIPAY','external_id','CONFIRMA'], ';');
foreach ($linhas as $l) { $l[] = ''; fputcsv($fp, $l, ';'); }
fclose($fp);
echo "\nCSV: /root/CONFERIR-for115-pagamentos.csv (" . count($linhas) . " linhas)\n";

$somaOk = 0;
foreach ($linhas as $l) if ($l[8] === 'paid') $somaOk += (float) $l[5];
printf("valor confirmado pelo Shipay: R$ %s\n", number_format($somaOk, 2, ',', '.'));
