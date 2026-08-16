<?php

namespace App\Filament\App\Widgets;

use App\Models\MissedOrderAlert;
use Filament\Widgets\Widget;

class MissedOrdersBannerWidget extends Widget
{
    protected static string $view = 'filament.app.widgets.missed-orders-banner';
    protected int|string|array $columnSpan = 'full';
    protected static ?int $sort = -2;

    public int $pendingCount = 0;

    public function mount(): void
    {
        $client = auth()->user()?->client;
        if (! $client) {
            $this->pendingCount = 0;
            return;
        }

        // Nao mostrar para Start (nao tem acesso a pagina de destino)
        $subscription = $client->subscriptions()
            ->whereIn('status', ['active', 'trialing'])
            ->with('plan')
            ->latest()
            ->first();

        $isStart = ! $subscription || ! $subscription->plan || (int) $subscription->plan->max_skus <= 30;
        if ($isStart) {
            $this->pendingCount = 0;
            return;
        }

        $this->pendingCount = MissedOrderAlert::forClient($client->id)->pending()->count();
    }

    public static function canView(): bool
    {
        $client = auth()->user()?->client;
        if (! $client) {
            return false;
        }

        // So mostra se ha alertas — canView e chamado antes do mount,
        // entao fazemos a query aqui tambem (Filament caches o resultado)
        $subscription = $client->subscriptions()
            ->whereIn('status', ['active', 'trialing'])
            ->with('plan')
            ->latest()
            ->first();

        if (! $subscription || ! $subscription->plan || (int) $subscription->plan->max_skus <= 30) {
            return false;
        }

        return MissedOrderAlert::forClient($client->id)->pending()->exists();
    }
}
