<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OrderLogisticsStats extends BaseWidget
{
    protected static ?int $sort = 1;
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    protected function getStats(): array
    {
        $startOfMonth = Carbon::now()->startOfMonth();
        $supplierId = (auth()->user()?->profile === 'supplier' && auth()->user()->supplier)
            ? auth()->user()->supplier->id
            : null;

        $base = fn () => $supplierId
            ? Order::query()->where('supplier_id', $supplierId)
            : Order::query();

        // Etiquetas impressas no mes (orders com label_printed_at >= inicio do mes)
        $impressas = (clone $base())
            ->whereNotNull('label_printed_at')
            ->where('label_printed_at', '>=', $startOfMonth)
            ->count();

        $impressasOntem = (clone $base())
            ->whereNotNull('label_printed_at')
            ->whereDate('label_printed_at', Carbon::yesterday())
            ->count();

        // Pedidos enviados no mes
        $enviadas = (clone $base())
            ->whereNotNull('shipped_at')
            ->where('shipped_at', '>=', $startOfMonth)
            ->count();

        $enviadasHoje = (clone $base())
            ->whereNotNull('shipped_at')
            ->whereDate('shipped_at', Carbon::today())
            ->count();

        // MUL-378: "aguardando etiqueta" e pedido pago que AINDA NAO TEM etiqueta.
        // Media anterior (ops=awaiting_label + status='paid'): 12.886 pedidos, dos quais
        // 6 JA tinham etiqueta e 74 JA estavam enviados. O erro tinha duas pontas:
        //   - orders.status='paid' e o balaio de 12.683 pedidos sem etiqueta (canonical
        //     'created'), nao o pago pronto pra expedir;
        //   - order_processing_status nasce 'awaiting_label' por default da coluna e nem
        //     sempre avanca, entao ele nao prova ausencia de etiqueta — label_url prova.
        // Regra nova, medida em 17/08/2026: 162 pedidos.
        $semEtiqueta = ['paid', 'processing', 'awaiting_shipment'];
        $aguardando = (clone $base())
            ->where('is_draft', false)
            ->whereNotNull('paid_at')
            ->where(function ($w) {
                $w->whereNull('label_url')->orWhere('label_url', '');
            })
            ->whereNull('shipped_at')
            ->whereIn('canonical_status', $semEtiqueta)
            ->count();

        // MUL-378: mesmo problema no alarme de SLA — marcava 12.474 pedidos, entao nunca
        // significou nada. canonical_status separa trabalho real (paid/processing) do
        // balaio 'created', e cancelado/entregue ficam fora por nao aceitarem mais envio.
        // Regra nova: 182 pedidos.
        $atrasados = (clone $base())
            ->where('is_draft', false)
            ->whereIn('canonical_status', ['paid', 'processing'])
            ->whereNotNull('paid_at')
            ->where('paid_at', '<=', now()->subHours(48))
            ->whereNull('shipped_at')
            ->count();

        return [
            Stat::make('Etiquetas Impressas (mês)', number_format($impressas, 0, ',', '.'))
                ->description($impressasOntem > 0 ? "$impressasOntem ontem" : 'Nenhuma ontem')
                ->descriptionIcon('heroicon-m-printer')
                ->color($impressas > 0 ? 'info' : 'gray'),

            Stat::make('Pedidos Enviados (mês)', number_format($enviadas, 0, ',', '.'))
                ->description($enviadasHoje > 0 ? "$enviadasHoje hoje" : 'Nenhum hoje')
                ->descriptionIcon('heroicon-m-truck')
                ->color($enviadas > 0 ? 'success' : 'gray'),

            Stat::make('Aguardando Etiqueta', number_format($aguardando, 0, ',', '.'))
                ->description($aguardando > 0 ? 'Requer ação' : 'Tudo em dia')
                ->descriptionIcon('heroicon-m-clock')
                ->color($aguardando > 20 ? 'warning' : ($aguardando > 0 ? 'info' : 'success')),

            Stat::make('SLA: Pagos > 48h sem envio', number_format($atrasados, 0, ',', '.'))
                ->description($atrasados > 0 ? 'Atenção urgente' : 'OK')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($atrasados > 0 ? 'danger' : 'success'),
        ];
    }
}
