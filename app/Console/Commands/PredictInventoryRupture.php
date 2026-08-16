<?php

namespace App\Console\Commands;

use App\Models\Inventory;
use App\Models\OrderItem;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PredictInventoryRupture extends Command
{
    protected $signature = 'hubai:predict-rupture';
    protected $description = 'Calcula a media de vendas de produto nos ultimos 30 dias e cruza com estoque atual para prever ruptura.';

    public function handle()
    {
        $this->info('Iniciando Motor Analitico Preditivo...');
        $thirtyDaysAgo = Carbon::now()->subDays(30);

        // INF-029-B: coluna correta em order_items e product_id (nao product_variation_id)
        // Agrupar itens vendidos nos ultimos 30 dias por product_id e contar quantidade
        $salesData = OrderItem::where('created_at', '>=', $thirtyDaysAgo)
            ->whereNotNull('product_id')
            ->selectRaw('product_id, SUM(quantity) as total_sold')
            ->groupBy('product_id')
            ->get();

        foreach ($salesData as $sale) {
            $dailyAverage = $sale->total_sold / 30;

            if ($dailyAverage > 0) {
                // INF-029-B: coluna correta em inventory e product_id (nao product_variation_id)
                $inventory = Inventory::where('product_id', $sale->product_id)->first();

                if ($inventory && $inventory->quantity > 0) {
                    $daysRemaining = (int) floor($inventory->quantity / $dailyAverage);

                    if ($daysRemaining <= 5) {
                        $this->triggerSupplierAlert($inventory, $daysRemaining);
                    }
                } elseif ($inventory && $inventory->quantity <= 0) {
                    $this->triggerSupplierAlert($inventory, 0); // Ja zerou
                }
            }
        }
        $this->info('Motor Preditivo Finalizado.');
    }

    private function triggerSupplierAlert(Inventory $inventory, int $daysRemaining)
    {
        // INF-029-B: inventory relaciona com product (nao variation)
        $product = $inventory->product;
        if (!$product) {
            return;
        }

        $supplier = $product->supplier;
        $supplierId = $supplier->id ?? 'unknown';

        if ($daysRemaining == 0) {
            $msg = "[HubAI Preditivo] RUPTURA: O produto {$product->name} (id={$product->id}) zerou o estoque!";
        } else {
            $msg = "[HubAI Preditivo] ALERTA VERMELHO: O produto {$product->name} (id={$product->id}) vai zerar o estoque em {$daysRemaining} dias se as vendas continuarem no ritmo atual.";
        }

        Log::channel('single')->warning($msg, ['supplier_id' => $supplierId, 'product_id' => $product->id]);
        $this->warn($msg);
    }
}
