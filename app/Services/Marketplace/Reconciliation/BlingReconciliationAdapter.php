<?php

namespace App\Services\Marketplace\Reconciliation;

use App\Models\MarketplaceAccount;
use App\Services\Integrations\Erps\Bling\BlingApiClient;
use App\Services\Integrations\Erps\Bling\BlingAuthService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Adapter de reconciliacao para o Bling (ERP / canal de vendas).
 *
 * Reutiliza BlingApiClient (retry 429 com backoff exponencial + sleep 340ms
 * entre chamadas para respeitar limite de 3 req/s).
 *
 * Em RuntimeException com "rate limit" lancada pelo BlingApiClient apos
 * maxRetries, capturamos e retornamos reconciliacao parcial (nao propaga).
 * Outros erros (token invalido, 500) propagam para o Job abortar.
 *
 * Situacoes Bling incluidas: 6 (verificado), 9 (em aberto), 12 (em andamento), 15 (atendido)
 * Situacao 11 (cancelado) e excluida automaticamente pelo filtro idsSituacoes.
 *
 * Nota: usa MarketplaceAccount (padrao da interface). BlingApiClient::resolveToken()
 * ja suporta MarketplaceAccount via BlingAuthService internamente.
 */
class BlingReconciliationAdapter implements ReconciliationAdapter
{
    private const PAGE_SIZE = 50;
    private const MAX_PAGES = 3;

    private const VALID_SITUATIONS = [6, 9, 12, 15];

    public function __construct(
        private readonly BlingApiClient   $blingClient,
        private readonly BlingAuthService $blingAuth,
    ) {}

    public function fetchRecentOrders(MarketplaceAccount $account, Carbon $since): Collection
    {
        $results = collect();

        // Valida token antes de iniciar — fail fast
        try {
            $this->blingAuth->getValidToken($account);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "[BlingReconciliationAdapter] Token invalido para account #{$account->id}: {$e->getMessage()}"
            );
        }

        $dataInicial = $since->format('Y-m-d');
        $dataFinal   = now()->format('Y-m-d');
        $pagina      = 1;

        do {
            try {
                $response = $this->blingClient->get($account, '/pedidos/vendas', [
                    'dataInicial'  => $dataInicial,
                    'dataFinal'    => $dataFinal,
                    'pagina'       => $pagina,
                    'limite'       => self::PAGE_SIZE,
                    'idsSituacoes' => implode(',', self::VALID_SITUATIONS),
                ]);
            } catch (\RuntimeException $e) {
                if (str_contains(strtolower($e->getMessage()), 'rate limit')) {
                    Log::warning('[BlingReconciliationAdapter] Rate limit — reconciliacao parcial', [
                        'account_id' => $account->id,
                        'pagina'     => $pagina,
                    ]);
                    break; // Retorna parcial, nao propaga
                }
                throw $e;
            }

            $orders = $response['data'] ?? [];

            if (empty($orders)) {
                break;
            }

            foreach ($orders as $raw) {
                try {
                    $results->push($this->parseOrder($raw));
                } catch (\Throwable $e) {
                    Log::warning('[BlingReconciliationAdapter] Parse error — skipping', [
                        'account_id' => $account->id,
                        'bling_id'   => $raw['id'] ?? 'unknown',
                        'error'      => $e->getMessage(),
                    ]);
                }
            }

            $pagina++;

        } while (count($orders) === self::PAGE_SIZE && $pagina <= self::MAX_PAGES);

        Log::info('[BlingReconciliationAdapter] Concluido', [
            'account_id' => $account->id,
            'total'      => $results->count(),
        ]);

        return $results;
    }

    // ---------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------

    private function parseOrder(array $raw): ReconciliationOrderDto
    {
        $blingId = (string) ($raw['id'] ?? throw new \InvalidArgumentException('pedido.id ausente'));

        $orderId = ! empty($raw['numeroPedidoCompra'])
            ? (string) $raw['numeroPedidoCompra']
            : $blingId;

        $totalLiquido = (float) ($raw['total']['liquido'] ?? $raw['total']['valor'] ?? 0);
        $amountCents  = (int) round($totalLiquido * 100);

        $contato   = $raw['contato'] ?? [];
        $buyerName = $contato['nome'] ?? null;
        $buyerDoc  = isset($contato['cpfCnpj'])
            ? preg_replace('/\D/', '', $contato['cpfCnpj'])
            : null;

        $products = [];
        foreach ($raw['itens'] ?? [] as $item) {
            $produto    = $item['produto'] ?? [];
            $products[] = [
                'sku'        => (string) ($produto['codigo'] ?? $produto['id'] ?? ''),
                'qty'        => (int) ($item['quantidade'] ?? 1),
                'unit_price' => (int) round((float) ($item['valor'] ?? 0) * 100),
            ];
        }

        $dateStr   = $raw['dataOperacao'] ?? $raw['data'] ?? null;
        $createdAt = $dateStr ? Carbon::parse($dateStr) : Carbon::now();

        return new ReconciliationOrderDto(
            marketplace:        'bling',
            marketplaceOrderId: $orderId,
            buyerName:          $buyerName,
            buyerDoc:           $buyerDoc,
            amountCents:        $amountCents,
            currency:           'BRL',
            products:           $products,
            createdAt:          $createdAt,
            rawPayload:         $raw,
        );
    }
}
