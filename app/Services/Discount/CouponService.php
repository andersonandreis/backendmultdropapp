<?php

namespace App\Services\Discount;

use App\Models\Coupon;
use Carbon\Carbon;
use Exception;

class CouponService
{
    /**
     * Valida um Cupom inserido pelo Lojista ou Sub-Admin
     */
    public function validate(string $code, float $orderTotal, int $clientId): Coupon
    {
        $coupon = Coupon::where('code', strtoupper($code))->where('is_active', true)->first();

        if (!$coupon) {
            throw new Exception("Cupom Invalido.");
        }

        if ($coupon->starts_at && Carbon::now()->lt($coupon->starts_at)) {
            throw new Exception("Este Cupom ainda não é válido.");
        }

        if ($coupon->ends_at && Carbon::now()->gt($coupon->ends_at)) {
            throw new Exception("Este Cupom expirou.");
        }

        if ($coupon->max_uses && $coupon->uses_count >= $coupon->max_uses) {
            throw new Exception("Os limites deste cupom se esgotaram.");
        }

        if ($coupon->min_order_value && $orderTotal < $coupon->min_order_value) {
            throw new Exception("Valor minimo não atingido (RS{$coupon->min_order_value}).");
        }

        // Faltaria contar max_uses_per_client (Log de usos atrelados ao client_id)

        return $coupon;
    }

    /**
     * Executa a contagem e salva o uso
     */
    public function registerUsage(Coupon $coupon, int $clientId): void
    {
        $coupon->increment('uses_count');
    }
}
