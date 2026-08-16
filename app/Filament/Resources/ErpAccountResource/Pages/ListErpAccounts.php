<?php

namespace App\Filament\Resources\ErpAccountResource\Pages;

use App\Filament\Resources\ErpAccountResource;
use App\Filament\Widgets\SellerBlingAccountsWidget;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;

/**
 * MUL-148B — Página Conexões ERP com duas guias nativas Filament:
 *   - Fornecedores: tabela ErpAccount (padrão, com badge token + ação Renovar de MUL-144)
 *   - Sellers: widget SellerBlingAccountsWidget (hub_readonly, read-only)
 */
class ListErpAccounts extends ListRecords
{
    protected static string $resource = ErpAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->visible(fn () => $this->activeTab !== 'sellers'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'fornecedores' => Tab::make('Fornecedores')
                ->icon('heroicon-o-building-office'),

            'sellers' => Tab::make('Sellers')
                ->icon('heroicon-o-users'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'fornecedores';
    }

    /**
     * Quando a aba "sellers" está ativa, exibe o widget de tabela nativo
     * logo abaixo do cabeçalho (antes da tabela de fornecedores, que fica oculta).
     */
    protected function getHeaderWidgets(): array
    {
        if ($this->activeTab === 'sellers') {
            return [SellerBlingAccountsWidget::class];
        }

        return [];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    /**
     * Quando a aba sellers está ativa, retorna query vazia para esconder
     * a tabela padrão de ErpAccounts (o conteúdo vem do widget).
     */
    public function getTableQuery(): ?\Illuminate\Database\Eloquent\Builder
    {
        if ($this->activeTab === 'sellers') {
            return \App\Models\ErpAccount::query()->whereRaw('1 = 0');
        }

        return parent::getTableQuery();
    }
}
