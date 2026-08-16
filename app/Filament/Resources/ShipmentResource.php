<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShipmentResource\Pages;
use App\Models\Shipment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ShipmentResource extends Resource
{
    protected static ?string $model = Shipment::class;
    protected static ?string $slug = 'remessas';
    protected static ?string $modelLabel = 'Remessa';
    protected static ?string $pluralModelLabel = 'Remessas';

    protected static ?string $navigationIcon = 'heroicon-o-truck';
    protected static ?string $navigationGroup = 'Estoque & Remessas';
    protected static ?string $navigationLabel = 'Remessas';
    protected static ?int $navigationSort = 6;

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function getEloquentQuery(): Builder
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
                Forms\Components\Section::make('Dados da Remessa')->schema([
                    Forms\Components\Select::make('producer_id')
                        ->relationship('producer', 'company_name')
                        ->required()->searchable()->label('Produtor / Remetente'),
                    Forms\Components\Select::make('warehouse_id')
                        ->relationship('warehouse', 'company_name')
                        ->required()->searchable()->label('Armazém de Destino'),
                    Forms\Components\TextInput::make('shipment_number')
                        ->label('Número da Remessa')
                        ->maxLength(255)
                        ->helperText('Deixe em branco para geração automática.'),
                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options([
                            'draft' => 'Rascunho',
                            'pending' => 'Pendente',
                            'in_transit' => 'Em Trânsito',
                            'arrived' => 'Chegou',
                            'processing' => 'Processando',
                            'completed' => 'Concluído',
                            'cancelled' => 'Cancelado',
                        ])
                        ->required()->default('draft'),
                ])->columns(2),

                Forms\Components\Section::make('Logística (NOV-126)')->schema([
                    Forms\Components\TextInput::make('carrier')->label('Transportadora')->maxLength(80),
                    Forms\Components\TextInput::make('tracking_code')->label('Código de rastreio')->maxLength(100),
                    Forms\Components\TextInput::make('box_count')->label('Quantidade de caixas')->numeric()->default(1),
                    Forms\Components\TextInput::make('declared_value')->label('Valor declarado')->numeric()->prefix('R$'),
                    Forms\Components\Select::make('marketplace')->label('Marketplace alvo')
                        ->options([
                            'mercadolivre' => 'Mercado Livre',
                            'shopee'       => 'Shopee',
                            'amazon'       => 'Amazon',
                            'b2w'          => 'B2W',
                            'magalu'       => 'Magalu',
                            'site'         => 'Site próprio',
                        ])->nullable(),
                ])->columns(2)->collapsed(),

                Forms\Components\Textarea::make('notes')
                    ->label('Observações')
                    ->columnSpanFull(),

                Forms\Components\Section::make('Itens da Remessa')->schema([
                    Forms\Components\Repeater::make('items')
                        ->relationship()
                        ->schema([
                            Forms\Components\Select::make('product_id')
                                ->relationship('product', 'name')
                                ->required()->searchable()->label('Produto'),
                            Forms\Components\TextInput::make('quantity')
                                ->label('Qtd. Esperada')->required()->numeric()->default(1),
                            Forms\Components\TextInput::make('quantity_received')
                                ->label('Qtd. Recebida')->numeric()->default(0),
                            Forms\Components\TextInput::make('label_code')
                                ->label('Código de etiqueta / barcode')->maxLength(255),
                            Forms\Components\TextInput::make('box_number')
                                ->label('Caixa')->numeric()->placeholder('1'),
                        ])->columns(3),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('shipment_number')->label('Nº Remessa')->searchable(),
                Tables\Columns\TextColumn::make('producer.company_name')->label('Remetente')->searchable(),
                Tables\Columns\TextColumn::make('warehouse.company_name')->label('Armazém')->searchable(),
                Tables\Columns\BadgeColumn::make('status')->label('Status')->colors([
                    'gray' => 'draft', 'warning' => 'pending', 'info' => 'in_transit',
                    'success' => ['arrived', 'completed'], 'danger' => 'cancelled',
                ]),
                Tables\Columns\TextColumn::make('carrier')->label('Transp.')->placeholder('—'),
                Tables\Columns\TextColumn::make('total_items')->label('Itens')->numeric(),
                Tables\Columns\TextColumn::make('total_checked')->label('Bipados')->numeric(),
                Tables\Columns\TextColumn::make('box_count')->label('Cxs')->numeric()->placeholder('1'),
                Tables\Columns\TextColumn::make('created_at')->label('Criado em')->dateTime()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'draft' => 'Rascunho', 'pending' => 'Pendente', 'in_transit' => 'Em Trânsito',
                    'arrived' => 'Chegou', 'processing' => 'Processando',
                    'completed' => 'Concluído', 'cancelled' => 'Cancelado',
                ]),
                SelectFilter::make('marketplace')->options([
                    'mercadolivre' => 'Mercado Livre', 'shopee' => 'Shopee',
                    'amazon' => 'Amazon', 'b2w' => 'B2W', 'magalu' => 'Magalu', 'site' => 'Site próprio',
                ]),
                Filter::make('created_between')->form([
                    Forms\Components\DatePicker::make('from')->label('De'),
                    Forms\Components\DatePicker::make('to')->label('Até'),
                ])->query(function (Builder $query, array $data): Builder {
                    return $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d));
                }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Editar'),
                Tables\Actions\Action::make('scanner')
                    ->label('Bipar')
                    ->icon('heroicon-o-qr-code')
                    ->color('info')
                    ->modalHeading(fn ($record) => 'Scanner — Remessa '.$record->shipment_number)
                    ->modalContent(fn ($record) => view('filament.modals.shipment-scanner', ['record' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar')
                    ->modalWidth('xl'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('Excluir'),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListShipments::route('/'),
            'create' => Pages\CreateShipment::route('/create'),
            'edit'   => Pages\EditShipment::route('/{record}/edit'),
        ];
    }
}
