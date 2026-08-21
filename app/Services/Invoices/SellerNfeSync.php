<?php

namespace App\Services\Invoices;

use App\Jobs\FanoutOrderWebhookJob;
use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Models\OrderInvoiceSync;
use App\Models\OrderLabelQueue;
use App\Services\Integrations\Erps\Bling\BlingAuthService;
use App\Services\Integrations\Marketplaces\ShopeeService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * MUL-454: etapa "Nota Fiscal" automatizada — o que o Bling deveria fazer e nao retenta.
 *
 * O Bling emite a NF-e mas, quando a transmissao ao marketplace falha, NAO retenta (so o
 * botao manual resolve) — e sem invoice valida o canal BR da Shopee nunca libera o
 * documento de envio (causa raiz MUL-429). Alem da invoice, o Bling tambem ORGANIZA O
 * ENVIO (ship_order) — sem esse passo o create_shipping_document responde "not yet ready"
 * pra sempre (provado ao vivo nos 4 presos de 21/08).
 *
 * Cadeia observada/remediada pelo Bling do SELLER (niveis 1+2; gerar-nfe automatico ficou
 * de fora por decisao do Ruan — consequencia fiscal, opt-in futuro):
 *
 *   pedido nao esta no Bling      -> falha: integracao Bling x Shopee do seller
 *   pedido sem NF                 -> falha: seller precisa emitir a nota
 *   NF 1 Pendente                 -> POST /nfe/{id}/enviar (transmite a SEFAZ)
 *   NF 2/4/9/11                   -> falha com motivo pro seller
 *   NF 3/8/10                     -> transitorio, aguarda (NAO queima tentativa)
 *   NF 5/6/7 Autorizada           -> arquiva XML+DANFE (disco privado) + invoice_* no
 *                                    pedido -> invoice pending na Shopee? upload do XML
 *                                    (receita MUL-429) -> organiza o envio (ship_order)
 *
 * Tentativas LIMITADAS (config bling.seller_nfe_max_attempts): so FALHA queima tentativa.
 * Esgotou -> label_status_reason='nfe_failed' + anotacao com o motivo + fila de etiqueta
 * encerrada + fanout pro WL alertar seller e admin no painel.
 */
class SellerNfeSync
{
    public function __construct(
        private BlingAuthService $auth,
        private ShopeeService $shopee,
    ) {
    }

    /**
     * @return array{state: string, motivo: ?string, acted: bool, exhausted: bool}
     */
    public function garantir(Order $order): array
    {
        if (! config('bling.seller_nfe_sync', true)) {
            return $this->resultado('desligado');
        }

        $sn = trim((string) ($order->marketplace_order_id ?: $order->external_order_id));
        if ($sn === '' || ! $order->client_id) {
            return $this->resultado('nao_aplicavel');
        }

        // so Shopee por enquanto — o canal onde a cadeia inteira foi provada (MUL-429)
        $accShopee = $this->contaShopee($order);
        if (! $accShopee) {
            return $this->resultado('nao_aplicavel');
        }

        $accBling = MarketplaceAccount::where('client_id', $order->client_id)
            ->where('platform', 'bling')
            ->where('status', 'active')
            ->whereNotNull('bling_access_token')
            ->first();
        if (! $accBling) {
            return $this->resultado('sem_bling'); // nada a observar — fluxo atual segue
        }

        $sync = OrderInvoiceSync::firstOrCreate(['order_id' => $order->id]);
        if ($sync->status === 'failed') {
            return $this->resultado('falha_permanente', $sync->reason, false, true);
        }
        if ($sync->status === 'resolved') {
            // acoes de remediacao sao one-shot; a etiqueta vem pelo ciclo normal
            return $this->resultado('nf_ok');
        }

        try {
            $token = $this->auth->getValidToken($accBling);
            if (! $token) {
                return $this->falha($order, $sync, 'Token do Bling do seller invalido — reconectar o Bling no painel');
            }

            $pedido = $this->acharPedidoBling($token, $sn, $sync);
            if ($pedido === null) {
                return $this->falha($order, $sync, 'O Bling do seller nao recebeu este pedido — verificar a integracao Bling x Shopee');
            }

            $nfeId = $pedido['notaFiscal']['id'] ?? null;
            if (! $nfeId) {
                return $this->falha($order, $sync, 'Pedido esta no Bling mas SEM nota fiscal — emitir a NF-e no Bling');
            }
            $sync->forceFill(['bling_nfe_id' => $nfeId])->save();

            usleep(400000); // rate limit Bling 3 req/s
            $nf = Http::withToken($token)->timeout(20)
                ->get(config('bling.api_base') . '/nfe/' . $nfeId)
                ->json()['data'] ?? null;
            if (! is_array($nf)) {
                return $this->resultado('bling_instavel'); // transitorio — nao queima
            }

            $situacao = (int) ($nf['situacao'] ?? 0);
            $sync->forceFill(['nfe_situacao' => $situacao, 'last_checked_at' => now()])->save();

            if ($situacao === 1) {
                return $this->transmitirNf($order, $sync, $token, (int) $nfeId);
            }
            if (in_array($situacao, [2, 4, 9, 11], true)) {
                return $this->falha($order, $sync, [
                    2  => 'NF-e do pedido foi CANCELADA no Bling',
                    4  => 'NF-e REJEITADA pela SEFAZ — corrigir no Bling e reemitir (motivo detalhado no Bling)',
                    9  => 'NF-e DENEGADA pela SEFAZ',
                    11 => 'NF-e BLOQUEADA no Bling',
                ][$situacao]);
            }
            if (in_array($situacao, [5, 6, 7], true)) {
                return $this->nfAutorizada($order, $sync, $nf, $accShopee, $sn);
            }

            return $this->resultado('nf_em_processamento'); // 3/8/10 — SEFAZ processando
        } catch (\Throwable $e) {
            Log::warning('[MUL-454] SellerNfeSync excecao (transitoria, nao queima tentativa)', [
                'order_id' => $order->id, 'erro' => $e->getMessage(),
            ]);
            return $this->resultado('erro_transitorio');
        }
    }

