<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaAdsWidget extends Widget
{
    protected static string $view = 'filament.widgets.meta-ads-widget';
    protected static ?int $sort = 6;
    protected int|string|array $columnSpan = 'full';
    protected static ?string $heading = 'Trafego Pago - Meta Ads';

    public bool $configured = false;
    public array $hubAds    = [];
    public array $tokfyAds  = [];
    public string $error    = '';
    public string $lastUpdated = '';

    public function mount(): void
    {
        $this->loadData();
    }

    public function loadData(): void
    {
        $token   = config('services.meta_ads_access_token');
        $hubId   = config('services.meta_ads_hub_account_id');
        $tokfyId = config('services.meta_ads_tokfy_account_id');

        if (! $token) {
            $this->configured = false;
            $this->lastUpdated = now()->format('H:i:s');
            return;
        }

        $this->configured = true;

        if ($hubId) {
            $this->hubAds = $this->fetchAdInsights($token, $hubId, 'HubAI');
        }
        if ($tokfyId) {
            $this->tokfyAds = $this->fetchAdInsights($token, $tokfyId, 'Tokfy');
        }

        $this->lastUpdated = now()->format('H:i:s');
    }

    private function fetchAdInsights(string $token, string $accountId, string $label): array
    {
        $cacheKey = 'centro_comando_meta_' . $accountId . '_' . date('YmdH') . '_' . floor(date('i') / 5);

        return Cache::remember($cacheKey, 300, function () use ($token, $accountId, $label) {
            $result = [
                'label'            => $label,
                'spend_today'      => 0,
                'spend_7d'         => 0,
                'impressions'      => 0,
                'clicks'           => 0,
                'leads_today'      => 0,
                'leads_7d'         => 0,
                'cpa_today'        => 0,
                'error'            => '',
                'active_campaigns' => 0,
            ];

            try {
                $baseUrl = 'https://graph.facebook.com/v19.0/act_' . $accountId . '/insights';

                // Gasto hoje
                $resp = Http::timeout(8)->get($baseUrl, [
                    'fields'       => 'spend,impressions,clicks,actions',
                    'date_preset'  => 'today',
                    'access_token' => $token,
                ]);

                if ($resp->successful()) {
                    $data = $resp->json('data.0') ?? [];
                    $result['spend_today']  = (float)($data['spend'] ?? 0);
                    $result['impressions']  = (int)($data['impressions'] ?? 0);
                    $result['clicks']       = (int)($data['clicks'] ?? 0);
                    $leads = collect($data['actions'] ?? [])->firstWhere('action_type', 'lead');
                    $result['leads_today'] = (int)($leads['value'] ?? 0);
                    if ($result['leads_today'] > 0) {
                        $result['cpa_today'] = round($result['spend_today'] / $result['leads_today'], 2);
                    }
                } else {
                    $result['error'] = $resp->json('error.message') ?? 'Erro na API';
                }

                // Gasto 7 dias + leads 7d
                $resp7 = Http::timeout(8)->get($baseUrl, [
                    'fields'       => 'spend,actions',
                    'date_preset'  => 'last_7d',
                    'access_token' => $token,
                ]);

                if ($resp7->successful()) {
                    $data7 = $resp7->json('data.0') ?? [];
                    $result['spend_7d'] = (float)($data7['spend'] ?? 0);
                    $leads7 = collect($data7['actions'] ?? [])->firstWhere('action_type', 'lead');
                    $result['leads_7d'] = (int)($leads7['value'] ?? 0);
                }
            } catch (\Throwable $e) {
                $result['error'] = 'Timeout ou erro de rede: ' . $e->getMessage();
                Log::warning('MetaAdsWidget [' . $accountId . ']: ' . $e->getMessage());
            }

            return $result;
        });
    }

    public static function canView(): bool
    {
        return auth()->user()?->role === 'super_admin';
    }
}
