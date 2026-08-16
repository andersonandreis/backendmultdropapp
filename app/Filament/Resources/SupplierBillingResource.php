<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierBillingResource\Pages;
use App\Models\SupplierBillingCycle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** NOV-124 — Faturamento WL self-service (supplier vê suas cobranças). */
class SupplierBillingResource extends Resource
{
    protected static ?string $model = SupplierBillingCycle::class;
    protected static ?string $slug = 'faturamento';
    protected static ?string $modelLabel = 'Fatura';
    protected static ?string $pluralModelLabel = 'Faturamento da Plataforma';
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationGroup = 'Financeiro';
    protected static ?int $navigationSort = 50;

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->role === 'super_admin';
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return auth()->user()?->role === 'super_admin';
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return auth()->user()?->role === 'super_admin';
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = static::getEloquentQuery()->whereIn('status', ['open', 'overdue'])->count();
        return $pending > 0 ? (string) $pending : null;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Período')->schema([
                Forms\Components\Select::make('supplier_id')
                    ->relationship('supplier', 'company_name')->required()->searchable(),
                Forms\Components\DatePicker::make('period_start')->required(),
                Forms\Components\DatePicker::make('period_end')->required(),
                Forms\Components\DatePicker::make('due_date')->required(),
            ])->columns(2),

            Forms\Components\Section::make('Valores')->schema([
                Forms\Components\TextInput::make('clients_active')->numeric()->default(0),
                Forms\Components\TextInput::make('orders_count')->numeric()->default(0),
                Forms\Components\TextInput::make('amount_users')->numeric()->prefix('R$')->default(0),
                Forms\Components\TextInput::make('amount_orders')->numeric()->prefix('R$')->default(0),
                Forms\Components\TextInput::make('amount_extra')->numeric()->prefix('R$')->default(0),
                Forms\Components\TextInput::make('amount_total')->numeric()->prefix('R$')->required(),
            ])->columns(2),

            Forms\Components\Section::make('Cobrança')->schema([
                Forms\Components\Select::make('status')->options([
                    'draft' => 'Rascunho', 'open' => 'Aberta', 'paid' => 'Paga',
                    'overdue' => 'Atrasada', 'cancelled' => 'Cancelada',
                ])->required(),
                Forms\Components\TextInput::make('payment_method'),
                Forms\Components\TextInput::make('payment_url')->columnSpanFull(),
                Forms\Components\Textarea::make('pix_qr_code')->rows(3)->columnSpanFull(),
                Forms\Components\DateTimePicker::make('paid_at'),
                Forms\Components\Textarea::make('notes')->rows(2)->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(SupplierBillingCycle::query()->with('supplier'))
            ->columns([
                Tables\Columns\TextColumn::make('period_start')->label('Início')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('period_end')->label('Fim')->date('d/m/Y'),
                Tables\Columns\TextColumn::make('due_date')->label('Vencimento')->date('d/m/Y')
                    ->color(fn ($record) => $record->isOverdue() ? 'danger' : 'gray'),
                Tables\Columns\TextColumn::make('supplier.company_name')->label('Fornecedor')
                    ->visible(fn () => auth()->user()?->role === 'super_admin'),
                Tables\Columns\TextColumn::make('clients_active')->label('Lojistas')->numeric(),
                Tables\Columns\TextColumn::make('orders_count')->label('Pedidos')->numeric(),
                Tables\Columns\TextColumn::make('amount_total')->label('Total')->money('BRL')->sortable(),
                Tables\Columns\BadgeColumn::make('status')->colors([
                    'gray'    => 'draft',
                    'warning' => 'open',
                    'success' => 'paid',
                    'danger'  => 'overdue',
                    'gray'    => 'cancelled',
                ]),
            ])
            ->defaultSort('period_start', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    'draft' => 'Rascunho', 'open' => 'Aberta', 'paid' => 'Paga',
                    'overdue' => 'Atrasada', 'cancelled' => 'Cancelada',
                ]),
            ])
            ->actions([
                Tables\Actions\Action::make('verPagar')
                    ->label(fn ($record) => $record->isPaid() ? 'Detalhes' : 'Pagar')
                    ->icon(fn ($record) => $record->isPaid() ? 'heroicon-o-check' : 'heroicon-o-credit-card')
                    ->color(fn ($record) => $record->isPaid() ? 'success' : 'warning')
                    ->modalHeading(fn ($record) => 'Fatura '.$record->period_start->format('d/m/Y'))
                    ->modalContent(fn ($record) => view('filament.modals.supplier-billing-detail', ['record' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar'),
                Tables\Actions\EditAction::make()->visible(fn () => auth()->user()?->role === 'super_admin'),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSupplierBilling::route('/'),
            'create' => Pages\CreateSupplierBilling::route('/create'),
            'edit'   => Pages\EditSupplierBilling::route('/{record}/edit'),
        ];
    }
}
