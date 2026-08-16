<?php

namespace App\Filament\Resources\MarketplaceAccountResource\Pages;

use App\Filament\Resources\MarketplaceAccountResource;
use Filament\Resources\Pages\ListRecords;

/**
 * MUL-159 — Lista read-only de lojas conectadas via hub.
 * Sem botao Criar — contas sao gerenciadas via OAuth no painel do lojista.
 */
class ListMarketplaceAccounts extends ListRecords
{
    protected static string $resource = MarketplaceAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
