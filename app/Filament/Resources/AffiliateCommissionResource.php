<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AffiliateCommissionResource\Pages;
use App\Models\AffiliateCommission;
use App\Models\Affiliate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class AffiliateCommissionResource extends Resource
{
    protected static ?string $model = AffiliateCommission::class;

    protected static ?string $slug = 'comissoes-afiliados';
    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';
    protected static ?string $navigationGroup = 'Afiliados';
    protected static ?string $navigationLabel = 'Comissoes';
    protected static ?string $modelLabel = 'Comissao';
    protected static ?string $pluralModelLabel = 'Comissoes';
    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('affiliate_id')
                    ->label('Afiliado')
                    ->relationship('affiliate', 'id')
                    ->getOptionLabelFromRecordUsing(fn (Affiliate $record) => $record->user?->name ?? 'ID: '.$record->id)
                    ->searchable()
                    ->required(),
                Forms\Components\TextInput::make('gross_amount')
                    ->label('Valor Bruto')
                    ->numeric()
                    ->prefix('R$')
                    ->required(),
                Forms\Components\TextInput::make('commission_rate')
                    ->label('Taxa (%)')
                    ->numeric()
                    ->suffix('%')
                    ->required(),
                Forms\Components\TextInput::make('commission_amount')
                    ->label('Valor da Comissao')
                    ->numeric()
                    ->prefix('R$')
                    ->required(),
                Forms\Components\Select::make('status')
                    ->label('Status')
                    ->options([
                        'pending'   => 'Pendente',
                        'approved'  => 'Aprovada',
                        'paid'      => 'Paga',
                        'cancelled' => 'Cancelada',
                    ])
                    ->required()
                    ->default('pending'),
                Forms\Components\Textarea::make('notes')
                    ->label('Observacoes')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('affiliate.user.name')
                    ->label('Afiliado')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('gross_amount')
                    ->label('Valor Bruto')
                    ->money('BRL')
                    ->sortable(),
                Tables\Columns\TextColumn::make('commission_rate')
                    ->label('Taxa')
                    ->suffix('%')
                    ->sortable(),
                Tables\Columns\TextColumn::make('commission_amount')
                    ->label('Comissao')
                    ->money('BRL')
                    ->sortable()
                    ->color('success'),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'pending',
                        'info'    => 'approved',
                        'success' => 'paid',
                        'danger'  => 'cancelled',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending'   => 'Pendente',
                        'approved'  => 'Aprovada',
                        'paid'      => 'Paga',
                        'cancelled' => 'Cancelada',
                        default     => $state,
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending'   => 'Pendente',
                        'approved'  => 'Aprovada',
                        'paid'      => 'Paga',
                        'cancelled' => 'Cancelada',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Editar'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('approve')
                        ->label('Aprovar selecionadas')
                        ->icon('heroicon-o-check-circle')
                        ->color('info')
                        ->requiresConfirmation()
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records): void {
                            $records->each(function (AffiliateCommission $record): void {
                                if ($record->status === 'pending') {
                                    $record->update(['status' => 'approved']);
                                }
                            });
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('mark_paid')
                        ->label('Marcar como pagas')
                        ->icon('heroicon-o-banknotes')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records): void {
                            $records->each(function (AffiliateCommission $record): void {
                                if ($record->status === 'approved') {
                                    $record->update([
                                        'status'  => 'paid',
                                        'paid_at' => Carbon::now(),
                                    ]);
                                    if ($record->affiliate) {
                                        $record->affiliate->increment('total_earned', $record->commission_amount);
                                    }
                                }
                            });
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make()->label('Excluir'),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListAffiliateCommissions::route('/'),
            'create' => Pages\CreateAffiliateCommission::route('/create'),
            'edit'   => Pages\EditAffiliateCommission::route('/{record}/edit'),
        ];
    }
}
