<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\InvoiceResource\Pages;
use App\Filament\App\Resources\InvoiceResource\RelationManagers;
use App\Models\Invoice;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?int $navigationSort = 5;
    protected static ?string $navigationLabel = 'Minhas Faturas';
    protected static ?string $modelLabel = 'Fatura';



    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = auth()->user();

        if (!$user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->role === 'super_admin') {
            return $query;
        }

        $clientId = $user->client?->id;
        if ($clientId) {
            return $query->where('client_id', $clientId);
        }

        return $query->whereRaw('1 = 0');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('amount')
                    ->numeric()
                    ->prefix('R$')
                    ->disabled(),
                Forms\Components\Select::make('status')
                    ->options([
                        'open' => 'Em Aberto',
                        'paid' => 'Pago',
                        'canceled' => 'Cancelado'
                    ])
                    ->disabled(),
                Forms\Components\DateTimePicker::make('due_date')
                    ->disabled(),
                Forms\Components\DateTimePicker::make('paid_at')
                    ->disabled(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('amount')->money('BRL')->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'paid' => 'success',
                        'open' => 'warning',
                        'canceled' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('due_date')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('paid_at')->dateTime()->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('Pagar')
                    ->url(fn(Invoice $record) => $record->payment_url ?? '#')
                    ->icon('heroicon-o-credit-card')
                    ->visible(fn(Invoice $record) => $record->status === 'open')
                    ->openUrlInNewTab(),
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
            'index' => Pages\ListInvoices::route('/'),
            'create' => Pages\CreateInvoice::route('/create'),
            'edit' => Pages\EditInvoice::route('/{record}/edit'),
        ];
    }
}
