<?php

namespace App\Filament\App\Widgets;

use App\Models\MarketplaceAccount;
use Filament\Widgets\Widget;

class ShopeeTokenExpiredBannerWidget extends Widget
{
    protected static string $view = 'filament.app.widgets.shopee-token-expired-banner';
    protected int|string|array $columnSpan = 'full';
    protected static ?int $sort = -3;

    public int $expiredCount = 0;

    public function mount(): void
    {
        $client = auth()->user()?->client;
        if (! $client) {
            $this->expiredCount = 0;
            return;
        }

        $this->expiredCount = MarketplaceAccount::where('client_id', $client->id)
            ->where('platform', 'shopee')
            ->where(function ($q) {
                $q->whereIn('status', ['token_expired', 'token_invalid', 'expired', 'needs_reauth'])
                  ->orWhere(function ($q2) {
                      $q2->whereNotNull('token_expires_at')
                         ->where('token_expires_at', '<', now());
                  });
            })
            ->count();
    }

    public static function canView(): bool
    {
        $client = auth()->user()?->client;
        if (! $client) {
            return false;
        }

        return MarketplaceAccount::where('client_id', $client->id)
            ->where('platform', 'shopee')
            ->where(function ($q) {
                $q->whereIn('status', ['token_expired', 'token_invalid', 'expired', 'needs_reauth'])
                  ->orWhere(function ($q2) {
                      $q2->whereNotNull('token_expires_at')
                         ->where('token_expires_at', '<', now());
                  });
            })
            ->exists();
    }
}
