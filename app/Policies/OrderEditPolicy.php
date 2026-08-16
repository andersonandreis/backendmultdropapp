<?php

namespace App\Policies;

use App\Models\Order;

/**
 * MUL-298 -- Unico lugar que sabe quando um pedido pode ser editado.
 *
 * Regra: editavel enquanto NAO houve repasse ao fornecedor (wallet_paid_at IS NULL).
 *
 * wallet_paid_at e o repasse ao fornecedor. NAO confundir com paid_at, que e o
 * pagamento do comprador no marketplace (gravado por SyncShopeeOrdersJob,
 * SyncMLOrdersJob, SyncTikTokOrdersJob). Ver Arquitetura/15.
 *
 * Consequencia aceita: cobranca forcada carimba wallet_paid_at e fecha a edicao na
 * hora; estorno reabre.
 */
class OrderEditPolicy
{
    public const MOTIVO_REPASSADO = "pedido_ja_repassado";

    public static function podeEditar(?Order $order): bool
    {
        return $order !== null && $order->wallet_paid_at === null;
    }

    /**
     * Motivo legivel da recusa, ou null se pode editar.
     */
    public static function motivo(?Order $order): ?string
    {
        if ($order === null) {
            return "pedido_inexistente";
        }
        if ($order->wallet_paid_at !== null) {
            return self::MOTIVO_REPASSADO;
        }
        return null;
    }
}
