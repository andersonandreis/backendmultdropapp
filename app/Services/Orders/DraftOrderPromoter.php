<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Services\Financial\AutoPayService;
use Illuminate\Support\Facades\Log;

/**
 * MUL-197 — Promocao de pedido-rascunho a pedido normal.
 *
 * Regra (decisao Ruan, verbatim): pedido incompleto NUNCA sobe pro front —
 * nasce rascunho (is_draft=1) e so vira pedido quando tiver:
 *   - customer_name preenchido
 *   - total > 0
 *   - itens gravados
 *   - paid_at (se status indica pago — READY_TO_SHIP Shopee = pago)
 *   - custo calculado quando houver fonte (products.price, decisao MUL-198; nunca cost)
 *
 * Efeitos colaterais acontecem SO aqui (nunca no draft):
 *   - fanout order.created (FanoutOrderWebhookJob) — suprimido no OrderObserver p/ draft
 *   - AutoPay (debito da carteira) — idempotente via wallet_paid_at (nunca 2x)
 *
 * Interacao MUL-192: supplier_total e preenchido AQUI, ANTES do guard
 * supplier_cost_missing do StatusTransitioner — rascunho nao passa pelo transitioner.
 */
class DraftOrderPromoter
{
    /** Status locais que significam "pedido pago" (Shopee READY_TO_SHIP mapeia p/ processing). */
    public const PAID_LIKE_STATUSES = ['processing', 'paid', 'shipped', 'delivered', 'completed'];

    /**
     * Lista o que falta pro pedido poder ser promovido. Vazio = promovivel.
     */
    public function missingRequirements(Order $order): array
    {
        $missing = [];

        if (trim((string) $order->customer_name) === '') {
            $missing[] = 'customer_name';
        }
        if ((float) $order->total <= 0) {
            $missing[] = 'total';
        }

        $items = $order->items()->with('product:id,cost,price')->get();
        if ($items->isEmpty()) {
            $missing[] = 'items';
        }

        if (in_array((string) $order->status, self::PAID_LIKE_STATUSES, true) && ! $order->paid_at) {
            $missing[] = 'paid_at';
        }

        // Custo exigido apenas quando HA fonte: item vinculado a produto com cost/price > 0.
        // Item sem vinculo de produto nao bloqueia (o guard MUL-192 do transitioner cobre depois).
        foreach ($items as $item) {
            if ((float) ($item->supplier_unit_cost ?? 0) > 0) {
                continue;
            }
            $product = $item->product;
            // MUL-198: custo = price do catalogo (nunca cost)
            if ($product && (float) ($product->price ?? 0) > 0) {
                $missing[] = 'supplier_cost';
                break;
            }
        }

        return $missing;
    }

    /**
     * Preenche custos dos itens via products.price (MUL-198; nunca cost) e o
     * supplier_total do pedido. NUNCA sobrescreve valor ja preenchido.
     */
    public function fillCosts(Order $order): void
    {
        foreach ($order->items()->with('product:id,cost,price')->get() as $item) {
            if ((float) ($item->supplier_unit_cost ?? 0) > 0) {
                continue; // nao sobrescrever custo ja preenchido
            }
            $product = $item->product;
            if (! $product) {
                continue;
            }
            // MUL-198: custo = price do catalogo (nunca cost); sem price = nao preenche
            $price = (float) ($product->price ?? 0);
            if ($price <= 0) {
                continue;
            }
            $item->supplier_unit_cost  = $price;
            $item->supplier_total_cost = round($price * max(1, (int) $item->quantity), 2);
            $item->save();
        }

        // supplier_total ANTES do guard MUL-192 (supplier_cost_missing)
        $sum = (float) $order->items()->sum('supplier_total_cost');
        if ($sum > 0 && (float) ($order->supplier_total ?? 0) <= 0) {
            $order->supplier_total = $sum;
        }
    }

    /**
     * Tenta promover o rascunho. Retorna [bool promovido, array faltando].
     * Pedido ja promovido retorna [true, []] sem efeito (idempotente).
     */
    public function promote(Order $order, string $origin = 'system'): array
    {
        if (! $order->is_draft) {
            return [true, []];
        }

        $this->fillCosts($order);

        $missing = $this->missingRequirements($order);
        if (! empty($missing)) {
            $order->draft_reason = 'incomplete: ' . implode(',', $missing);
            $order->saveQuietly();
            return [false, $missing];
        }

        $order->is_draft     = false;
        $order->draft_reason = null;
        $order->saveQuietly();

        Log::channel('marketplace')->info('[MUL-197] Rascunho PROMOVIDO a pedido', [
            'order_id'     => $order->id,
            'order_number' => $order->order_number,
            'source'       => $order->source,
            'origin'       => $origin,
        ]);

        // Fanout order.created acontece NA PROMOCAO (foi suprimido na criacao do draft).
        // Delay 30s: mesmo racional do MUL-177 (garantir itens gravados no payload do fanout).
        try {
            if ($order->supplier_id) {
                \App\Jobs\FanoutOrderWebhookJob::dispatch($order->id, 'order.created')
                    ->delay(now()->addSeconds(30));
            }
        } catch (\Throwable $e) {
            Log::warning('[MUL-197] fanout na promocao falhou', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }

        // FOR-096 P3: a etiqueta tambem so pode ser buscada NA PROMOCAO.
        // O OrderObserver::created() dispara FetchShippingLabelJob, mas sai cedo
        // quando o pedido nasce rascunho — e todo pedido de sync de marketplace
        // nasce rascunho (MUL-202). A promocao usa saveQuietly(), que nao redispara
        // o observer, e o ::updated() nao tem esse dispatch. Resultado: pedido
        // promovido NUNCA buscava etiqueta. O fallback CheckLabelAvailabilityJob nao
        // cobre porque so olha quem ja tem linha em OrderLabelQueue.
        // Contagem em 29/07: 833 pedidos travados no Fornecefy, 855 no HUB.
        // Seguro re-disparar: o job e ShouldBeUnique e sai cedo se label_url ja existe.
        try {
            \App\Jobs\FetchShippingLabelJob::dispatch($order->id, 'promotion')
                ->delay(now()->addSeconds(30));
        } catch (\Throwable $e) {
            Log::warning('[FOR-096] dispatch de etiqueta na promocao falhou', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }

        // MUL-363: promocao usa saveQuietly (Observer nao ve) — dispara o MESMO executor
        // do evento unico; a politica de pagabilidade mora no AutoPayService.
        if (in_array((string) $order->status, self::PAID_LIKE_STATUSES, true)) {
            try {
                \App\Jobs\TryAutoPayJob::dispatch($order->id)->onQueue('default');
            } catch (\Throwable $e) {
                Log::channel('marketplace')->warning('[MUL-197] AutoPay na promocao falhou', [
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return [true, []];
    }
}
