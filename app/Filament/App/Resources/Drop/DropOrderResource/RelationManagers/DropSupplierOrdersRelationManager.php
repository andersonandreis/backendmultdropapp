<?php

namespace App\Filament\App\Resources\Drop\DropOrderResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class DropSupplierOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'supplierOrders';
    protected static ?string $title = 'Compras no Fornecedor';
    protected static ?string $label = 'Compra';
    protected static ?string $pluralLabel = 'Compras';

    public function form(Form $form): Form
    {
        return $form
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
                    ->default('ordered')
                    ->required(),
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
                    ->rows(2)
                    ->columnSpanFull(),
            ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('supplier_slug')
                    ->label('Fornecedor')
                    ->badge()
                    ->color('primary'),
                Tables\Columns\TextColumn::make('variant_title')
                    ->label('Variante')
                    ->limit(30)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('cost_paid_usd')
                    ->label('Custo Pago')
                    ->formatStateUsing(fn ($state) => '$ ' . number_format((float) $state, 2)),
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
                Tables\Columns\TextColumn::make('ordered_at')
                    ->label('Data')
                    ->date('d/m/Y'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Registrar Compra'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->iconButton(),
                Tables\Actions\DeleteAction::make()
                    ->iconButton(),
            ]);
    }
}
