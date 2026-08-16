<?php

namespace App\Services\Marketplace\Reconciliation;

use App\Models\MarketplaceAccount;
use App\Models\MissedOrderAlert;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Detecta pedidos que chegaram no marketplace mas nao foram capturados via webhook.
 *
 * Responsabilidades:
 *  1. Delegar busca de pedidos ao adapter correto via factory
 *  2. Deduplicar contra tabela orders e missed_orders_alerts
 *  3. Persistir alertas novos (idempotente via UNIQUE constraint)
 *  4. Retornar collection dos alertas efetivamente criados
 *
 * O que este service NAO faz:
 *  - Nao filtra clientes ativos (responsabilidade do Job)
 *  - Nao envia notificacoes (responsabilidade do NotificationService)
 *  - Nao faz rate limiting (o adapter lanca RuntimeException se atingir 429)
 */
class MissedOrderDetectionService
{
    /**
     * Detecta pedidos perdidos para uma conta de marketplace especifica.
     *
     * @param  MarketplaceAccount  $account  Conta com credenciais validas
     * @param  Carbon              $since    Inicio da janela de busca (UTC)
     * @return Collection<int, MissedOrderAlert>  Alertas criados nesta execucao
     *
     * @throws \RuntimeException  Propagado do adapter em caso de rate limit sem retry
     */
    public function detectForAccount(MarketplaceAccount $account, Carbon $since): Collection
    {
        $marketplace = $account->platform ?? 'unknown';
        $clientId    = $account->client_id;

        // 1. Resolver adapter via factory
        //    Plataformas sem adapter (ex: hubaisimulator) sao ignoradas com warning
        //    e nao geram failed jobs.
        try {
            $adapter = MarketplaceReconciliationFactory::forMarketplace($marketplace);
        } catch (\InvalidArgumentException $e) {
            Log::warning("[MissedOrderDetection] Marketplace sem adapter de reconciliacao -- skip", [
                "client_id"           => $clientId,
                "marketplace"         => $marketplace,
                "marketplace_account" => $account->id,
            ]);
            return collect();
        }

        // 2. Buscar pedidos recentes no marketplace
        $dtos = $adapter->fetchRecentOrders($account, $since);

        if ($dtos->isEmpty()) {
            Log::info('[MissedOrderDetection] Nenhum pedido retornado pelo marketplace', [
                'client_id'           => $clientId,
                'marketplace'         => $marketplace,
                'marketplace_account' => $account->id,
                'since'               => $since->toIso8601String(),
            ]);

            return collect();
        }

        // 3. Carregar IDs ja existentes para deduplicacao em memoria (evita N+1 por DTO)
        $orderIds  = $this->existingOrderIds($clientId, $marketplace, $dtos);
        $alertIds  = $this->existingAlertIds($clientId, $marketplace, $dtos);

        $created = collect();

        foreach ($dtos as $dto) {
            // Pular pedidos ja registrados em orders ou ja alertados
            if (
                isset($orderIds[$dto->marketplaceOrderId]) ||
                isset($alertIds[$dto->marketplaceOrderId])
            ) {
                continue;
            }

            try {
                $alert = $this->persistAlert($account, $dto);

                if ($alert->wasRecentlyCreated) {
                    $created->push($alert);
                }
            } catch (\Throwable $e) {
                // Erro em um pedido nao deve derrubar os demais
                Log::warning('[MissedOrderDetection] Falha ao persistir alerta — skip', [
                    'client_id'            => $clientId,
                    'marketplace'          => $marketplace,
                    'marketplace_order_id' => $dto->marketplaceOrderId,
                    'error'                => $e->getMessage(),
                ]);
            }
        }

        Log::info('[MissedOrderDetection] Detectados pedidos perdidos', [
            'client_id'           => $clientId,
            'marketplace'         => $marketplace,
            'marketplace_account' => $account->id,
            'fetched'             => $dtos->count(),
            'new_alerts'          => $created->count(),
        ]);

        return $created;
    }

    // -------------------------------------------------------------------------
    // Helpers privados
    // -------------------------------------------------------------------------

    /**
     * Retorna map [marketplace_order_id => true] de pedidos ja existentes em orders.
     * Usa external_order_id como campo de matching.
     */
    private function existingOrderIds(int $clientId, string $marketplace, Collection $dtos): array
    {
        $externalIds = $dtos->pluck('marketplaceOrderId')->unique()->values()->all();

        return Order::where('client_id', $clientId)
            ->whereIn('external_order_id', $externalIds)
            ->pluck('external_order_id')
            ->flip()
            ->all();
    }

    /**
     * Retorna map [marketplace_order_id => true] de alertas ja existentes.
     */
    private function existingAlertIds(int $clientId, string $marketplace, Collection $dtos): array
    {
        $externalIds = $dtos->pluck('marketplaceOrderId')->unique()->values()->all();

        return MissedOrderAlert::where('client_id', $clientId)
            ->where('marketplace', $marketplace)
            ->whereIn('marketplace_order_id', $externalIds)
            ->pluck('marketplace_order_id')
            ->flip()
            ->all();
    }

    /**
     * Persiste o alerta de forma idempotente.
     * firstOrCreate garante que colisao de UNIQUE retorna o registro existente.
     */
    private function persistAlert(MarketplaceAccount $account, ReconciliationOrderDto $dto): MissedOrderAlert
    {
        return MissedOrderAlert::firstOrCreate(
            // Chave unica — espelha o UNIQUE constraint da migration
            [
                'marketplace'          => $dto->marketplace,
                'marketplace_order_id' => $dto->marketplaceOrderId,
                'client_id'            => $account->client_id,
            ],
            // Dados preenchidos apenas na criacao
            [
                'marketplace_account_id' => $account->id,
                'buyer_name'             => $dto->buyerName,
                'buyer_doc'              => $dto->buyerDoc,
                'amount_cents'           => $dto->amountCents,
                'currency'               => $dto->currency,
                'detected_at'            => now(),
                'notification_count'     => 0,
                'payload'                => $dto->toArray(),
            ]
        );
    }
}
