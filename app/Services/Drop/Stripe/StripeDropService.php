<?php

namespace App\Services\Drop\Stripe;

use App\Models\Drop\DropModuleConfig;
use App\Models\Drop\DropOrder;
use App\Models\Drop\DropStripeEvent;
use Illuminate\Support\Facades\Log;
use Stripe\Account;
use Stripe\AccountLink;
use Stripe\Stripe;
use Stripe\Webhook;

class StripeDropService
{
    public function __construct()
    {
        Stripe::setApiKey(config("services.stripe.secret_key"));
    }

    public function createConnectAccount(int $clientId): string
    {
        $config = DropModuleConfig::firstOrNew(["client_id" => $clientId]);

        if (empty($config->stripe_account_id)) {
            $account = Account::create([
                "type"         => "express",
                "country"      => "US",
                "capabilities" => ["transfers" => ["requested" => true]],
                "metadata"     => ["hubai_client_id" => (string) $clientId],
            ]);
            $config->stripe_account_id     = $account->id;
            $config->stripe_account_status = "pending";
            $config->save();
            Log::info("[StripeDropService] Conta Express criada", ["client_id" => $clientId, "account_id" => $account->id]);
        }

        $baseUrl     = config("app.url", "https://api.hubai.io");
        $accountLink = AccountLink::create([
            "account"     => $config->stripe_account_id,
            "refresh_url" => $baseUrl . "/drop/stripe/connect/refresh?client_id=" . $clientId,
            "return_url"  => $baseUrl . "/drop/stripe/connect/return?client_id=" . $clientId,
            "type"        => "account_onboarding",
        ]);

        return $accountLink->url;
    }

    public function getAccountStatus(string $stripeAccountId): array
    {
        $account = Account::retrieve($stripeAccountId);
        return [
            "charges_enabled"   => (bool) $account->charges_enabled,
            "payouts_enabled"   => (bool) $account->payouts_enabled,
            "details_submitted" => (bool) $account->details_submitted,
            "requirements"      => $account->requirements ? $account->requirements->toArray() : [],
        ];
    }

    public function constructWebhookEvent(string $payload, string $sigHeader): \Stripevent
    {
        $webhookSecret = config("services.stripe.webhook_secret");
        return Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
    }

    public function handlePaymentSucceeded(array $eventData): void
    {
        $stripeEventId = $eventData["id"] ?? null;
        $pi            = $eventData["data"]["object"] ?? [];
        $meta          = $pi["metadata"] ?? [];
        $dropOrderId   = $meta["drop_order_id"] ?? null;
        $amount        = isset($pi["amount"]) ? round($pi["amount"] / 100, 4) : null;
        $currency      = $pi["currency"] ?? null;
        $clientId      = $meta["hubai_client_id"] ?? null;

        Log::info("[StripeDropService] payment_intent.succeeded", [
            "stripe_event_id" => $stripeEventId,
            "drop_order_id"   => $dropOrderId,
            "amount"          => $amount,
        ]);

        if ($dropOrderId) {
            DropOrder::where("id", $dropOrderId)
                ->whereIn("status", ["pending", "awaiting_payment"])
                ->update(["status" => "payment_received"]);
        }

        DropStripeEvent::create([
            "client_id"       => $clientId ?? 0,
            "stripe_event_id" => $stripeEventId ?? uniqid("evt_"),
            "type"            => "payment_intent.succeeded",
            "drop_order_id"   => $dropOrderId,
            "amount"          => $amount,
            "currency"        => $currency,
            "status"          => DropStripeEvent::STATUS_PROCESSED,
            "payload"         => json_encode($eventData),
            "processed_at"    => now(),
        ]);
    }

    public function handleDisputeCreated(array $eventData): void
    {
        $stripeEventId = $eventData["id"] ?? null;
        $dispute       = $eventData["data"]["object"] ?? [];
        $amount        = isset($dispute["amount"]) ? round($dispute["amount"] / 100, 4) : null;
        $currency      = $dispute["currency"] ?? null;
        $meta          = $dispute["metadata"] ?? [];
        $dropOrderId   = $meta["drop_order_id"] ?? null;
        $clientId      = $meta["hubai_client_id"] ?? null;

        Log::warning("[StripeDropService] charge.dispute.created -- CHARGEBACK", [
            "stripe_event_id" => $stripeEventId,
            "drop_order_id"   => $dropOrderId,
            "amount"          => $amount,
            "reason"          => $dispute["reason"] ?? "unknown",
        ]);

        DropStripeEvent::create([
            "client_id"       => $clientId ?? 0,
            "stripe_event_id" => $stripeEventId ?? uniqid("evt_"),
            "type"            => "charge.dispute.created",
            "drop_order_id"   => $dropOrderId,
            "amount"          => $amount,
            "currency"        => $currency,
            "status"          => DropStripeEvent::STATUS_DISPUTE,
            "payload"         => json_encode($eventData),
            "processed_at"    => now(),
        ]);
    }

    public function handleRefundCreated(array $eventData): void
    {
        $stripeEventId = $eventData["id"] ?? null;
        $refund        = $eventData["data"]["object"] ?? [];
        $amount        = isset($refund["amount"]) ? round($refund["amount"] / 100, 4) : null;
        $currency      = $refund["currency"] ?? null;
        $meta          = $refund["metadata"] ?? [];
        $dropOrderId   = $meta["drop_order_id"] ?? null;
        $clientId      = $meta["hubai_client_id"] ?? null;

        Log::info("[StripeDropService] refund.created", [
            "stripe_event_id" => $stripeEventId,
            "drop_order_id"   => $dropOrderId,
            "amount"          => $amount,
        ]);

        if ($dropOrderId) {
            DropOrder::where("id", $dropOrderId)->update(["status" => "refunded"]);
        }

        DropStripeEvent::create([
            "client_id"       => $clientId ?? 0,
            "stripe_event_id" => $stripeEventId ?? uniqid("evt_"),
            "type"            => "refund.created",
            "drop_order_id"   => $dropOrderId,
            "amount"          => $amount,
            "currency"        => $currency,
            "status"          => DropStripeEvent::STATUS_REFUNDED,
            "payload"         => json_encode($eventData),
            "processed_at"    => now(),
        ]);
    }
}
