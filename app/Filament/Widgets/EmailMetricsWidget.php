<?php

namespace App\Filament\Widgets;

use App\Enums\EmailStatus;
use App\Models\EmailLog;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class EmailMetricsWidget extends BaseWidget
{
    protected static ?int $sort = 0;
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin']);
    }

    protected function getStats(): array
    {
        $sentStatuses = [
            EmailStatus::Sent->value,
            EmailStatus::Delivered->value,
            EmailStatus::Opened->value,
            EmailStatus::Clicked->value,
        ];

        $totalEnviados = EmailLog::whereIn('status', $sentStatuses)->count();

        $totalAbertos = EmailLog::whereIn('status', [
            EmailStatus::Opened->value,
            EmailStatus::Clicked->value,
        ])->count();

        $totalClicados = EmailLog::where('status', EmailStatus::Clicked->value)->count();

        $totalFalhas = EmailLog::whereIn('status', [
            EmailStatus::Failed->value,
            EmailStatus::Bounced->value,
        ])->count();

        $taxaAbertura = $totalEnviados > 0
            ? round(($totalAbertos / $totalEnviados) * 100, 1)
            : 0;

        $taxaClique = $totalEnviados > 0
            ? round(($totalClicados / $totalEnviados) * 100, 1)
            : 0;

        return [
            Stat::make('Total Enviados', number_format($totalEnviados))
                ->description('Status: enviado, entregue, aberto ou clicado')
                ->color('primary')
                ->icon('heroicon-o-paper-airplane'),

            Stat::make('Taxa de Abertura', $taxaAbertura . '%')
                ->description("{$totalAbertos} emails abertos")
                ->color('success')
                ->icon('heroicon-o-eye'),

            Stat::make('Taxa de Clique', $taxaClique . '%')
                ->description("{$totalClicados} emails clicados")
                ->color('info')
                ->icon('heroicon-o-cursor-arrow-rays'),

            Stat::make('Falhas', number_format($totalFalhas))
                ->description('Falhou + Rejeitado')
                ->color($totalFalhas > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-exclamation-triangle'),
        ];
    }
}
