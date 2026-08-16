<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductQuestionResource\Pages;
use App\Models\ProductQuestion;
use App\Services\Marketplaces\ProductQuestionService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** NOV-123 — Painel de perguntas de compradores. */
class ProductQuestionResource extends Resource
{
    protected static ?string $model = ProductQuestion::class;
    protected static ?string $slug = 'perguntas';
    protected static ?string $modelLabel = 'Pergunta';
    protected static ?string $pluralModelLabel = 'Perguntas de Compradores';
    protected static ?string $navigationIcon = 'heroicon-o-question-mark-circle';
    protected static ?string $navigationGroup = 'Atendimento';
    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', 'pending')->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('buyer_name')->label('Comprador')->disabled(),
            Forms\Components\TextInput::make('marketplace')->label('Marketplace')->disabled(),
            Forms\Components\TextInput::make('marketplace_item_id')->label('Anúncio')->disabled(),
            Forms\Components\Textarea::make('question')->label('Pergunta')->disabled()->rows(3),
            Forms\Components\Textarea::make('answer')->label('Resposta')->required()->rows(4)
                ->maxLength(2000)
                ->helperText('Ao salvar, a resposta sera enviada ao marketplace via API e marcada como respondida.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(ProductQuestion::query()->with(['product', 'marketplaceAccount']))
            ->columns([
                Tables\Columns\TextColumn::make('asked_at')
                    ->label('Recebida')
                    ->dateTime('d/m H:i')
                    ->sortable(),
                Tables\Columns\BadgeColumn::make('marketplace')
                    ->label('Origem')
                    ->colors([
                        'success' => 'mercadolivre',
                        'warning' => 'shopee',
                    ])
                    ->formatStateUsing(fn (?string $s) => $s ?: 'site'),
                Tables\Columns\TextColumn::make('buyer_name')->label('Comprador')->placeholder('—')->limit(20),
                Tables\Columns\TextColumn::make('product.name')->label('Produto')->limit(30)->placeholder('—'),
                Tables\Columns\TextColumn::make('question')->label('Pergunta')->wrap()->limit(80),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'answered',
                        'danger'  => 'failed',
                        'gray'    => 'auto_dismissed',
                    ]),
            ])
            ->defaultSort('asked_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    'pending'        => 'Pendentes',
                    'answered'       => 'Respondidas',
                    'failed'         => 'Falhas',
                    'auto_dismissed' => 'Descartadas',
                ])->default('pending'),
                SelectFilter::make('marketplace')->options([
                    'mercadolivre' => 'Mercado Livre',
                    'shopee'       => 'Shopee',
                ]),
            ])
            ->actions([
                Tables\Actions\Action::make('responder')
                    ->label('Responder')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('primary')
                    ->visible(fn (ProductQuestion $r) => $r->status === 'pending' || $r->status === 'failed')
                    ->form([
                        Forms\Components\Placeholder::make('q')->label('Pergunta')
                            ->content(fn ($record) => $record->question),
                        Forms\Components\Textarea::make('answer')
                            ->label('Sua resposta')
                            ->required()
                            ->rows(4)
                            ->maxLength(2000),
                    ])
                    ->action(function ($record, array $data): void {
                        $svc = app(ProductQuestionService::class);
                        $ok = $svc->answer($record, $data['answer'], auth()->id());
                        if ($ok) {
                            Notification::make()->title('Resposta enviada')->success()->send();
                        } else {
                            Notification::make()
                                ->title('Resposta salva mas marketplace falhou')
                                ->body($record->fresh()->failure_reason ?: 'verifique logs')
                                ->warning()->send();
                        }
                    }),
                Tables\Actions\Action::make('descartar')
                    ->label('Descartar')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn ($r) => $r->status === 'pending')
                    ->action(fn ($record) => $record->update(['status' => 'auto_dismissed'])),
            ])
            ->headerActions([
                Tables\Actions\Action::make('sincronizar')
                    ->label('Sincronizar agora')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->action(function () {
                        $supplierId = auth()->user()->supplier?->id;
                        if (!$supplierId) {
                            Notification::make()->title('Sem supplier vinculado')->danger()->send();
                            return;
                        }
                        $svc = app(ProductQuestionService::class);
                        $out = $svc->syncForSupplier($supplierId);
                        Notification::make()
                            ->title('Sync concluido')
                            ->body('ML: '.($out['ml'] ?? 0).' / Shopee: '.($out['shopee'] ?? 0).' novas perguntas')
                            ->success()->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('descartarLote')
                        ->label('Descartar selecionadas')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->action(fn ($records) => $records->each->update(['status' => 'auto_dismissed'])),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductQuestions::route('/'),
        ];
    }
}
