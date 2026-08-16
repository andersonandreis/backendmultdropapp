<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\SubscriptionResource\Pages;
use App\Filament\App\Resources\SubscriptionResource\RelationManagers;
use App\Models\Subscription;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';
    protected static ?int $navigationSort = 7;
    protected static ?string $navigationLabel = 'Minha Assinatura';
    protected static ?string $modelLabel = 'Assinatura';


    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('client_id', auth()->user()->client->id);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Detalhes da Assinatura')
                    ->schema([
                        Forms\Components\Select::make('plan_id')
                            ->relationship('plan', 'name')
                            ->label('Plano desejado')
                            ->required(),
                        Forms\Components\Select::make('status')
                            ->options([
                                'active' => 'Ativa',
                                'past_due' => 'Aguardando Pagamento',
                                'canceled' => 'Cancelada',
                                'incomplete' => 'Incompleta'
                            ])
                            ->default('active')
                            ->label('Status'),
                        Forms\Components\DateTimePicker::make('current_period_start')
                            ->label('Início do Período')
                            ->default(now()),
                        Forms\Components\DateTimePicker::make('current_period_end')
                            ->label('Término do Período')
                            ->default(now()->addMonth()),
                        Forms\Components\DateTimePicker::make('canceled_at')
                            ->label('Cancelada em')
                            ->hiddenOn('create'),
                    ])->columns(2)
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('plan.name')
                    ->label('Plano')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'active' => 'Ativa',
                        'past_due' => 'Aguardando Pagamento',
                        'canceled' => 'Cancelada',
                        'incomplete' => 'Incompleta',
                        default => $state,
                    })
                    ->color(fn(string $state): string => match ($state) {
                        'active' => 'success',
                        'past_due' => 'warning',
                        'canceled' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('current_period_end')
                    ->label('Próxima Cobrança')
                    ->dateTime('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Ver'),
                Tables\Actions\Action::make('Manage Billing')
                    ->label('Gerenciar Assinatura')
                    ->url('#')
                    ->icon('heroicon-o-arrow-top-right-on-square'),
            ])
            ->bulkActions([
                // 
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptions::route('/'),
            'create' => Pages\CreateSubscription::route('/create'),
            'edit' => Pages\EditSubscription::route('/{record}/edit'),
        ];
    }
}
