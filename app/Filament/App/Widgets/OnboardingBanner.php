<?php

namespace App\Filament\App\Widgets;

use Filament\Widgets\Widget;

class OnboardingBanner extends Widget
{
    protected static string $view = 'filament.app.widgets.onboarding-banner';
    protected int|string|array $columnSpan = 'full';
    protected static ?int $sort = -1;

    public static function canView(): bool
    {
        $client = auth()->user()?->client;
        if (!$client) {
            return true;
        }
        return !$client->subscriptions()
            ->whereIn('status', ['active', 'trialing'])
            ->exists();
    }
}
