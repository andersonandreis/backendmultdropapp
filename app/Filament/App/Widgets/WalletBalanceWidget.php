<?php

namespace App\Filament\App\Widgets;

use App\Filament\App\Pages\WalletStatement;
use App\Models\ClientSupplierBalance;
use Filament\Widgets\Widget;

class WalletBalanceWidget extends Widget
{
    protected static string $view = 'filament.app.widgets.wallet-balance';
    protected static ?int $sort = 1;
    protected int | string | array $columnSpan = 1;

    protected function getViewData(): array
    {
        $user     = auth()->user();
        $clientId = $user->client_id ?? ($user->client->id ?? null);

        if (!$clientId) {
            return ['total' => 0, 'balances' => collect(), 'walletUrl' => '#'];
        }

        $balances = ClientSupplierBalance::where('client_id', $clientId)
            ->with('supplier')
            ->orderByDesc('balance')
            ->get();

        $total = $balances->sum('balance');

        return [
            'total'     => $total,
            'balances'  => $balances->take(3),
            'walletUrl' => WalletStatement::getUrl(),
        ];
    }
}
