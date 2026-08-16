<?php

namespace App\Jobs;

use App\Models\ClientProduct;
use App\Models\MarketplaceAccount;
use App\Services\MercadoLivreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * CheckMLListingsJob — MES-023
 *
 * Verifica a cada 2h o status real dos anúncios ML com listing_status=active.
 * O ML não envia webhook quando ele próprio pausa um anúncio (ex: foto ruim,
 * conta nova, review). Este job detecta essas pausas via polling.
 *
 * Usa endpoint batch GET /items?ids=ID1,ID2,... (até 20 por chamada)
 * para minimizar chamadas à API ML.
 */
class CheckMLListingsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 300;

    private const ML_STATUS_MAP = [
        'active'       => 'active',
        'paused'       => 'paused',
        'closed'       => 'inactive',
        'deleted'      => 'inactive',
        'under_review' => 'paused',
        'inactive'     => 'inactive',
    ];

    /** INF-029-B: max contas por execucao (evita timeout com centenas de contas ML). */
    private const MAX_ACCOUNTS_PER_RUN = 30;

    public function handle(MercadoLivreService $mlService): void
    {
        // INF-029-B: processar no maximo 30 contas por execucao em round-robin por slot de 2h.
        // Ex: 242 contas / 30 = 9 slots; cada conta verificada ~a cada 18h (adequado para polling).
        $totalCount = MarketplaceAccount::whereIn('platform', ['mercadolivre', 'mercado_livre'])
            ->whereIn('status', ['active', 'connected'])
            ->whereNotNull('access_token')
            ->whereNull('sync_blocked_at')
            ->count();

        $numSlots = max(1, (int) ceil($totalCount / self::MAX_ACCOUNTS_PER_RUN));
        $slot   = (int) floor(now()->timestamp / 7200) % $numSlots;
        $offset = $slot * self::MAX_ACCOUNTS_PER_RUN;

        $accounts = MarketplaceAccount::whereIn('platform', ['mercadolivre', 'mercado_livre'])
            ->whereIn('status', ['active', 'connected'])
            ->whereNotNull('access_token')
            ->whereNull('sync_blocked_at')
            ->orderBy('id')
            ->skip($offset)
            ->take(self::MAX_ACCOUNTS_PER_RUN)
            ->get();

        $totalChecked = 0;
        $totalUpdated = 0;

        foreach ($accounts as $account) {
            try {
                ['checked' => $c, 'updated' => $u] = $this->checkAccountListings($account, $mlService);
                $totalChecked += $c;
                $totalUpdated += $u;
            } catch (\Exception $e) {
                Log::error('[CheckMLListingsJob] Erro ao verificar conta', [
                    'account_id' => $account->id,
                    'message'    => $e->getMessage(),
                ]);
            }
        }

        Log::info('[CheckMLListingsJob] Ciclo concluido', [
            'contas'               => $accounts->count(),
            'listings_verificados' => $totalChecked,
            'listings_atualizados' => $totalUpdated,
        ]);
    }

    protected function checkAccountListings(MarketplaceAccount $account, MercadoLivreService $mlService): array
    {
        try {
            $token = $mlService->getValidToken($account);
        } catch (\Exception $e) {
            Log::warning('[CheckMLListingsJob] Token invalido para conta ML — pulando', [
                'account_id' => $account->id,
                'error'      => $e->getMessage(),
            ]);
            return ['checked' => 0, 'updated' => 0];
        }

        $checked = 0;
        $updated = 0;

        // Lotes de 20 — limite do endpoint batch ML GET /items?ids=...
        ClientProduct::where('marketplace_account_id', $account->id)
            ->where('listing_status', 'active')
            ->whereNotNull('external_listing_id')
            ->select(['id', 'external_listing_id', 'listing_status', 'sync_status'])
            ->chunkById(20, function ($products) use ($token, $account, &$checked, &$updated) {
                $ids = $products->pluck('external_listing_id')->implode(',');

                $response = Http::withToken($token)
                    ->timeout(15)
                    ->get('https://api.mercadolibre.com/items', ['ids' => $ids]);

                if ($response->status() === 401) {
                    $account->increment('sync_errors_count');
                    if ($account->sync_errors_count >= 3) {
                        $account->update(['sync_blocked_at' => now()]);
                        Log::critical('[CheckMLListingsJob] Conta ML bloqueada apos 3 falhas 401', [
                            'account_id' => $account->id,
                        ]);
                    }
                    return false; // interrompe o chunk
                }

                if ($response->failed()) {
                    Log::warning('[CheckMLListingsJob] Falha na chamada batch ML', [
                        'account_id' => $account->id,
                        'status'     => $response->status(),
                        'ids'        => $ids,
                    ]);
                    return;
                }

                // Resetar contador de erros se chamada OK
                if ($account->sync_errors_count > 0) {
                    $account->update(['sync_errors_count' => 0, 'sync_blocked_at' => null]);
                }

                // Indexar resposta por item_id para lookup O(1)
                $mlItemsById = collect($response->json())->keyBy(
                    fn($item) => $item['body']['id'] ?? null
                );

                foreach ($products as $product) {
                    $mlItem = $mlItemsById->get($product->external_listing_id);

                    if (!$mlItem || ($mlItem['code'] ?? 0) !== 200) {
                        $checked++;
                        if (($mlItem['code'] ?? 0) === 404) {
                            $product->update([
                                'listing_status'  => 'inactive',
                                'sync_status'     => 'paused',
                                'last_sync_error' => 'Item nao encontrado no ML (404)',
                            ]);
                            $updated++;
                        }
                        continue;
                    }

                    $checked++;
                    $mlStatus    = $mlItem['body']['status'] ?? 'unknown';
                    $localStatus = self::ML_STATUS_MAP[$mlStatus] ?? 'paused';

                    if ($localStatus === $product->listing_status) {
                        continue;
                    }

                    $errorMsg = $localStatus !== 'active'
                        ? 'ML: status=' . $mlStatus . $this->extractHealthError($mlItem['body'])
                        : null;

                    $product->update([
                        'listing_status'  => $localStatus,
                        'sync_status'     => $localStatus === 'active' ? 'synced' : 'paused',
                        'last_sync_error' => $errorMsg,
                    ]);

                    $updated++;

                    Log::info('[CheckMLListingsJob] Listing status atualizado', [
                        'client_product_id'   => $product->id,
                        'external_listing_id' => $product->external_listing_id,
                        'de'                  => $product->listing_status,
                        'para'                => $localStatus,
                        'ml_status'           => $mlStatus,
                    ]);
                }
            });

        return ['checked' => $checked, 'updated' => $updated];
    }

    /**
     * Extrai mensagem de erro do sub_status ou tags do item ML, se disponível.
     */
    protected function extractHealthError(array $body): string
    {
        $sub = $body['sub_status'] ?? [];
        if (!empty($sub)) {
            return ' | sub_status=' . implode(',', (array) $sub);
        }

        $tags = $body['tags'] ?? [];
        $errorTags = array_filter($tags, fn($t) => str_contains((string) $t, 'error') || str_contains((string) $t, 'blocked'));
        if (!empty($errorTags)) {
            return ' | tags=' . implode(',', $errorTags);
        }

        return '';
    }
}
