<?php

namespace App\Services\Orders;

use App\Models\ClientSupplierBalance;
use App\Models\Order;

/**
 * Supplier Core / Fase 3 / M4 — Maquina de estados canonica de orders.
 *
 * Estados:
 *   created -> paid -> accepted -> in_fulfillment -> shipped -> delivered -> completed
 *
 * Ramos terminais: cancelled, refunded.
 *
 * Transicoes permitidas e quem dispara:
 *
 *   created          -> paid              (gateway, marketplace)
 *   created          -> cancelled         (tenant, hubai, system)
 *
 *   paid             -> accepted          (tenant, hubai)
 *   paid             -> cancelled         (tenant, hubai, system)
 *
 *   accepted         -> in_fulfillment    (tenant, supplier)
 *   accepted         -> cancelled         (tenant, hubai)
 *
 *   in_fulfillment   -> shipped           (tenant, supplier)        (POST tracking faz isso)
 *   in_fulfillment   -> cancelled         (tenant, hubai)
 *
 *   shipped          -> delivered         (marketplace, tenant, system)
 *   shipped          -> refunded          (hubai)                   (raro)
 *
 *   delivered        -> completed         (system, tenant)
 *   delivered        -> refunded          (hubai, tenant)
 *
 *   qualquer         -> refunded          (hubai)                   (com motivo + valor)
 *
 * Quem pode disparar fica registrado em Transition::actors.
 */
class StatusTransitioner
{
    public const STATES = [
        'created', 'paid', 'accepted', 'in_fulfillment',
        'shipped', 'delivered', 'completed',
        'cancelled', 'refunded',
    ];

    public const TERMINAL = ['completed', 'cancelled', 'refunded'];

    /**
     * Mapa from->[to => actors[]].
     * actors valoras: tenant, hubai, supplier, marketplace, system.
     */
    public const ALLOWED = [
        'created' => [
            'paid'      => ['gateway', 'marketplace', 'hubai'],
            'cancelled' => ['tenant', 'hubai', 'system'],
        ],
        'paid' => [
            'accepted'  => ['tenant', 'hubai'],
            'cancelled' => ['tenant', 'hubai', 'system'],
            'refunded'  => ['hubai'],
        ],
        'accepted' => [
            'in_fulfillment' => ['tenant', 'supplier'],
            'cancelled'      => ['tenant', 'hubai'],
            'refunded'       => ['hubai'],
        ],
        'in_fulfillment' => [
            'shipped'   => ['tenant', 'supplier'],
            'cancelled' => ['tenant', 'hubai'],
            'refunded'  => ['hubai'],
        ],
        'shipped' => [
            'delivered' => ['marketplace', 'tenant', 'system'],
            'refunded'  => ['hubai'],
        ],
        'delivered' => [
            'completed' => ['system', 'tenant'],
            'refunded'  => ['hubai', 'tenant'],
        ],
        // Terminais nao tem saidas
        'completed' => [],
        'cancelled' => [],
        'refunded'  => [],
    ];

    public function canTransition(string $from, string $to, string $actor): bool
    {
        $allowed = self::ALLOWED[$from] ?? [];
        return isset($allowed[$to]) && in_array($actor, $allowed[$to], true);
    }

    /**
     * Tenta transicionar o pedido. Retorna [bool ok, ?string error_code, ?string detail].
     * NAO salva o pedido — chamador deve chamar $order->save() depois (pra controlar transacao).
     */
    public function transition(Order $order, string $to, string $actor, array $metadata = []): array
    {
        $from = $order->canonical_status ?? 'created';

        if (!in_array($to, self::STATES, true)) {
            return [false, 'invalid_state', "Unknown state '{$to}'"];
        }
        if ($from === $to) {
            return [false, 'noop', 'Order already in this state'];
        }
        if (in_array($from, self::TERMINAL, true)) {
            return [false, 'terminal_state', "Order is in terminal state '{$from}'"];
        }
        if (!$this->canTransition($from, $to, $actor)) {
            return [false, 'invalid_transition', "Cannot move '{$from}' -> '{$to}' as '{$actor}'"];
        }

        // Guard financeiro: paid -> accepted requer custo do fornecedor quitado.
        // hubai/system/gateway podem bypassar (correcoes internas e auto-pay).
        //
        // Duas situacoes bloqueadas:
        //  1. wallet_paid_at IS NULL: pagamento nunca tentado (nem via auto-pay nem via PIX)
        //  2. wallet_paid_at IS NOT NULL mas saldo negativo: AuditUnpaidOrdersJob registrou
        //     divida — o deposito ainda nao cobriu o custo pendente.
        if ($from === 'paid' && $to === 'accepted' && !in_array($actor, ['hubai', 'system', 'gateway'])) {
            $supplierTotal = (float) ($order->supplier_total ?? 0);
            if ($supplierTotal > 0) {
                if ($order->wallet_paid_at === null) {
                    return [false, 'supplier_unpaid', 'O custo do fornecedor ainda nao foi pago. Adicione saldo na carteira para aceitar o pedido.'];
                }
                // Checa saldo: se negativo, o deposito ainda nao cobriu a divida registrada.
                $balance = (float) (ClientSupplierBalance::where('client_id', $order->client_id)
                    ->where('supplier_id', $order->supplier_id ?? 0)
                    ->value('balance') ?? 0);
                if ($balance < 0) {
                    return [false, 'supplier_debt', 'Saldo insuficiente. Faca um deposito para cobrir a divida pendente e aceitar os pedidos.'];
                }
            } elseif ($order->supplier_id) {
                // MUL-192: custo zero/indefinido nao e "gratis" — pedido dropship sempre tem custo.
                // supplier_total<=0 = dado quebrado no catalogo; sem lastro financeiro nao avanca.
                return [false, 'supplier_cost_missing', 'Custo do fornecedor indefinido para este pedido. Aguarde a correcao do custo antes de aceitar.'];
            }
        }

        $order->canonical_status = $to;
        return [true, null, null];
    }
}
