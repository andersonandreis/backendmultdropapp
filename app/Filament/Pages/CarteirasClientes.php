<?php

namespace App\Filament\Pages;

use App\Models\ClientSupplierBalance;
use App\Models\ClientSupplierTransaction;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class CarteirasClientes extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-wallet';
    protected static ?string $navigationGroup = 'Financeiro';
    protected static ?string $title = 'Carteiras dos Clientes';
    protected static ?string $slug = 'carteiras-clientes';
    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.carteiras-clientes';

    public function table(Table $table): Table
    {
        $query = ClientSupplierBalance::query()
            ->with(['client.user', 'supplier'])
            ->join('clients', 'client_supplier_balances.client_id', '=', 'clients.id')
            ->join('users', 'clients.user_id', '=', 'users.id')
            ->select('client_supplier_balances.*');

        // Filtrar por supplier do usuário logado (se não super_admin)
        $user = auth()->user();
        if ($user?->role !== 'super_admin') {
            $supplierId = $user?->supplier?->id;
            if ($supplierId) {
                $query->where('client_supplier_balances.supplier_id', $supplierId);
            }
        }

        return $table
            ->query($query)
            ->columns([
                TextColumn::make('client.company_name')
                    ->label('Seller')
                    ->html()
                    ->formatStateUsing(function (?string $state, ClientSupplierBalance $record): HtmlString {
                        $nome  = htmlspecialchars($state ?? 'N/A');
                        $email = htmlspecialchars($record->client?->user?->email ?? '');
                        return new HtmlString(
                            '<div style="line-height:1.4;">'
                            . '<span style="font-weight:600;">' . $nome . '</span><br>'
                            . '<span style="font-size:0.75rem; color:#6b7280;">' . $email . '</span>'
                            . '</div>'
                        );
                    })
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        // MUL-269 fase 2: busca no user (clients.company_name removido).
                        return $query->where(function ($q) use ($search) {
                            $q->where('users.full_name', 'like', "%{$search}%")
                              ->orWhere('users.name', 'like', "%{$search}%")
                              ->orWhere('users.email', 'like', "%{$search}%");
                        });
                    }),

                TextColumn::make('balance')
                    ->label('Saldo')
                    ->html()
                    ->formatStateUsing(function (string $state): HtmlString {
                        $saldo = (float) $state;
                        $formatted = 'R$ ' . number_format(abs($saldo), 2, ',', '.');
                        if ($saldo > 0) {
                            return new HtmlString('<span style="color:#10b981; font-weight:700; font-size:1rem;">' . $formatted . '</span>');
                        }
                        if ($saldo < 0) {
                            return new HtmlString('<span style="color:#ef4444; font-weight:700; font-size:1rem;">- ' . $formatted . '</span>');
                        }
                        return new HtmlString('<span style="color:#6b7280; font-weight:600;">R$ 0,00</span>');
                    })
                    ->sortable(),

                TextColumn::make('ultima_transacao')
                    ->label('Última Transação')
                    ->getStateUsing(function (ClientSupplierBalance $record): string {
                        $ultima = ClientSupplierTransaction::where('client_id', $record->client_id)
                            ->where('supplier_id', $record->supplier_id)
                            ->orderBy('created_at', 'desc')
                            ->value('created_at');
                        return $ultima ? \Carbon\Carbon::parse($ultima)->format('d/m/Y H:i') : '--/--';
                    }),
            ])
            ->actions([
                Action::make('extrato')
                    ->label('Extrato')
                    ->icon('heroicon-o-document-text')
                    ->color('info')
                    ->slideOver()
                    ->modalHeading(fn(ClientSupplierBalance $record): string =>
                        'Extrato: ' . ($record->client?->company_name ?? 'Seller')
                    )
                    ->modalContent(function (ClientSupplierBalance $record): HtmlString {
                        $transacoes = ClientSupplierTransaction::where('client_id', $record->client_id)
                            ->where('supplier_id', $record->supplier_id)
                            ->orderBy('created_at', 'desc')
                            ->get();

                        if ($transacoes->isEmpty()) {
                            return new HtmlString('<p class="text-gray-500 text-sm">Nenhuma transação encontrada.</p>');
                        }

                        $html = '<div style="overflow-x:auto;">';
                        $html .= '<table style="width:100%; border-collapse:collapse; font-size:0.875rem;">';
                        $html .= '<thead><tr style="background:#f1f5f9; text-align:left;">';
                        $html .= '<th style="padding:8px 12px;">Data</th>';
                        $html .= '<th style="padding:8px 12px;">Tipo</th>';
                        $html .= '<th style="padding:8px 12px;">Descrição</th>';
                        $html .= '<th style="padding:8px 12px; text-align:right;">Valor</th>';
                        $html .= '</tr></thead><tbody>';

                        foreach ($transacoes as $t) {
                            $cor   = $t->type === 'credit' ? '#10b981' : '#ef4444';
                            $sinal = $t->type === 'credit' ? '+' : '-';
                            $tipo  = $t->type === 'credit' ? 'Crédito' : 'Débito';
                            $html .= '<tr style="border-bottom:1px solid #e5e7eb;">';
                            $html .= '<td style="padding:8px 12px;">' . $t->created_at->format('d/m/Y H:i') . '</td>';
                            $html .= '<td style="padding:8px 12px;"><span style="color:' . $cor . '; font-weight:600;">' . $tipo . '</span></td>';
                            $html .= '<td style="padding:8px 12px;">' . htmlspecialchars($t->description ?? '') . '</td>';
                            $html .= '<td style="padding:8px 12px; text-align:right; font-weight:700; color:' . $cor . ';">'
                                . $sinal . ' R$ ' . number_format((float) $t->amount, 2, ',', '.')
                                . '</td>';
                            $html .= '</tr>';
                        }

                        $html .= '</tbody></table></div>';
                        return new HtmlString($html);
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar'),
            ])
            ->defaultSort('balance', 'desc');
    }
}
