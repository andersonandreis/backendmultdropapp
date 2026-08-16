<?php

namespace App\Filament\Pages;

use App\Models\Order;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Forms\Components\DatePicker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class PagamentosPedidos extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';
    protected static ?string $navigationGroup = 'Financeiro';
    protected static ?string $navigationLabel = 'Pagamentos de Pedidos';
    protected static ?string $title = 'Pagamentos de Pedidos';
    protected static ?string $slug = 'pagamentos-pedidos';
    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.pagamentos-pedidos';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Order::query()
                    ->with(['client', 'items.product'])
                    ->latest()
            )
            ->columns([
                TextColumn::make('order_number')
                    ->label('Nº Pedido')
                    ->fontFamily('mono')
                    ->copyable()
                    ->searchable(),

                TextColumn::make('source')
                    ->label('Canal')
                    ->badge()
                    ->formatStateUsing(fn(?string $state) => match (strtolower($state ?? '')) {
                        'mercadolivre','mercado_livre','mercado livre' => 'Mercado Livre',
                        'shopee' => 'Shopee',
                        'amazon' => 'Amazon',
                        'b2w'    => 'B2W',
                        'magalu' => 'Magalu',
                        'manual' => 'Manual',
                        default  => ucfirst($state ?? '-'),
                    })
                    ->color(fn(?string $state) => match (strtolower($state ?? '')) {
                        'mercadolivre','mercado_livre','mercado livre' => 'warning',
                        'shopee' => 'danger',
                        'amazon' => 'info',
                        default  => 'gray',
                    }),

                TextColumn::make('created_at')
                    ->label('Criado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                // MUL-269 fase 2: client.company_name via accessor; busca no user conectado.
                TextColumn::make('client.company_name')
                    ->label('Lojista')
                    ->searchable(query: fn ($query, $search) => $query->whereHas('client.user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('full_name', 'like', "%{$search}%"))),

                TextColumn::make('produtos_resumo')
                    ->label('Produtos')
                    ->html()
                    ->wrap()
                    ->getStateUsing(function (Order $record): HtmlString {
                        $items = $record->items ?? collect();
                        if ($items->isEmpty()) {
                            return new HtmlString('<span style="color:#94a3b8;font-size:0.75rem;">-</span>');
                        }
                        $html = '<div style="display:flex; flex-direction:column; gap:6px;">';
                        foreach ($items->take(3) as $item) {
                            $product = $item->product;
                            $imgUrl = null;
                            if ($product) {
                                $media = \App\Models\ProductMedia::where('product_id', $product->id)
                                    ->where('type','image')
                                    ->orderByDesc('is_cover')
                                    ->orderBy('position')
                                    ->first();
                                $imgUrl = $media?->url;
                                if ($imgUrl && !str_starts_with($imgUrl, 'http')) {
                                    $imgUrl = asset($imgUrl);
                                }
                            }
                            $imgFallback = 'https://ui-avatars.com/api/?name=' . urlencode(substr($item->sku ?? '?', 0, 2)) . '&background=1e293b&color=94a3b8&size=80';
                            $img = $imgUrl ?? $imgFallback;
                            $name = htmlspecialchars($item->name ?? $product?->name ?? 'Produto');
                            $sku  = htmlspecialchars($item->sku ?? '');
                            $html .= '<div style="display:flex; align-items:center; gap:8px;">';
                            $html .= '<img src="'.$img.'" style="width:36px; height:36px; border-radius:6px; object-fit:cover; border:1px solid rgba(255,255,255,0.1);" loading="lazy">';
                            $html .= '<div style="min-width:0; flex:1;">';
                            $html .= '<div style="font-size:0.78rem; font-weight:600; line-height:1.2; max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">'.$name.'</div>';
                            $html .= '<div style="font-size:0.7rem; opacity:0.6; font-family:monospace;">'.$sku.' x '.$item->quantity.'</div>';
                            $html .= '</div></div>';
                        }
                        if ($items->count() > 3) {
                            $html .= '<div style="font-size:0.7rem; opacity:0.6;">+ '.($items->count() - 3).' produto(s)</div>';
                        }
                        $html .= '</div>';
                        return new HtmlString($html);
                    }),

                TextColumn::make('total')
                    ->label('Valor')
                    ->money('BRL')
                    ->sortable()
                    ->weight('bold')
                    ->color('success'),

                TextColumn::make('status')
                    ->label('Pagamento')
                    ->badge()
                    ->formatStateUsing(fn(?string $state) => match (strtolower($state ?? '')) {
                        'paid'              => 'Confirmado',
                        'pending'           => 'Pendente',
                        'pending_payment'   => 'Aguardando',
                        'cancelled'         => 'Bloqueado',
                        'returned'          => 'Devolvido',
                        'shipped'           => 'Enviado',
                        'delivered'         => 'Entregue',
                        'refunded'          => 'Reembolsado',
                        default             => ucfirst($state ?? '-'),
                    })
                    ->color(fn(?string $state) => match (strtolower($state ?? '')) {
                        'paid','delivered','shipped' => 'success',
                        'pending','pending_payment'  => 'warning',
                        'cancelled','returned','refunded' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('wallet_paid_at')
                    ->label('Pago ao Fornecedor em')
                    ->badge()
                    ->getStateUsing(fn(Order $record): string => $record->wallet_paid_at
                        ? \Carbon\Carbon::parse($record->wallet_paid_at)->format('d/m/Y H:i')
                        : 'Pendente')
                    ->color(fn(Order $record): string => $record->wallet_paid_at ? 'success' : 'gray')
                    ->placeholder('-')
                    ->sortable(),
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

                SelectFilter::make('status')
                    ->label('Situação')
                    ->options([
                        'paid'      => 'Confirmado',
                        'pending'   => 'Pendente',
                        'cancelled' => 'Bloqueado',
                    ]),
            ])
            ->actions([
                \Filament\Tables\Actions\Action::make('ver_detalhe')
                    ->label('Detalhes')
                    ->icon('heroicon-m-eye')
                    ->color('info')
                    ->url(fn (Order $record) => route('filament.admin.resources.pedidos.edit', $record))
                    ->openUrlInNewTab(),
                \Filament\Tables\Actions\Action::make('bloquear')
                    ->label('Bloquear')
                    ->icon('heroicon-m-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Bloquear pagamento')
                    ->modalDescription('O pedido sera marcado como cancelado. Continuar?')
                    ->action(fn (Order $record) => $record->update(['status' => 'cancelled']))
                    ->visible(fn (Order $record) => in_array(strtolower($record->status ?? ''), ['paid','pending','pending_payment'])),
                \Filament\Tables\Actions\Action::make('marcar_pago')
                    ->label('Marcar Pago')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (Order $record) {
                        $record->update(['status' => 'paid', 'paid_at' => now()]);
                    })
                    ->visible(fn (Order $record) => in_array(strtolower($record->status ?? ''), ['pending','pending_payment'])),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
