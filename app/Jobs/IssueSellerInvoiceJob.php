<?php

namespace App\Jobs;

use App\Models\FiscalNote;
use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Services\Integrations\Erps\Bling\BlingApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * NOV-166-D — Emite NF-e no Bling do seller quando pedido é pago.
 *
 * Disparado pelo OrderObserver quando order.status muda para 'paid'
 * e o seller tem MarketplaceAccount Bling com auto_invoice_enabled=1.
 */
class IssueSellerInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int    $tries   = 3;
    public int    $timeout = 120;
    public array  $backoff = [60, 300, 900];

    public function __construct(private readonly int $orderId)
    {
    }

    // ─── Handle ──────────────────────────────────────────────────────────────

    public function handle(): void
    {
        $order = Order::find($this->orderId);

        if (!$order) {
            Log::warning('[IssueSellerInvoiceJob] Pedido não encontrado', ['order_id' => $this->orderId]);
            return;
        }

        // Guard de idempotência — não emitir segunda vez
        $alreadyIssued = FiscalNote::where('order_id', $this->orderId)
            ->where('source', 'bling_seller')
            ->where('status', 'issued')
            ->exists();

        if ($alreadyIssued) {
            Log::info('[IssueSellerInvoiceJob] NF já emitida para este pedido, ignorando', [
                'order_id' => $this->orderId,
            ]);
            return;
        }

        // Buscar conta Bling do seller com auto_invoice_enabled
        $account = MarketplaceAccount::where('client_id', $order->client_id)
            ->where('platform', 'bling')
            ->where('status', 'active')
            ->where('auto_invoice_enabled', 1)
            ->first();

        if (!$account) {
            Log::info('[IssueSellerInvoiceJob] Seller sem Bling auto-invoice ativo', [
                'order_id'  => $this->orderId,
                'client_id' => $order->client_id,
            ]);
            return;
        }

        // Criar registro em fiscal_notes com status pending antes de tentar
        $fiscalNote = FiscalNote::updateOrCreate(
            ['order_id' => $this->orderId, 'source' => 'bling_seller'],
            [
                'client_id'  => $order->client_id,
                'supplier_id' => $order->supplier_id,
                'status'     => 'pending',
                'nf_series'  => $account->invoice_series ?? '1',
            ]
        );

        try {
            $blingClient = app(BlingApiClient::class);

            // Montar payload para emissão da NF-e
            $payload = $this->buildNfePayload($order, $account);

            $response = $blingClient->post($account, '/nfe', $payload);

            $nfeData = $response['data'] ?? [];

            // Atualizar fiscal_note com dados retornados
            $fiscalNote->update([
                'status'      => 'issued',
                'nf_key'      => $nfeData['chaveAcesso'] ?? null,
                'nf_number'   => $nfeData['numero'] ?? null,
                'nf_series'   => $nfeData['serie'] ?? ($account->invoice_series ?? '1'),
                'issued_at'   => now(),
                'value'       => $order->total ?? null,
                'external_id' => (string) ($nfeData['id'] ?? ''),
                'xml_url'     => $nfeData['linkXmlDanfe'] ?? null,
                'pdf_url'     => $nfeData['linkDanfe'] ?? null,
                'raw_data'    => $nfeData,
            ]);

            // Atualizar order
            $order->update([
                'nf_status' => 'issued',
                'nf_key'    => $nfeData['chaveAcesso'] ?? null,
            ]);

            Log::info('[IssueSellerInvoiceJob] NF emitida com sucesso', [
                'order_id'  => $this->orderId,
                'nf_number' => $nfeData['numero'] ?? null,
            ]);

        } catch (\Throwable $e) {
            // Registrar erro sem re-lançar indefinidamente
            $fiscalNote->update([
                'status'   => 'error',
                'raw_data' => ['error' => $e->getMessage(), 'order_id' => $this->orderId],
            ]);

            Log::error('[IssueSellerInvoiceJob] Falha ao emitir NF', [
                'order_id' => $this->orderId,
                'error'    => $e->getMessage(),
            ]);

            // Lança para retry automático do queue
            throw $e;
        }
    }

    // ─── Monta payload NF-e para o Bling ─────────────────────────────────────

    private function buildNfePayload(Order $order, MarketplaceAccount $account): array
    {
        return [
            'tipo'                 => 1, // NF-e saída
            'finalidade'          => 1, // Normal
            'naturezaOperacao'    => ['descricao' => 'Venda de mercadoria'],
            'serie'               => $account->invoice_series ?? '1',
            'numero'              => null, // Bling auto-gera
            'contato'             => [
                // Dados do comprador (seller/lojista)
                'nome' => $order->client->name ?? 'Cliente',
            ],
            'itens'               => $this->buildItems($order),
            'parcelas'            => [
                [
                    'dias'  => 0,
                    'valor' => $order->total ?? 0,
                    'forma' => ['id' => 1], // PIX/dinheiro
                ],
            ],
            'numeroPedido'        => $order->order_number,
            'observacoes'         => "Pedido #{$order->order_number} — emitido automaticamente via HubAI",
        ];
    }

    private function buildItems(Order $order): array
    {
        // Tenta buscar itens do pedido; fallback com item genérico
        $items = [];

        if ($order->items && $order->items->isNotEmpty()) {
            foreach ($order->items as $item) {
                $items[] = [
                    'codigo'      => $item->sku ?? 'PROD',
                    'descricao'   => $item->product_name ?? 'Produto',
                    'unidade'     => 'UN',
                    'quantidade'  => $item->quantity ?? 1,
                    'valor'       => $item->unit_price ?? ($order->total ?? 0),
                ];

                // NOV-203: produto com codigo de servico gera linha extra (mesma qtd)
                $serviceSku = $item->product?->service_sku;
                if ($serviceSku) {
                    $items[] = [
                        'codigo'      => $serviceSku,
                        'descricao'   => 'Servico embalagem - ' . ($item->product_name ?? $item->sku ?? 'Produto'),
                        'unidade'     => 'UN',
                        'quantidade'  => $item->quantity ?? 1,
                        'valor'       => 0,
                    ];
                }
            }
        } else {
            // Fallback genérico
            $items[] = [
                'codigo'     => 'VENDA',
                'descricao'  => "Venda referente ao pedido {$order->order_number}",
                'unidade'    => 'UN',
                'quantidade' => 1,
                'valor'      => $order->total ?? 0,
            ];
        }

        return $items;
    }
}
