<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/** NOV-135 — Análise de velocidade de vendas (top 20 mais rápidos e mais lentos). */
class SalesVelocityPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-rocket-launch';
    protected static ?string $navigationGroup = 'Análises';
    protected static ?string $title = 'Velocidade de Vendas';
    protected static ?string $slug = 'velocidade-vendas';
    protected static string $view = 'filament.pages.sales-velocity';
    protected static ?int $navigationSort = 20;

    public array $fastest = [];
    public array $slowest = [];
    public int $daysWindow = 30;

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'supplier']);
    }

    public function mount(): void
    {
        $this->loadData();
    }

    public function changeWindow(int $days): void
    {
        $this->daysWindow = max(7, min($days, 365));
        $this->loadData();
    }

    public function loadData(): void
    {
        $supplierId = auth()->user()?->supplier?->id;
        $supplierFilter = '';
        $params = ['days' => $this->daysWindow];
        if ($supplierId && auth()->user()->role !== 'super_admin') {
            $supplierFilter = ' AND o.supplier_id = :supplier_id';
            $params['supplier_id'] = $supplierId;
        }

        $sql = "
            SELECT
                p.id,
                p.name,
                p.sku,
                COALESCE(SUM(oi.quantity), 0) AS qty_sold,
                COUNT(DISTINCT oi.order_id) AS orders_count,
                DATEDIFF(NOW(), MIN(p.created_at)) AS days_in_catalog,
                CASE
                    WHEN DATEDIFF(NOW(), MIN(p.created_at)) > 0
                    THEN ROUND(COALESCE(SUM(oi.quantity), 0) / DATEDIFF(NOW(), MIN(p.created_at)), 3)
                    ELSE COALESCE(SUM(oi.quantity), 0)
                END AS velocity_per_day
            FROM products p
            LEFT JOIN order_items oi ON oi.product_id = p.id
            LEFT JOIN orders o ON o.id = oi.order_id
                AND o.created_at >= NOW() - INTERVAL :days DAY
                AND o.status IN ('paid', 'shipped', 'delivered', 'completed')
                {$supplierFilter}
            WHERE p.is_active = 1
            GROUP BY p.id, p.name, p.sku
            HAVING qty_sold > 0
            ORDER BY velocity_per_day DESC
            LIMIT 20
        ";
        $this->fastest = json_decode(json_encode(DB::select($sql, $params)), true);

        $sql2 = "
            SELECT
                p.id,
                p.name,
                p.sku,
                COALESCE(SUM(oi.quantity), 0) AS qty_sold,
                COUNT(DISTINCT oi.order_id) AS orders_count,
                DATEDIFF(NOW(), MIN(p.created_at)) AS days_in_catalog
            FROM products p
            LEFT JOIN order_items oi ON oi.product_id = p.id
                AND oi.created_at >= NOW() - INTERVAL :days DAY
            LEFT JOIN orders o ON o.id = oi.order_id
                AND o.status IN ('paid', 'shipped', 'delivered', 'completed')
                {$supplierFilter}
            WHERE p.is_active = 1
              AND DATEDIFF(NOW(), p.created_at) >= 30
            GROUP BY p.id, p.name, p.sku
            ORDER BY qty_sold ASC, days_in_catalog DESC
            LIMIT 20
        ";
        $this->slowest = json_decode(json_encode(DB::select($sql2, $params)), true);
    }
}
