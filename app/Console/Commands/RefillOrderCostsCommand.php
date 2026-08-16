<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Orders\DraftOrderPromoter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * MUL-216: mata a race temporal do fillCosts — pedido promovido antes do
 * products.price existir (sync Bling roda depois) ficava sem custo pra sempre.
 * Re-visita pedidos recentes sem custo cujo produto ja tem price>0.
 * Nao cobre pedidos legados (BackfillOrderCostsJob) nem inventa preco (MUL-198).
 */
class RefillOrderCostsCommand extends Command
{
    protected $signature = 'orders:refill-costs {--days=14 : janela de pedidos} {--dry-run : so lista, nao escreve}';

    protected $description = 'Re-preenche supplier_unit_cost de itens sem custo cujo produto ganhou price>0 apos a promocao';

    public function handle(DraftOrderPromoter $promoter): int
    {
        $days = max(1, (int) $this->option('days'));
        $dry  = (bool) $this->option('dry-run');

        $orders = Order::query()
            ->where('is_draft', false)
            ->where('status', '!=', 'cancelled')
            ->where('created_at', '>=', now()->subDays($days))
            ->whereHas('items', function ($q) {
                $q->where(function ($qq) {
                    $qq->whereNull('supplier_unit_cost')->orWhere('supplier_unit_cost', '<=', 0);
                })->whereHas('product', fn ($p) => $p->where('price', '>', 0));
            })
            ->orderBy('id')
            ->get();

        $refilled = 0;
        foreach ($orders as $order) {
            if ($dry) {
                $this->line("dry-run: {$order->id} {$order->order_number} ({$order->source})");
                continue;
            }

            $promoter->fillCosts($order);
            $order->saveQuietly(); // persiste supplier_total setado pelo fillCosts

            if ($order->supplier_id) {
                \App\Jobs\FanoutOrderWebhookJob::dispatch($order->id, 'order.updated');
            }
            $refilled++;
        }

        $this->info("candidatos: {$orders->count()} | refill executado: {$refilled}" . ($dry ? ' (dry-run)' : ''));

        if (! $dry && $orders->isNotEmpty()) {
            Log::channel('marketplace')->info('[MUL-216] orders:refill-costs', [
                'candidatos' => $orders->count(),
                'refilled'   => $refilled,
                'days'       => $days,
            ]);
        }

        return self::SUCCESS;
    }
}
