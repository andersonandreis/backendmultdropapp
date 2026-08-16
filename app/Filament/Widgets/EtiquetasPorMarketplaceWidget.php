<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\ImprimirEtiquetas;
use App\Models\Order;
use Filament\Widgets\Widget;

class EtiquetasPorMarketplaceWidget extends Widget
{
    protected static string $view = 'filament.widgets.etiquetas-por-marketplace';

    protected static ?int $sort = 2;

    protected static bool $isLazy = false;

    // MUL-226-11: meia largura — fica lado a lado com o card "Status dos Pedidos"
    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    /**
     * MUL-226-09: mesmos canais/sources e mesma query da página Imprimir Etiquetas —
     * o total de pendentes bate com a fila de impressão por construção.
     */
    public function getCanaisData(): array
    {
        $map = ImprimirEtiquetas::canalSourceMap();
        $knownSources = array_merge(...array_values($map));
        $knownSources = array_diff($knownSources, $map['TikTok Shop']);

        $data = [];
        foreach (array_keys($map) as $canal) {
            $applyCanal = function ($query) use ($canal, $map, $knownSources) {
                if ($canal === 'TikTok Shop') {
                    $query->where(function ($q) use ($map, $knownSources) {
                        $q->whereIn('source', $map['TikTok Shop'])
                          ->orWhereNotIn('source', $knownSources);
                    });
                } else {
                    $query->whereIn('source', $map[$canal]);
                }

                return $query;
            };

            $pendentes = $applyCanal(
                Order::query()
                    ->whereNotNull('label_url')
                    ->whereNull('label_printed_at')
                    ->whereNull('blocked_at')
                    ->whereIn('order_processing_status', ['awaiting_dispatch', 'separated'])
            )->count();

            $embaladas = $applyCanal(
                Order::query()
                    ->whereNotNull('label_printed_at')
                    ->whereIn('order_processing_status', ['awaiting_dispatch', 'separating', 'separated', 'awaiting_shipment'])
            )->count();

            $data[] = [
                'canal'     => $canal,
                'pendentes' => $pendentes,
                'embaladas' => $embaladas,
                'cor'       => match ($canal) {
                    'MercadoLivre' => '#eab308',
                    'Shopee'       => '#f97316',
                    'Amazon'       => '#3b82f6',
                    'Magalu'       => '#a855f7',
                    'TikTok Shop'  => '#ec4899',
                    default        => '#6b7280',
                },
            ];
        }

        return $data;
    }
}
