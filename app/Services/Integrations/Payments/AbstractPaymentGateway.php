<?php

namespace App\Services\Integrations\Payments;

use App\Models\Order;
use App\Services\Integrations\Contracts\PaymentGatewayInterface;

/**
 * Implementacoes padrao (stub) para os metodos hookáveis da Fase 1.
 *
 * Os gateways existentes (PagarmeService, AsaasService, ShipayService) estendem
 * esta classe para nao precisar implementar todos os metodos de imediato.
 * Cada gateway pode sobrescrever individualmente conforme for evoluindo.
 */
abstract class AbstractPaymentGateway implements PaymentGatewayInterface
{
    /**
     * Configura credenciais dinamicas (por supplier).
     * Subclasses devem sobrescrever e popular suas propriedades internas.
     */
    public function configure(array $credentials): void
    {
        // Stub — subclasses sobrescrevem conforme necessario
    }

    /**
     * Cria QR Code PIX.
     * Deve ser sobrescrito por gateways que suportam PIX supplier-driven.
     */
    public function createPixQrCode(Order $order, float $amount, string $idempotencyKey): array
    {
        throw new \RuntimeException(
            static::class . ' ainda nao implementa createPixQrCode. Sobrescreva este metodo.'
        );
    }

    /**
     * Estorna um pagamento.
     * Deve ser sobrescrito por gateways que suportam estorno via API.
     */
    public function refundPayment(string $externalId, float $amount, string $reason): bool
    {
        throw new \RuntimeException(
            static::class . ' ainda nao implementa refundPayment. Sobrescreva este metodo.'
        );
    }

    /**
     * Verifica assinatura HMAC do webhook.
     * Implementacao generica usando SHA-256 — gateways podem sobrescrever
     * se usarem outro algoritmo ou formato de assinatura.
     */
    public function verifyWebhookSignature(string $payload, string $signature, string $secret): bool
    {
        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
    }
}
