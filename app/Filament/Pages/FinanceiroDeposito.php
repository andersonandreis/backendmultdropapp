<?php

namespace App\Filament\Pages;

use App\Models\Supplier;
use App\Models\SupplierBalance;
use App\Models\SupplierTransaction;
use Filament\Actions;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Actions\Action;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

class FinanceiroDeposito extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';
    protected static ?string $navigationGroup = 'Estoque & Remessas';
    protected static ?string $navigationLabel = 'Financeiro do Deposito';
    protected static ?string $title = 'Financeiro do Depósito';
    protected static ?string $slug = 'financeiro-deposito';
    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.financeiro-deposito';

    public function getTableRecordKey(\Illuminate\Database\Eloquent\Model $record): string
    {
        return (string) ($record->dia ?? $record->getKey() ?? uniqid());
    }

    /**
     * MUL-226-06: cards de saldo — total, ativo (disponível) e bloqueado (saques em aberto).
     */
    public function getSaldosData(): array
    {
        $ativo = (float) SupplierBalance::query()->sum('balance');
        $bloqueado = (float) DB::table('withdrawal_requests')
            ->whereNotIn('status', ['paid', 'rejected', 'cancelled', 'canceled'])
            ->sum('amount');
        $fmt = fn (float $v) => 'R$ ' . number_format($v, 2, ',', '.');

        return [
            'total'     => $fmt($ativo + $bloqueado),
            'ativo'     => $fmt($ativo),
            'bloqueado' => $fmt($bloqueado),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('creditar')
                ->label('Pagar / Creditar')
                ->icon('heroicon-o-plus-circle')
                ->color('success')
                ->form([
                    Select::make('balance_id')
                        ->label('Conta (Remetente)')
                        ->options(fn () => SupplierBalance::with('producer')->get()
                            ->mapWithKeys(fn ($b) => [
                                $b->id => ($b->producer?->company_name ?? "Conta #{$b->id}")
                                    . ' — saldo R$ ' . number_format((float) $b->balance, 2, ',', '.'),
                            ])->toArray())
                        ->required()
                        ->searchable(),

                    TextInput::make('valor')
                        ->label('Valor (R$)')
                        ->numeric()
                        ->required()
                        ->minValue(0.01),

                    Textarea::make('descricao')
                        ->label('Descrição')
                        ->required()
                        ->rows(2),
                ])
                ->action(function (array $data): void {
                    $record = SupplierBalance::with('producer')->find($data['balance_id']);
                    if (! $record) {
                        return;
                    }
                    $valor = (float) $data['valor'];

                    $record->increment('balance', $valor);
                    $record->increment('total_earned', $valor);

                    // MUL-226-06 fix: coluna correta é producer_id (antes gravava 'supplier_id',
                    // que não existe na tabela — transação nascia órfã e o extrato quebrava)
                    SupplierTransaction::create([
                        'producer_id'  => $record->producer_id,
                        'warehouse_id' => $record->warehouse_id,
                        'type'         => 'adjustment',
                        'amount'       => $valor,
                        'description'  => $data['descricao'],
                    ]);

                    Notification::make()
                        ->title('Crédito registrado!')
                        ->body('R$ ' . number_format($valor, 2, ',', '.') . ' creditado para ' . ($record->producer?->company_name ?? ''))
                        ->success()
                        ->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                // MUL-226-06: depósitos agrupados por DATA (uma linha por dia)
                SupplierTransaction::query()
                    ->select(
                        DB::raw('DATE(supplier_transactions.created_at) as dia'),
                        DB::raw('MIN(supplier_transactions.id) as id'),
                        DB::raw("SUM(CASE WHEN supplier_transactions.type = 'withdrawal' THEN 0 ELSE supplier_transactions.amount END) as total_depositos"),
                        DB::raw("SUM(CASE WHEN supplier_transactions.type = 'withdrawal' THEN supplier_transactions.amount ELSE 0 END) as total_saques"),
                        DB::raw('COUNT(*) as qtd_transacoes'),
                        DB::raw('COUNT(DISTINCT supplier_transactions.producer_id) as qtd_contas')
                    )
                    ->groupBy('dia')
            )
            ->defaultSort('dia', 'asc')
            ->paginationPageOptions([31, 62, 93])
            ->columns([
                TextColumn::make('dia')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->description(fn ($record) => ucfirst(\Carbon\Carbon::parse($record->dia)->locale('pt_BR')->dayName))
                    ->sortable(),

                TextColumn::make('qtd_contas')
                    ->label('Contas')
                    ->tooltip('Contas (remetentes) com movimentação no dia')
                    ->badge()
                    ->color('info'),

                TextColumn::make('qtd_transacoes')
                    ->label('Transações')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('total_depositos')
                    ->label('Depósitos (Entradas)')
                    ->money('BRL')
                    ->weight('bold')
                    ->color('success')
                    ->sortable(),

                TextColumn::make('total_saques')
                    ->label('Saques (Saídas)')
                    ->money('BRL')
                    ->color('danger')
                    ->sortable(),
            ])
            ->actions([
                Action::make('detalhe')
                    ->label('Ver Detalhe')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->slideOver()
                    ->modalHeading(fn ($record) => 'Detalhe do dia ' . \Carbon\Carbon::parse($record->dia)->format('d/m/Y'))
                    ->modalContent(fn ($record) => self::renderDetalheDia((string) $record->dia))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar'),
            ])
            ->filters([
                // MUL-226-06: default = mês atual (1º → último dia)
                SelectFilter::make('periodo_rapido')
                    ->label('Período rápido')
                    ->options(TopLojas::quickPeriodOptions())
                    ->default('mes')
                    ->query(fn (Builder $query, array $data): Builder => $data['value']
                        ? TopLojas::applyQuickPeriod($query, $data['value'], 'supplier_transactions.created_at')
                        : $query),

                Filter::make('periodo')
                    ->label('Período personalizado')
                    ->form([
                        DatePicker::make('data_inicio')->label('De')->displayFormat('d/m/Y'),
                        DatePicker::make('data_fim')->label('Até')->displayFormat('d/m/Y'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['data_inicio'], fn ($q, $val) => $q->whereDate('supplier_transactions.created_at', '>=', $val))
                        ->when($data['data_fim'],   fn ($q, $val) => $q->whereDate('supplier_transactions.created_at', '<=', $val))),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(2)
            ->emptyStateHeading('Nenhuma movimentação no período');
    }

    /**
     * MUL-226-06: detalhe do dia — breakdown por conta; a soma das linhas
     * é o mesmo agregado SQL da linha do dia (bate por construção).
     */
    protected static function renderDetalheDia(string $dia): HtmlString
    {
        $rows = SupplierTransaction::query()
            ->select(
                'producer_id',
                DB::raw("SUM(CASE WHEN type = 'withdrawal' THEN 0 ELSE amount END) as entradas"),
                DB::raw("SUM(CASE WHEN type = 'withdrawal' THEN amount ELSE 0 END) as saidas"),
                DB::raw('COUNT(*) as qtd')
            )
            ->whereDate('created_at', $dia)
            ->groupBy('producer_id')
            ->orderByDesc('entradas')
            ->get();

        if ($rows->isEmpty()) {
            return new HtmlString('<p class="text-gray-500 text-sm p-4">Nenhuma transação neste dia.</p>');
        }

        $nomes = Supplier::whereIn('id', $rows->pluck('producer_id')->filter())
            ->pluck('company_name', 'id');

        $fmt = fn (float $v) => 'R$ ' . number_format($v, 2, ',', '.');
        $totEntradas = 0.0;
        $totSaidas = 0.0;

        $html = '<div class="overflow-x-auto p-2"><table style="width:100%;border-collapse:collapse;font-size:0.85rem;">';
        $html .= '<thead><tr style="border-bottom:2px solid #94a3b8;"><th style="padding:8px 10px;text-align:left;">Conta</th><th style="padding:8px 10px;text-align:center;">Transações</th><th style="padding:8px 10px;text-align:right;">Entradas</th><th style="padding:8px 10px;text-align:right;">Saídas</th></tr></thead><tbody>';

        foreach ($rows as $r) {
            $nome = $nomes[$r->producer_id] ?? ($r->producer_id ? "Conta #{$r->producer_id}" : 'Sem conta vinculada');
            $totEntradas += (float) $r->entradas;
            $totSaidas += (float) $r->saidas;
            $html .= '<tr style="border-bottom:1px solid #e5e7eb;">';
            $html .= '<td style="padding:8px 10px;font-weight:600;">' . htmlspecialchars($nome) . '</td>';
            $html .= '<td style="padding:8px 10px;text-align:center;">' . (int) $r->qtd . '</td>';
            $html .= '<td style="padding:8px 10px;text-align:right;color:#10b981;font-weight:700;">' . $fmt((float) $r->entradas) . '</td>';
            $html .= '<td style="padding:8px 10px;text-align:right;color:#ef4444;font-weight:700;">' . ((float) $r->saidas > 0 ? '- ' . $fmt((float) $r->saidas) : '—') . '</td>';
            $html .= '</tr>';
        }

        $html .= '<tr style="border-top:2px solid #94a3b8;">';
        $html .= '<td style="padding:10px;font-weight:800;">TOTAL DO DIA</td>';
        $html .= '<td style="padding:10px;text-align:center;font-weight:700;">' . $rows->sum('qtd') . '</td>';
        $html .= '<td style="padding:10px;text-align:right;color:#10b981;font-weight:800;">' . $fmt($totEntradas) . '</td>';
        $html .= '<td style="padding:10px;text-align:right;color:#ef4444;font-weight:800;">' . ($totSaidas > 0 ? '- ' . $fmt($totSaidas) : '—') . '</td>';
        $html .= '</tr>';

        $html .= '</tbody></table></div>';

        return new HtmlString($html);
    }
}
