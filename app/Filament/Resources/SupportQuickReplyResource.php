<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupportQuickReplyResource\Pages;
use App\Models\SupportQuickReply;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SupportQuickReplyResource extends Resource
{
    protected static ?string $model = SupportQuickReply::class;
    protected static ?string $slug = 'sac-respostas';
    protected static ?string $modelLabel = 'Resposta Pronta';
    protected static ?string $pluralModelLabel = 'SAC — Respostas Prontas';
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';
    protected static ?string $navigationGroup = 'Atendimento';
    protected static ?int $navigationSort = 12;

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('supplier_id')->relationship('supplier', 'company_name')
                ->required()->visible(fn () => auth()->user()?->role === 'super_admin'),
            Forms\Components\TextInput::make('title')->required()->maxLength(100),
            Forms\Components\Textarea::make('body')->required()->rows(5)->maxLength(4000)
                ->helperText('Pode usar {{nome}}, {{pedido}}, {{produto}} como variáveis.'),
            Forms\Components\Select::make('department_id')->relationship('department', 'name')->nullable(),
            Forms\Components\Toggle::make('active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable(),
                Tables\Columns\TextColumn::make('department.name')->label('Setor')->placeholder('Todos'),
                Tables\Columns\TextColumn::make('body')->limit(60)->wrap(),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSupportQuickReplies::route('/'),
            'create' => Pages\CreateSupportQuickReply::route('/create'),
            'edit'   => Pages\EditSupportQuickReply::route('/{record}/edit'),
        ];
    }
}
