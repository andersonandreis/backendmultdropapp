<?php

namespace App\Filament\Widgets;

use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * MUL-226-11: gráfico interativo do dashboard do fornecedor.
 * - Visões: faturamento total (com comparação dia-a-dia dos 30 dias anteriores),
 *   por marketplace, por canal de envio e por categoria.
 * - Tooltip: top 10 produtos vendidos no dia + canal (padrão do dashboard do seller).
 */
class SupplierSalesChart extends ChartWidget
{
    protected static ?string $heading = 'Desempenho de Vendas (Últimos 30 Dias)';
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public ?string $filter = 'total';

    private const STATUSES = ['paid', 'separated', 'shipped', 'completed'];

    protected function getFilters(): ?array
    {
        return [
            'total'       => 'Faturamento (vs. período anterior)',
            'marketplace' => 'Por Marketplace',
            'shipping'    => 'Por Canal de Envio',
            'category'    => 'Por Categoria',
        ];
    }

    public function getDescription(): ?string
    {
        return 'Passe o mouse sobre um dia pra ver o top 10 de produtos vendidos e o canal.';
    }

    private function supplierId(): ?int
    {
        $user = auth()->user();

        return ($user?->profile === 'supplier' && $user->supplier) ? $user->supplier->id : null;
    }

    private function baseOrders(Carbon $from, ?Carbon $to = null)
    {
        return Order::query()
            ->where('orders.created_at', '>=', $from)
            ->when($to, fn ($q) => $q->where('orders.created_at', '<', $to))
            ->whereIn('orders.status', self::STATUSES)
            ->when($this->supplierId(), fn ($q, $v) => $q->where('orders.supplier_id', $v));
    }

    private function dayLabels(Carbon $start): array
    {
        $labels = [];
        for ($i = 0; $i < 30; $i++) {
            $labels[] = $start->copy()->addDays($i)->format('d/m');
        }

        return $labels;
    }

    private function dimensionColor(string $key): string
    {
        $k = mb_strtolower($key);

        return match (true) {
            str_contains($k, 'mercado') || $k === 'ml' => '#eab308',
            str_contains($k, 'shopee')                 => '#f97316',
            str_contains($k, 'tiktok')                 => '#ec4899',
            str_contains($k, 'amazon')                 => '#3b82f6',
            str_contains($k, 'magalu')                 => '#a855f7',
            str_contains($k, 'bling')                  => '#10b981',
            default => ['#6366f1', '#14b8a6', '#f43f5e', '#84cc16', '#0ea5e9', '#f59e0b', '#6b7280'][crc32($k) % 7],
        };
    }

    protected function getData(): array
    {
        $start = Carbon::today()->subDays(29);
        $labels = $this->dayLabels($start);

        if ($this->filter === 'total') {
            return $this->dataTotal($start, $labels);
        }

        [$column, $joinProducts] = match ($this->filter) {
            'marketplace' => ['orders.source', false],
            'shipping'    => ['orders.shipping_mode', false],
            'category'    => ['categories.name', true],
            default       => ['orders.source', false],
        };

        return $this->dataByDimension($start, $labels, $column, $joinProducts);
    }

