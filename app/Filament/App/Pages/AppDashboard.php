<?php

namespace App\Filament\App\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

class AppDashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-home';
    protected static ?string $title = 'Painel do Lojista';

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('toggle_private_stock')
                ->label(function () {
                    $user = auth()->user();
                    $clientId = $user->client_id ?? ($user->client->id ?? null);
                    if (!$clientId)
                        return 'Ativar Estoque Próprio';

                    $hasPrivate = \App\Models\Supplier::where('owner_client_id', $clientId)->exists();
                    return $hasPrivate ? 'Desativar Estoque Próprio' : 'Ativar Estoque Próprio';
                })
                ->icon('heroicon-o-cube')
                ->color(function () {
                    $user = auth()->user();
                    $clientId = $user->client_id ?? ($user->client->id ?? null);
                    if (!$clientId)
                        return 'primary';

                    $hasPrivate = \App\Models\Supplier::where('owner_client_id', $clientId)->exists();
                    return $hasPrivate ? 'danger' : 'success';
                })
                ->requiresConfirmation()
                ->modalHeading('Gerenciamento de Estoque Próprio')
                ->modalDescription('Ao ativar, você ganhará menus de Fornecedor para cadastrar seu Catálogo e gerenciar seu próprio Estoque num ambiente isolado. Ninguém mais verá esses produtos.')
                ->action(function () {
                    $user = auth()->user();
                    $client = $user->client;
                    if (!$client) {
                        \Filament\Notifications\Notification::make()->title('Erro: Cliente não localizado')->danger()->send();
                        return;
                    }

                    $supplier = \App\Models\Supplier::where('owner_client_id', $client->id)->first();

                    if ($supplier) {
                        // Se já tem, desativa
                        $supplier->delete();
                        \Filament\Notifications\Notification::make()->title('Estoque Próprio desativado.')->success()->send();
                    } else {
                        // Se não tem, cria
                        \App\Models\Supplier::create([
                            'owner_client_id' => $client->id,
                            'is_private' => true,
                            'company_name' => 'Estoque Próprio: ' . $client->company_name,
                            'type' => 'nacional',
                            'is_active' => true,
                        ]);
                        \Filament\Notifications\Notification::make()->title('Estoque Próprio Ativado!')->success()->body('Recarregue a página para ver os novos menus.')->send();
                    }
                }),
        ];
    }
}
