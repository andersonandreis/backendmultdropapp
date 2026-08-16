<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\MarketplaceAccount;
use App\Services\Marketplace\Reconciliation\MissedOrderDetectionService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job de reconciliacao de pedidos por cliente.
 *
 * Executado a cada hora pelo scheduler (routes/console.php).
 * Cada instancia do job processa UM cliente, paralelizando naturalmente.
 *
 * O job "orquestrador" que itera clientes ativos e despacha um
 * ReconcileMarketplaceOrdersJob por cliente esta registrado no scheduler.
 *
 * Fila: reconciliation (isolada da fila principal para nao bloquear pedidos reais)
 * Timeout: 90s por job (seguro para 3 marketplaces com paginacao defensiva)
 * maxExceptions: 3 (apos 3 falhas nao-catchadas, marca como failed)
 */
class ReconcileMarketplaceOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Janela de busca: 25h garante 1h de sobreposicao entre execucoes horárias */
    private const WINDOW_HOURS = 25;

    /** Cliente considerado inativo apos X dias sem pedido ou login */
    private const INACTIVE_DAYS = 7;

    public int $timeout       = 90;
    public int $maxExceptions = 3;

    public function __construct(
        public readonly int     $clientId,
        public readonly ?string $marketplace = null,
    ) {
        $this->onQueue('reconciliation');
    }

    public function handle(MissedOrderDetectionService $service): void
    {
        // 1. Carregar cliente
        $client = Client::with([
            'subscriptions.plan',
            'marketplaceAccounts',
        ])->find($this->clientId);

        if (! $client) {
            Log::warning('[ReconcileJob] Cliente nao encontrado — abortando', [
                'client_id' => $this->clientId,
            ]);
            return;
        }

        // 2. Verificar se cliente esta ativo nos ultimos 7 dias
        //    Usa updated_at como proxy de last_active quando last_active_at nao existe
        if (! $this->isClientActive($client)) {
            Log::info('[ReconcileJob] Cliente inativo — skip para economizar rate limit', [
                'client_id' => $this->clientId,
            ]);
            return;
        }

        // 3. Filtrar contas de marketplace ativas
        $accounts = $client->marketplaceAccounts
            ->filter(fn(MarketplaceAccount $acc) => $this->isAccountEligible($acc));

        if ($accounts->isEmpty()) {
            Log::info('[ReconcileJob] Nenhuma conta de marketplace elegivel', [
                'client_id'   => $this->clientId,
                'marketplace' => $this->marketplace ?? 'all',
            ]);
            return;
        }

        $since        = now()->subHours(self::WINDOW_HOURS);
        $totalCreated = 0;
        $totalErrors  = 0;

        // 4. Iterar contas e detectar pedidos perdidos
        foreach ($accounts as $account) {
            try {
                $alerts = $service->detectForAccount($account, $since);
                $totalCreated += $alerts->count();
            } catch (\RuntimeException $e) {
                // Rate limit ou token invalido: loga e continua para a proxima conta
                // Nao falha o job — proxima execucao horaria tentara novamente
                Log::warning('[ReconcileJob] RuntimeException na conta — skip', [
                    'client_id'           => $this->clientId,
                    'marketplace_account' => $account->id,
                    'platform'            => $account->platform,
                    'error'               => $e->getMessage(),
                ]);
                $totalErrors++;
            }
        }

        // 5. Resumo
        Log::info('[ReconcileJob] Reconciliacao concluida', [
            'client_id'     => $this->clientId,
            'marketplace'   => $this->marketplace ?? 'all',
            'accounts'      => $accounts->count(),
            'new_alerts'    => $totalCreated,
            'errors'        => $totalErrors,
            'window_hours'  => self::WINDOW_HOURS,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers privados
    // -------------------------------------------------------------------------

    /**
     * Cliente ativo = subscricao ativa E (ultimo pedido OU login nos ultimos 7 dias).
     * Usa updated_at do client como fallback quando colunas de last_active nao existem.
     */
    private function isClientActive(Client $client): bool
    {
        // Subscricao ativa ou em trial
        $hasActiveSubscription = $client->subscriptions
            ->whereIn('status', ['active', 'trialing'])
            ->isNotEmpty();

        if (! $hasActiveSubscription) {
            return false;
        }

        $threshold = now()->subDays(self::INACTIVE_DAYS);

        // Verificar colunas de atividade — existem no schema real do client
        foreach (['last_active_at', 'last_order_at', 'last_login_at'] as $col) {
            if (isset($client->$col) && $client->$col instanceof Carbon && $client->$col->gte($threshold)) {
                return true;
            }
        }

        // Fallback: updated_at como proxy de atividade recente
        return $client->updated_at && $client->updated_at->gte($threshold);
    }

    /**
     * Conta elegivel:
     *  - Nao bloqueada por erro de sync
     *  - Platform compativel com o filtro opcional $this->marketplace
     *  - Tem access_token (nao precisa estar necessariamente valido — adapter trata expiracao)
     */
    private function isAccountEligible(MarketplaceAccount $account): bool
    {
        // Filtrar por marketplace especifico se setado
        if ($this->marketplace !== null && $account->platform !== $this->marketplace) {
            return false;
        }

        // Conta bloqueada por erros consecutivos
        if ($account->sync_blocked_at !== null) {
            return false;
        }

        // Conta marcada como precisa de reautenticacao manual — nao tentar renovar token aqui
        // (evita spam de erros invalid_grant a cada hora para contas com refresh_token expirado)
        if ($account->status === 'needs_reauth') {
            return false;
        }

        // Sem token nenhum — nao ha como fazer a chamada
        $hasToken = ! empty($account->access_token)
            || ! empty($account->ml_access_token)
            || ! empty($account->bling_access_token);

        return $hasToken;
    }
}
