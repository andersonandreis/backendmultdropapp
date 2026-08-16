<?php

namespace App\Models\Drop;

use Illuminate\Database\Eloquent\Model;
use App\Models\Client;

/**
 * Modelo de relatorio financeiro periodico para clientes Drop Internacional.
 *
 * @property int    $id
 * @property int    $client_id
 * @property string $period_start         Data inicio do periodo (YYYY-MM-DD)
 * @property string $period_end           Data fim do periodo (YYYY-MM-DD)
 * @property float  $total_revenue        Receita total no periodo (USD)
 * @property float  $total_cost_product   Custo total de produtos (USD)
 * @property float  $total_cost_shipping  Custo total de frete (USD)
 * @property float  $total_gateway_fees   Total de taxas de gateway (USD)
 * @property float  $total_platform_fees  Total de taxas de plataforma (USD)
 * @property float  $total_chargebacks    Total de chargebacks (USD)
 * @property float  $total_refunds        Total de reembolsos (USD)
 * @property float  $gross_profit         Lucro bruto (USD)
 * @property float  $net_profit           Lucro liquido (USD)
 * @property int    $orders_count         Total de pedidos no periodo
 * @property int    $profitable_orders    Pedidos com lucro positivo
 * @property int    $loss_orders          Pedidos com prejuizo
 */
class DropProfitReport extends Model
{
    protected $table = 'drop_profit_reports';

    protected $fillable = [
        'client_id',
        'period_start',
        'period_end',
        'total_revenue',
        'total_cost_product',
        'total_cost_shipping',
        'total_gateway_fees',
        'total_platform_fees',
        'total_chargebacks',
        'total_refunds',
        'gross_profit',
        'net_profit',
        'orders_count',
        'profitable_orders',
        'loss_orders',
    ];

    protected $casts = [
        'period_start'        => 'date',
        'period_end'          => 'date',
        'total_revenue'       => 'decimal:4',
        'total_cost_product'  => 'decimal:4',
        'total_cost_shipping' => 'decimal:4',
        'total_gateway_fees'  => 'decimal:4',
        'total_platform_fees' => 'decimal:4',
        'total_chargebacks'   => 'decimal:4',
        'total_refunds'       => 'decimal:4',
        'gross_profit'        => 'decimal:4',
        'net_profit'          => 'decimal:4',
    ];

    // -------------------------------------------------------------------------
    // Relacionamentos
    // -------------------------------------------------------------------------

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    // -------------------------------------------------------------------------
    // Acessores calculados
    // -------------------------------------------------------------------------

    /**
     * Margem liquida percentual sobre a receita total.
     */
    public function getNetMarginPctAttribute(): float
    {
        if ((float) $this->total_revenue <= 0) {
            return 0.0;
        }
        return round(((float) $this->net_profit / (float) $this->total_revenue) * 100, 2);
    }
}
