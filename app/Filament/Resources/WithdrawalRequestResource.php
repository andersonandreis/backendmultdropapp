<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WithdrawalRequestResource\Pages;
use App\Models\WithdrawalRequest;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Actions\Action;
use Filament\Forms\Components\Textarea as FormsTextarea;
use Illuminate\Database\Eloquent\Builder;

class WithdrawalRequestResource extends Resource
{
    protected static ?string $model = WithdrawalRequest::class;
    protected static ?string $slug = 'saques';

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationGroup = 'Financeiro';
    protected static ?string $navigationLabel = 'Solicitações de Saque';
    protected static ?string $modelLabel = 'Solicitação de Saque';
    protected static ?string $pluralModelLabel = 'Solicitações de Saque';


    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery();
        if (auth()->user()?->role === 'supplier') {
            $supplierId = auth()->user()->supplier?->id;
            if ($supplierId) {
                $query->where('producer_id', $supplierId);
            }
        }
        return $query;
    }
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('producer_id')
                    ->relationship('producer', 'company_name')
                    ->required()
                    ->disabledOn('edit')
                    ->label('Produtor / Fornecedor'),
                Forms\Components\Select::make('warehouse_id')
                    ->relationship('warehouse', 'company_name')
                    ->required()
                    ->disabledOn('edit')
                    ->label('Armazém'),
                Forms\Components\TextInput::make('amount')
                    ->label('Valor Solicitado')
                    ->required()
                    ->numeric()
                    ->prefix('R$')
                    ->disabledOn('edit'),
                Forms\Components\TextInput::make('pix_key')
                    ->label('Chave Pix')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Pendente',
                        'approved' => 'Aprovado',
                        'rejected' => 'Rejeitado',
                        'paid' => 'Pago',
                    ])
                    ->required()
                    ->default('pending'),
                Forms\Components\Textarea::make('rejection_reason')
                    ->label('Motivo da Rejeição')
                    ->columnSpanFull()
                    ->maxLength(65535),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('producer.company_name')->label('Produtor')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('warehouse.company_name')->label('Armazém')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('amount')->label('Valor')->money('BRL')->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'paid' => 'success',
                        'approved' => 'info',
                        'rejected' => 'danger',
                        'pending' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('created_at')->label('Solicitado em')->dateTime()->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Editar'),

                Action::make('aprovar')
                    ->label('Aprovar')
                    ->icon('heroicon-o-check-circle')
                    ->color('info')
                    ->visible(fn (\App\Models\WithdrawalRequest $record) => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->modalHeading('Aprovar saque?')
                    ->modalDescription('O produtor sera notificado que o saque foi aprovado.')
                    ->action(fn (\App\Models\WithdrawalRequest $record) => $record->update([
                        'status'      => 'approved',
                        'approved_at' => now(),
                    ])),

                Action::make('marcar_pago')
                    ->label('Marcar como Pago')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (\App\Models\WithdrawalRequest $record) => $record->status === 'approved')
                    ->requiresConfirmation()
                    ->modalHeading('Confirmar pagamento?')
                    ->modalDescription('Esta acao marca o saque como efetivamente pago via PIX.')
                    ->action(fn (\App\Models\WithdrawalRequest $record) => $record->update([
                        'status'  => 'paid',
                        'paid_at' => now(),
                    ])),

                Action::make('rejeitar')
                    ->label('Rejeitar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (\App\Models\WithdrawalRequest $record) => $record->status === 'pending')
                    ->form([
                        FormsTextarea::make('rejection_reason')
                            ->label('Motivo da rejeicao')
                            ->required()
                            ->minLength(10)
                            ->rows(3),
                    ])
                    ->action(fn (\App\Models\WithdrawalRequest $record, array $data) => $record->update([
                        'status'           => 'rejected',
                        'rejection_reason' => $data['rejection_reason'],
                    ])),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('Excluir'),
                ]),
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
            'index' => Pages\ListWithdrawalRequests::route('/'),
            'create' => Pages\CreateWithdrawalRequest::route('/create'),
            'edit' => Pages\EditWithdrawalRequest::route('/{record}/edit'),
        ];
    }
}
