<?php
namespace App\Filament\Resources\AffiliateResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ReferralsRelationManager extends RelationManager
{
    protected static string $relationship = 'referrals';
    protected static ?string $title = 'Indicados';
    protected static ?string $icon = 'heroicon-o-user-group';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable(),
                Tables\Columns\TextColumn::make('referredClient.user.name')->label('Nome')->searchable(),
                Tables\Columns\TextColumn::make('referredClient.user.email')->label('Email')->searchable()->copyable(),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors(['success' => 'converted', 'warning' => 'pending', 'gray' => 'clicked']),
                Tables\Columns\TextColumn::make('utm_source')->label('Origem')->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->label('Quando')->dateTime('d/m H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
