<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClientKitResource\Pages;
use App\Filament\Resources\ClientKitResource\RelationManagers;
use App\Models\ClientKit;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * HUB-178: Kits reais que os clientes dropshippers montam com produtos
 * do catalogo (client_kits, 6.020 rows). Fonte usada pelo calculo de
 * custo dos pedidos - antes so existia painel /admin/kits-bundles que
 * lia product_bundles (vazio). Aqui listamos client_kits com filtro
 * por source_tenant (multdrop, mestoredrop, jtdrop, fornecefy,
 * seller.global, dropksr, hub).
 */
class ClientKitResource extends Resource
{
    protected static ?string $model = ClientKit::class;
    protected static ?string $slug = 'client-kits';
    protected static ?string $modelLabel = 'Kit do Cliente';
    protected static ?string $pluralModelLabel = 'Kits dos Clientes';
    protected static ?string $navigationIcon = 'heroicon-o-cube';
    protected static ?string $navigationGroup = 'Catalogo';
    protected static ?int $navigationSort = 16;

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identificacao')
                ->schema([
                    Forms\Components\Select::make('client_id')
                        ->label('Cliente')
                        ->relationship('client', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),
                    Forms\Components\TextInput::make('name')
                        ->label('Nome do kit')
                        ->maxLength(200)
                        ->required()
                        ->columnSpan(2),
                    Forms\Components\TextInput::make('sku')
                        ->label('SKU')
                        ->maxLength(100),
                    Forms\Components\Select::make('source_tenant')
                        ->label('Tenant de origem')
                        ->options([
                            'hub' => 'hub (NovoHubAI)',
                            'multdrop' => 'multdrop',
                            'mestoredrop' => 'mestoredrop',
                            'jtdrop' => 'jtdrop',
                            'fornecefy' => 'fornecefy',
                            'seller.global' => 'seller.global',
                            'dropksr' => 'dropksr',
                        ])
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('Definido no momento da criacao - nao editavel aqui'),
                ])
                ->columns(2),

            Forms\Components\Section::make('Preco e Status')
                ->schema([
                    Forms\Components\TextInput::make('price')
                        ->label('Preco (R$)')
                        ->numeric()
                        ->prefix('R$')
                        ->minValue(0),
                    Forms\Components\Toggle::make('is_active')
                        ->label('Kit ativo')
                        ->default(true),
                    Forms\Components\TextInput::make('legacy_kit_id')
                        ->label('ID Legado')
                        ->numeric()
                        ->disabled()
                        ->dehydrated(false),
                ])
                ->columns(3),

            Forms\Components\Section::make('Descricao')
                ->schema([
                    Forms\Components\Textarea::make('description')
                        ->label('Descricao')
                        ->rows(3)
                        ->columnSpan(2),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('source_tenant')
                    ->label('Tenant')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'multdrop' => 'success',
                        'mestoredrop' => 'warning',
                        'jtdrop' => 'info',
                        'fornecefy' => 'primary',
                        'seller.global' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('client.name')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable()
                    ->limit(30)
                    ->tooltip(fn ($record) => $record->client?->name),
                Tables\Columns\TextColumn::make('name')
                    ->label('Nome do Kit')
                    ->searchable()
                    ->limit(60)
                    ->tooltip(fn ($record) => $record->name),
                Tables\Columns\TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('price')
                    ->label('Preco')
                    ->money('BRL')
                    ->sortable(),
                Tables\Columns\TextColumn::make('items_count')
                    ->label('Itens')
                    ->counts('items')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean(),
                Tables\Columns\TextColumn::make('legacy_kit_id')
                    ->label('ID Leg.')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Criado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('source_tenant')
                    ->label('Tenant')
                    ->options([
                        'multdrop' => 'multdrop',
                        'mestoredrop' => 'mestoredrop',
                        'jtdrop' => 'jtdrop',
                        'fornecefy' => 'fornecefy',
                        'seller.global' => 'seller.global',
                        'dropksr' => 'dropksr',
                        'hub' => 'hub',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status')
                    ->trueLabel('Apenas ativos')
                    ->falseLabel('Apenas inativos'),
                Tables\Filters\Filter::make('com_preco')
                    ->label('Com preco')
                    ->query(fn (Builder $q) => $q->whereNotNull('price')->where('price', '>', 0)),
                Tables\Filters\Filter::make('sem_itens')
                    ->label('Sem itens (vazios)')
                    ->query(fn (Builder $q) => $q->whereDoesntHave('items')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListClientKits::route('/'),
            'create' => Pages\CreateClientKit::route('/create'),
            'edit'   => Pages\EditClientKit::route('/{record}/edit'),
        ];
    }
}
