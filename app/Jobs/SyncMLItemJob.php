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

class SyncMLItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(
        public readonly string $mlItemId,
        public readonly ?int   $mlUserId
    ) {}

    public function handle(MercadoLivreService $mlService): void
    {
        $account = MarketplaceAccount::where('ml_user_id', (string) $this->mlUserId)
            ->whereIn('platform', ['mercadolivre', 'mercado_livre'])
            ->first();

        if (! $account) {
            Log::warning("[SyncMLItemJob] Conta ML não encontrada para user_id={$this->mlUserId} — descartando job");
            // Sem conta = sem retry (deletar do failed mais tarde via purge)
            return;
        }

        // Guard: conta bloqueada por reauth necessário — não tenta até ser desbloqueada manualmente
        if ($account->sync_blocked_at !== null) {
            Log::warning("[ML-REAUTH-NEEDED] Conta ML id={$account->id} ml_user_id={$this->mlUserId} bloqueada desde {$account->sync_blocked_at}. Job descartado — reautentique no painel.");
            return;
        }

        $token = $mlService->getValidToken($account);

        // Busca item na API ML
        $response = Http::withToken($token)
            ->get("https://api.mercadolibre.com/items/{$this->mlItemId}");

        if ($response->failed()) {
            // HUB-182: 403 (PolicyAgent/moderacao) e 404 (item deletado) nao sao
            // recuperaveis por retry — descartar sem ERROR (webhook novo re-dispara se mudar).
            if (in_array($response->status(), [403, 404], true)) {
                Log::warning("[SyncMLItemJob] Item ML #{$this->mlItemId} HTTP {$response->status()} — descartando sem retry");
                return;
            }

            Log::error("[SyncMLItemJob] Falha ao buscar item ML #{$this->mlItemId}", [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            // 401 = token inválido. Incrementar contador de erros e bloquear se >= 3
            if ($response->status() === 401) {
                $account->increment('sync_errors_count');
                if ($account->sync_errors_count >= 3) {
                    $account->update(['sync_blocked_at' => now()]);
                    Log::critical("[ML-REAUTH-NEEDED] Conta ML id={$account->id} ml_user_id={$this->mlUserId} bloqueada após 3 falhas 401. Reautentique no painel HubAI.");
                }
            }

            $this->release(60);
            return;
        }

        // Sucesso: resetar contador de erros
        if ($account->sync_errors_count > 0) {
            $account->update(['sync_errors_count' => 0, 'sync_blocked_at' => null]);
        }

        $mlItem = $response->json();

        // Mapeia status ML → HubAI
        $statusMap = [
            'active'  => 'active',
            'paused'  => 'paused',
            'closed'  => 'inactive',
            'deleted' => 'inactive',
        ];
        $localStatus = $statusMap[$mlItem['status'] ?? ''] ?? 'inactive';

        // Encontra o ClientProduct associado pelo external_listing_id
        $clientProduct = ClientProduct::where('marketplace_account_id', $account->id)
            ->where('external_listing_id', $this->mlItemId)
            ->first();

        if (! $clientProduct) {
            Log::info("[SyncMLItemJob] ClientProduct não encontrado para item ML #{$this->mlItemId} — ignorando.");
            return;
        }

        $updated = [
            'sync_status'    => $localStatus === 'active' ? 'synced' : 'paused',
            'listing_status' => $localStatus,
            'stock_quantity' => $mlItem['available_quantity'] ?? $clientProduct->stock_quantity,
        ];

        $clientProduct->update($updated);

        Log::info("[SyncMLItemJob] Item ML #{$this->mlItemId} sincronizado → status: {$localStatus}, stock: {$updated['stock_quantity']}");
    }
}
