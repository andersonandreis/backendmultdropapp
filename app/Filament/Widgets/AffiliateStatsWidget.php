<?php

namespace App\Filament\Widgets;

use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliateReferral;
use App\Models\AffiliateWithdrawal;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * SEL-345: Widget de KPIs do programa de afiliados.
 * Inspirado no StatsOverview do Tokfy — 6 cards no dashboard /admin.
 */
class AffiliateStatsWidget extends BaseWidget
{
    protected static ?int $sort = 5;
    protected static bool $isLazy = true;

    public static function canView(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'admin']);
    }

    protected function getStats(): array
    {
        $totalActive   = Affiliate::where('approval_status', 'approved')->count();
        $totalPending  = Affiliate::where('approval_status', 'pending')->count();

        $month         = now()->month;
        $year          = now()->year;
        $monthReferrals = AffiliateReferral::whereMonth('attributed_at', $month)
            ->whereYear('attributed_at', $year)->count();
        $monthConversions = AffiliateReferral::where('status', 'converted')
            ->whereMonth('converted_at', $month)->whereYear('converted_at', $year)->count();

        $commPending = (float) AffiliateCommission::where('status', 'pending')->sum('commission_amount');
        $commPaid    = (float) AffiliateCommission::where('status', 'paid')->sum('commission_amount');
        $withdrawPending = (float) AffiliateWithdrawal::where('status', 'pending')->sum('amount');

        $convRate = $monthReferrals > 0
            ? round(($monthConversions / $monthReferrals) * 100, 1)
            : 0;

        return [
            Stat::make('Afiliados Ativos', $totalActive)
                ->description($totalPending > 0 ? "{$totalPending} aguardando aprovacao" : 'Todos aprovados')
                ->color($totalPending > 0 ? 'warning' : 'success')
                ->icon('heroicon-o-user-group')
                ->url('/admin/afiliados?tableFilters[approval_status][value]=pending'),

            Stat::make('Cliques este mes', $monthReferrals)
                ->description("{$monthConversions} convertidos ({$convRate}%)")
                ->color('info')
                ->icon('heroicon-o-cursor-arrow-rays'),

            Stat::make('Comissao Pendente', 'R$ ' . number_format($commPending, 2, ',', '.'))
                ->description('Aguardando aprovacao/pagamento')
                ->color($commPending > 0 ? 'warning' : 'success')
                ->icon('heroicon-o-clock')
                ->url('/admin/comissoes-afiliados?tableFilters[status][value]=pending'),

            Stat::make('Comissao Total Paga', 'R$ ' . number_format($commPaid, 2, ',', '.'))
                ->description('Historico total de pagamentos')
                ->color('success')
                ->icon('heroicon-o-banknotes'),

            Stat::make('Saques Pendentes', 'R$ ' . number_format($withdrawPending, 2, ',', '.'))
                ->description($withdrawPending > 0 ? 'Pagar no dia 15' : 'Nenhum pendente')
                ->color($withdrawPending > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-arrow-up-tray'),

            Stat::make('Conversoes este mes', $monthConversions)
                ->description('Clientes que fizeram upgrade')
                ->color($monthConversions > 0 ? 'success' : 'gray')
                ->icon('heroicon-o-arrow-trending-up'),
        ];
    }
}
