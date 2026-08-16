<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ForbiddenWordResource\Pages;
use App\Models\ForbiddenWord;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ForbiddenWordResource extends Resource
{
    protected static ?string $model = ForbiddenWord::class;
    protected static ?string $slug = 'palavras-proibidas';

    protected static ?string $navigationIcon = 'heroicon-o-shield-exclamation';
    protected static ?string $navigationGroup = 'Suporte';
    protected static ?string $navigationLabel = 'Proteção de Anúncios';
    protected static ?int $navigationSort = 1;
    protected static ?string $modelLabel = 'Palavra Proibida';
    protected static ?string $pluralModelLabel = 'Palavras Proibidas';


    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin']);
    }
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Adicionar Termo Sensível')
                    ->description('Causará o bloqueio preventivo de sincronização de anúncios contendo a palavra abaixo.')
                    ->schema([
                        Forms\Components\TextInput::make('word')
                            ->label('Palavra / Termo')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        Forms\Components\Select::make('context')
                            ->label('Marketplace (Contexto)')
                            ->options([
                                'shopee' => 'Somente Shopee',
                                'mercadolivre' => 'Somente Mercado Livre',
                            ])
                            ->default(null)
                            ->hint('Deixe nulo para bloquear globalmente em qualquer integração.'),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Ativo')
                            ->default(true),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('word')
                    ->label('Palavra Proibida')
                    ->searchable(),
                Tables\Columns\TextColumn::make('context')
                    ->label('Contexto')
                    ->badge()
                    ->formatStateUsing(fn(?string $state): string => match ($state) {
                        'shopee' => 'Shopee',
                        'mercadolivre' => 'Mercado Livre',
                        default => 'Global',
                    })
                    ->colors([
                        'primary' => null,
                        'warning' => 'mercadolivre',
                        'danger' => 'shopee',
                    ]),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label('Status'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Adicionado em')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListForbiddenWords::route('/'),
            'create' => Pages\CreateForbiddenWord::route('/create'),
            'edit' => Pages\EditForbiddenWord::route('/{record}/edit'),
        ];
    }
}
