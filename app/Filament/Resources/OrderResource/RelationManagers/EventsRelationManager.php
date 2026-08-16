<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * INF-036 — Histórico do pedido (order_events): tudo que aconteceu com o pedido,
 * incluindo respostas completas das APIs de marketplace (metadata JSON).
 */
class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'Histórico do Pedido';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Quando')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
                Tables\Columns\TextColumn::make('event_type')
                    ->label('Evento')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'marketplace_not_found' => 'danger',
                        default                 => 'gray',
                    }),
                Tables\Columns\TextColumn::make('description')
                    ->label('Descrição')
                    ->wrap(),
                Tables\Columns\TextColumn::make('metadata')
                    ->label('Resposta da API (JSON)')
                    ->formatStateUsing(fn ($state) => is_array($state)
                        ? json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        : (string) $state)
                    ->limit(150)
                    ->tooltip(fn ($record) => is_array($record->metadata)
                        ? json_encode($record->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        : null)
                    ->wrap()
                    ->copyable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50]);
    }
}
