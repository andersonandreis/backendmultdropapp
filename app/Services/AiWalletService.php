<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * SEL-245 Ruan 18/07/2026 — Wallet créditos IA (Kling, gpt-image etc).
 */
class AiWalletService
{
    public const MIN_DEPOSIT = 50.00;

    public static function getBalance(int $clientId): float
    {
        $w = DB::table('client_ai_wallets')->where('client_id', $clientId)->first();
        return (float) ($w->balance ?? 0);
    }

    public static function summary(int $clientId): array
    {
        $w = DB::table('client_ai_wallets')->where('client_id', $clientId)->first();
        if (!$w) return ['balance' => 0, 'lifetime_deposited' => 0, 'lifetime_consumed' => 0];
        return [
            'balance' => (float) $w->balance,
            'lifetime_deposited' => (float) $w->lifetime_deposited,
            'lifetime_consumed' => (float) $w->lifetime_consumed,
        ];
    }

    public static function credit(int $clientId, float $amount, string $kind, ?string $ref = null, ?string $note = null): float
    {
        return DB::transaction(function () use ($clientId, $amount, $kind, $ref, $note) {
            $w = DB::table('client_ai_wallets')->where('client_id', $clientId)->lockForUpdate()->first();
            if (!$w) {
                DB::table('client_ai_wallets')->insert([
                    'client_id' => $clientId,
                    'balance' => $amount,
                    'lifetime_deposited' => $amount,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $balanceAfter = $amount;
            } else {
                $balanceAfter = (float) $w->balance + $amount;
                DB::table('client_ai_wallets')->where('client_id', $clientId)->update([
                    'balance' => $balanceAfter,
                    'lifetime_deposited' => (float) $w->lifetime_deposited + $amount,
                    'updated_at' => now(),
                ]);
            }
            DB::table('ai_wallet_transactions')->insert([
                'client_id' => $clientId,
                'direction' => 'credit',
                'amount' => $amount,
                'balance_after' => $balanceAfter,
                'kind' => $kind,
                'ref' => $ref,
                'note' => $note,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return $balanceAfter;
        });
    }

    /**
     * Debita se saldo suficiente. Retorna new balance, ou false se saldo insuficiente.
     */
    public static function debit(int $clientId, float $amount, string $kind, ?string $ref = null, ?string $note = null): float|false
    {
        return DB::transaction(function () use ($clientId, $amount, $kind, $ref, $note) {
            $w = DB::table('client_ai_wallets')->where('client_id', $clientId)->lockForUpdate()->first();
            if (!$w || (float) $w->balance < $amount) return false;
            $balanceAfter = (float) $w->balance - $amount;
            DB::table('client_ai_wallets')->where('client_id', $clientId)->update([
                'balance' => $balanceAfter,
                'lifetime_consumed' => (float) $w->lifetime_consumed + $amount,
                'updated_at' => now(),
            ]);
            DB::table('ai_wallet_transactions')->insert([
                'client_id' => $clientId,
                'direction' => 'debit',
                'amount' => $amount,
                'balance_after' => $balanceAfter,
                'kind' => $kind,
                'ref' => $ref,
                'note' => $note,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return $balanceAfter;
        });
    }

    public static function history(int $clientId, int $limit = 30): array
    {
        return DB::table('ai_wallet_transactions')
            ->where('client_id', $clientId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'direction', 'amount', 'balance_after', 'kind', 'ref', 'note', 'created_at'])
            ->map(fn ($r) => (array) $r)
            ->all();
    }
}
