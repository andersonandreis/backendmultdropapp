<?php

namespace App\Helpers;

class DocumentValidator
{
    public static function isValid(string $doc): bool
    {
        $doc = preg_replace('/\D/', '', $doc);
        if (strlen($doc) === 11) return self::validateCPF($doc);
        if (strlen($doc) === 14) return self::validateCNPJ($doc);
        return false;
    }

    private static function validateCPF(string $cpf): bool
    {
        if (preg_match('/(\d)\1{10}/', $cpf)) return false;
        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += $cpf[$i] * (($t + 1) - $i);
            }
            $d = ((10 * $sum) % 11) % 10;
            if ($cpf[$t] != $d) return false;
        }
        return true;
    }

    private static function validateCNPJ(string $cnpj): bool
    {
        if (preg_match('/(\d)\1{13}/', $cnpj)) return false;
        $weights1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $weights2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        for ($i = 0; $i < 12; $i++) $sum += $cnpj[$i] * $weights1[$i];
        $d1 = $sum % 11 < 2 ? 0 : 11 - ($sum % 11);
        if ($cnpj[12] != $d1) return false;
        $sum = 0;
        for ($i = 0; $i < 13; $i++) $sum += $cnpj[$i] * $weights2[$i];
        $d2 = $sum % 11 < 2 ? 0 : 11 - ($sum % 11);
        return $cnpj[13] == $d2;
    }
}
