<?php

namespace App\Filament\App\Pages;

use App\Models\ClientSupplierBalance;
use App\Models\ClientSupplierTransaction;
use App\Models\PixTransaction;
use App\Models\Supplier;
use App\Services\Financial\PixService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

class WalletStatement extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-wallet';
    protected static ?string $navigationLabel = 'Minha Wallet';
    protected static ?string $title = 'Extrato de Wallet';
    protected static ?string $slug = 'wallet';
    protected static ?int $navigationSort = 30;

    // Estado da página
    public ?int $selectedSupplierId = null;
    public ?string $pixQrCode = null;
    public ?string $pixCopyPaste = null;
    public ?string $pixTransactionId = null;
    public bool $showingPix = false;
    public int $pixCountdown = 1800; // 30 minutos em segundos

    public function mount(): void
    {
        $balances = $this->getClientBalances();
        if ($balances->isNotEmpty()) {
            $this->selectedSupplierId = $balances->first()->supplier_id;
        }
    }

    protected function getClientId(): ?int
    {
        $user = auth()->user();
        return $user->client_id ?? ($user->client->id ?? null);
    }

    protected function getClientBalances()
    {
        $clientId = $this->getClientId();
        if (!$clientId) return collect();

        return ClientSupplierBalance::where('client_id', $clientId)
            ->with('supplier')
            ->get();
    }

    public function table(Table $table): Table
    {
        $clientId = $this->getClientId();

        return $table
            ->query(
                ClientSupplierTransaction::query()
                    ->where('client_id', $clientId)
                    ->when($this->selectedSupplierId, fn ($q) => $q->where('supplier_id', $this->selectedSupplierId))
            )
            ->columns([
                TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('transaction_type')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'pix_topup'   => 'success',
                        'order_debit' => 'danger',
                        'refund'      => 'info',
                        default       => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'pix_topup'   => 'Recarga PIX',
                        'order_debit' => 'Debito Pedido',
                        'refund'      => 'Reembolso',
                        default       => $state ?? 'Transação',
                    }),

                TextColumn::make('description')
                    ->label('Descrição')
                    ->limit(40),

                TextColumn::make('amount')
                    ->label('Valor')
                    ->formatStateUsing(function ($state, $record) {
                        $prefix = in_array($record->type, ['credit']) ? '+' : '-';
                        $color  = in_array($record->type, ['credit']) ? 'text-green-500' : 'text-red-500';
                        return "{$prefix}R$ " . number_format(abs($state), 2, ',', '.');
                    })
                    ->html(),

                TextColumn::make('running_balance')
                    ->label('Saldo após')
                    ->money('BRL'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('transaction_type')
                    ->label('Tipo')
                    ->options([
                        'pix_topup'   => 'Recarga PIX',
                        'order_debit' => 'Débito Pedido',
                        'refund'      => 'Reembolso',
                    ]),
            ])
            ->emptyStateHeading('Sem transações')
            ->emptyStateDescription('Nenhuma transação encontrada para este fornecedor.')
            ->emptyStateIcon('heroicon-o-banknotes');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recarregar_saldo')
                ->label('Recarregar Saldo')
                ->icon('heroicon-o-plus-circle')
                ->color('success')
                ->form([
                    Select::make('supplier_id')
                        ->label('Fornecedor')
                        ->options(function () {
                            $clientId = $this->getClientId();
                            return ClientSupplierBalance::where('client_id', $clientId)
                                ->with('supplier')
                                ->get()
                                ->pluck('supplier.company_name', 'supplier_id');
                        })
                        ->default($this->selectedSupplierId)
                        ->required(),

                    TextInput::make('valor')
                        ->label('Valor da recarga')
                        ->numeric()
                        ->prefix('R$')
                        ->minValue(10)
                        ->required()
                        ->helperText('Valor mínimo: R$ 10,00'),
                ])
                ->action(function (array $data) {
                    $clientId = $this->getClientId();
                    if (!$clientId) {
                        Notification::make()->title('Erro: cliente não localizado.')->danger()->send();
                        return;
                    }

                    $supplier = Supplier::find($data['supplier_id']);
                    if (!$supplier) {
                        Notification::make()->title('Fornecedor não encontrado.')->danger()->send();
                        return;
                    }

                    try {
                        $client = auth()->user()->client;
                        $pix    = app(PixService::class)->createWalletTopup($client, $supplier, (float) $data['valor']);

                        $this->pixQrCode       = $pix->qr_code ?? null;
                        $this->pixCopyPaste    = $pix->qr_code_text;
                        $this->pixTransactionId = (string) $pix->id;
                        $this->showingPix      = true;
                        $this->pixCountdown    = 1800;

                        Notification::make()
                            ->title('PIX gerado com sucesso!')
                            ->body('Escaneie o QR Code ou copie o código PIX para pagar.')
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Erro ao gerar PIX')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    public function selectSupplier(int $supplierId): void
    {
        $this->selectedSupplierId = $supplierId;
        $this->resetTable();
    }

    public function checkPixStatus(): void
    {
        if (!$this->pixTransactionId) return;

        try {
            $pix = PixTransaction::find($this->pixTransactionId);
            if ($pix) {
                app(PixService::class)->syncPixStatus($pix);
                $pix->refresh();

                if ($pix->status === 'paid') {
                    $this->showingPix = false;
                    $this->resetTable();
                    Notification::make()
                        ->title('Pagamento confirmado!')
                        ->body('Seu saldo foi atualizado.')
                        ->success()
                        ->send();
                }
            }
        } catch (\Exception $e) {
            // Silencioso — polling vai tentar novamente
        }
    }

    protected function getViewData(): array
    {
        $balances = $this->getClientBalances();

        return [
            'balances'            => $balances,
            'selectedSupplierId'  => $this->selectedSupplierId,
            'pixQrCode'           => $this->pixQrCode,
            'pixCopyPaste'        => $this->pixCopyPaste,
            'showingPix'          => $this->showingPix,
        ];
    }

    protected static string $view = 'filament.app.pages.wallet-statement';
}
