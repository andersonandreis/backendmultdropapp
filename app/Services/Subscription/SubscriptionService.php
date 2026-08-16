<?php

namespace App\Services\Subscription;

use App\Models\Client;
use App\Models\Plan;
use App\Services\Integrations\Factories\PaymentGatewayFactory;
use App\Models\Subscription;

class SubscriptionService
{
    /**
     * Assinar um Plano via gateway configurado (padrão: config/payment.php → PAYMENT_DEFAULT_GATEWAY)
     */
    public function subscribeClientToPlan(Client $client, Plan $plan, array $paymentDetails): Subscription
    {
        // Cancelar antiga (se houver) localmente antes de nova adesão
        $activeSub = $client->subscriptions()->where('status', 'active')->first();
        if ($activeSub) {
            $this->cancelSubscription($activeSub);
        }

        $gatewayName = config('payment.default_gateway', 'pagarme');
        $gateway = PaymentGatewayFactory::make($gatewayName);

        $subscription = $gateway->createSubscription($client, $plan->id, $paymentDetails);

        return $subscription;
    }

    /**
     * Suspender conta inadimplente ou pausada manualmente
     */
    public function cancelSubscription(Subscription $subscription): bool
    {
        $gatewayName = config('payment.default_gateway', 'pagarme');
        $gateway = PaymentGatewayFactory::make($gatewayName);
        return $gateway->cancelSubscription($subscription);
    }
}
