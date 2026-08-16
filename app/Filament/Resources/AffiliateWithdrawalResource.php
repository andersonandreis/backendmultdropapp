<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AffiliateWithdrawalResource\Pages;
use App\Models\AffiliateWithdrawal;
use App\Models\Affiliate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class AffiliateWithdrawalResource extends Resource
{
    protected static ?string $model = AffiliateWithdrawal::class;

    protected static ?string $slug = 'saques-afiliados';
    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';
    protected static ?string $navigationGroup = 'Afiliados';
    protected static ?string $navigationLabel = 'Saques';
    protected static ?string $modelLabel = 'Saque';
    protected static ?string $pluralModelLabel = 'Saques';
    protected static ?int $navigationSort = 3;

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
                Forms\Components\TextInput::make('amount')
                    ->label('Valor')
                    ->numeric()
                    ->prefix('R$')
                    ->required(),
                Forms\Components\TextInput::make('pix_key')
                    ->label('Chave Pix')
                    ->maxLength(255),
                Forms\Components\Select::make('pix_type')
                    ->label('Tipo de Chave Pix')
                    ->options([
                        'cpf'    => 'CPF',
                        'cnpj'   => 'CNPJ',
                        'email'  => 'E-mail',
                        'phone'  => 'Celular',
                        'random' => 'Chave Aleatoria',
                    ]),
                Forms\Components\Select::make('status')
                    ->label('Status')
                    ->options([
                        'pending'    => 'Pendente',
                        'processing' => 'Processando',
                        'paid'       => 'Pago',
                        'rejected'   => 'Rejeitado',
                    ])
                    ->required()
                    ->default('pending'),
                Forms\Components\Textarea::make('rejection_reason')
                    ->label('Motivo da Rejeicao')
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
                Tables\Columns\TextColumn::make('amount')
                    ->label('Valor')
                    ->money('BRL')
                    ->sortable(),
                Tables\Columns\TextColumn::make('pix_key')
                    ->label('Chave Pix')
                    ->searchable(),
                Tables\Columns\TextColumn::make('pix_type')
                    ->label('Tipo Pix')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'cpf'    => 'CPF',
                        'cnpj'   => 'CNPJ',
                        'email'  => 'E-mail',
                        'phone'  => 'Celular',
                        'random' => 'Aleatoria',
                        default  => $state ?? '-',
                    }),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'pending',
                        'info'    => 'processing',
                        'success' => 'paid',
                        'danger'  => 'rejected',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending'    => 'Pendente',
                        'processing' => 'Processando',
                        'paid'       => 'Pago',
                        'rejected'   => 'Rejeitado',
                        default      => $state,
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Solicitado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('processed_at')
                    ->label('Processado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending'    => 'Pendente',
                        'processing' => 'Processando',
                        'paid'       => 'Pago',
                        'rejected'   => 'Rejeitado',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('process')
                    ->label('Processar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->requiresConfirmation()
                    ->visible(fn (AffiliateWithdrawal $record): bool => $record->status === 'pending')
                    ->action(fn (AffiliateWithdrawal $record) => $record->update(['status' => 'processing'])),
                Tables\Actions\Action::make('mark_paid')
                    ->label('Marcar como pago')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (AffiliateWithdrawal $record): bool => $record->status === 'processing')
                    ->action(function (AffiliateWithdrawal $record): void {
                        $record->update([
                            'status'       => 'paid',
                            'processed_at' => Carbon::now(),
                        ]);
                        if ($record->affiliate) {
                            $record->affiliate->increment('total_withdrawn', $record->amount);
                        }
                    }),
                Tables\Actions\Action::make('reject')
                    ->label('Rejeitar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (AffiliateWithdrawal $record): bool => in_array($record->status, ['pending', 'processing']))
                    ->form([
                        Forms\Components\Textarea::make('rejection_reason')
                            ->label('Motivo da Rejeicao')
                            ->required()
                            ->minLength(5),
                    ])
                    ->action(function (AffiliateWithdrawal $record, array $data): void {
                        $record->update([
                            'status'           => 'rejected',
                            'rejection_reason' => $data['rejection_reason'],
                        ]);
                    }),
                Tables\Actions\EditAction::make()->label('Editar'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListAffiliateWithdrawals::route('/'),
            'create' => Pages\CreateAffiliateWithdrawal::route('/create'),
            'edit'   => Pages\EditAffiliateWithdrawal::route('/{record}/edit'),
        ];
    }
}
