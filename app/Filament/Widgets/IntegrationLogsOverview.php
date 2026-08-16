<?php

namespace App\Filament\Widgets;

use App\Models\IntegrationLog;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * HUB-032 — Widget de overview pra pagina de Logs de Integracoes.
 *
 * Mostra: total nas ultimas 24h, taxa de sucesso (2xx), erros 5xx,
 * top integracao por volume.
 *
 * PERF 2026-07-16: era $isLazy=false + 5 counts diretos numa tabela com 5.7M rows.
 * Cada login no /admin dispara ~1min de query lenta antes do dashboard aparecer.
 * Fix: lazy + cache 5min (as metricas de "24h" nao precisam ser real-time).
 * Alem disso, uma unica query agregada substitui as 4 contagens separadas.
 */
class IntegrationLogsOverview extends BaseWidget
{
    protected static ?int $sort = 0;
    protected static bool $isLazy = true;  // 2026-07-16: nao bloqueia render do dashboard

    public static function canView(): bool
    {
        return auth()->user()?->role === 'super_admin';
    }

    protected function getStats(): array
    {
        // PERF: as estatisticas de 24h nao precisam refletir instantaneamente;
        // 5min de cache reduz drasticamente a carga do dashboard admin.
        [$total, $ok, $err5xx, $err4xx, $topLabel] = Cache::remember(
            'widget_integration_logs_overview_24h',
            300,
            fn () => $this->computeStats()
        );

        $pct = $total > 0 ? round(($ok / $total) * 100, 1) : 0;

        return [
            Stat::make('Chamadas (24h)', number_format($total))
                ->description('Inbound + outbound')
                ->color('info')
                ->icon('heroicon-o-bolt'),

            Stat::make('Taxa de sucesso', $pct . '%')
                ->description("{$ok} de {$total} com HTTP 2xx")
                ->color($pct >= 95 ? 'success' : ($pct >= 80 ? 'warning' : 'danger'))
                ->icon('heroicon-o-check-circle'),

            Stat::make('Erros 4xx', number_format($err4xx))
                ->description('Cliente / autorizacao')
                ->color($err4xx > 100 ? 'warning' : 'gray')
                ->icon('heroicon-o-exclamation-triangle'),

            Stat::make('Erros 5xx', number_format($err5xx))
                ->description('Servidor / integracao')
                ->color($err5xx > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-x-circle'),

            Stat::make('Top integracao', $topLabel)
                ->description('Maior volume nas 24h')
                ->color('gray')
                ->icon('heroicon-o-trophy'),
        ];
    }

    /**
     * PERF: 1 query agregada em vez de 4 counts separados. Usa o indice
     * (created_at, status_code) que adicionamos junto neste fix.
     */
    private function computeStats(): array
    {
        $since = now()->subDay();

        $row = IntegrationLog::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status_code BETWEEN 200 AND 299 THEN 1 ELSE 0 END) as ok,
                SUM(CASE WHEN status_code >= 500 THEN 1 ELSE 0 END) as err5xx,
                SUM(CASE WHEN status_code BETWEEN 400 AND 499 THEN 1 ELSE 0 END) as err4xx
            ')
            ->first();

        $topIntegration = IntegrationLog::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('integration_name, COUNT(*) as c')
            ->groupBy('integration_name')
            ->orderByDesc('c')
            ->first();

        $topLabel = $topIntegration
            ? "{$topIntegration->integration_name} ({$topIntegration->c})"
            : '—';

        return [
            (int) ($row->total ?? 0),
            (int) ($row->ok ?? 0),
            (int) ($row->err5xx ?? 0),
            (int) ($row->err4xx ?? 0),
            $topLabel,
        ];
    }
}
