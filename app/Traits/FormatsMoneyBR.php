<?php

namespace App\Traits;

trait FormatsMoneyBR
{
    protected function formatBRL(float $value): string
    {
        return 'R$ ' . number_format($value, 2, ',', '.');
    }
}