    private function dataTotal(Carbon $start, array $labels): array
    {
        $current = $this->baseOrders($start)
            ->select(DB::raw('DATE(orders.created_at) as date'), DB::raw('SUM(orders.total) as total'))
            ->groupBy('date')
            ->pluck('total', 'date');

        $prevStart = $start->copy()->subDays(30);
        $previous = $this->baseOrders($prevStart, $start)
            ->select(DB::raw('DATE(orders.created_at) as date'), DB::raw('SUM(orders.total) as total'))
            ->groupBy('date')
            ->pluck('total', 'date');

        $dataAtual = [];
        $dataAnterior = [];
        for ($i = 0; $i < 30; $i++) {
            $dataAtual[] = (float) ($current[$start->copy()->addDays($i)->format('Y-m-d')] ?? 0);
            $dataAnterior[] = (float) ($previous[$prevStart->copy()->addDays($i)->format('Y-m-d')] ?? 0);
        }

        return [
            'datasets' => [
                [
                    'label'           => 'Faturamento (R$)',
                    'data'            => $dataAtual,
                    'borderColor'     => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.2)',
                    'fill'            => true,
                ],
                [
                    'label'       => 'Período anterior (R$)',
                    'data'        => $dataAnterior,
                    'borderColor' => '#94a3b8',
                    'borderDash'  => [6, 4],
                    'pointRadius' => 0,
                    'fill'        => false,
                ],
            ],
            'labels' => $labels,
        ];
    }

    private function dataByDimension(Carbon $start, array $labels, string $column, bool $joinProducts): array
    {
        $query = $this->baseOrders($start);

        if ($joinProducts) {
            $query->join('order_items', 'order_items.order_id', '=', 'orders.id')
                ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
                ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
                ->select(
                    DB::raw('DATE(orders.created_at) as date'),
                    DB::raw("COALESCE({$column}, 'Sem categoria') as dim"),
                    DB::raw('SUM(order_items.total) as total')
                );
        } else {
            $query->select(
                DB::raw('DATE(orders.created_at) as date'),
                DB::raw("COALESCE({$column}, 'outros') as dim"),
                DB::raw('SUM(orders.total) as total')
            );
        }

        $rows = $query->groupBy('date', 'dim')->get();

        $topDims = $rows->groupBy('dim')
            ->map(fn ($g) => $g->sum('total'))
            ->sortDesc()
            ->take(6)
            ->keys()
            ->all();

        $byDim = $rows->whereIn('dim', $topDims)->groupBy('dim');

        $datasets = [];
        foreach ($topDims as $dim) {
            $porData = ($byDim[$dim] ?? collect())->pluck('total', 'date');
            $serie = [];
            for ($i = 0; $i < 30; $i++) {
                $serie[] = (float) ($porData[$start->copy()->addDays($i)->format('Y-m-d')] ?? 0);
            }

            $datasets[] = [
                'label'       => (string) $dim,
                'data'        => $serie,
                'borderColor' => $this->dimensionColor((string) $dim),
                'fill'        => false,
                'tension'     => 0.3,
            ];
        }

        return ['datasets' => $datasets, 'labels' => $labels];
    }

    /**
     * Top 10 produtos por dia (com canal), pré-computado pro tooltip do Chart.js.
     */
    private function tooltipTopProdutos(Carbon $start): array
    {
        $supplierId = $this->supplierId();
        // statuses são constante do código; supplier vai por binding
        $statusIn = "'" . implode("','", self::STATUSES) . "'";
        $supplierWhere = $supplierId ? 'AND o.supplier_id = ?' : '';
        $bindings = $supplierId
            ? [$start->toDateTimeString(), $supplierId]
            : [$start->toDateTimeString()];

        // MariaDB 10.11: window function limita o top 10 por dia direto no SQL
        $rows = DB::select("
            SELECT t.date, t.name, t.source, t.qty FROM (
                SELECT DATE(o.created_at) AS date, oi.name, o.source, SUM(oi.quantity) AS qty,
                       ROW_NUMBER() OVER (PARTITION BY DATE(o.created_at) ORDER BY SUM(oi.quantity) DESC) AS rn
                FROM order_items oi
                JOIN orders o ON o.id = oi.order_id
                WHERE o.created_at >= ? AND o.status IN ({$statusIn}) {$supplierWhere}
                GROUP BY DATE(o.created_at), oi.name, o.source
            ) t
            WHERE t.rn <= 10
            ORDER BY t.date, t.qty DESC
        ", $bindings);

        $map = [];
        foreach ($rows as $r) {
            $label = Carbon::parse($r->date)->format('d/m');
            $pos = count($map[$label] ?? []) + 1;
            $nome = mb_strimwidth((string) ($r->name ?: 'Produto'), 0, 38, '…');
            $map[$label][] = $pos . '. ' . (int) $r->qty . '× ' . $nome . ' (' . ($r->source ?: '—') . ')';
        }

        return $map;
    }

    protected function getOptions(): RawJs
    {
        $top = json_encode($this->tooltipTopProdutos(Carbon::today()->subDays(29)), JSON_UNESCAPED_UNICODE) ?: '{}';

        $js = <<<'JS'
        {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                tooltip: {
                    callbacks: {
                        footer: function (items) {
                            const map = __TOP_PRODUTOS__;
                            const rows = map[items[0]?.label] || [];
                            return rows.length ? ['', 'Top 10 produtos do dia:'].concat(rows) : [];
                        },
                    },
                },
            },
            scales: { y: { beginAtZero: true } },
        }
        JS;

        return RawJs::make(str_replace('__TOP_PRODUTOS__', $top, $js));
    }

    protected function getType(): string
    {
        return 'line';
    }
}
