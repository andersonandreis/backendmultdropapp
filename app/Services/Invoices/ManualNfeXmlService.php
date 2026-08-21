<?php

namespace App\Services\Invoices;

use App\Jobs\FanoutOrderWebhookJob;
use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Models\OrderInvoiceSync;
use App\Models\OrderLabelQueue;
use App\Services\Integrations\Marketplaces\ShopeeService;
use App\Support\NfeXmlParser;
use Illuminate\Support\Facades\Log;

/**
 * MUL-455: anexo MANUAL de NF-e por XML — o arquivo e a unica entrada.
 *
 * Decisao do Ruan (21/08): o formulario nao pede numero/serie/chave — tudo e extraido
 * do XML, e o XML e TRANSITORIO: serve pro envio ao marketplace (upload_invoice_doc) e
 * e descartado; o painel guarda numero/serie/chave/emissao. Depois do anexo, roda a
 * MESMA perna de marketplace da cadeia automatica (MUL-454): invoice na Shopee +
 * organizar o envio (ship_order) — e destrava pedido que estava em nfe_failed.
 *
 * Roda no HUB (as contas de marketplace dos pedidos vivem aqui); a WL chega via proxy
 * de federacao com o XML no corpo JSON.
 */
class ManualNfeXmlService
{
    /** @return array{ok: bool, error?: string, dados?: array, marketplace?: string} */
    public function anexar(Order $order, string $xml): array
    {
        $dados = NfeXmlParser::extrair($xml);
        if ($dados === null) {
            return ['ok' => false, 'error' => 'xml_invalido — não foi possível extrair número e chave da NF-e'];
        }

        // O XML e TRANSITORIO (decisao do Ruan 21/08): serve pro envio ao marketplace e
        // e descartado — o painel guarda numero, serie, chave e emissao. Nada de acumular
        // arquivo; o unico PDF guardado no sistema e o da NF do FORNECEDOR (nfe_entrada).
        $order->updateQuietly(array_filter([
            'invoice_number'     => $dados['numero'],
            'invoice_series'     => $dados['serie'],
            'invoice_access_key' => $dados['chave'],
            'invoice_issued_at'  => $dados['emissao'],
            'invoice_status'     => 'authorized',
        ], fn ($v) => $v !== null && $v !== ''));
        $this->anotar($order, 'NF-e anexada manualmente pelo seller (XML) — nº ' . $dados['numero']
            . ($dados['serie'] ? ' série ' . $dados['serie'] : ''));

        // perna de marketplace — mesma da cadeia automatica (MUL-454), best effort:
        // falha aqui nao desfaz o anexo, so e reportada.
        $marketplace = 'nao_aplicavel';
        try {
            $acc = $order->marketplace_account_id ? MarketplaceAccount::find($order->marketplace_account_id) : null;
            $sn = trim((string) ($order->marketplace_order_id ?: $order->external_order_id));
            if ($acc && $acc->platform === 'shopee' && $sn !== '') {
                $shopee = app(ShopeeService::class);
                $inv = $shopee->getInvoiceData($acc, $sn);
                if (is_array($inv) && (string) ($inv['status'] ?? 'pending') !== 'valid') {
                    $up = $shopee->uploadInvoiceXml($acc, $sn, $xml);
                    if (! empty($up['ok'])) {
                        $marketplace = 'enviada';
                        $order->updateQuietly(['invoice_status' => 'marketplace_valid']);
                        $this->anotar($order, 'NF-e transmitida a Shopee (via anexo manual)');
                    } else {
                        $marketplace = 'falha: ' . trim(($up['error'] ?? '') . ' ' . ($up['message'] ?? ''));
                    }
                } elseif (is_array($inv)) {
                    $marketplace = 'ja_valida';
                    $order->updateQuietly(['invoice_status' => 'marketplace_valid']);
                }
                $ship = $shopee->arrangeShipment($acc, $sn);
                if (! empty($ship['ok']) && empty($ship['already'])) {
                    $this->anotar($order, 'Envio organizado na Shopee (ship_order, via anexo manual)');
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[MUL-455] Perna de marketplace do anexo manual falhou', [
                'order_id' => $order->id, 'erro' => $e->getMessage(),
            ]);
            $marketplace = 'falha: ' . $e->getMessage();
        }

        // destrava a cadeia automatica: NF resolvida na mao encerra o estado de falha
        OrderInvoiceSync::updateOrCreate(['order_id' => $order->id], [
            'status' => 'resolved', 'reason' => null, 'alerted_at' => null,
        ]);
        if ($order->label_status_reason === 'nfe_failed') {
            $order->updateQuietly(['label_status_reason' => null, 'label_error_at' => null]);
            OrderLabelQueue::where('order_id', $order->id)->where('status', 'failed')
                ->update(['status' => 'pending', 'next_check_at' => now(),
                    'error_log' => 'MUL-455: NF manual anexada — retomando etiqueta']);
        }

        FanoutOrderWebhookJob::dispatch($order->id, 'order.updated', ['action' => 'invoice_synced']);

        return ['ok' => true, 'dados' => $dados, 'marketplace' => $marketplace];
    }

    private function anotar(Order $order, string $texto): void
    {
        $linha = '[' . now()->format('d/m/Y H:i') . '] ' . $texto . ' (MUL-455)';
        $order->updateQuietly([
            'admin_note' => trim(($order->admin_note ? $order->admin_note . "\n" : '') . $linha),
        ]);
    }
}
