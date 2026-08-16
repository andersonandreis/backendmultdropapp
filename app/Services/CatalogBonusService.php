<?php

namespace App\Services;

use App\Models\CatalogBonusSubsidy;
use App\Models\Client;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SEL-171 — Bonus 50% SILENCIOSO nas 3 primeiras vendas via /catalogo.
 *
 * Fluxo:
 *  - Cliente entra em /catalogo. Front consulta /me/bonus-status.
 *    Se eligible=true, aplica bonus_pct de desconto SILENCIOSAMENTE (sem badge
 *    nem "promocao" no UI — parece preco normal).
 *  - Ao criar o pedido, front envia original vs discounted em orders.
 *    catalog_original_price / catalog_discounted_price / pending_subsidy_amount
 *    ficam persistidos.
 *  - Quando o pedido vira pago (OrderPaymentService::payWithWalletOnly ou
 *    confirmOrderPix), este service chama recordSubsidy: cria row em
 *    catalog_bonus_subsidies e incrementa clients.first_orders_used.
 *  - Painel admin ve total pendente por fornecedor, marca como paid quando
 *    o Ruan pagar por fora.
 */
class CatalogBonusService
{
    public const MAX_FIRST_ORDERS = 3;
    public const DEFAULT_BONUS_PCT = 50;

    /**
     * O cliente ainda tem bonus disponivel? (used < 3)
     *
     * SEL-179 Ruan 16:10: bonus vale pra TODO cliente incluindo tiktok_free.
     * NAO adicionar checagem de plan_slug/subscription aqui — quem cortar o
     * beneficio pra free vai quebrar o funil de conversao do catalogo.
     */
    public function isEligible(Client $c): bool
    {
        $used = (int) ($c->first_orders_used ?? 0);
        return $used < self::MAX_FIRST_ORDERS;
    }

    /**
     * Retorna o preco com desconto se elegivel, ou o preco original se nao.
     */
    public function applyDiscount(Client $c, float $price): float
    {
        if (!$this->isEligible($c)) {
            return round($price, 2);
        }
        $pct = (int) ($c->first_orders_bonus_pct ?? self::DEFAULT_BONUS_PCT);
        $pct = max(0, min(100, $pct));
        $discounted = $price * (1 - ($pct / 100));
        return round($discounted, 2);
    }

    /**
     * Registra o subsidio devido pro fornecedor e marca +1 no counter do cliente.
     * Chamado quando o pedido vira pago com bonus ativo.
     *
     * Idempotente por order_id (unique).
     */
    public function recordSubsidy(
        Order $order,
        Client $c,
        int $supplierId,
        ?string $productName,
        ?int $productId,
        float $original,
        float $discounted
    ): ?CatalogBonusSubsidy {
        $subsidy = round($original - $discounted, 2);
        if ($subsidy <= 0.0) {
            return null;
        }

        // Idempotencia: se ja tem subsidy pra esse pedido, nao duplica
        $existing = CatalogBonusSubsidy::where('order_id', $order->id)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($order, $c, $supplierId, $productName, $productId, $original, $discounted, $subsidy) {
            $row = CatalogBonusSubsidy::create([
                'client_id' => $c->id,
                'supplier_id' => $supplierId,
                'order_id' => $order->id,
                'product_name' => $productName,
                'product_id' => $productId,
                'original_price' => $original,
                'discounted_price' => $discounted,
                'subsidy_amount' => $subsidy,
                'status' => 'pending',
            ]);

            // Incrementa contador do cliente
            $c->increment('first_orders_used');

            Log::info('[CatalogBonusService] subsidio registrado', [
                'order_id' => $order->id,
                'client_id' => $c->id,
                'supplier_id' => $supplierId,
                'subsidy' => $subsidy,
                'first_orders_used_after' => $c->fresh()->first_orders_used,
            ]);

            return $row;
        });
    }

    /**
     * Trigger a partir do fluxo de pagamento: se o pedido tem catalog_bonus_applied
     * e pending_subsidy_amount > 0, registra o subsidio.
     * Silencioso em erro — nunca deve derrubar o pagamento.
     */
    public function tryRecordFromOrder(Order $order): ?CatalogBonusSubsidy
    {
        try {
            if (!$order->catalog_bonus_applied) {
                return null;
            }
            $pending = (float) ($order->pending_subsidy_amount ?? 0);
            if ($pending <= 0.0) {
                return null;
            }
            $client = Client::find($order->client_id);
            if (!$client) {
                return null;
            }
            $original = (float) ($order->catalog_original_price ?? 0);
            $discounted = (float) ($order->catalog_discounted_price ?? 0);
            if ($original <= 0 || $discounted < 0 || $discounted >= $original) {
                return null;
            }
            $productName = null;
            $productId = null;
            $firstItem = $order->items()->first();
            if ($firstItem) {
                $productName = $firstItem->name ?? null;
                $productId = $firstItem->product_id ?? null;
            }
            return $this->recordSubsidy(
                $order,
                $client,
                (int) $order->supplier_id,
                $productName,
                $productId ? (int) $productId : null,
                $original,
                $discounted,
            );
        } catch (\Throwable $e) {
            Log::error('[CatalogBonusService] tryRecordFromOrder falhou (silencioso)', [
                'order_id' => $order->id ?? null,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Payload pro endpoint /me/bonus-status.
     *
     * SEL-179 Ruan 16:10: bonus vale pra TODO cliente incluindo tiktok_free —
     * o gate e SO first_orders_used < 3, nunca plan_slug. Middleware
     * RestrictFreeAccess libera esta rota pra tier free (allowlist).
     */
    public function statusFor(Client $c): array
    {
        $used = (int) ($c->first_orders_used ?? 0);
        $pct = (int) ($c->first_orders_bonus_pct ?? self::DEFAULT_BONUS_PCT);
        $remaining = max(0, self::MAX_FIRST_ORDERS - $used);
        return [
            'used' => $used,
            'remaining' => $remaining,
            'bonus_pct' => $pct,
            'eligible' => $remaining > 0,
        ];
    }
}
