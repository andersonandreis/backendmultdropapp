<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CatalogDiscountRuleResource\Pages;
use App\Models\CatalogDiscountRule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CatalogDiscountRuleResource extends Resource
{
    protected static ?string $model = CatalogDiscountRule::class;
    protected static ?string $slug = 'catalogo-descontos';

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';
    protected static ?string $navigationGroup = 'Catálogo';
    protected static ?string $navigationLabel = 'Descontos por Catálogo';
    protected static ?string $modelLabel = 'Regra de Desconto';
    protected static ?string $pluralModelLabel = 'Descontos por Catálogo';
    protected static ?int $navigationSort = 30;

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user?->role === 'supplier' && $user->supplier) {
            $query->where('supplier_id', $user->supplier->id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Nome da regra')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('catalog_id')
                ->label('ID do catálogo')
                ->numeric()
                ->nullable()
                ->helperText('Deixe em branco para aplicar em todos os catálogos do fornecedor.'),

            Forms\Components\TextInput::make('min_qty')
                ->label('Quantidade mínima')
                ->numeric()
                ->default(1)
                ->required()
                ->minValue(1),

            Forms\Components\TextInput::make('max_qty')
                ->label('Quantidade máxima (opcional)')
                ->numeric()
                ->nullable()
                ->helperText('Em branco = sem limite superior.'),

            Forms\Components\TextInput::make('discount_pct')
                ->label('Desconto (%)')
                ->numeric()
                ->suffix('%')
                ->required()
                ->minValue(0)
                ->maxValue(100),

            Forms\Components\DatePicker::make('starts_at')
                ->label('Início (opcional)')
                ->displayFormat('d/m/Y'),

            Forms\Components\DatePicker::make('ends_at')
                ->label('Fim (opcional)')
                ->displayFormat('d/m/Y'),

            Forms\Components\Toggle::make('active')
                ->label('Ativo')
                ->default(true),

            Forms\Components\Hidden::make('supplier_id')
                ->default(fn () => auth()->user()?->supplier?->id),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('catalog_id')
                    ->label('Catálogo')
                    ->placeholder('Todos'),

                Tables\Columns\TextColumn::make('min_qty')
                    ->label('Qtd min')
                    ->sortable(),

                Tables\Columns\TextColumn::make('max_qty')
                    ->label('Qtd max')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('discount_pct')
                    ->label('Desconto')
                    ->suffix('%')
                    ->sortable(),

                Tables\Columns\IconColumn::make('active')
                    ->label('Ativo')
                    ->boolean(),

                Tables\Columns\TextColumn::make('starts_at')
                    ->label('Início')
                    ->date('d/m/Y')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('ends_at')
                    ->label('Fim')
                    ->date('d/m/Y')
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('active')->label('Status'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCatalogDiscountRules::route('/'),
            'create' => Pages\CreateCatalogDiscountRule::route('/create'),
            'edit'   => Pages\EditCatalogDiscountRule::route('/{record}/edit'),
        ];
    }
}
