<?php

namespace App\Jobs;

use App\Models\ErpAccount;
use App\Models\FiscalNote;
use App\Models\Order;
use App\Services\Integrations\Erps\Bling\BlingApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * NOV-166-B — Monitora NFs do fornecedor emitidas no Bling ERP.
 *
 * Roda diariamente às 03:00. Para cada ErpAccount Bling ativa,
 * busca NFs dos últimos 7 dias e vincula aos pedidos na tabela fiscal_notes.
 */
class FetchSupplierInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int    $tries   = 2;
    public int    $timeout = 300;

    // ─── Handle ──────────────────────────────────────────────────────────────

    public function handle(): void
    {
        $dataInicio = now()->subDays(7)->format('Y-m-d');
        $dataFim    = now()->format('Y-m-d');

        $erpAccounts = ErpAccount::where('platform', 'bling')
            ->where('status', 'active')
            ->get();

        if ($erpAccounts->isEmpty()) {
            Log::info('[FetchSupplierInvoicesJob] Nenhuma ErpAccount Bling ativa encontrada.');
            return;
        }

        $totalProcessed = 0;
        $totalLinked    = 0;
        $totalErrors    = 0;

        foreach ($erpAccounts as $erp) {
            try {
                [$processed, $linked] = $this->processErpAccount($erp, $dataInicio, $dataFim);
                $totalProcessed += $processed;
                $totalLinked    += $linked;
            } catch (\Throwable $e) {
                $totalErrors++;
                Log::error('[FetchSupplierInvoicesJob] Erro ao processar ErpAccount', [
                    'erp_id' => $erp->id,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        Log::info('[FetchSupplierInvoicesJob] Concluído', [
            'nfs_processadas' => $totalProcessed,
            'nfs_vinculadas'  => $totalLinked,
            'erros'           => $totalErrors,
            'periodo'         => "{$dataInicio} → {$dataFim}",
        ]);
    }

    // ─── Processa uma ErpAccount ──────────────────────────────────────────────

    private function processErpAccount(ErpAccount $erp, string $dataInicio, string $dataFim): array
    {
        $client = app(BlingApiClient::class);

        // Situacao 6 = NF-e autorizada/emitida
        $response = $client->get($erp, '/nfe', [
            'situacao'           => 6,
            'dataEmissaoInicio'  => $dataInicio,
            'dataEmissaoFim'     => $dataFim,
        ]);

        $nfes = $response['data'] ?? [];

        if (empty($nfes)) {
            return [0, 0];
        }

        $processed = 0;
        $linked    = 0;

        foreach ($nfes as $nfe) {
            $processed++;

            $order = $this->findOrder($nfe);

            $fiscalNoteData = [
                'supplier_id' => $erp->supplier_id ?? null,
                'client_id'   => null,
                'nf_key'      => $nfe['chaveAcesso'] ?? null,
                'nf_number'   => $nfe['numero'] ?? null,
                'nf_series'   => $nfe['serie'] ?? null,
                'status'      => 'issued',
                'issued_at'   => isset($nfe['dataEmissao']) ? now()->parse($nfe['dataEmissao']) : now(),
                'value'       => $nfe['valorNota'] ?? null,
                'xml_url'     => $nfe['linkXmlDanfe'] ?? null,
                'pdf_url'     => $nfe['linkDanfe'] ?? null,
                'external_id' => (string) ($nfe['id'] ?? ''),
                'raw_data'    => $nfe,
            ];

            if ($order) {
                $fiscalNoteData['order_id'] = $order->id;

                FiscalNote::updateOrCreate(
                    ['order_id' => $order->id, 'source' => 'bling_supplier'],
                    $fiscalNoteData
                );

                // Atualizar order com status NF
                $order->update([
                    'nf_status' => 'issued',
                    'nf_key'    => $nfe['chaveAcesso'] ?? null,
                ]);

                $linked++;

                Log::info('[FetchSupplierInvoicesJob] NF vinculada ao pedido', [
                    'order_id'  => $order->id,
                    'nf_number' => $nfe['numero'] ?? null,
                    'nf_key'    => $nfe['chaveAcesso'] ?? null,
                ]);
            } else {
                // Salvar mesmo sem pedido vinculado (para auditoria)
                $numeroPedido = $nfe['numeroPedido'] ?? null;
                if ($numeroPedido) {
                    Log::warning('[FetchSupplierInvoicesJob] NF sem pedido correspondente', [
                        'numero_pedido' => $numeroPedido,
                        'nf_number'     => $nfe['numero'] ?? null,
                        'erp_id'        => $erp->id,
                    ]);
                }
            }
        }

        return [$processed, $linked];
    }

    // ─── Localiza o pedido pela NF ────────────────────────────────────────────

    private function findOrder(array $nfe): ?Order
    {
        $numeroPedido = $nfe['numeroPedido'] ?? null;

        if (!$numeroPedido) {
            return null;
        }

        // Tentar por order_number primeiro
        $order = Order::where('order_number', $numeroPedido)->first();
        if ($order) {
            return $order;
        }

        // Tentar por external_order_id
        $order = Order::where('external_order_id', $numeroPedido)->first();
        if ($order) {
            return $order;
        }

        return null;
    }
}
