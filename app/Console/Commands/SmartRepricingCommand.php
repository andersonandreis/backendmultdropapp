<?php

namespace App\Console\Commands;

use App\Jobs\ApplyRepricingPriceJob;
use App\Models\ClientProduct;
use App\Models\OrderItem;
use App\Services\Repricing\RepricingCalculatorService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SmartRepricingCommand extends Command
{
    protected $signature   = 'hubai:smart-repricing';
    protected $description = 'Motor de Precificacao Bionica: ajusta margem de itens de alta tracacao/parados e atualiza preco em ML/Shopee.';

    public function handle(RepricingCalculatorService $calculator): void
    {
        $diasTracao       = 7;
        $vendasParaAltar  = 10;
        $diasMorgado      = 21;
        $marketplacesAtivos = ['mercadolivre', 'mercado_livre', 'shopee'];

        $clientProducts = ClientProduct::with(['product', 'marketplaceAccount'])
            ->where('is_active', true)
            ->whereNotNull('external_listing_id')
            ->whereHas('marketplaceAccount', fn ($q) =>
                $q->whereIn('platform', $marketplacesAtivos)
                   ->where('status', 'active')
                   ->whereNull('sync_blocked_at')
            )
            ->get();

        $atualizados = 0;

        foreach ($clientProducts as $cp) {
            $account  = $cp->marketplaceAccount;
            $platform = strtolower($account->platform ?? '');

            $salesLast7Days = OrderItem::where('client_product_id', $cp->id)
                ->whereHas('order', fn ($q) =>
                    $q->where('status', 'paid')->where('created_at', '>=', Carbon::now()->subDays($diasTracao))
                )->sum('quantity');

            if ($salesLast7Days >= $vendasParaAltar) {
                $novaMargem = min(60.0, (float) $cp->profit_margin + 2.0);
                if ($novaMargem == (float) $cp->profit_margin) continue;

                $novoPreco = $this->calcularPreco($calculator, $cp, $platform, $novaMargem);
                if ($novoPreco === null) continue;

                $cp->update(['profit_margin' => $novaMargem, 'custom_price' => $novoPreco]);
                ApplyRepricingPriceJob::dispatch($cp->id, $novoPreco)->onQueue('default');

                Log::info('[NOV-127] Alta tracao — margem sobe', [
                    'client_product_id' => $cp->id, 'margem' => $novaMargem, 'preco' => $novoPreco,
                ]);
                $atualizados++;
                continue;
            }

            $salesLast21Days = OrderItem::where('client_product_id', $cp->id)
                ->whereHas('order', fn ($q) =>
                    $q->where('status', 'paid')->where('created_at', '>=', Carbon::now()->subDays($diasMorgado))
                )->sum('quantity');

            if ($salesLast21Days == 0) {
                $novaMargem = max(5.0, (float) $cp->profit_margin - 5.0);
                if ($novaMargem >= (float) $cp->profit_margin) continue;

                $novoPreco = $this->calcularPreco($calculator, $cp, $platform, $novaMargem);
                if ($novoPreco === null) continue;

                $cp->update(['profit_margin' => $novaMargem, 'custom_price' => $novoPreco]);
                ApplyRepricingPriceJob::dispatch($cp->id, $novoPreco)->onQueue('default');

                Log::info('[NOV-127] Produto parado — margem cai', [
                    'client_product_id' => $cp->id, 'margem' => $novaMargem, 'preco' => $novoPreco,
                ]);
                $atualizados++;
            }
        }

        $this->info("Smart Repricing concluido: {$atualizados} produtos ajustados.");
        Log::info('[NOV-127] Smart Repricing finalizado', ['atualizados' => $atualizados]);
    }

    private function calcularPreco(RepricingCalculatorService $calculator, ClientProduct $cp, string $platform, float $margem): ?float
    {
        $product = $cp->product;
        if (!$product) return null;

        $marketplace = match(true) {
            in_array($platform, ['mercadolivre', 'mercado_livre']) => 'mercadolivre',
            $platform === 'shopee' => 'shopee',
            default => $platform,
        };

        try {
            $result = $calculator->calculateWithCosts($product, $marketplace, null, $margem);
            $price  = (float) $result['final_price'];
            return $price > 0 ? $price : null;
        } catch (\Throwable $e) {
            Log::warning('[NOV-127] Erro ao calcular preco', [
                'client_product_id' => $cp->id,
                'error'             => $e->getMessage(),
            ]);
            return null;
        }
    }
}
