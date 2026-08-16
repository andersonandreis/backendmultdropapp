<?php

namespace App\Support;

/**
 * BillingRules — fonte de verdade única para cobrança WL.
 *
 * Regra Ruan (03/08/2026): um pedido é BILLABLE se NÃO cancelled
 * E passar em pelo menos 1 das camadas de prova de que saiu:
 *
 *  1. Etiqueta emitida  — label_printed_at IS NOT NULL
 *  2. Tem tracking      — tracking_number IS NOT NULL (col tracking_code se existir)
 *  3. Pago fornecedor   — wallet_paid_at IS NOT NULL
 *  4. shipped_at set    — shipped_at IS NOT NULL
 *  5. Status shipped    — status = 'shipped'
 *  6. Fornecedor bipou  — supplierOrder['printed_at'] ou ['bipped_at'] (opcional)
 *  7. Fornecedor status — supplierOrder['status'] in paid/shipped/completed/processing
 *
 * Filosofia: cliente pode tentar burlar qualquer camada individual,
 * mas se QUALQUER uma disser "saiu" → cobra. Regra antiga só olhava
 * status=shipped OR shipped_at — muito fraca.
 *
 * ZERO side-effects: este helper só lê dados, nunca escreve.
 */
class BillingRules
{
    /** Status que indicam cancelamento definitivo */
    private const CANCELLED_STATUSES = ['cancelled', 'canceled', 'cancellation_in_process'];

    /**
     * Avalia se um pedido é cobrável.
     *
     * @param  array       $order         Colunas do pedido WL (arrays ou objeto convertido via (array))
     * @param  array|null  $supplierOrder Pedido no banco do fornecedor (opcional, para cross-check)
     * @return array{billable: bool, reasons: string[], blocked_by: string|null}
     */
    public static function isBillable(array $order, ?array $supplierOrder = null): array
    {
        $status = strtolower((string)($order['status'] ?? ''));

        // Camada 0: cancelado → nunca cobra, independente de qualquer outra flag
        if (in_array($status, self::CANCELLED_STATUSES, true) || !empty($order['cancelled_at'])) {
            return [
                'billable'   => false,
                'reasons'    => [],
                'blocked_by' => 'cancelled',
            ];
        }

        $reasons = [];

        // Camada 1: etiqueta emitida
        if (!empty($order['label_printed_at'])) {
            $reasons[] = 'etiqueta_emitida';
        }

        // Camada 2: tem tracking (campo pode ser tracking_number ou tracking_code)
        $trackingVal = $order['tracking_number'] ?? $order['tracking_code'] ?? null;
        if (!empty($trackingVal)) {
            $reasons[] = 'tem_tracking';
        }

        // Camada 3: pagamento ao fornecedor (WL pagou pelo pedido)
        if (!empty($order['wallet_paid_at'])) {
            $reasons[] = 'pago_fornecedor';
        }

        // Camada 4: shipped_at definido
        if (!empty($order['shipped_at'])) {
            $reasons[] = 'shipped_at_set';
        }

        // Camada 5: status manual = shipped
        if ($status === 'shipped') {
            $reasons[] = 'status_shipped';
        }

        // Camada 6+7: cross-check fornecedor (opcional — skip silencioso se não fornecido)
        if ($supplierOrder !== null) {
            if (!empty($supplierOrder['printed_at'])) {
                $reasons[] = 'fornecedor_imprimiu';
            }
            if (!empty($supplierOrder['bipped_at'])) {
                $reasons[] = 'fornecedor_bipou';
            }
            $sStatus = strtolower((string)($supplierOrder['status'] ?? ''));
            if (in_array($sStatus, ['paid', 'shipped', 'completed', 'processing'], true)) {
                $reasons[] = 'fornecedor_status_' . $sStatus;
            }
        }

        $billable = count($reasons) > 0;

        return [
            'billable'   => $billable,
            'reasons'    => $reasons,
            'blocked_by' => $billable ? null : 'sem_prova',
        ];
    }

    /**
     * Regra antiga (referência comparativa):
     * billable = NOT cancelled AND (status='shipped' OR shipped_at IS NOT NULL)
     */
    public static function isBillableOld(array $order): bool
    {
        $status = strtolower((string)($order['status'] ?? ''));
        if (in_array($status, self::CANCELLED_STATUSES, true) || !empty($order['cancelled_at'])) {
            return false;
        }
        return $status === 'shipped' || !empty($order['shipped_at']);
    }

    /**
     * Classifica um pedido em bucket de auditoria.
     * Retorna string descritiva do bucket.
     */
    public static function bucket(array $result, bool $oldBillable): string
    {
        $blocked = $result['blocked_by'] ?? null;
        $reasons = $result['reasons'] ?? [];

        if ($blocked === 'cancelled') {
            return 'cancelados';
        }
        if (!$result['billable'] && !$oldBillable) {
            return 'sem_prova_nenhuma';
        }
        if ($result['billable'] && !$oldBillable) {
            // Novo capturado pela regra nova
            if (count($reasons) === 1 && $reasons[0] === 'etiqueta_emitida') {
                return 'so_etiqueta';
            }
            if (count($reasons) === 1 && $reasons[0] === 'pago_fornecedor') {
                return 'so_pagamento_fornec';
            }
            if (count($reasons) === 1 && $reasons[0] === 'tem_tracking') {
                return 'so_tracking';
            }
            return 'novo_capturado';
        }
        if (!$result['billable'] && $oldBillable) {
            // Regra antiga cobrava mas nova não cobra — suspeito (raro)
            return 'suspeito_antiga_cobra_nova_nao';
        }
        // Ambas cobram
        return 'multi_camada_forte';
    }
}
