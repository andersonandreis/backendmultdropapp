<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FiscalNoteResource\Pages;
use App\Models\FiscalNote;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

/**
 * NOV-166-E — Resource Filament para visualização de Notas Fiscais Eletrônicas.
 *
 * Somente leitura (canCreate=false, canEdit=false).
 * Supplier vê apenas suas notas via TenantSupplierScope.
 * Super_admin vê todas.
 */
class FiscalNoteResource extends Resource
{
    protected static ?string $model = FiscalNote::class;

    protected static ?string $navigationIcon  = 'heroicon-o-document-text';
    protected static ?string $navigationLabel = 'Notas Fiscais (NF-e)';
    protected static ?string $navigationGroup = 'Pedidos & Logística';
    protected static ?int    $navigationSort  = 99;
    protected static ?string $modelLabel      = 'Nota Fiscal';
    protected static ?string $pluralModelLabel = 'Notas Fiscais';

    // ─── Visibilidade ─────────────────────────────────────────────────────────

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier'], true);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->role === 'super_admin';
    }

    // ─── Form (não usado, mas obrigatório pelo contrato) ──────────────────────

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    // ─── Table ────────────────────────────────────────────────────────────────

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('order.order_number')
                    ->label('Pedido')
                    ->sortable()
                    ->searchable()
                    ->url(fn (FiscalNote $record): string => route('filament.admin.resources.orders.view', ['record' => $record->order_id]))
                    ->openUrlInNewTab(),

                BadgeColumn::make('source')
                    ->label('Origem')
                    ->colors([
                        'primary'   => 'bling_supplier',
                        'success'   => 'bling_seller',
                        'warning'   => 'mercadolivre',
                        'danger'    => 'shopee',
                    ])
                    ->formatStateUsing(fn (string $state): string => match($state) {
                        'bling_supplier' => 'Bling Fornecedor',
                        'bling_seller'   => 'Bling Seller',
                        'mercadolivre'   => 'Mercado Livre',
                        'shopee'         => 'Shopee',
                        default          => $state,
                    }),

                TextColumn::make('nf_number')
                    ->label('Nº NF')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('nf_key')
                    ->label('Chave')
                    ->limit(12)
                    ->tooltip(fn (FiscalNote $record): ?string => $record->nf_key)
                    ->searchable()
                    ->placeholder('—'),

                BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'issued',
                        'gray'    => 'cancelled',
                        'danger'  => 'error',
                    ])
                    ->formatStateUsing(fn (string $state): string => match($state) {
                        'pending'   => 'Pendente',
                        'issued'    => 'Emitida',
                        'cancelled' => 'Cancelada',
                        'error'     => 'Erro',
                        default     => $state,
                    }),

                TextColumn::make('value')
                    ->label('Valor')
                    ->money('BRL')
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('issued_at')
                    ->label('Emitida em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending'   => 'Pendente',
                        'issued'    => 'Emitida',
                        'cancelled' => 'Cancelada',
                        'error'     => 'Erro',
                    ]),

                SelectFilter::make('source')
                    ->label('Origem')
                    ->options([
                        'bling_supplier' => 'Bling Fornecedor',
                        'bling_seller'   => 'Bling Seller',
                        'mercadolivre'   => 'Mercado Livre',
                        'shopee'         => 'Shopee',
                    ]),
            ])
            ->defaultSort('id', 'desc')
            ->striped()
            ->emptyStateHeading('Nenhuma nota fiscal encontrada')
            ->emptyStateDescription('As notas fiscais aparecerão aqui conforme forem emitidas nos sistemas externos (Bling, Mercado Livre).');
    }

    // ─── Pages ────────────────────────────────────────────────────────────────

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFiscalNotes::route('/'),
        ];
    }
}
