<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClientProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ClientProductCountController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user   = $request->user();
        $client = $user->client;

        if (! $client) {
            return response()->json(['error' => 'Cliente nao encontrado.'], 404);
        }

        // Produtos vinculados a marketplaces (importados das contas conectadas)
        $marketplaceLinked = ClientProduct::where('client_id', $client->id)
            ->whereNotNull('marketplace_account_id')
            ->where('is_active', true)
            ->where('excluido', 0)
            ->count();

        // Plan limit via subscricao ativa
        $planLimit = $this->resolvePlanLimit($client);

        // Catalogo baixado do legado: tentamos via legacy_id_login
        $catalogDownloaded = 0;
        try {
            $catalogDownloaded = $this->countLegacyCatalog($client);
        } catch (\Throwable $e) {
            Log::channel('marketplace')->warning('[ClientProductCountController] Falha ao contar legado', [
                'client_id' => $client->id,
                'error'     => $e->getMessage(),
            ]);
        }

        $total = $marketplaceLinked + $catalogDownloaded;

        return response()->json([
            'marketplace_linked' => $marketplaceLinked,
            'catalog_downloaded' => $catalogDownloaded,
            'total'              => $total,
            'plan_limit'         => $planLimit,
        ]);
    }

    private function resolvePlanLimit($client): int
    {
        try {
            $subscription = $client->subscriptions()
                ->whereIn('status', ['active', 'trialing'])
                ->with('plan')
                ->orderByDesc('created_at')
                ->first();

            if (! $subscription || ! $subscription->plan) {
                return 100;
            }

            $plan    = $subscription->plan;
            $maxSkus = (int) ($plan->max_skus ?? 100);

            if (! empty($plan->slug)) {
                $slugMap = [
                    'start'   => 100,
                    'scaling' => 200,
                    'scale'   => 200,
                    'pro'     => 300,
                ];
                foreach ($slugMap as $key => $limit) {
                    if (str_contains(strtolower($plan->slug), $key)) {
                        return $limit;
                    }
                }
            }

            return $maxSkus > 0 ? $maxSkus : 100;
        } catch (\Throwable $e) {
            return 100;
        }
    }

    private function countLegacyCatalog($client): int
    {
        // Tabela customer_downloaded_products nao existe no legado
        return 0;
    }
}
