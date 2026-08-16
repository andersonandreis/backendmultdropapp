<?php

namespace App\Services\Marketplace\Reconciliation;

use App\Models\MarketplaceAccount;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Interface comum para buscar pedidos recentes de um marketplace.
 *
 * Responsabilidade EXCLUSIVA: buscar dados crus do marketplace e
 * normalizar para Collection<ReconciliationOrderDto>.
 *
 * O que o adapter NAO faz:
 * - Nao filtra clientes ativos (responsabilidade do Job)
 * - Nao faz deduplicacao contra tabela orders (responsabilidade do Service)
 * - Nao cria registros em missed_orders_alerts (responsabilidade do Service)
 */
interface ReconciliationAdapter
{
    /**
     * Busca pedidos criados no marketplace desde $since.
     *
     * Regras:
     * - Retorna apenas pedidos com status "pago" ou "enviado".
     * - Paginacao defensiva: maximo 50 itens por request. Abort graceful se 429.
     * - Erros de parse em pedidos individuais: log + skip + continua.
     * - Token invalido/expirado e impossivel renovar: lanca RuntimeException.
     *
     * @param  MarketplaceAccount  $account  Conta com credenciais OAuth validas
     * @param  Carbon              $since    Data/hora de inicio da janela (UTC)
     * @return Collection<int, ReconciliationOrderDto>
     *
     * @throws \RuntimeException  Rate limit sem retry possivel
     * @throws \RuntimeException  Token invalido/nao renovavel
     */
    public function fetchRecentOrders(MarketplaceAccount $account, Carbon $since): Collection;
}
