<?php

namespace App\Services\Financial;

use App\Models\WithdrawalRequest;
use App\Models\SupplierBalance;
use App\Models\SupplierTransaction;
use Exception;
use Illuminate\Support\Facades\DB;

class WithdrawalService
{
    /**
     * Fornecedor/Produtor pede o resgate do valor de vendas que ele possui num Galpão
     */
    public function requestWithdrawal(int $producerId, int $warehouseId, float $amount, string $pixKey): WithdrawalRequest
    {
        $balance = SupplierBalance::where('producer_id', $producerId)
            ->where('warehouse_id', $warehouseId)
            ->first();

        if (!$balance || $balance->balance < $amount) {
            throw new Exception("Insufficient Funds in this warehouse.");
        }

        return DB::transaction(function () use ($producerId, $warehouseId, $amount, $pixKey, $balance) {
            // "Prende" o saldo do Produtor subtraindo provisoriamente
            $balance->decrement('balance', $amount);

            // Cria o Request Pending pro Galpão conferir e Depositar
            return WithdrawalRequest::create([
                'producer_id' => $producerId,
                'warehouse_id' => $warehouseId,
                'amount' => $amount,
                'pix_key' => $pixKey,
                'status' => 'pending',
                'requested_at' => now(),
            ]);
        });
    }

    /**
     * Admin/Galpao confere transferência real enviada via Asaas Pix (ou banco manual) e Baixa
     */
    public function approveAndPay(WithdrawalRequest $request): bool
    {
        if ($request->status !== 'pending') {
            return false;
        }

        DB::transaction(function () use ($request) {
            $request->update([
                'status' => 'paid',
                'approved_at' => now(),
                'paid_at' => now()
            ]);

            // Atualiza Balanço (Total Withdrawn Status)
            $balance = SupplierBalance::where('producer_id', $request->producer_id)
                ->where('warehouse_id', $request->warehouse_id)
                ->first();

            $balance->increment('total_withdrawn', $request->amount);

            // Gera log contábil de débito
            SupplierTransaction::create([
                'producer_id' => $request->producer_id,
                'warehouse_id' => $request->warehouse_id,
                'type' => 'withdrawal',
                'amount' => -$request->amount,
                'description' => "Withdrawal Request #{$request->id} Paid via PIX ({$request->pix_key})",
                'withdrawal_request_id' => $request->id,
            ]);
        });

        return true;
    }

    /**
     * Devolve o dinheiro à tela disponível do fornecedor se for rejeitado
     */
    public function rejectWithdrawal(WithdrawalRequest $request, string $reason): void
    {
        if ($request->status !== 'pending')
            return;

        DB::transaction(function () use ($request, $reason) {
            $request->update([
                'status' => 'rejected',
                'rejection_reason' => $reason
            ]);

            // Devolve o saldo visual
            SupplierBalance::where('producer_id', $request->producer_id)
                ->where('warehouse_id', $request->warehouse_id)
                ->increment('balance', $request->amount);
        });
    }
}
