<?php
namespace App\Filament\Resources\AffiliateResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class CommissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'commissions';
    protected static ?string $title = 'Comissões';
    protected static ?string $icon = 'heroicon-o-currency-dollar';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable(),
                Tables\Columns\TextColumn::make('event_type')->label('Evento')->badge(),
                Tables\Columns\TextColumn::make('plan_slug')->label('Plano')->badge()->color('info'),
                Tables\Columns\TextColumn::make('gross_amount')
                    ->label('Valor pago')->money('BRL')->sortable(),
                Tables\Columns\TextColumn::make('commission_amount')
                    ->label('Comissão')->money('BRL')->sortable()->weight('bold'),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors(['success' => 'paid', 'warning' => 'pending', 'danger' => 'cancelled']),
                Tables\Columns\TextColumn::make('paid_at')->label('Pago em')->dateTime('d/m H:i'),
                Tables\Columns\TextColumn::make('created_at')->label('Gerada')->dateTime('d/m H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
