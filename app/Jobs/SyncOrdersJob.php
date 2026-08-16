<?php

namespace App\Jobs;

use App\Models\MarketplaceAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * SyncOrdersJob — Orquestrador de sincronizacao de pedidos de marketplace.
 *
 * Dispatcha um job especifico por plataforma para cada conta de marketplace ativa:
 *   - ML  (mercadolivre / mercado_livre) → SyncMLOrdersJob
 *   - Shopee                             → SyncShopeeOrdersJob
 *
 * Projetado para ser chamado manualmente (via artisan dispatch, tinker ou
 * outros jobs) quando necessario forcar uma sincronizacao completa.
 * O scheduling periodico normal ja e coberto por:
 *   - 'ml-orders-periodic-sync'     (hourly, console.php)
 *   - 'shopee-orders-periodic-sync' (hourly, console.php)
 *
 * Comportamento:
 *   - Sem $platform → todas as contas ativas de todos os marketplaces suportados.
 *   - Com $platform → apenas contas do marketplace especificado.
 *   - Ignora contas bloqueadas (sync_blocked_at), needs_reauth e sem token.
 *
 * Idempotente: os jobs filhos (SyncMLOrdersJob, SyncShopeeOrdersJob)
 * de-duplicam por external_order_id / marketplace_order_id.
 *
 * Fila: default (mesma dos jobs filhos para nao criar nova fila sem necessidade)
 * Timeout: 60s — so dispatcha, nao processa pedidos diretamente.
 * Tries: 3 (backoff 30s, 5min, 30min)
 */
class SyncOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Plataformas suportadas e o job correspondente */
    private const PLATFORM_JOBS = [
        'mercadolivre'  => SyncMLOrdersJob::class,
        'mercado_livre' => SyncMLOrdersJob::class,
        'shopee'        => SyncShopeeOrdersJob::class,
    ];

    public int $tries   = 3;
    public int $timeout = 60;

    /** @var array<int,int> Backoff entre tentativas: 30s, 5min, 30min */
    public function backoff(): array
    {
        return [30, 300, 1800];
    }

    /**
     * @param string|null $platform Filtra por plataforma especifica (null = todas).
     *                              Valores aceitos: 'mercadolivre', 'mercado_livre', 'shopee'.
     */
    public function __construct(
        public ?string $platform = null
    ) {}

    public function handle(): void
    {
        $query = MarketplaceAccount::whereIn('status', ['active', 'connected'])
            ->whereNotNull('access_token')
            ->whereNull('sync_blocked_at')
            ->where('status', '!=', 'needs_reauth')
            ->whereIn('platform', array_keys(self::PLATFORM_JOBS));

        // Filtro opcional por plataforma
        if ($this->platform !== null) {
            $query->where('platform', $this->platform);
        }

        $dispatched = 0;
        $skipped    = 0;

        $query->chunkById(50, function ($accounts) use (&$dispatched, &$skipped) {
            foreach ($accounts as $account) {
                $jobClass = self::PLATFORM_JOBS[$account->platform] ?? null;

                if ($jobClass === null) {
                    $skipped++;
                    continue;
                }

                // SyncMLOrdersJob requer token ML especifico
                if ($account->platform === 'mercadolivre' || $account->platform === 'mercado_livre') {
                    if (empty($account->access_token) && empty($account->ml_access_token)) {
                        $skipped++;
                        continue;
                    }
                }

                // SyncShopeeOrdersJob requer shop_id
                if ($account->platform === 'shopee' && empty($account->shop_id)) {
                    $skipped++;
                    continue;
                }

                $jobClass::dispatch($account->id);
                $dispatched++;
            }
        });

        Log::channel('marketplace')->info('[SyncOrdersJob] Orquestracao concluida', [
            'platform'   => $this->platform ?? 'all',
            'dispatched' => $dispatched,
            'skipped'    => $skipped,
        ]);
    }
}
