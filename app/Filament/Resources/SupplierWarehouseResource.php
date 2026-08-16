<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierWarehouseResource\Pages;
use App\Models\SupplierWarehouse;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SupplierWarehouseResource extends Resource
{
    protected static ?string $model = SupplierWarehouse::class;
    protected static ?string $slug = 'depositos';

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';
    protected static ?string $navigationGroup = 'Configurações';
    protected static ?string $navigationLabel = 'Depósitos / Galpões';
    protected static ?string $modelLabel = 'Depósito';
    protected static ?string $pluralModelLabel = 'Depósitos';
    protected static ?int $navigationSort = 15;

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
            Forms\Components\Section::make('Identificação')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nome / Apelido')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\Toggle::make('is_default')
                        ->label('Padrão')
                        ->helperText('Usado por default quando não há outro selecionado.')
                        ->default(false),

                    Forms\Components\Toggle::make('active')
                        ->label('Ativo')
                        ->default(true),
                ]),

            Forms\Components\Section::make('Endereço')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('zip_code')
                        ->label('CEP')
                        ->mask('99999-999')
                        ->maxLength(9),

                    Forms\Components\TextInput::make('state')
                        ->label('UF')
                        ->maxLength(2),

                    Forms\Components\TextInput::make('city')
                        ->label('Cidade')
                        ->maxLength(255),

                    Forms\Components\TextInput::make('address')
                        ->label('Logradouro')
                        ->maxLength(500)
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('number')
                        ->label('Número')
                        ->maxLength(20),

                    Forms\Components\TextInput::make('complement')
                        ->label('Complemento')
                        ->maxLength(255),

                    Forms\Components\TextInput::make('district')
                        ->label('Bairro')
                        ->maxLength(255)
                        ->columnSpan(2),
                ]),

            Forms\Components\Section::make('Contato')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('contact_name')
                        ->label('Responsável')
                        ->maxLength(255),

                    Forms\Components\TextInput::make('contact_phone')
                        ->label('Telefone')
                        ->tel()
                        ->maxLength(20),

                    Forms\Components\TextInput::make('contact_email')
                        ->label('E-mail')
                        ->email()
                        ->maxLength(255)
                        ->columnSpan(2),
                ]),

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

                Tables\Columns\TextColumn::make('city')
                    ->label('Cidade')
                    ->searchable(),

                Tables\Columns\TextColumn::make('state')
                    ->label('UF'),

                Tables\Columns\TextColumn::make('contact_name')
                    ->label('Responsável')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('contact_phone')
                    ->label('Telefone')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_default')
                    ->label('Padrão')
                    ->boolean(),

                Tables\Columns\IconColumn::make('active')
                    ->label('Ativo')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')->label('Status'),
                Tables\Filters\TernaryFilter::make('is_default')->label('Padrão'),
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
            'index'  => Pages\ListSupplierWarehouses::route('/'),
            'create' => Pages\CreateSupplierWarehouse::route('/create'),
            'edit'   => Pages\EditSupplierWarehouse::route('/{record}/edit'),
        ];
    }
}
