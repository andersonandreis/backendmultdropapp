<?php

namespace App\Filament\Pages;

use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Services\Inventory\InventoryMovementService;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;

class EstoqueDeposito extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';
    protected static ?string $navigationGroup = 'Estoque & Remessas';
    protected static ?string $navigationLabel = 'Controle de Estoque';
    protected static ?string $title = 'Controle de Estoque';
    protected static ?string $slug = 'estoque-deposito';
    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.estoque-deposito';

    public function table(Table $table): Table
    {
        return $table
            ->query(Inventory::query()->with(['product', 'warehouse'])->latest())
            ->columns([
                TextColumn::make('product.sku')->label('SKU')->searchable(),
                TextColumn::make('product.name')->label('Produto')->searchable()->limit(40),
                TextColumn::make('warehouse.company_name')->label('Galpão / Fornecedor')->default('—'),
                TextColumn::make('quantity')
                    ->label('Estoque')
                    ->badge()
                    ->color(fn($state): string => match (true) {
                        (int)$state <= 0  => 'danger',
                        (int)$state <= 5  => 'warning',
                        default           => 'success',
                    }),
                TextColumn::make('warehouse_price')->label('Preço')->money('BRL'),
                TextColumn::make('updated_at')->label('Atualizado')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->actions([
                Action::make('movimento')
                    ->label('Movimento')
                    ->icon('heroicon-o-arrows-up-down')
                    ->color('info')
                    ->form([
                        Placeholder::make('estoque_atual')
                            ->label('Estoque atual')
                            ->content(fn (Inventory $record) => $record->quantity),
                        Select::make('tipo')
                            ->label('Tipo')
                            ->options([
                                'entrada' => 'Entrada',
                                'saida'   => 'Saída',
                                'ajuste'  => 'Ajuste (definir valor exato)',
                                'zerar'   => 'Zerar estoque',
                            ])
                            ->required()
                            ->native(false)
                            ->reactive(),
                        TextInput::make('quantidade')
                            ->label('Quantidade')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->hidden(fn ($get) => $get('tipo') === 'zerar'),
                        Textarea::make('motivo')
                            ->label('Motivo')
                            ->rows(2)
                            ->required(fn ($get) => in_array($get('tipo'), ['ajuste', 'zerar']))
                            ->helperText('Obrigatório para ajuste/zerar — fica registrado no histórico.'),
                    ])
                    ->action(function (Inventory $record, array $data): void {
                        $tipo = $data['tipo'];
                        $qtd  = (int) ($data['quantidade'] ?? 0);
                        $motivo = $data['motivo'] ?? null;

                        $novoEstoque = match ($tipo) {
                            'entrada' => $record->quantity + $qtd,
                            'saida'   => max(0, $record->quantity - $qtd),
                            'ajuste'  => $qtd,
                            'zerar'   => 0,
                            default   => $record->quantity,
                        };

                        $svc = app(InventoryMovementService::class);
                        $movement = $svc->recordManualAdjust($record, $novoEstoque, $tipo, $motivo, auth()->id());

                        Notification::make()
                            ->title('Movimento registrado')
                            ->body("Estoque atualizado: {$movement->qty_after} unidades. Movimento #{$movement->id}.")
                            ->success()
                            ->send();
                    }),

                Action::make('historico')
                    ->label('Histórico')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->modalHeading(fn (Inventory $record) => 'Histórico — ' . ($record->product?->sku ?? $record->id))
                    ->modalContent(function (Inventory $record) {
                        $movs = InventoryMovement::query()
                            ->where('inventory_id', $record->id)
                            ->with('user')
                            ->latest('created_at')
                            ->limit(50)
                            ->get();
                        return view('filament.partials.inventory-movements-modal', ['movs' => $movs]);
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar'),
            ])
            ->bulkActions([
                BulkAction::make('zerarLote')
                    ->label('Zerar selecionados')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->form([
                        Textarea::make('motivo')->label('Motivo (obrigatório)')->required()->rows(2),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        $svc = app(InventoryMovementService::class);
                        $count = 0;
                        foreach ($records as $record) {
                            $svc->recordManualAdjust($record, 0, 'zerar', $data['motivo'], auth()->id());
                            $count++;
                        }
                        Notification::make()->title("{$count} itens zerados")->success()->send();
                    }),

                BulkAction::make('ajusteLote')
                    ->label('Ajuste em lote')
                    ->icon('heroicon-o-arrows-up-down')
                    ->color('info')
                    ->form([
                        Select::make('tipo')
                            ->label('Tipo')
                            ->options(['entrada' => 'Entrada', 'saida' => 'Saída'])
                            ->required()
                            ->native(false),
                        TextInput::make('quantidade')->label('Quantidade por item')->numeric()->required()->minValue(1),
                        Textarea::make('motivo')->label('Motivo (obrigatório)')->required()->rows(2),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        $svc = app(InventoryMovementService::class);
                        $qtd = (int) $data['quantidade'];
                        $tipo = $data['tipo'];
                        $count = 0;
                        foreach ($records as $record) {
                            $novaQty = $tipo === 'entrada'
                                ? $record->quantity + $qtd
                                : max(0, $record->quantity - $qtd);
                            $svc->recordManualAdjust($record, $novaQty, $tipo, $data['motivo'], auth()->id());
                            $count++;
                        }
                        Notification::make()->title("{$count} itens ajustados")->success()->send();
                    }),
            ])
            ->searchable()
            ->defaultSort('updated_at', 'desc');
    }
}
