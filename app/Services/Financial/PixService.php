<?php

namespace App\Services\Financial;

use App\Models\Client;
use App\Models\Order;
use App\Models\PixTransaction;
use App\Models\Supplier;
use App\Models\SupplierPaymentSetting;
use App\Models\ClientSupplierTransaction;
use App\Services\ClientWalletService;
use App\Services\Integrations\Factories\PaymentGatewayFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orquestra a geracao de QR Codes PIX, idempotencia, persistencia
 * em pix_transactions e calculo de taxa para pagamentos HubAI.
 *
 * Suporta dois tipos de transacao:
 *   - order_payment : pagamento de pedido
 *   - wallet_topup  : recarga de saldo do cliente
 */
class PixService
{
    // -------------------------------------------------------------------------
    // createOrderPix
    // -------------------------------------------------------------------------

    /**
     * Gera (ou recupera existente) um PIX para pagamento de pedido.
     *
     * Idempotencia: se ja existe PixTransaction pendente e nao expirada
     * para o mesmo order_id, retorna a existente sem criar nova cobranca.
     *
     * @throws \RuntimeException Se o supplier nao tiver gateway configurado
     *                           ou o gateway falhar.
     */
    public function createOrderPix(Order $order, Supplier $supplier, ?float $customAmount = null, ?string $buyerDoc = null): PixTransaction
    {
        $settings = $supplier->paymentSetting;

        $this->assertActiveSettings($supplier);

        // Quando ha valor customizado (ex: pagamento parcial), a chave de idempotencia
        // inclui o valor para evitar colisao com um PIX anterior para o total do pedido.
        $amountTag      = $customAmount ? '_partial' . (int) ($customAmount * 100) : '';
        $idempotencyKey = "order_{$order->id}{$amountTag}_" . now()->format('YmdHi');

        // 1. Verificar se ja existe PIX pendente valido para este pedido
        //    (somente reaproveita se nao houver valor customizado, para evitar retornar
        //     um PIX do valor errado em caso de partial payment)
        if ($customAmount === null) {
            $existing = PixTransaction::where('order_id', $order->id)
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        // 2. Calcular taxa (FOR-045: usar CUSTO do fornecedor, nao preco de venda ML)
        $amount = $customAmount ?? (float) ($order->supplier_total !== null ? $order->supplier_total : $order->total);
        $fee    = $this->calculateFee($settings, $amount);

        // 3. Chamar gateway
        $gateway = PaymentGatewayFactory::makeForSupplier($supplier);
        $pixData = $gateway->createPixQrCode($order, $amount, $idempotencyKey, $buyerDoc);

        // 4. Persistir
        return PixTransaction::create([
            'supplier_id'     => $supplier->id,
            'client_id'       => $order->client_id,
            'order_id'        => $order->id,
            'type'            => 'order_payment',
            'gateway'         => $settings->gateway,
            'external_id'     => $pixData['external_id'],
            'amount'          => $amount,
            'fee_amount'      => $fee,
            'net_amount'      => $amount - $fee,
            'status'          => 'pending',
            'qr_code'         => $pixData['qr_code'],
            'qr_code_text'    => $pixData['qr_code_text'],
            'expires_at'      => $pixData['expires_at'] ?? now()->addMinutes($settings->pix_timeout_minutes ?? 30),
            'gateway_response'=> $pixData,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    // -------------------------------------------------------------------------
    // createWalletTopup
    // -------------------------------------------------------------------------

    /**
     * Gera um PIX para recarga de wallet do cliente.
     *
     * @throws \RuntimeException Se o supplier nao permitir topup ou o gateway falhar.
     */
    public function createWalletTopup(Client $client, Supplier $supplier, float $amount): PixTransaction
    {
        $settings = $supplier->paymentSetting;

        $this->assertActiveSettings($supplier);

        if (!$settings->allows_wallet_topup) {
            throw new \RuntimeException("Supplier {$supplier->id} nao permite recarga de wallet.");
        }

        $idempotencyKey = "topup_{$client->id}_{$supplier->id}_" . now()->format('YmdHis');
        $fee            = $this->calculateFee($settings, $amount);

        // Gerar PIX via gateway sem Order model
        $gateway = PaymentGatewayFactory::makeForSupplier($supplier);
        $pixData = $this->createTopupPixViaGateway($gateway, $client, $supplier, $amount, $idempotencyKey);

        return PixTransaction::create([
            'supplier_id'     => $supplier->id,
            'client_id'       => $client->id,
            'order_id'        => null,
            'type'            => 'wallet_topup',
            'gateway'         => $settings->gateway,
            'external_id'     => $pixData['external_id'],
            'amount'          => $amount,
            'fee_amount'      => $fee,
            'net_amount'      => $amount - $fee,
            'status'          => 'pending',
            'qr_code'         => $pixData['qr_code'],
            'qr_code_text'    => $pixData['qr_code_text'],
            'expires_at'      => $pixData['expires_at'] ?? now()->addMinutes($settings->pix_timeout_minutes ?? 30),
            'gateway_response'=> $pixData,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    // -------------------------------------------------------------------------
    // syncPixStatus
    // -------------------------------------------------------------------------

    /**
     * Consulta o status no gateway e atualiza o registro local.
     * Nao faz nada se a transacao ja estiver em estado final (paid/expired/refunded).
     */
    public function syncPixStatus(PixTransaction $pixTransaction): PixTransaction
    {
        if ($pixTransaction->status !== 'pending') {
            return $pixTransaction;
        }

        // Verificar expiração local antes de bater no gateway
        if ($pixTransaction->isExpired()) {
            $pixTransaction->markAsExpired();
            return $pixTransaction->fresh();
        }

        try {
            $supplier = $pixTransaction->supplier;
            $gateway  = PaymentGatewayFactory::makeForSupplier($supplier);
            $status   = $gateway->getPaymentStatus($pixTransaction->external_id);

            if ($status === 'paid') {
                $pixTransaction->markAsPaid();

                // Se for recarga de wallet, creditar o saldo com idempotencia
                if ($pixTransaction->type === 'wallet_topup') {
                    $alreadyCredited = ClientSupplierTransaction::where('client_id', $pixTransaction->client_id)
                        ->where('supplier_id', $pixTransaction->supplier_id)
                        ->where('pix_transaction_id', $pixTransaction->id)
                        ->where('type', 'credit')
                        ->exists();

                    if (!$alreadyCredited) {
                        DB::transaction(function () use ($pixTransaction) {
                            $walletService = app(ClientWalletService::class);
                            $walletService->creditTopup(
                                $pixTransaction->client_id,
                                $pixTransaction->supplier_id,
                                (float) $pixTransaction->net_amount,
                                $pixTransaction
                            );
                        });

                        Log::info('[PixService] Wallet creditada via syncPixStatus', [
                            'pix_transaction_id' => $pixTransaction->id,
                            'client_id'          => $pixTransaction->client_id,
                            'supplier_id'        => $pixTransaction->supplier_id,
                            'amount'             => $pixTransaction->net_amount,
                        ]);
                    }
                }
            } elseif (in_array($status, ['expired', 'failed', 'cancelled'])) {
                $pixTransaction->markAsExpired();
            }
        } catch (\Throwable $e) {
            Log::error('[PixService] Erro ao sincronizar status PIX', [
                'pix_transaction_id' => $pixTransaction->id,
                'external_id'        => $pixTransaction->external_id,
                'error'              => $e->getMessage(),
            ]);
        }

        return $pixTransaction->fresh();
    }

    // -------------------------------------------------------------------------
    // Helpers privados
    // -------------------------------------------------------------------------

    /**
     * Calcula a taxa do supplier sobre um valor.
     *   pix_fee_type = 'fixed'      → taxa fixa em R$
     *   pix_fee_type = 'percentage' → percentual sobre o valor
     */
    private function calculateFee(SupplierPaymentSetting $settings, float $amount): float
    {
        $feeValue = (float) ($settings->pix_fee_value ?? 0);

        if ($settings->pix_fee_type === 'fixed') {
            return $feeValue;
        }

        // Percentual
        return round($amount * ($feeValue / 100), 2);
    }

    /**
     * Gera o PIX de topup no gateway sem um Order model.
     *
     * Cada gateway lida de forma diferente:
     *   - Asaas: POST /payments com externalReference → GET /payments/{id}/pixQrCode
     *   - Shipay: POST /order com order_ref = idempotencyKey → retorna qr_code_url direto
     *
     * @return array{external_id: string, qr_code: string, qr_code_text: string, expires_at: ?Carbon}
     */
    private function createTopupPixViaGateway(
        $gateway,
        Client $client,
        Supplier $supplier,
        float $amount,
        string $idempotencyKey
    ): array {
        $data = [
            'amount'             => $amount,
            'description'        => "Recarga wallet - Cliente #{$client->id} - Fornecedor #{$supplier->id}",
            'external_reference' => "wallet_{$client->id}_{$supplier->id}",
        ];

        $result = $gateway->createOrderPayment($data);

        // Gateway ja retornou dados PIX completos (ex: Shipay)
        if (!empty($result['qr_code']) || !empty($result['qr_code_text'])) {
            return [
                'qr_code'      => $result['qr_code']      ?? '',
                'qr_code_text' => $result['qr_code_text'] ?? '',
                'external_id'  => $result['external_id']  ?? $result['transaction_id'] ?? '',
                'expires_at'   => $result['expires_at']   ?? null,
            ];
        }

        // Gateway retornou apenas transaction_id (ex: Asaas — precisa buscar QR Code)
        if (method_exists($gateway, 'getPixQrCode') && !empty($result['transaction_id'])) {
            $qrData = $gateway->getPixQrCode($result['transaction_id']);
            return [
                'qr_code'      => $qrData['qr_code']      ?? '',
                'qr_code_text' => $qrData['qr_code_text'] ?? '',
                'external_id'  => $qrData['external_id']  ?? $result['transaction_id'],
                'expires_at'   => $qrData['expires_at']   ?? null,
            ];
        }

        // Fallback: normalizar o que vier
        return [
            'qr_code'      => $result['qr_code']      ?? '',
            'qr_code_text' => $result['qr_code_text'] ?? '',
            'external_id'  => $result['transaction_id'] ?? $result['external_id'] ?? '',
            'expires_at'   => $result['expires_at']    ?? null,
        ];
    }

    /**
     * Garante que o supplier tem configuracao de gateway ativa.
     */
    private function assertActiveSettings(Supplier $supplier): void
    {
        $settings = $supplier->paymentSetting;

        if (!$settings || !$settings->is_active) {
            throw new \RuntimeException(
                "Supplier {$supplier->id} nao possui configuracao de pagamento ativa."
            );
        }
    }
}
