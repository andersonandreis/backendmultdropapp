<?php

namespace App\Filament\App\Widgets;

use Filament\Widgets\Widget;
use App\Models\Order;

class FirstSaleGuideWidget extends Widget
{
    protected static string $view = 'filament.app.widgets.first-sale-guide-widget';
    protected int|string|array $columnSpan = 'full';
    protected static ?int $sort = 1;

    public $orderCount = 0;

    public function mount()
    {
        $user = auth()->user();
        if ($user->role === 'client' && $user->client) {
            $this->orderCount = Order::where('client_id', $user->client->id)->count();
        }
    }

    public static function canView(): bool
    {
        $user = auth()->user();

        // Exibe apenas para clients
        if ($user->role !== 'client' || !$user->client) {
            return false;
        }

        // Exibe apenas se o Lojista vendeu de 1 a 5 vezes (Fase de Aprendizado)
        $count = Order::where('client_id', $user->client->id)->count();
        return false;
    }
}
