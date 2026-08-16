<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Services\Integrations\Marketplaces\MercadoLivreService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TrendsController extends Controller
{
    private const CACHE_KEY = 'ml_trends_mlb_v1';
    private const CACHE_TTL = 21600; // 6h

    public function index()
    {
        $payload = Cache::get(self::CACHE_KEY);
        if ($payload) {
            return response()->json($payload);
        }

        $payload = $this->fetchLocal() ?? $this->fetchFromHub();

        if ($payload) {
            Cache::put(self::CACHE_KEY, $payload, self::CACHE_TTL);
            return response()->json($payload);
        }

        return response()->json(['source' => 'none', 'trends' => []]);
    }

    private function fetchLocal(): ?array
    {
        $svc = app(MercadoLivreService::class);

        $accounts = MarketplaceAccount::whereIn('platform', ['ml', 'mercadolivre'])
            ->where('status', 'active')
            ->whereNotNull('ml_access_token')
            ->orderByDesc('ml_token_expires_at')
            ->limit(3)
            ->get();

        foreach ($accounts as $account) {
            $token = $svc->getAccessToken($account);
            if (!$token) {
                continue;
            }

            try {
                $resp = Http::withToken($token)->timeout(10)->get('https://api.mercadolibre.com/trends/MLB');
            } catch (\Throwable $e) {
                Log::warning('[Trends] /trends/MLB exception: ' . $e->getMessage());
                continue;
            }

            if (!$resp->ok()) {
                Log::warning('[Trends] /trends/MLB falhou', ['account_id' => $account->id, 'status' => $resp->status()]);
                continue;
            }

            $trends = collect($resp->json())->take(12)->map(function ($t) use ($token) {
                // /sites/MLB/search retorna 403 (escopo restrito desde 2024) -> usar catalogo /products
                $prod = null;
                try {
                    $s = Http::withToken($token)->timeout(8)->get('https://api.mercadolibre.com/products/search', [
                        'status' => 'active', 'site_id' => 'MLB', 'q' => $t['keyword'], 'limit' => 1,
                    ]);
                    $pid = $s->ok() ? (collect($s->json('results'))->first()['id'] ?? null) : null;
                    if ($pid) {
                        $d = Http::withToken($token)->timeout(8)->get('https://api.mercadolibre.com/products/' . $pid);
                        if ($d->ok()) {
                            $prod = $d->json();
                        }
                    }
                } catch (\Throwable $e) {
                    // enriquecimento best-effort
                }

                return [
                    'keyword'   => $t['keyword'],
                    'url'       => $t['url'] ?? null,
                    'title'     => $prod['name'] ?? null,
                    'price'     => $prod['buy_box_winner']['price'] ?? null,
                    'thumbnail' => isset($prod['pictures'][0]['url']) ? str_replace('http://', 'https://', $prod['pictures'][0]['url']) : null,
                    'permalink' => $prod['permalink'] ?? null,
                ];
            })->values()->all();

            return ['source' => 'ml', 'fetched_at' => now()->toIso8601String(), 'trends' => $trends];
        }

        return null;
    }

    private function fetchFromHub(): ?array
    {
        // Instalacoes sem conta ML (ex.: seller.global) consomem o hub, que roda o mesmo codigo.
        // Guard anti-loop: o proprio hub nunca faz fallback pra si mesmo.
        if (str_contains((string) config('app.url'), 'api.hubai.io')) {
            return null;
        }

        try {
            $resp = Http::timeout(15)->get('https://api.hubai.io/api/v1/trends');
            if ($resp->ok() && !empty($resp->json('trends'))) {
                $data = $resp->json();
                $data['source'] = 'hub';
                return $data;
            }
        } catch (\Throwable $e) {
            Log::warning('[Trends] fallback hub falhou: ' . $e->getMessage());
        }

        return null;
    }
}
