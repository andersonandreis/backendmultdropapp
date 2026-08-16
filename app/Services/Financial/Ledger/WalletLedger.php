<?php

namespace App\Services\Financial\Ledger;

use App\Models\ClientSupplierBalance;
use App\Models\ClientSupplierTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MUL-363 Fase 0 — nucleo financeiro canonico da wallet do seller.
 *
 * DUAS operacoes: credit() e debit(). Todo o resto (topup, estorno, cobranca
 * forcada, PIX, cartao futuro, moeda...) e METADADO (LedgerEntryMeta), nunca
 * metodo novo. Doc canonico: obsidian-hubai Recursos/Arquitetura/16-financeiro-wallet.md.
 *
 * Invariantes garantidos aqui:
 *  - append-only: nenhuma linha do ledger sofre UPDATE; correcao e contra-partida
 *    (meta->reversesTransactionId). running_balance e gravado NO INSERT, sob lock.
 *  - idempotencia dura: meta->idempotencyKey tem UNIQUE no banco; replay (retry de
 *    job, webhook duplicado, clique duplo) devolve a transacao original sem aplicar.
 *  - atomicidade: saldo + linha do ledger na mesma transacao, com lockForUpdate.
 *  - saldo derivado: balance e cache de SUM(ledger) — `wallet:reconcile` confere.
 *  - overdraft explicito: debit() so negativa com allowOverdraft=true (cobranca
 *    forcada, regra MUL-254). Recusa vira InsufficientBalanceException + evento.
 *  - tudo logado: cada aplicacao E cada recusa vira linha em payment_events.
 */
final class WalletLedger
{
    public function credit(int $clientId, int $supplierId, float $amount, LedgerEntryMeta $meta): ClientSupplierTransaction
    {
        return $this->apply('credit', $clientId, $supplierId, $amount, $meta, true);
    }

    public function debit(int $clientId, int $supplierId, float $amount, LedgerEntryMeta $meta, bool $allowOverdraft = false): ClientSupplierTransaction
    {
        return $this->apply('debit', $clientId, $supplierId, $amount, $meta, $allowOverdraft);
    }

    private function apply(string $direction, int $clientId, int $supplierId, float $amount, LedgerEntryMeta $meta, bool $allowOverdraft): ClientSupplierTransaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException("Valor deve ser positivo (recebido: {$amount}).");
        }
        $amount = round($amount, 2);
        $origin = $meta->origin ?? (string) config('app.tenant');

        // Replay idempotente (fast path): a mesma operacao ja foi aplicada?
        if ($meta->idempotencyKey !== null) {
            $existing = ClientSupplierTransaction::where('idempotency_key', $meta->idempotencyKey)->first();
            if ($existing) {
                $this->event('idempotent_replay', $clientId, $supplierId, $meta, $amount, null, null, $existing->id, $origin);
                return $existing;
            }
        }

        try {
            return DB::transaction(function () use ($direction, $clientId, $supplierId, $amount, $meta, $allowOverdraft, $origin) {
                ClientSupplierBalance::firstOrCreate(
                    ['client_id' => $clientId, 'supplier_id' => $supplierId],
                    ['balance' => 0]
                );
                $balance = ClientSupplierBalance::where('client_id', $clientId)
                    ->where('supplier_id', $supplierId)
                    ->lockForUpdate()
                    ->first();

                $before = (float) $balance->balance;

                if ($direction === 'debit' && ! $allowOverdraft && round($before - $amount, 2) < 0) {
                    throw new InsufficientBalanceException($before, $amount);
                }

                $after = round($direction === 'debit' ? $before - $amount : $before + $amount, 2);

                $tx = ClientSupplierTransaction::create([
                    'client_id'               => $clientId,
                    'supplier_id'             => $supplierId,
                    'type'                    => $direction,
                    'amount'                  => $amount,
                    'description'             => $meta->description,
                    'order_id'                => $meta->orderId,
                    'reference'               => $meta->reference,
                    'running_balance'         => $after,
                    'transaction_type'        => $meta->type,
                    'pix_transaction_id'      => $meta->pixTransactionId,
                    'idempotency_key'         => $meta->idempotencyKey,
                    'actor'                   => $meta->actor,
                    'origin'                  => $origin,
                    'reverses_transaction_id' => $meta->reversesTransactionId,
                    'meta'                    => $meta->extra !== [] ? json_encode($meta->extra, JSON_UNESCAPED_UNICODE) : null,
                ]);

                $balance->update(['balance' => $after]);

                $this->event($direction . '_ok', $clientId, $supplierId, $meta, $amount, $before, $after, $tx->id, $origin);

                return $tx;
            });
        } catch (InsufficientBalanceException $e) {
            $this->event('insufficient_balance', $clientId, $supplierId, $meta, $amount, $e->balance, $e->balance, null, $origin);
            throw $e;
        } catch (QueryException $e) {
            // Corrida na UNIQUE da idempotency_key: outro processo aplicou primeiro.
            if ($meta->idempotencyKey !== null && str_contains($e->getMessage(), 'idempotency_key')) {
                $existing = ClientSupplierTransaction::where('idempotency_key', $meta->idempotencyKey)->first();
                if ($existing) {
                    $this->event('idempotent_replay', $clientId, $supplierId, $meta, $amount, null, null, $existing->id, $origin);
                    return $existing;
                }
            }
            throw $e;
        }
    }

    /** payment_events e append-only e nunca pode derrubar a operacao principal. */
    private function event(string $event, int $clientId, int $supplierId, LedgerEntryMeta $meta, float $amount, ?float $before, ?float $after, ?int $txId, string $origin): void
    {
        try {
            DB::table('payment_events')->insert([
                'client_id'      => $clientId,
                'supplier_id'    => $supplierId,
                'order_id'       => $meta->orderId,
                'event'          => $event,
                'amount'         => $amount,
                'balance_before' => $before,
                'balance_after'  => $after,
                'transaction_id' => $txId,
                'actor'          => $meta->actor,
                'origin'         => $origin,
                'context'        => json_encode([
                    'type'            => $meta->type,
                    'idempotency_key' => $meta->idempotencyKey,
                    'reference'       => $meta->reference,
                ] + $meta->extra, JSON_UNESCAPED_UNICODE),
                'created_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[WalletLedger] falha ao gravar payment_event (operacao principal preservada)', [
                'event' => $event, 'client_id' => $clientId, 'error' => $e->getMessage(),
            ]);
        }
    }
}
