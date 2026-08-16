<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Policies\OrderEditPolicy;

/**
 * MUL-108 -- Troca de produto em pedido.
 *
 * MUL-298: esta classe DEIXOU de apagar os itens do pedido. Ela agora e um caso
 * particular do OrderItemEditService: trocar o produto do unico item do pedido.
 *
 * O que mudou e por que:
 *   - Antes: $order->items()->delete() + cria 1 item. Em pedido multi-item, trocar
 *     um produto apagava os outros (250 pedidos multi-item no MultDrop).
 *   - Antes: trava era status === 'pending_payment' -- 16 dos 15.395 pedidos.
 *     Agora e OrderEditPolicy (wallet_paid_at IS NULL), que e o repasse ao
 *     fornecedor. Ver Arquitetura/15.
 *
 * Pedido com mais de um item recusa e aponta a rota por item -- este endpoint nao
 * tem como saber qual item o usuario quis trocar.
 */
class OrderSwapProductService
{
    public function swap(Order $order, Product $newProduct, int $quantity, int $actorUserId): array
    {
        if (!OrderEditPolicy::podeEditar($order)) {
            throw new \DomainException(
                OrderEditPolicy::motivo($order) . ": pedido ja repassado ao fornecedor, nao pode mais ser editado."
            );
        }

        $itens = OrderItem::where('order_id', $order->id)->get();

        if ($itens->isEmpty()) {
            throw new \DomainException('pedido_sem_itens: nao ha item para trocar.');
        }

        if ($itens->count() > 1) {
            throw new \DomainException(
                'pedido_multi_item: este pedido tem ' . $itens->count() . ' itens e esta rota nao diz qual trocar. '
                . 'Use PATCH /api/v1/orders/{id}/items/{itemId}.'
            );
        }

        return app(OrderItemEditService::class)->updateItem(
            $order,
            $itens->first(),
            $newProduct->sku,
            $quantity,
            $actorUserId
        );
    }
}