    // ------------------------------------------------------------------ passos

    private function transmitirNf(Order $order, OrderInvoiceSync $sync, string $token, int $nfeId): array
    {
        usleep(400000);
        $r = Http::withToken($token)->timeout(30)
            ->post(config('bling.api_base') . '/nfe/' . $nfeId . '/enviar');
        if ($r->successful()) {
            $this->anotar($order, 'NF-e pendente transmitida a SEFAZ pelo sistema (o Bling nao havia transmitido)');
            return $this->resultado('nf_transmitida', null, true);
        }
        // o corpo de erro do /enviar e onde a SEFAZ fala (ex.: certificado digital invalido)
        $body = $r->json() ?? [];
        $motivo = (string) ($body['error']['description'] ?? $body['error']['message'] ?? mb_substr($r->body(), 0, 300));
        return $this->falha($order, $sync, 'Transmissao da NF-e a SEFAZ falhou: ' . mb_substr($motivo, 0, 300));
    }

    private function nfAutorizada(Order $order, OrderInvoiceSync $sync, array $nf, MarketplaceAccount $accShopee, string $sn): array
    {
        $agiu = false;
        $xml = $this->arquivarNf($order, $nf);

        $inv = $this->shopee->getInvoiceData($accShopee, $sn);
        $invStatus = is_array($inv) ? (string) ($inv['status'] ?? 'pending') : null;

        if ($invStatus !== null && $invStatus !== 'valid') {
            if ($xml === null) {
                return $this->falha($order, $sync, 'NF-e autorizada mas o XML nao pode ser baixado do Bling para envio a Shopee');
            }
            $up = $this->shopee->uploadInvoiceXml($accShopee, $sn, $xml);
            if (empty($up['ok'])) {
                return $this->falha($order, $sync, 'Envio da NF-e a Shopee falhou: ' . trim(($up['error'] ?? '') . ' ' . ($up['message'] ?? '')));
            }
            $this->anotar($order, 'NF-e transmitida a Shopee pelo sistema — o Bling nao sincronizou (invoice estava pendente)');
            $order->updateQuietly(['invoice_status' => 'marketplace_valid']);
            $agiu = true;
        }

        // organizar o envio — o passo do Bling que libera o documento; "ja organizado" e ok
        $ship = $this->shopee->arrangeShipment($accShopee, $sn);
        if (empty($ship['ok'])) {
            return $this->falha($order, $sync, 'Shopee recusou organizar o envio (ship_order): ' . trim(($ship['error'] ?? '') . ' ' . ($ship['message'] ?? '')));
        }
        if (empty($ship['already'])) {
            $this->anotar($order, 'Envio organizado na Shopee pelo sistema (ship_order) — libera a geracao da etiqueta');
            $agiu = true;
        }

        $sync->forceFill(['status' => 'resolved', 'reason' => null])->save();
        return $this->resultado('nf_ok', null, $agiu);
    }

