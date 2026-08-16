<?php

namespace App\Filament\App\Resources\Drop;

use App\Filament\App\Resources\Drop\DropSupplierOrderResource\Pages;
use App\Models\Drop\DropSupplierOrder;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DropSupplierOrderResource extends Resource
{
    protected static ?string $model = DropSupplierOrder::class;
    protected static ?string $slug = 'drop/compras-fornecedor';
    protected static ?string $navigationIcon = 'heroicon-o-truck';
    protected static ?string $navigationGroup = 'Drop Internacional';
    protected static bool $shouldRegisterNavigation = false;
    protected static ?int $navigationSort = 4;
    protected static ?string $navigationLabel = 'Compras no Fornecedor';
    protected static ?string $modelLabel = 'Compra no Fornecedor';
    protected static ?string $pluralModelLabel = 'Compras no Fornecedor';

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
            return $query->whereHas('dropOrder', function (Builder $q) use ($clientId) {
                $q->where('client_id', $clientId);
            });
        }

        return $query->whereRaw('1 = 0');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Compra no Fornecedor')
                    ->schema([
                        Forms\Components\Select::make('supplier_slug')
                            ->label('Fornecedor')
                            ->options([
                                'aliexpress' => 'AliExpress',
                                'cj'         => 'CJ Dropshipping',
                                'zendrop'    => 'Zendrop',
                                'outro'      => 'Outro',
                            ])
                            ->required(),
                        Forms\Components\TextInput::make('product_url')
                            ->label('URL do Produto')
                            ->url()
                            ->maxLength(500),
                        Forms\Components\TextInput::make('variant_title')
                            ->label('Variante')
                            ->nullable()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('cost_paid_usd')
                            ->label('Custo Pago (USD)')
                            ->numeric()
                            ->prefix('$')
                            ->required(),
                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options([
                                'pending'           => 'Pendente',
                                'ordered'           => 'Pedido Feito',
                                'tracking_received' => 'Rastreio Recebido',
                                'shipped'           => 'Enviado',
                                'delivered'         => 'Entregue',
                                'failed'            => 'Falhou',
                            ])
                            ->required(),
                    ])->columns(2),

                Forms\Components\Section::make('Rastreamento')
                    ->schema([
                        Forms\Components\TextInput::make('tracking_code')
                            ->label('Codigo de Rastreio')
                            ->nullable()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('tracking_carrier')
                            ->label('Transportadora')
                            ->nullable()
                            ->maxLength(255),
                        Forms\Components\Textarea::make('notes')
                            ->label('Observacoes')
                            ->nullable()
                            ->rows(3)
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('dropOrder.shopify_order_number')
                    ->label('Pedido Shopify')
                    ->searchable()
                    ->sortable()
                    ->prefix('#'),
                Tables\Columns\TextColumn::make('supplier_slug')
                    ->label('Fornecedor')
                    ->badge()
                    ->color('primary'),
                Tables\Columns\TextColumn::make('variant_title')
                    ->label('Variante')
                    ->limit(30),
                Tables\Columns\TextColumn::make('cost_paid_usd')
                    ->label('Custo Pago')
                    ->formatStateUsing(fn ($state) => '$ ' . number_format((float) $state, 2))
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending'           => 'gray',
                        'ordered'           => 'primary',
                        'tracking_received' => 'info',
                        'shipped'           => 'success',
                        'delivered'         => 'success',
                        'failed'            => 'danger',
                        default             => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending'           => 'Pendente',
                        'ordered'           => 'Pedido Feito',
                        'tracking_received' => 'Rastreio Recebido',
                        'shipped'           => 'Enviado',
                        'delivered'         => 'Entregue',
                        'failed'            => 'Falhou',
                        default             => $state,
                    }),
                Tables\Columns\TextColumn::make('tracking_code')
                    ->label('Rastreio')
                    ->copyable()
                    ->copyMessage('Codigo copiado!')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('tracking_carrier')
                    ->label('Transportadora')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('ordered_at')
                    ->label('Data do Pedido')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending'           => 'Pendente',
                        'ordered'           => 'Pedido Feito',
                        'tracking_received' => 'Rastreio Recebido',
                        'shipped'           => 'Enviado',
                        'delivered'         => 'Entregue',
                        'failed'            => 'Falhou',
                    ]),
                Tables\Filters\SelectFilter::make('supplier_slug')
                    ->label('Fornecedor')
                    ->options([
                        'aliexpress' => 'AliExpress',
                        'cj'         => 'CJ Dropshipping',
                        'zendrop'    => 'Zendrop',
                        'outro'      => 'Outro',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->iconButton()
                    ->tooltip('Editar'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([]),
            ])
            ->defaultSort('ordered_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDropSupplierOrders::route('/'),
            'edit'  => Pages\EditDropSupplierOrder::route('/{record}/edit'),
        ];
    }
}
