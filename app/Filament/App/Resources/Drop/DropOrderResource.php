<?php

namespace App\Filament\App\Resources\Drop;

use App\Filament\App\Resources\Drop\DropOrderResource\Pages;
use App\Filament\App\Resources\Drop\DropOrderResource\RelationManagers;
use App\Models\Drop\DropOrder;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DropOrderResource extends Resource
{
    protected static ?string $model = DropOrder::class;
    protected static ?string $slug = 'drop/pedidos';
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationGroup = 'Drop Internacional';
    protected static bool $shouldRegisterNavigation = false;
    protected static ?int $navigationSort = 3;
    protected static ?string $navigationLabel = 'Pedidos Drop';
    protected static ?string $modelLabel = 'Pedido Drop';
    protected static ?string $pluralModelLabel = 'Pedidos Drop';

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

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informacoes do Pedido')
                    ->schema([
                        Forms\Components\TextInput::make('shopify_order_number')
                            ->label('Numero do Pedido Shopify')
                            ->disabled(),
                        Forms\Components\TextInput::make('status')
                            ->label('Status')
                            ->disabled(),
                        Forms\Components\TextInput::make('total_amount')
                            ->label('Total')
                            ->disabled(),
                        Forms\Components\TextInput::make('currency')
                            ->label('Moeda')
                            ->disabled(),
                        Forms\Components\TextInput::make('created_at')
                            ->label('Data do Pedido')
                            ->disabled(),
                    ])->columns(2),

                Forms\Components\Section::make('Cliente')
                    ->schema([
                        Forms\Components\TextInput::make('customer_name')
                            ->label('Nome')
                            ->disabled(),
                        Forms\Components\TextInput::make('customer_email')
                            ->label('Email')
                            ->disabled(),
                        Forms\Components\TextInput::make('customer_phone')
                            ->label('Telefone')
                            ->disabled(),
                    ])->columns(2),

                Forms\Components\Section::make('Rastreamento de Marketing')
                    ->schema([
                        Forms\Components\TextInput::make('traffic_source')
                            ->label('Fonte de Trafego')
                            ->disabled(),
                        Forms\Components\TextInput::make('utm_source')
                            ->label('UTM Source')
                            ->disabled(),
                        Forms\Components\TextInput::make('utm_campaign')
                            ->label('UTM Campaign')
                            ->disabled(),
                        Forms\Components\TextInput::make('utm_medium')
                            ->label('UTM Medium')
                            ->disabled(),
                    ])->columns(2),

                Forms\Components\Section::make('Lucratividade')
                    ->schema([
                        Forms\Components\TextInput::make('profit_estimate')
                            ->label('Lucro Estimado')
                            ->disabled(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('shopify_order_number')
                    ->label('Pedido')
                    ->searchable()
                    ->sortable()
                    ->prefix('#'),
                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_amount')
                    ->label('Total')
                    ->formatStateUsing(fn ($state) => '$ ' . number_format((float) $state, 2))
                    ->sortable(),
                Tables\Columns\TextColumn::make('currency')
                    ->label('Moeda')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending_payment'     => 'warning',
                        'payment_received'    => 'info',
                        'awaiting_supplier'   => 'gray',
                        'ordered_supplier'    => 'primary',
                        'awaiting_tracking'   => 'primary',
                        'shipped'             => 'success',
                        'in_transit'          => 'success',
                        'out_for_delivery'    => 'success',
                        'delivered'           => 'success',
                        'logistics_issue'     => 'danger',
                        'refunded'            => 'warning',
                        'cancelled'           => 'danger',
                        default               => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending_payment'     => 'Aguardando Pagamento',
                        'payment_received'    => 'Pago',
                        'awaiting_supplier'   => 'Aguardando Fornecedor',
                        'ordered_supplier'    => 'Pedido ao Fornecedor',
                        'awaiting_tracking'   => 'Aguardando Rastreio',
                        'shipped'             => 'Enviado',
                        'in_transit'          => 'Em Transito',
                        'out_for_delivery'    => 'Em Entrega',
                        'delivered'           => 'Entregue',
                        'logistics_issue'     => 'Problema Logistico',
                        'refunded'            => 'Reembolsado',
                        'cancelled'           => 'Cancelado',
                        default               => $state,
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('traffic_source')
                    ->label('Fonte')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('profit_estimate')
                    ->label('Lucro Est.')
                    ->formatStateUsing(fn ($state) => '$ ' . number_format((float) $state, 2))
                    ->color('success')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending_payment'     => 'Aguardando Pagamento',
                        'payment_received'    => 'Pago',
                        'awaiting_supplier'   => 'Aguardando Fornecedor',
                        'ordered_supplier'    => 'Pedido ao Fornecedor',
                        'awaiting_tracking'   => 'Aguardando Rastreio',
                        'shipped'             => 'Enviado',
                        'in_transit'          => 'Em Transito',
                        'out_for_delivery'    => 'Em Entrega',
                        'delivered'           => 'Entregue',
                        'logistics_issue'     => 'Problema Logistico',
                        'refunded'            => 'Reembolsado',
                        'cancelled'           => 'Cancelado',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->iconButton()
                    ->tooltip('Ver detalhes'),
                Tables\Actions\Action::make('buy_supplier')
                    ->label('Comprar no Fornecedor')
                    ->icon('heroicon-o-shopping-cart')
                    ->color('primary')
                    ->visible(fn (DropOrder $record): bool => $record->status === 'payment_received')
                    ->form([
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
                            ->required(),
                        Forms\Components\TextInput::make('variant_title')
                            ->label('Variante')
                            ->nullable(),
                        Forms\Components\TextInput::make('cost_paid_usd')
                            ->label('Custo Pago (USD)')
                            ->numeric()
                            ->prefix('$')
                            ->required(),
                    ])
                    ->action(function (DropOrder $record, array $data) {
                        try {
                            \App\Models\Drop\DropSupplierOrder::create([
                                'drop_order_id'  => $record->id,
                                'supplier_slug'  => $data['supplier_slug'],
                                'product_url'    => $data['product_url'],
                                'variant_title'  => $data['variant_title'] ?? null,
                                'cost_paid_usd'  => $data['cost_paid_usd'],
                                'status'         => 'ordered',
                                'ordered_at'     => now(),
                            ]);

                            $record->update(['status' => 'ordered_supplier']);

                            Notification::make()
                                ->title('Compra registrada!')
                                ->body('Pedido ao fornecedor registrado com sucesso.')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Erro ao registrar compra')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('register_tracking')
                    ->label('Registrar Rastreio')
                    ->icon('heroicon-o-map-pin')
                    ->color('info')
                    ->visible(fn (DropOrder $record): bool => in_array($record->status, ['ordered_supplier', 'awaiting_tracking']))
                    ->form([
                        Forms\Components\TextInput::make('tracking_code')
                            ->label('Codigo de Rastreio')
                            ->required(),
                        Forms\Components\TextInput::make('carrier')
                            ->label('Transportadora')
                            ->nullable(),
                    ])
                    ->action(function (DropOrder $record, array $data) {
                        try {
                            $supplierOrder = \App\Models\Drop\DropSupplierOrder::where('drop_order_id', $record->id)
                                ->latest()
                                ->first();

                            if ($supplierOrder) {
                                $supplierOrder->update([
                                    'tracking_code'    => $data['tracking_code'],
                                    'tracking_carrier' => $data['carrier'] ?? null,
                                    'status'           => 'tracking_received',
                                ]);
                            }

                            $record->update(['status' => 'shipped']);

                            Notification::make()
                                ->title('Rastreio registrado!')
                                ->body('Codigo de rastreio salvo com sucesso.')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Erro ao registrar rastreio')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\DropSupplierOrdersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDropOrders::route('/'),
            'view'  => Pages\ViewDropOrder::route('/{record}'),
        ];
    }
}
