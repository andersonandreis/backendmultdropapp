<?php

namespace App\Services\Integrations\Factories;

use App\Models\Supplier;
use App\Services\Integrations\Contracts\PaymentGatewayInterface;
use App\Services\Integrations\Payments\AsaasService;
use App\Services\Integrations\Payments\PagarmeService;
use App\Services\Integrations\Payments\ShipayService;
use App\Services\Integrations\Payments\MercadoPagoGatewayService;
use InvalidArgumentException;

class PaymentGatewayFactory
{
    /**
     * Retorna a implementacao do Gateway de pagamento desejado.
     *
     * @param string|null $gateway Se null, le de config/payment.php:default_gateway
     *                             (default: 'pagarme' via env PAYMENT_DEFAULT_GATEWAY)
     */
    public static function make(?string $gateway = null): PaymentGatewayInterface
    {
        $gateway = $gateway ?: config('payment.default_gateway', 'pagarme');

        return match (strtolower($gateway)) {
            'asaas'   => app(AsaasService::class),
            'shipay'  => app(ShipayService::class),
            'pagarme'       => app(PagarmeService::class),
            'mercadopago'  => app(MercadoPagoGatewayService::class),
            default   => throw new InvalidArgumentException(
                "Gateway de pagamento nao suportado: {$gateway}"
            ),
        };
    }

    /**
     * Retorna o gateway configurado para um supplier especifico,
     * com as credenciais do SupplierPaymentSetting injetadas via configure().
     *
     * @throws \RuntimeException Se o supplier nao tiver configuracao ativa.
     */
    public static function makeForSupplier(Supplier $supplier): PaymentGatewayInterface
    {
        $settings = $supplier->paymentSetting;

        if (! $settings || ! $settings->is_active) {
            throw new \RuntimeException(
                "Supplier {$supplier->id} nao possui configuracao de pagamento ativa. " .
                "Cada WL precisa configurar seu proprio gateway (ShiPay/Pagar.me/Asaas/MercadoPago) " .
                "para que o pagamento caia na conta correta."
            );
        }

        // FOR-048: guards defensivos anti-erro de routing cross-WL.
        // (1) settings tem que ter creds — evita gateway ativado por engano sem chave.
        if (empty($settings->api_key)) {
            throw new \RuntimeException(
                "Supplier {$supplier->id} com gateway {$settings->gateway} sem api_key. " .
                "Rejeitando para nao rotear pagamento pra conta errada."
            );
        }
        // (2) supplier_id do settings tem que bater com o passado — protege de query mal-feita.
        if ((int) $settings->supplier_id !== (int) $supplier->id) {
            throw new \RuntimeException(
                "Mismatch supplier_id: config aponta pra {$settings->supplier_id} mas request pra {$supplier->id}. " .
                "Abortando para evitar rotear pagamento para o supplier errado."
            );
        }

        $gateway = static::make($settings->gateway);

        $gateway->configure([
            'api_key'     => $settings->api_key,
            'api_secret'  => $settings->api_secret,
            'extra'       => $settings->api_extra ?? [],
            'supplier_id' => $supplier->id,
        ]);

        return $gateway;
    }
}
