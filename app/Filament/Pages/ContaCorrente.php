<?php

namespace App\Filament\Pages;

use App\Models\ClientSupplierTransaction;
use App\Models\Client;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DatePicker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class ContaCorrente extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationGroup = 'Financeiro';
    protected static ?string $title = 'Conta Corrente';
    protected static ?string $slug = 'conta-corrente';
    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.conta-corrente';

    public function table(Table $table): Table
    {
        $query = ClientSupplierTransaction::query()->with(['client', 'order']);

        // Filtrar por supplier_id do usuário logado (se não for super_admin)
        $user = auth()->user();
        if ($user?->role !== 'super_admin') {
            $supplierId = $user?->supplier?->id;
            if ($supplierId) {
                $query->where('supplier_id', $supplierId);
            }
        }

        return $table
            ->query($query->orderBy('created_at', 'desc'))
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'credit' => 'Crédito',
                        'debit'  => 'Débito',
                        default  => ucfirst($state),
                    })
                    ->color(fn(string $state): string => match ($state) {
                        'credit' => 'success',
                        'debit'  => 'danger',
                        default  => 'gray',
                    }),

                TextColumn::make('description')
                    ->label('Descrição')
                    ->wrap()
                    ->limit(60),

                TextColumn::make('client.company_name')
                    ->label('Seller')
                    // MUL-269 fase 2: busca no user (clients.company_name removido).
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('client.user', function ($u) use ($search) {
                            $u->where('full_name', 'like', "%{$search}%")
                              ->orWhere('name', 'like', "%{$search}%");
                        });
                    }),

                TextColumn::make('amount')
                    ->label('Valor')
                    ->html()
                    ->formatStateUsing(function (string $state, ClientSupplierTransaction $record): HtmlString {
                        $valor = number_format((float) $state, 2, ',', '.');
                        if ($record->type === 'credit') {
                            return new HtmlString('<span style="color:#10b981; font-weight:600;">+ R$ ' . $valor . '</span>');
                        }
                        return new HtmlString('<span style="color:#ef4444; font-weight:600;">- R$ ' . $valor . '</span>');
                    }),

                TextColumn::make('saldo_acumulado')
                    ->label('Saldo Acumulado')
                    ->html()
                    ->getStateUsing(function (ClientSupplierTransaction $record): HtmlString {
                        // Calcular running total: soma de todas as transações até e incluindo esta
                        $saldo = ClientSupplierTransaction::where('client_id', $record->client_id)
                            ->where('supplier_id', $record->supplier_id)
                            ->where(function (Builder $q) use ($record) {
                                $q->where('created_at', '<', $record->created_at)
                                  ->orWhere(function (Builder $q2) use ($record) {
                                      $q2->where('created_at', '=', $record->created_at)
                                         ->where('id', '<=', $record->id);
                                  });
                            })
                            ->selectRaw("SUM(CASE WHEN type = 'credit' THEN amount ELSE -amount END) as running_total")
                            ->value('running_total') ?? 0;

                        $saldo = (float) $saldo;
                        $formatted = 'R$ ' . number_format(abs($saldo), 2, ',', '.');
                        if ($saldo >= 0) {
                            return new HtmlString('<span style="color:#10b981; font-weight:700;">' . $formatted . '</span>');
                        }
                        return new HtmlString('<span style="color:#ef4444; font-weight:700;">- ' . $formatted . '</span>');
                    }),
            ])
            ->filters([
                Filter::make('periodo')
                    ->label('Período')
                    ->form([
                        DatePicker::make('data_inicio')->label('De')->displayFormat('d/m/Y'),
                        DatePicker::make('data_fim')->label('Até')->displayFormat('d/m/Y'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['data_inicio'], fn($q, $val) => $q->whereDate('created_at', '>=', $val))
                            ->when($data['data_fim'],   fn($q, $val) => $q->whereDate('created_at', '<=', $val));
                    }),

                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options([
                        'credit' => 'Crédito',
                        'debit'  => 'Débito',
                    ]),

                SelectFilter::make('client_id')
                    ->label('Seller')
                    // MUL-269 fase 2: label do seller vem do user (clients.company_name removido).
                    ->options(function (): array {
                        return Client::query()
                            ->join('users', 'users.id', '=', 'clients.user_id')
                            ->orderByRaw("COALESCE(NULLIF(users.full_name,''), users.name)")
                            ->select('clients.id', \Illuminate\Support\Facades\DB::raw("COALESCE(NULLIF(users.full_name,''), users.name) as label"))
                            ->pluck('label', 'clients.id')
                            ->toArray();
                    })
                    ->searchable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
