<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductBundleResource\Pages;
use App\Models\Product;
use App\Models\ProductBundle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * NOV-133 / MES-046-E: Gerencia kits/bundles — define quais produtos compõem um kit.
 * Campos expandidos: preço, estoque, SKU, imagens (migrado do legado via bundles:import-legacy).
 */
class ProductBundleResource extends Resource
{
    protected static ?string $model = ProductBundle::class;
    protected static ?string $slug = 'kits-bundles';
    protected static ?string $modelLabel = 'Kit / Bundle';
    protected static ?string $pluralModelLabel = 'Kits & Bundles';
    protected static ?string $navigationIcon = 'heroicon-o-squares-plus';
    protected static ?string $navigationGroup = 'Catálogo';
    protected static ?int $navigationSort = 15;

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identificação do Kit')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nome do kit')
                        ->maxLength(500)
                        ->columnSpan(2),
                    Forms\Components\TextInput::make('sku')
                        ->label('SKU')
                        ->maxLength(60),
                    Forms\Components\TextInput::make('ean')
                        ->label('EAN')
                        ->maxLength(25),
                ])
                ->columns(2),

            Forms\Components\Section::make('Preço e Estoque')
                ->schema([
                    Forms\Components\TextInput::make('price')
                        ->label('Preço (R$)')
                        ->numeric()
                        ->prefix('R$')
                        ->minValue(0),
                    Forms\Components\TextInput::make('stock')
                        ->label('Estoque')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->helperText('Estoque do kit como unidade vendável'),
                    Forms\Components\TextInput::make('weight')
                        ->label('Peso (kg)')
                        ->numeric()
                        ->minValue(0),
                ])
                ->columns(3),

            Forms\Components\Section::make('Composição do Kit')
                ->schema([
                    Forms\Components\Select::make('component_product_id')
                        ->label('Produto componente')
                        ->relationship('component', 'name')
                        ->searchable()
                        ->preload()
                        ->helperText('Para kits com múltiplos componentes, cada linha é um registro separado com o mesmo SKU de kit'),
                    Forms\Components\TextInput::make('qty')
                        ->label('Quantidade do componente')
                        ->numeric()
                        ->minValue(1)
                        ->default(1)
                        ->required(),
                    Forms\Components\TextInput::make('legacy_kit_id')
                        ->label('ID Legado (sku_pai_kit)')
                        ->numeric()
                        ->disabled()
                        ->helperText('Preenchido automaticamente na importação do legado'),
                ])
                ->columns(3),

            Forms\Components\Section::make('Imagem de Capa')
                ->schema([
                    Forms\Components\TextInput::make('cover_image_url')
                        ->label('URL da imagem principal')
                        ->url()
                        ->columnSpan(2),
                    Forms\Components\Toggle::make('is_active')
                        ->label('Kit ativo')
                        ->default(true),
                ])
                ->columns(2),

            Forms\Components\Section::make('Descrição')
                ->schema([
                    Forms\Components\Textarea::make('description')
                        ->label('Descrição do kit')
                        ->rows(3)
                        ->columnSpan(2),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('cover_image_url')
                    ->label('')
                    ->width(48)
                    ->height(48)
                    ->defaultImageUrl(asset('images/no-image.png')),
                Tables\Columns\TextColumn::make('name')
                    ->label('Nome do Kit')
                    ->searchable()
                    ->sortable()
                    ->limit(60)
                    ->tooltip(fn ($record) => $record->name),
                Tables\Columns\TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('price')
                    ->label('Preço')
                    ->money('BRL')
                    ->sortable(),
                Tables\Columns\TextColumn::make('stock')
                    ->label('Estoque')
                    ->sortable()
                    ->color(fn ($state) => $state > 0 ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('component.name')
                    ->label('Componente')
                    ->searchable()
                    ->limit(40),
                Tables\Columns\TextColumn::make('qty')
                    ->label('Qtd'),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean(),
                Tables\Columns\TextColumn::make('legacy_kit_id')
                    ->label('ID Leg.')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Atualizado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status')
                    ->trueLabel('Apenas ativos')
                    ->falseLabel('Apenas inativos'),
                Tables\Filters\Filter::make('tem_preco')
                    ->label('Com preço')
                    ->query(fn (Builder $q) => $q->whereNotNull('price')->where('price', '>', 0)),
                Tables\Filters\Filter::make('sem_componente')
                    ->label('Sem componente mapeado')
                    ->query(fn (Builder $q) => $q->whereNull('component_product_id')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('sku');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListProductBundles::route('/'),
            'create' => Pages\CreateProductBundle::route('/create'),
            'edit'   => Pages\EditProductBundle::route('/{record}/edit'),
        ];
    }
}
