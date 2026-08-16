<?php

namespace App\Services\Integrations\Erps\Bling;

use App\Models\ErpAccount;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MUL-275: emissao de NF-e no Bling do fornecedor a partir do pedido de venda
 * ja sincronizado (orders.bling_pedido_id). UNIDIRECIONAL (MUL-264): so envia
 * pro Bling — nunca importa notas/pedidos de la pra ca.
 */
class BlingNfeService
{
    public function __construct(
        protected BlingApiClient $client
    ) {}

    /**
     * Gera + envia (SEFAZ) + consulta a NF-e do pedido. Idempotente:
     * - ja autorizada -> already_emitted
     * - nota gerada antes (numero gravado) -> reusa via GET /nfe?numero= (nunca duplica)
     */
    public function emitForOrder(ErpAccount $erp, Order $order): array
    {
        if (! $order->bling_pedido_id) {
            throw new \RuntimeException('Pedido nao sincronizado com o Bling (bling_pedido_id vazio). Sincronize primeiro.');
        }

        if ($order->nfe_entrada_number && $order->nfe_entrada_status === 'authorized') {
            return [
                'action'         => 'already_emitted',
                'nfe_number'     => $order->nfe_entrada_number,
                'nfe_access_key' => $order->nfe_entrada_access_key,
                'nfe_pdf_url'    => $order->nfe_entrada_pdf_url,
            ];
        }

        $nfeId = $this->resolveNfeId($erp, $order);

        // Envia pra SEFAZ. Se falhar (ex: nota ja enviada/rejeitada), o GET abaixo
        // ainda atualiza o estado real da nota no pedido antes de reportar.
        $sendError = null;
        try {
            $this->client->post($erp, "nfe/{$nfeId}/enviar");
        } catch (\Throwable $e) {
            $sendError = $e->getMessage();
            Log::warning('[BlingNfeService] enviar SEFAZ falhou', [
                'order_id' => $order->id, 'nfe_id' => $nfeId, 'error' => $sendError,
            ]);
        }

        $data   = $this->client->getNfe($erp, (int) $nfeId)['data'] ?? [];
        $status = $this->mapStatus($data['situacao'] ?? null);
        $xml    = (string) ($data['xml'] ?? '');

        DB::table('orders')->where('id', $order->id)->update([
            'nfe_entrada_number'      => $data['numero'] ?? $order->nfe_entrada_number,
            'nfe_entrada_access_key'  => $data['chaveAcesso'] ?? $order->nfe_entrada_access_key,
            'nfe_entrada_pdf_url'     => $data['linkDanfe'] ?? $data['linkPDF'] ?? $order->nfe_entrada_pdf_url,
            'nfe_entrada_xml_url'     => str_starts_with($xml, 'http') ? $xml : $order->nfe_entrada_xml_url,
            'nfe_entrada_status'      => $status,
            'nfe_entrada_received_at' => $order->nfe_entrada_received_at ?? now(),
            'nfe_entrada_updated_at'  => now(),
        ]);

        if ($status !== 'authorized' && $sendError) {
            throw new \RuntimeException(
                'NF-e gerada (#' . ($data['numero'] ?? $nfeId) . ") mas envio SEFAZ falhou: {$sendError}"
            );
        }

        Log::info('[BlingNfeService] NF-e emitida', [
            'order_id' => $order->id, 'nfe_id' => $nfeId,
            'numero' => $data['numero'] ?? null, 'status' => $status,
        ]);

        return [
            'action'         => 'emitted',
            'nfe_id'         => $nfeId,
            'nfe_number'     => $data['numero'] ?? null,
            'nfe_status'     => $status,
            'nfe_access_key' => $data['chaveAcesso'] ?? null,
            'nfe_pdf_url'    => $data['linkDanfe'] ?? $data['linkPDF'] ?? null,
        ];
    }

    /**
     * MUL-360 item 4: espelha no pedido a NF-e que o FORNECEDOR ja emitiu no Bling dele.
     * Read-only no Bling — nao gera nem envia nada a SEFAZ. O caminho e o pedido de venda
     * vinculado (bling_pedido_id) -> notaFiscal.id -> dados da nota. Cobre nota emitida
     * por qualquer via (manual no Bling inclusive).
     */
    public function syncIssuedNfe(ErpAccount $erp, Order $order): bool
    {
        if (! $order->bling_pedido_id) {
            return false;
        }

        $pv    = $this->client->get($erp, "/pedidos/vendas/{$order->bling_pedido_id}");
        $nfeId = $pv['data']['notaFiscal']['id'] ?? null;
        if (! $nfeId) {
            return false;
        }

        $data = $this->client->getNfe($erp, (int) $nfeId)['data'] ?? null;
        if (! $data) {
            return false;
        }

        $xml = (string) ($data['xml'] ?? '');
        DB::table('orders')->where('id', $order->id)->update([
            'nfe_entrada_number'      => $data['numero'] ?? $order->nfe_entrada_number,
            'nfe_entrada_access_key'  => $data['chaveAcesso'] ?? $order->nfe_entrada_access_key,
            'nfe_entrada_pdf_url'     => $data['linkDanfe'] ?? $data['linkPDF'] ?? $order->nfe_entrada_pdf_url,
            'nfe_entrada_xml_url'     => str_starts_with($xml, 'http') ? $xml : $order->nfe_entrada_xml_url,
            'nfe_entrada_status'      => $this->mapStatus($data['situacao'] ?? null),
            'nfe_entrada_received_at' => $order->nfe_entrada_received_at ?? now(),
            'nfe_entrada_updated_at'  => now(),
        ]);

        Log::info('[BlingNfeService] NF-e do fornecedor espelhada no pedido', [
            'order_id' => $order->id, 'nfe_id' => $nfeId, 'numero' => $data['numero'] ?? null,
        ]);

        return true;
    }

    /** Reusa nota ja gerada (pelo numero) ou gera uma nova a partir do pedido de venda. */
    protected function resolveNfeId(ErpAccount $erp, Order $order): int
    {
        if ($order->nfe_entrada_number) {
            try {
                $found = $this->client->getNfeByNumero($erp, (string) $order->nfe_entrada_number);
                $id = $found['data'][0]['id'] ?? null;
                if ($id) {
                    return (int) $id;
                }
            } catch (\Throwable $e) {
                Log::warning('[BlingNfeService] busca por numero falhou, vai gerar nova', [
                    'order_id' => $order->id, 'numero' => $order->nfe_entrada_number, 'error' => $e->getMessage(),
                ]);
            }
        }

        $res = $this->client->post($erp, "pedidos/vendas/{$order->bling_pedido_id}/gerar-nfe");
        $id  = $res['data']['idNotaFiscal'] ?? $res['idNotaFiscal'] ?? null;
        if (! $id) {
            throw new \RuntimeException('Bling nao retornou idNotaFiscal no gerar-nfe: ' . json_encode($res));
        }

        return (int) $id;
    }

    /**
     * Situacao Bling v3: 1 Pendente, 2 Cancelada, 3 Aguardando recibo, 4 Rejeitada,
     * 5 Autorizada, 6 Emitida DANFE, 7 Registrada, 8 Aguardando protocolo,
     * 9 Denegada, 10 Consulta situacao, 11 Bloqueada.
     */
    protected function mapStatus(mixed $situacao): string
    {
        return match ((int) $situacao) {
            5, 6, 7 => 'authorized',
            4       => 'rejected',
            9       => 'denied',
            2       => 'cancelled',
            11      => 'blocked',
            default => 'pending',
        };
    }
}
