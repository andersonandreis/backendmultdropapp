<?php

namespace App\Filament\Pages;

use App\Models\Payment;
use App\Models\Supplier;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PaymentReport extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationGroup = 'Análises';
    protected static ?string $navigationLabel = 'Relatório de Pagamentos';
    protected static ?string $title = 'Relatório de Pagamentos';
    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.payment-report';

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin']);
    }

    // -------------------------------------------------------------------------
    // KPI helpers
    // -------------------------------------------------------------------------

    public function getTotalCobradoProperty(): float
    {
        return Payment::where('status', 'paid')->sum('amount');
    }

    public function getTotalPixProperty(): float
    {
        return Payment::where('status', 'paid')->sum('pix_amount');
    }

    public function getTotalWalletProperty(): float
    {
        return Payment::where('status', 'paid')->sum('wallet_amount');
    }

    public function getTaxaMediaProperty(): float
    {
        return Payment::where('status', 'paid')->avg('fee_amount') ?? 0;
    }

    public function getTotalReembolsosProperty(): float
    {
        return Payment::whereNotNull('refunded_at')->sum('refund_amount');
    }

    // -------------------------------------------------------------------------
    // Table
    // -------------------------------------------------------------------------

    public function table(Table $table): Table
    {
        return $table
            ->query(Payment::query()->with(['order', 'client', 'supplier'])->latest('created_at'))
            ->columns([
                TextColumn::make('order_id')
                    ->label('Pedido')
                    ->searchable(),

                // MUL-269 fase 2: client.company_name via accessor; busca no user conectado.
                TextColumn::make('client.company_name')
                    ->label('Cliente')
                    ->searchable(query: fn ($query, $search) => $query->whereHas('client.user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('full_name', 'like', "%{$search}%"))),

                TextColumn::make('supplier.company_name')
                    ->label('Fornecedor')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('method')
                    ->label('Método')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'pix'         => 'info',
                        'wallet'      => 'success',
                        'pix+wallet'  => 'primary',
                        default       => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'pix'        => 'PIX',
                        'wallet'     => 'Wallet',
                        'pix+wallet' => 'PIX + Wallet',
                        default      => $state ?? '-',
                    }),

                TextColumn::make('amount')
                    ->label('Total')
                    ->money('BRL')
                    ->sortable(),

                TextColumn::make('wallet_amount')
                    ->label('Wallet')
                    ->money('BRL')
                    ->toggleable(),

                TextColumn::make('pix_amount')
                    ->label('PIX')
                    ->money('BRL')
                    ->toggleable(),

                TextColumn::make('fee_amount')
                    ->label('Taxa')
                    ->money('BRL')
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'paid'     => 'success',
                        'pending'  => 'warning',
                        'failed'   => 'danger',
                        'refunded' => 'info',
                        default    => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'paid'     => 'Pago',
                        'pending'  => 'Pendente',
                        'failed'   => 'Falhou',
                        'refunded' => 'Reembolsado',
                        default    => $state ?? '-',
                    }),

                TextColumn::make('paid_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('supplier')
                    ->label('Fornecedor')
                    ->relationship('supplier', 'company_name'),

                SelectFilter::make('gateway')
                    ->label('Gateway')
                    ->options([
                        'asaas'  => 'Asaas',
                        'shipay' => 'Shipay',
                        'pagarme' => 'Pagar.me',
                    ]),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'paid'     => 'Pago',
                        'pending'  => 'Pendente',
                        'failed'   => 'Falhou',
                        'refunded' => 'Reembolsado',
                    ]),

                Filter::make('periodo')
                    ->form([
                        DatePicker::make('de')->label('De'),
                        DatePicker::make('ate')->label('Até'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['de'],  fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
                            ->when($data['ate'], fn ($q, $v) => $q->whereDate('created_at', '<=', $v));
                    })
                    ->label('Período'),
            ])
            ->emptyStateHeading('Nenhum pagamento encontrado')
            ->emptyStateIcon('heroicon-o-banknotes');
    }
}
