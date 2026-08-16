<?php

namespace App\Http\Controllers\TenantApi\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Orders\StatusTransitioner;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OrderWriteController extends Controller
{
    public function __construct(private StatusTransitioner $transitioner) {}

    /**
     * PATCH /v1/orders/{id}/status
     * Body: { "to": "accepted"|"in_fulfillment"|"shipped"|... , "reason"?: string }
     */
    public function status(Request $request, string $id)
    {
        $v = Validator::make($request->all(), [
            'to'     => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'validation', 'errors' => $v->errors()], 422);
        }

        $tenantId = $request->attributes->get('tenant_id');
        $order = Order::forTenant($tenantId)->find($id);
        if (!$order) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return DB::transaction(function () use ($request, $order, $v) {
            [$ok, $err, $detail] = $this->transitioner->transition(
                $order,
                $request->input('to'),
                'tenant',
                ['reason' => $request->input('reason')]
            );
            if (!$ok) {
                $status = $err === 'invalid_transition' ? 409 : 422;
                return response()->json([
                    'error'  => $err,
                    'detail' => $detail,
                    'from'   => $order->getOriginal('canonical_status'),
                    'to'     => $request->input('to'),
                ], $status);
            }
            $order->save();
            return response()->json([
                'data' => [
                    'id'               => $order->id,
                    'canonical_status' => $order->canonical_status,
                    'updated_at'       => $order->updated_at?->toIso8601String(),
                ],
            ]);
        });
    }

    /**
     * POST /v1/orders/{id}/tracking
     * Body: { carrier, code, url?, shipped_at? }
     * Tambem transiciona pra shipped (se permitido).
     */
    public function tracking(Request $request, string $id)
    {
        $v = Validator::make($request->all(), [
            'carrier'    => ['required', 'string', 'max:64'],
            'code'       => ['required', 'string', 'max:64'],
            'url'        => ['nullable', 'url', 'max:255'],
            'shipped_at' => ['nullable', 'date'],
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'validation', 'errors' => $v->errors()], 422);
        }

        $tenantId = $request->attributes->get('tenant_id');
        $order = Order::forTenant($tenantId)->find($id);
        if (!$order) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return DB::transaction(function () use ($request, $order) {
            $order->tracking_number = $request->input('code');
            $order->carrier_name    = $request->input('carrier');
            $order->tracking_url    = $request->input('url');

            // Tenta transicionar pra shipped (so se atual permite).
            if ($order->canonical_status !== 'shipped') {
                [$ok, $err, $detail] = $this->transitioner->transition($order, 'shipped', 'tenant');
                if (!$ok) {
                    $status = $err === 'invalid_transition' ? 409 : 422;
                    return response()->json([
                        'error'  => $err,
                        'detail' => $detail,
                        'from'   => $order->getOriginal('canonical_status'),
                        'to'     => 'shipped',
                    ], $status);
                }
            }
            $order->save();
            return response()->json([
                'data' => [
                    'id'               => $order->id,
                    'canonical_status' => $order->canonical_status,
                    'tracking'         => [
                        'code'    => $order->tracking_number,
                        'carrier' => $order->carrier_name,
                        'url'     => $order->tracking_url,
                    ],
                ],
            ]);
        });
    }

    /**
     * POST /v1/orders/{id}/cancel
     * Body: { reason }
     */
    public function cancel(Request $request, string $id)
    {
        $v = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:255'],
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'validation', 'errors' => $v->errors()], 422);
        }

        $tenantId = $request->attributes->get('tenant_id');
        $order = Order::forTenant($tenantId)->find($id);
        if (!$order) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return DB::transaction(function () use ($request, $order) {
            [$ok, $err, $detail] = $this->transitioner->transition(
                $order, 'cancelled', 'tenant',
                ['reason' => $request->input('reason')]
            );
            if (!$ok) {
                $status = $err === 'invalid_transition' ? 409 : 422;
                return response()->json([
                    'error'  => $err,
                    'detail' => $detail,
                    'from'   => $order->getOriginal('canonical_status'),
                ], $status);
            }
            $order->cancel_reason = $request->input('reason');
            $order->save();
            return response()->json([
                'data' => [
                    'id'               => $order->id,
                    'canonical_status' => $order->canonical_status,
                    'cancel_reason'    => $order->cancel_reason,
                ],
            ]);
        });
    }

    /**
     * POST /v1/orders/{id}/refund
     * Body: { reason, amount_cents?, partial? }
     *
     * Fluxo:
     *  1. Acha o Payment do pedido (status=paid, ainda nao refundado).
     *  2. PaymentGatewayFactory::make(payment.gateway) -> Asaas/Pagarme/Shipay.
     *  3. Chama refundPayment(external_id, amount, reason).
     *  4. Se OK: grava payment.refunded_at/refund_amount/refund_reason + transiciona order pra refunded.
     */
    public function refund(Request $request, string $id)
    {
        $v = Validator::make($request->all(), [
            'reason'       => ['required', 'string', 'max:255'],
            'amount_cents' => ['nullable', 'integer', 'min:1'],
            'partial'      => ['nullable', 'boolean'],
        ]);
        if ($v->fails()) {
            return response()->json(['error' => 'validation', 'errors' => $v->errors()], 422);
        }

        $tenantId = $request->attributes->get('tenant_id');
        $order = Order::forTenant($tenantId)->find($id);
        if (!$order) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $payment = \App\Models\Payment::where('order_id', $order->id)
            ->where('status', 'paid')
            ->whereNull('refunded_at')
            ->orderByDesc('paid_at')
            ->first();

        if (!$payment) {
            return response()->json([
                'error'  => 'no_paid_payment',
                'detail' => 'Nao ha pagamento ativo (status=paid, refunded_at=null) pra estornar.',
            ], 422);
        }
        if (!$payment->external_id) {
            return response()->json([
                'error'  => 'no_external_id',
                'detail' => 'Pagamento sem external_id do gateway — refund nao pode ser disparado.',
            ], 422);
        }

        $amountFloat = $request->filled('amount_cents')
            ? round(((int) $request->input('amount_cents')) / 100, 2)
            : (float) $payment->amount;

        if ($amountFloat <= 0 || $amountFloat > (float) $payment->amount) {
            return response()->json([
                'error'  => 'invalid_amount',
                'detail' => "Valor de refund invalido. Maximo: {$payment->amount}",
            ], 422);
        }

        return DB::transaction(function () use ($request, $order, $payment, $amountFloat) {
            try {
                $gw = \App\Services\Integrations\Factories\PaymentGatewayFactory::make($payment->gateway);
                $ok = $gw->refundPayment($payment->external_id, $amountFloat, $request->input('reason'));
            } catch (\Throwable $e) {
                return response()->json([
                    'error'   => 'gateway_failed',
                    'detail'  => 'Gateway lancou excecao: ' . $e->getMessage(),
                    'gateway' => $payment->gateway,
                ], 502);
            }

            if (!$ok) {
                return response()->json([
                    'error'   => 'gateway_refused',
                    'detail'  => 'Gateway nao confirmou o refund.',
                    'gateway' => $payment->gateway,
                ], 502);
            }

            $payment->forceFill([
                'refunded_at'   => now(),
                'refund_amount' => $amountFloat,
                'refund_reason' => $request->input('reason'),
            ])->save();

            [$tok, $terr, $tdetail] = $this->transitioner->transition(
                $order, 'refunded', 'tenant',
                ['reason' => $request->input('reason'), 'payment_id' => $payment->id, 'amount' => $amountFloat]
            );
            if (!$tok) {
                // Gateway aceitou mas state machine recusou (estado terminal etc).
                // Mantemos refund + warning no response.
                return response()->json([
                    'data' => [
                        'id'               => $order->id,
                        'canonical_status' => $order->canonical_status,
                        'refund' => [
                            'amount'  => $amountFloat,
                            'gateway' => $payment->gateway,
                            'status'  => 'partial_state_warning',
                            'detail'  => $tdetail,
                        ],
                    ],
                ], 200);
            }
            $order->save();

            return response()->json([
                'data' => [
                    'id'               => $order->id,
                    'canonical_status' => $order->canonical_status,
                    'refund' => [
                        'amount'     => $amountFloat,
                        'gateway'    => $payment->gateway,
                        'payment_id' => $payment->id,
                    ],
                ],
            ]);
        });
    }
}