    /**
     * Preenche invoice_* e arquiva XML/DANFE. NUNCA no disco publico (MUL-424) —
     * NF-e so por autenticacao. Retorna o conteudo do XML quando disponivel.
     */
    private function arquivarNf(Order $order, array $nf): ?string
    {
        $update = array_filter([
            'invoice_number'     => $nf['numero'] ?? null,
            'invoice_series'     => isset($nf['serie']) ? (string) $nf['serie'] : null,
            'invoice_access_key' => $nf['chaveAcesso'] ?? null,
            'invoice_issued_at'  => $nf['dataEmissao'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
        if ($order->invoice_status !== 'marketplace_valid') {
            $update['invoice_status'] = 'authorized';
        }

        $xmlContent = null;
        if (! empty($nf['xml'])) {
            $r = Http::timeout(30)->get($nf['xml']); // campo xml e um LINK de download
            if ($r->ok() && $r->body() !== '') {
                $xmlContent = $r->body();
                if (empty($order->invoice_xml_url)) {
                    $path = 'nfe/nfe-' . $order->id . '-' . substr(md5($xmlContent), 0, 8) . '.xml';
                    Storage::disk('local')->put($path, $xmlContent);
                    $update['invoice_xml_url'] = $path;
                }
            }
        }
        if (empty($order->invoice_url) && ! empty($nf['linkPDF'])) {
            $pdf = Http::timeout(30)->get($nf['linkPDF']);
            if ($pdf->ok() && str_starts_with($pdf->body(), '%PDF')) {
                $path = 'nfe/danfe-' . $order->id . '-' . substr(md5($pdf->body()), 0, 8) . '.pdf';
                Storage::disk('local')->put($path, $pdf->body());
                $update['invoice_url'] = $path;
            }
        }
        $order->updateQuietly($update);

        return $xmlContent;
    }

    /**
     * Busca frouxa por numeroLoja com match DIRETO na lista (limite=100 — com 10 o
     * pedido real nem aparecia entre os vizinhos; medido 21/08).
     */
    private function acharPedidoBling(string $token, string $sn, OrderInvoiceSync $sync): ?array
    {
        $r = Http::withToken($token)->timeout(20)
            ->get(config('bling.api_base') . '/pedidos/vendas', ['numeroLoja' => $sn, 'limite' => 100]);
        foreach (($r->json()['data'] ?? []) as $p) {
            if (($p['numeroLoja'] ?? null) === $sn) {
                $sync->forceFill(['bling_pedido_id' => $p['id']])->save();
                usleep(400000);
                $det = Http::withToken($token)->timeout(20)
                    ->get(config('bling.api_base') . '/pedidos/vendas/' . $p['id'])
                    ->json()['data'] ?? null;
                return is_array($det) ? $det : null;
            }
        }
        return null;
    }

    private function contaShopee(Order $order): ?MarketplaceAccount
    {
        $acc = $order->marketplace_account_id ? MarketplaceAccount::find($order->marketplace_account_id) : null;

        return ($acc && $acc->platform === 'shopee') ? $acc : null;
    }

    // --------------------------------------------------------------- desfechos

    private function falha(Order $order, OrderInvoiceSync $sync, string $motivo): array
    {
        $sync->increment('attempts');
        $sync->forceFill(['reason' => mb_substr($motivo, 0, 490), 'last_checked_at' => now()])->save();

        $max = max(1, (int) config('bling.seller_nfe_max_attempts', 5));
        if ($sync->attempts < $max) {
            Log::warning('[MUL-454] Falha na cadeia de NF (' . $sync->attempts . '/' . $max . ')', [
                'order_id' => $order->id, 'motivo' => $motivo,
            ]);

            return $this->resultado('nf_falha', $motivo);
        }

        // esgotou: alerta seller + admin no painel (via espelho) e encerra a fila
        if (! $sync->alerted_at) {
            $sync->forceFill(['status' => 'failed', 'alerted_at' => now()])->save();
            $order->updateQuietly(['label_status_reason' => 'nfe_failed']);
            $this->anotar($order, 'ALERTA: nota fiscal do pedido com problema — ' . $motivo . ' (apos ' . $max . ' tentativas automaticas)');
            OrderLabelQueue::where('order_id', $order->id)
                ->update(['status' => 'failed', 'error_log' => 'MUL-454 nfe_failed: ' . mb_substr($motivo, 0, 200)]);
            FanoutOrderWebhookJob::dispatch($order->id, 'order.updated', [
                'action'     => 'nfe_failed',
                'nfe_motivo' => $motivo,
            ]);
            Log::warning('[MUL-454] Tentativas esgotadas — alerta emitido', [
                'order_id' => $order->id, 'motivo' => $motivo,
            ]);
        }

        return $this->resultado('nf_falha', $motivo, false, true);
    }

    private function anotar(Order $order, string $texto): void
    {
        $linha = '[' . now()->format('d/m/Y H:i') . '] ' . $texto . ' (MUL-454)';
        $order->updateQuietly([
            'admin_note' => trim(($order->admin_note ? $order->admin_note . "\n" : '') . $linha),
        ]);
    }

    private function resultado(string $state, ?string $motivo = null, bool $acted = false, bool $exhausted = false): array
    {
        return ['state' => $state, 'motivo' => $motivo, 'acted' => $acted, 'exhausted' => $exhausted];
    }
}
