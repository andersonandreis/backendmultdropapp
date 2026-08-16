<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DocumentResource\Pages;
use App\Filament\Resources\DocumentResource\RelationManagers;
use App\Models\Document;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;
    protected static ?string $slug = 'documentos';
    protected static ?string $modelLabel = 'Documento';
    protected static ?string $pluralModelLabel = 'Documentos';

    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';
    protected static ?string $navigationGroup = 'Configurações';


    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin']);
    }
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\MorphToSelect::make('documentable')
                    ->types([
                        // MUL-269 fase 2: clients.company_name removido; MorphToSelect nao suporta
                        // accessor. Fallback pra id (UX limitada; usar filtro por outros documentos).
                        Forms\Components\MorphToSelect\Type::make(\App\Models\Client::class)->titleAttribute('id'),
                        Forms\Components\MorphToSelect\Type::make(\App\Models\Supplier::class)->titleAttribute('company_name'),
                        Forms\Components\MorphToSelect\Type::make(\App\Models\Order::class)->titleAttribute('order_number'),
                    ])
                    ->searchable()
                    ->required()
                    ->label('Entidade'),
                Forms\Components\TextInput::make('type')
                    ->required()
                    ->maxLength(255)
                    ->label('Tipo de Documento'),
                Forms\Components\FileUpload::make('file_path')
                    ->required()
                    ->directory('documents')
                    ->visibility('private')
                    ->label('Arquivo'),
                Forms\Components\Select::make('status')
                    ->options([
                        'pending' => 'Pendente',
                        'approved' => 'Aprovado',
                        'rejected' => 'Rejeitado'
                    ])
                    ->required()
                    ->default('pending'),
                Forms\Components\Textarea::make('notes')
                    ->label('Observações')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('documentable_type')->label('Tipo de Entidade')->formatStateUsing(fn(string $state): string => class_basename($state)),
                Tables\Columns\TextColumn::make('type')->searchable()->label('Tipo de Doc'),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->label('Baixar')
                    ->url(fn(Document $record) => url('/storage/' . $record->file_path))
                    ->openUrlInNewTab(),
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
            'index' => Pages\ListDocuments::route('/'),
            'create' => Pages\CreateDocument::route('/create'),
            'edit' => Pages\EditDocument::route('/{record}/edit'),
        ];
    }
}
