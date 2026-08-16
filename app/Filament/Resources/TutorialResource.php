<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TutorialResource\Pages;
use App\Models\Tutorial;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TutorialResource extends Resource
{
    protected static ?string $model = Tutorial::class;
    protected static ?string $slug = 'tutoriais';

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';
    protected static ?string $navigationGroup = 'Suporte';
    protected static ?string $navigationLabel = 'Vídeos de Ajuda';
    protected static ?string $modelLabel = 'Aula';
    protected static ?int $navigationSort = 3;


    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->role === 'super_admin';
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return auth()->user()?->role === 'super_admin';
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return auth()->user()?->role === 'super_admin';
    }
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Conteúdo da Aula')
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label('Título do Treinamento')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Textarea::make('description')
                            ->label('Breve Descrição')
                            ->maxLength(65535)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('video_url')
                            ->label('URL do Vídeo (Youtube/Vimeo)')
                            ->url()
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Regras de Automação (Popups)')
                    ->description('Defina onde e para quem esta aula aparece automaticamente.')
                    ->schema([
                        Forms\Components\Select::make('target_page')
                            ->label('Página Alvo (Onde o Modal de Ajuda aparece)')
                            ->options([
                                'orders' => 'Meus Pedidos',
                                'invoices' => 'Notas Fiscais',
                                'products' => 'Catálogo de Produtos',
                                'settings' => 'Configurações da Loja',
                            ])
                            ->searchable()
                            ->hint('Ex: Se escolher "Notas Fiscais", um ícone de Play aparecerá na tela de NFe do lojista.'),
                        Forms\Components\Select::make('required_plan_id')
                            ->label('Plano Exigido (Upsell)')
                            ->relationship('plan', 'name')
                            ->hint('Deixe em branco para liberar a todos os Lojistas.'),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Aula Ativa')
                            ->default(true)
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Título')
                    ->searchable(),
                Tables\Columns\TextColumn::make('target_page')
                    ->label('Aparece em')
                    ->badge()
                    ->colors(['primary']),
                Tables\Columns\TextColumn::make('plan.name')
                    ->label('Restrito a')
                    ->badge()
                    ->colors(['success']),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Status')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Criado em')
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
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTutorials::route('/'),
            'create' => Pages\CreateTutorial::route('/create'),
            'edit' => Pages\EditTutorial::route('/{record}/edit'),
        ];
    }
}
