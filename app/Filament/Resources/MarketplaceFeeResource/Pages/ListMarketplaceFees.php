<?php

namespace App\Filament\Resources\MarketplaceFeeResource\Pages;

use App\Filament\Resources\MarketplaceFeeResource;
use App\Services\Pricing\MarketplaceFeeService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListMarketplaceFees extends ListRecords
{
    protected static string $resource = MarketplaceFeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nova Taxa'),

            Actions\Action::make('sincronizar_ml')
                ->label('Sincronizar ML')
                ->icon('heroicon-o-arrow-path')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Sincronizar Taxas do Mercado Livre')
                ->modalDescription('Isso buscará as taxas atuais diretamente da API do Mercado Livre e atualizará o banco. Continuar?')
                ->action(function () {
                    try {
                        $count = app(MarketplaceFeeService::class)->syncMercadoLivreFees();
                        Notification::make()
                            ->title("Taxas ML sincronizadas: {$count} registros atualizados.")
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Erro ao sincronizar ML')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Actions\Action::make('seed_defaults')
                ->label('Seed Padrões')
                ->icon('heroicon-o-circle-stack')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Inserir Taxas Padrão')
                ->modalDescription('Insere taxas padrão para todas as plataformas configuradas. Registros existentes não serão sobrescritos. Continuar?')
                ->action(function () {
                    try {
                        $count = app(MarketplaceFeeService::class)->seedDefaultFees();
                        Notification::make()
                            ->title("Taxas padrão inseridas: {$count} registros.")
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Erro ao inserir taxas padrão')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
