<?php

namespace App\Services\Integrations\Contracts;

use App\Models\Client;
use App\Models\Order;
use App\Models\Subscription;

interface PaymentGatewayInterface
{
    /**
     * Gera uma transacao (Boleto/PIX/Cartao) para cobrar um Pedido.
     */
    public function createOrderPayment(Order $order, array $paymentDetails): array;

    /**
     * Consulta situacao de um pagamento (usado num cron ou webhook).
     */
    public function getPaymentStatus(string $transactionId): string;

    /**
     * Cria/Renova uma assinatura pro Cliente (SaaS Billing).
     */
    public function createSubscription(Client $client, int $planId, array $paymentDetails): Subscription;

    /**
     * Cancela a assinatura local e remotamente.
     */
    public function cancelSubscription(Subscription $subscription): bool;

    // -------------------------------------------------------------------------
    // Metodos do sistema hookavel (Fase 1)
    // -------------------------------------------------------------------------

    /**
     * Configura as credenciais dinamicas do gateway (por supplier).
     * Chamado pela PaymentGatewayFactory::makeForSupplier() antes de qualquer operacao.
     *
     * @param array $credentials Chaves esperadas: api_key, api_secret, extra (array)
     */
    public function configure(array $credentials): void;

    /**
     * Cria um QR Code PIX para pagamento de pedido ou recarga de wallet.
     *
     * @param Order  $order          Pedido de referencia
     * @param float  $amount         Valor a cobrar
     * @param string $idempotencyKey Chave unica para idempotencia
     *
     * @return array{
     *   external_id: string,
     *   qr_code: string,
     *   qr_code_text: string,
     *   expires_at: string,
     *   raw: array
     * }
     */
    public function createPixQrCode(Order $order, float $amount, string $idempotencyKey): array;

    /**
     * Estorna total ou parcialmente um pagamento no gateway.
     *
     * @param string $externalId ID do pagamento no gateway
     * @param float  $amount     Valor a estornar
     * @param string $reason     Motivo do estorno
     */
    public function refundPayment(string $externalId, float $amount, string $reason): bool;

    /**
     * Verifica a assinatura HMAC de um payload de webhook recebido.
     *
     * @param string $payload   Corpo raw do request
     * @param string $signature Assinatura enviada pelo gateway (header)
     * @param string $secret    Secret compartilhado armazenado em supplier_payment_settings
     */
    public function verifyWebhookSignature(string $payload, string $signature, string $secret): bool;
}
