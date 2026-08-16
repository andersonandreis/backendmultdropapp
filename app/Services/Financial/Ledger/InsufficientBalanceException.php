<?php

namespace App\Services\Financial\Ledger;

/** MUL-363: debito recusado por saldo insuficiente (sem allow_overdraft). */
final class InsufficientBalanceException extends \RuntimeException
{
    public function __construct(
        public readonly float $balance,
        public readonly float $requested,
    ) {
        parent::__construct(
            'Saldo insuficiente: disponivel R$ ' . number_format($balance, 2, ',', '.')
            . ', solicitado R$ ' . number_format($requested, 2, ',', '.')
        );
    }
}
