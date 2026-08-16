<?php

namespace App\Http\Controllers\Drop;

use App\Http\Controllers\Controller;
use App\Services\Drop\Stripe\StripeDropService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripexception\SignatureVerificationException;

/**
 * Recebe webhooks do Stripe para o modulo Drop Internacional.
 * Sempre retorna HTTP 200 para nao desativar o endpoint no Stripe.
 */
class DropStripeController extends Controller
{
    public function __construct(
        private readonly StripeDropService $stripeService
    ) {}

    /**
     * POST /webhooks/drop/stripe
     */
    public function handle(Request $request): JsonResponse
    {
        $payload   = $request->getContent();
        $sigHeader = $request->header("X-Stripe-Signature", "");

        try {
            $event = $this->stripeService->constructWebhookEvent($payload, $sigHeader);
        } catch (SignatureVerificationException $e) {
            Log::warning("[DropStripeController] Assinatura Stripe invalida", ["error" => $e->getMessage()]);
            return response()->json(["error" => "Invalid signature"], 400);
        } catch (\UnexpectedValueException $e) {
            Log::warning("[DropStripeController] Payload Stripe invalido", ["error" => $e->getMessage()]);
            return response()->json(["error" => "Invalid payload"], 400);
        }

        $eventData = $event->toArray();

        try {
            switch ($event->type) {
                case "payment_intent.succeeded":
                    $this->stripeService->handlePaymentSucceeded($eventData);
                    break;

                case "charge.dispute.created":
                    $this->stripeService->handleDisputeCreated($eventData);
                    break;

                case "refund.created":
                    $this->stripeService->handleRefundCreated($eventData);
                    break;

                default:
                    Log::info("[DropStripeController] Evento nao tratado ignorado", ["type" => $event->type]);
                    break;
            }
        } catch (\Throwable $e) {
            Log::error("[DropStripeController] Falha ao processar evento Stripe", [
                "type"  => $event->type,
                "error" => $e->getMessage(),
                "trace" => $e->getTraceAsString(),
            ]);
            // Retorna 200 mesmo em caso de erro para nao desativar o webhook no Stripe
        }

        return response()->json(["received" => true]);
    }
}
