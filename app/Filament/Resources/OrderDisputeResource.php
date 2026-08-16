<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderDisputeResource\Pages;
use App\Models\OrderDispute;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class OrderDisputeResource extends Resource
{
    protected static ?string $model = OrderDispute::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'Pedidos';

    protected static ?string $navigationLabel = 'Disputas';

    protected static ?string $pluralModelLabel = 'Disputas';

    protected static ?string $modelLabel = 'Disputa';

    protected static ?int $navigationSort = 30;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informacoes da Disputa')
                    ->schema([
                        Forms\Components\TextInput::make('order_id')
                            ->label('Pedido #')
                            ->disabled(),

                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options([
                                'open'      => 'Aberta',
                                'in_review' => 'Em Analise',
                                'resolved'  => 'Resolvida',
                                'rejected'  => 'Rejeitada',
                            ])
                            ->required(),

                        Forms\Components\TextInput::make('reason')
                            ->label('Motivo')
                            ->disabled(),

                        Forms\Components\Textarea::make('description')
                            ->label('Descricao do Lojista')
                            ->disabled()
                            ->rows(4),

                        Forms\Components\Textarea::make('resolution_notes')
                            ->label('Notas de Resolucao (Admin)')
                            ->rows(4)
                            ->helperText('Preencha ao resolver ou rejeitar a disputa.'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Notas Fiscais')
                    ->schema([
                        Forms\Components\TextInput::make('invoice_xml_url')
                            ->label('URL do XML')
                            ->url()
                            ->disabled(),

                        Forms\Components\TextInput::make('invoice_pdf_url')
                            ->label('URL do PDF')
                            ->url()
                            ->disabled(),

                        Forms\Components\TextInput::make('invoice_xml_path')
                            ->label('Path do XML (storage local)')
                            ->disabled(),

                        Forms\Components\TextInput::make('invoice_pdf_path')
                            ->label('Path do PDF (storage local)')
                            ->disabled(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                Tables\Columns\TextColumn::make('order_id')
                    ->label('Pedido')
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'open',
                        'info'    => 'in_review',
                        'success' => 'resolved',
                        'danger'  => 'rejected',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'open'      => 'Aberta',
                        'in_review' => 'Em Analise',
                        'resolved'  => 'Resolvida',
                        'rejected'  => 'Rejeitada',
                        default     => $state,
                    }),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Motivo')
                    ->limit(50)
                    ->searchable(),

                Tables\Columns\TextColumn::make('openedBy.name')
                    ->label('Aberta Por')
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Aberta em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('resolved_at')
                    ->label('Resolvida em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'open'      => 'Aberta',
                        'in_review' => 'Em Analise',
                        'resolved'  => 'Resolvida',
                        'rejected'  => 'Rejeitada',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Analisar'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelationManagers(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrderDisputes::route('/'),
            'edit'  => Pages\EditOrderDispute::route('/{record}/edit'),
        ];
    }
}
