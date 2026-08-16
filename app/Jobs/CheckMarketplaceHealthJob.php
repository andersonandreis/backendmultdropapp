<?php

namespace App\Jobs;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceHealthCheck;
use App\Services\Integrations\Factories\MarketplaceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CheckMarketplaceHealthJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function handle(): void
    {
        $accounts = MarketplaceAccount::where('status', 'active')
            ->whereNotNull('access_token')
            ->whereNull('sync_blocked_at')
            ->get();

        foreach ($accounts as $account) {
            try {
                $this->checkAccount($account);
            } catch (\Exception $e) {
                Log::error('[CheckMarketplaceHealthJob] Erro ao verificar conta', [
                    'account_id' => $account->id,
                    'platform' => $account->platform,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function checkAccount(MarketplaceAccount $account): void
    {
        $metrics = match ($account->platform) {
            'mercadolivre', 'mercado_livre' => $this->checkMercadoLivre($account),
            'shopee' => $this->checkShopee($account),
            'tiktok' => $this->checkTikTok($account),
            default => [],
        };

        $hasCritical = false;

        foreach ($metrics as $metric) {
            MarketplaceHealthCheck::create([
                'marketplace_account_id' => $account->id,
                'metric' => $metric['metric'],
                'value' => $metric['value'],
                'status' => $metric['status'],
                'details' => $metric['details'] ?? null,
            ]);

            if ($metric['status'] === 'critical') {
                $hasCritical = true;
            }
        }

        if ($hasCritical) {
            Log::warning('[CheckMarketplaceHealthJob] Metrica CRITICA detectada', [
                'account_id' => $account->id,
                'platform' => $account->platform,
                'account_name' => $account->account_name,
                'critical_metrics' => collect($metrics)->where('status', 'critical')->pluck('metric')->toArray(),
            ]);
        }
    }

    // ──────────────────────────── Mercado Livre ────────────────────────────

    protected function checkMercadoLivre(MarketplaceAccount $account): array
    {
        $metrics = [];
        $userId = $account->ml_user_id ?? $account->seller_id;
        $token = $account->ml_access_token ?? $account->access_token;

        if (!$userId || !$token) {
            return [];
        }

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->get("https://api.mercadolibre.com/users/{$userId}");

            if ($response->failed()) {
                return [];
            }

            $data = $response->json();

            // Seller reputation level
            $level = $data['seller_reputation']['level_id'] ?? null;
            $reputationValue = match ($level) {
                '5_green' => 5.0,
                '4_light_green' => 4.0,
                '3_yellow' => 3.0,
                '2_orange' => 2.0,
                '1_red' => 1.0,
                default => 0.0,
            };
            $metrics[] = [
                'metric' => 'reputation',
                'value' => $reputationValue,
                'status' => $reputationValue >= 4 ? 'healthy' : ($reputationValue >= 3 ? 'warning' : 'critical'),
                'details' => ['level_id' => $level, 'raw' => $data['seller_reputation'] ?? null],
            ];

            // Cancellation rate
            $transactions = $data['seller_reputation']['transactions'] ?? [];
            $canceled = $transactions['canceled'] ?? 0;
            $total = $transactions['total'] ?? 1;
            $cancellationRate = $total > 0 ? ($canceled / $total) * 100 : 0;
            $metrics[] = [
                'metric' => 'cancellation_rate',
                'value' => round($cancellationRate, 4),
                'status' => $cancellationRate <= 2 ? 'healthy' : ($cancellationRate <= 5 ? 'warning' : 'critical'),
                'details' => ['canceled' => $canceled, 'total' => $total],
            ];

            // Claims rate
            $claims = $data['seller_reputation']['metrics']['claims']['value'] ?? null;
            $claimsRate = $claims !== null ? (float) $claims * 100 : 0;
            $metrics[] = [
                'metric' => 'claims_rate',
                'value' => round($claimsRate, 4),
                'status' => $claimsRate <= 1 ? 'healthy' : ($claimsRate <= 3 ? 'warning' : 'critical'),
                'details' => ['claims_value' => $claims],
            ];

            // Late shipment rate
            $lateShipment = $data['seller_reputation']['metrics']['delayed_handling_time']['value'] ?? null;
            $lateRate = $lateShipment !== null ? (float) $lateShipment * 100 : 0;
            $metrics[] = [
                'metric' => 'late_shipment_rate',
                'value' => round($lateRate, 4),
                'status' => $lateRate <= 5 ? 'healthy' : ($lateRate <= 10 ? 'warning' : 'critical'),
                'details' => ['delayed_value' => $lateShipment],
            ];
        } catch (\Exception $e) {
            Log::error('[CheckMarketplaceHealthJob] ML reputation check failed', [
                'account_id' => $account->id,
                'message' => $e->getMessage(),
            ]);
        }

        return $metrics;
    }

    // ──────────────────────────── Shopee ────────────────────────────

    protected function checkShopee(MarketplaceAccount $account): array
    {
        $metrics = [];
        $shopId = $account->shop_id;
        $token = $account->access_token;

        if (!$shopId || !$token) {
            return [];
        }

        try {
            $partnerId = config('services.shopee.partner_id', env('SHOPEE_PARTNER_ID'));
            $partnerKey = config('services.shopee.partner_key', env('SHOPEE_PARTNER_KEY'));

            $path = '/api/v2/shop/get_shop_info';
            $timestamp = time();
            $baseString = $partnerId . $path . $timestamp . $token . $shopId;
            $sign = hash_hmac('sha256', $baseString, $partnerKey);

            $url = "https://partner.shopeemobile.com/api/v2/shop/get_shop_info"
                . "?partner_id={$partnerId}&timestamp={$timestamp}&access_token={$token}&shop_id={$shopId}&sign={$sign}";

            $response = Http::timeout(15)->get($url);

            if ($response->failed()) {
                return [];
            }

            $data = $response->json('response') ?? [];

            // Shop rating
            $rating = $data['shop_rating'] ?? $data['rating_star'] ?? null;
            if ($rating !== null) {
                $ratingFloat = (float) $rating;
                $metrics[] = [
                    'metric' => 'reputation',
                    'value' => round($ratingFloat, 4),
                    'status' => $ratingFloat >= 4.5 ? 'healthy' : ($ratingFloat >= 4.0 ? 'warning' : 'critical'),
                    'details' => ['shop_rating' => $rating],
                ];
            }

            // Penalty points
            $penaltyPoints = $data['penalty_points'] ?? $data['penalty_point'] ?? null;
            if ($penaltyPoints !== null) {
                $pp = (float) $penaltyPoints;
                $metrics[] = [
                    'metric' => 'cancellation_rate',
                    'value' => $pp,
                    'status' => $pp <= 1 ? 'healthy' : ($pp <= 3 ? 'warning' : 'critical'),
                    'details' => ['penalty_points' => $penaltyPoints],
                ];
            }

            // Response time (chat response rate)
            $responseRate = $data['response_rate'] ?? null;
            if ($responseRate !== null) {
                $rr = (float) $responseRate * 100;
                $metrics[] = [
                    'metric' => 'response_time',
                    'value' => round($rr, 4),
                    'status' => $rr >= 90 ? 'healthy' : ($rr >= 70 ? 'warning' : 'critical'),
                    'details' => ['response_rate' => $responseRate],
                ];
            }
        } catch (\Exception $e) {
            Log::error('[CheckMarketplaceHealthJob] Shopee shop info check failed', [
                'account_id' => $account->id,
                'message' => $e->getMessage(),
            ]);
        }

        return $metrics;
    }

    // ──────────────────────────── TikTok Shop ────────────────────────────

    protected function checkTikTok(MarketplaceAccount $account): array
    {
        // TikTok Shop Seller API does not yet expose a public health/reputation endpoint
        // comparable to ML/Shopee. Log placeholder and return empty.
        Log::info('[CheckMarketplaceHealthJob] TikTok health check: endpoint nao disponivel ainda', [
            'account_id' => $account->id,
        ]);

        return [];
    }
}
