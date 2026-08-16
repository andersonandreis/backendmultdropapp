<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierBannerResource\Pages;
use App\Models\SupplierBanner;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SupplierBannerResource extends Resource
{
    protected static ?string $model = SupplierBanner::class;
    protected static ?string $slug = 'banners';

    protected static ?string $navigationIcon = 'heroicon-o-photo';
    protected static ?string $navigationGroup = 'Configurações';
    protected static ?string $navigationLabel = 'Banners';
    protected static ?string $modelLabel = 'Banner';
    protected static ?string $pluralModelLabel = 'Banners';
    protected static ?int $navigationSort = 10;

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
            Forms\Components\TextInput::make('title')
                ->label('Título')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('url')
                ->label('URL de destino')
                ->url()
                ->maxLength(500)
                ->helperText('Para onde o banner leva quando clicado (opcional)'),

            Forms\Components\FileUpload::make('image_url')
                ->label('Imagem do banner')
                ->disk('public')
                ->directory('banners')
                ->image()
                ->imageEditor()
                ->maxSize(5120)
                ->required(),

            Forms\Components\Toggle::make('active')
                ->label('Ativo')
                ->default(true),

            Forms\Components\TextInput::make('sort_order')
                ->label('Ordem de exibição')
                ->numeric()
                ->default(0)
                ->helperText('Menor número aparece primeiro'),

            Forms\Components\Hidden::make('supplier_id')
                ->default(fn () => auth()->user()?->supplier?->id),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image_url')
                    ->label('Imagem')
                    ->disk('public')
                    ->size(60),

                Tables\Columns\TextColumn::make('title')
                    ->label('Título')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('url')
                    ->label('URL')
                    ->url(fn ($record) => $record->url)
                    ->openUrlInNewTab()
                    ->limit(40)
                    ->toggleable(),

                Tables\Columns\IconColumn::make('active')
                    ->label('Ativo')
                    ->boolean(),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Ordem')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order', 'asc')
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
            'index'  => Pages\ListSupplierBanners::route('/'),
            'create' => Pages\CreateSupplierBanner::route('/create'),
            'edit'   => Pages\EditSupplierBanner::route('/{record}/edit'),
        ];
    }
}
