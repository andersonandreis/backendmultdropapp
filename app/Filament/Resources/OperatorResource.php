<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OperatorResource\Pages;
use App\Models\Operator;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class OperatorResource extends Resource
{
    protected static ?string $model = Operator::class;
    protected static ?string $slug = 'operadores';
    protected static ?string $modelLabel = 'Operador';
    protected static ?string $pluralModelLabel = 'Operadores';
    protected static ?string $navigationIcon = 'heroicon-o-identification';
    protected static ?string $navigationGroup = 'Pedidos & Logistica';
    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function canCreate(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();
        if ($user?->role === 'supplier') {
            $supplierId = $user->supplier?->id;
            if ($supplierId) {
                $query->where('supplier_id', $supplierId);
            }
        } else {
            $localSupplierId = config('multdrop.supplier_id');
            if ($localSupplierId) {
                $query->where('supplier_id', $localSupplierId);
            }
        }
        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Dados do Operador')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nome completo')
                            ->required()
                            ->maxLength(100)
                            ->placeholder('Ex: Joao da Silva'),
                        Forms\Components\TextInput::make('badge_code')
                            ->label('Codigo do Cracha')
                            ->required()
                            ->maxLength(64)
                            ->placeholder('Ex: OP-001 ou codigo do leitor')
                            ->helperText('Codigo bipado pelo scanner no galp. Deve ser unico.')
                            ->default(fn() => 'OP-' . strtoupper(Str::random(6))),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Operador ativo')
                            ->default(true),
                        Forms\Components\Hidden::make('supplier_id')
                            ->default(function () {
                                $user = auth()->user();
                                return $user?->supplier?->id
                                    ?? config('multdrop.supplier_id');
                            }),
                    ])
                    ->columns(2),
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
                Tables\Columns\TextColumn::make('badge_code')
                    ->label('Codigo do Cracha')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono'),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('danger'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Cadastrado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status')
                    ->trueLabel('Apenas ativos')
                    ->falseLabel('Apenas inativos')
                    ->native(false),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Editar'),
                Tables\Actions\Action::make('toggle_active')
                    ->label(fn(Operator $record) => $record->is_active ? 'Desativar' : 'Ativar')
                    ->icon(fn(Operator $record) => $record->is_active ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                    ->color(fn(Operator $record) => $record->is_active ? 'danger' : 'success')
                    ->action(fn(Operator $record) => $record->update(['is_active' => !$record->is_active]))
                    ->requiresConfirmation(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name', 'asc')
            ->emptyStateHeading('Nenhum operador cadastrado')
            ->emptyStateDescription('Crie o primeiro operador para comecar a rastrear bipes no picking/packing.')
            ->emptyStateIcon('heroicon-o-identification');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListOperators::route('/'),
            'create' => Pages\CreateOperator::route('/create'),
            'edit'   => Pages\EditOperator::route('/{record}/edit'),
        ];
    }
}
