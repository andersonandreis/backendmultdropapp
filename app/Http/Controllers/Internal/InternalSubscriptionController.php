<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * NOV-099: Endpoint interno para criar/renovar subscription no MySQL.
 *
 * Chamado pela edge function Supabase (pagarme-webhook) apos confirmacao
 * de pagamento Pagar.me. Garante que o MySQL de api.hubai.io tenha a
 * subscription criada/atualizada, pois a edge function processa no Supabase
 * mas nao chamava o backend Laravel.
 *
 * Rota: POST /internal/subscriptions
 * Auth: X-Internal-Key = INTERNAL_BRIDGE_KEY
 *
 * Payload esperado:
 * {
 *   "email": "cliente@exemplo.com",
 *   "plan_slug": "hubai-start",         // opcional - se omitido, tenta por valor
 *   "amount": 97.00,                    // valor pago em reais
 *   "charge_id": "ch_xxx",             // ID da cobranca Pagar.me (idempotencia)
 *   "subscription_id": "sub_xxx",      // ID da subscription Pagar.me (opcional)
 *   "payment_method": "credit_card",   // credit_card | pix
 *   "customer_name": "Joao Silva"      // opcional
 * }
 */
class InternalSubscriptionController extends Controller
{
    /** Mapeamento valor -> plan_slug para fallback quando plan_slug nao vem no payload */
    private const AMOUNT_TO_PLAN_SLUG = [
        97  => 'hubai-start',
        197 => 'hubai-scaling',
        297 => 'hubai-pro',
        149 => 'hubai-start',
        449 => 'hubai-scaling',
        799 => 'hubai-pro',
        30  => 'hubai-start',
        29  => 'hubai-start',
    ];

    /**
     * Cria ou renova subscription no MySQL apos confirmacao de pagamento Pagar.me.
     * Idempotente: se ja existe subscription ativa para o charge_id, retorna 200 sem criar duplicata.
     */
    public function provision(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email'           => 'required|email',
            'charge_id'       => 'required|string',
            'amount'          => 'required|numeric|min:0',
            'plan_slug'       => 'nullable|string',
            'subscription_id' => 'nullable|string',
            'payment_method'  => 'nullable|string',
            'customer_name'   => 'nullable|string',
            'customer_phone'  => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'errors' => $validator->errors()], 422);
        }

        $email         = strtolower(trim($request->string('email')));
        $chargeId      = $request->string('charge_id');
        $amount        = (float) $request->input('amount');
        $planSlug      = $request->input('plan_slug');

        // HUB-153: guard Tokfy - se o payload trouxer product_code ou items
        // com 'tokfy/tockfy', rejeita. Defesa em profundidade: pagarme-webhook
        // do Supabase (que chama este endpoint) ja tem early guard Tokfy pos
        // HUB-143/HUB-144, mas se algum bug futuro deixar passar, esta camada
        // continua bloqueando.
        $productCode = strtolower((string) $request->input('product_code', ''));
        $itemsInput = $request->input('items', []);
        $isTokfyProvision = str_contains($productCode, 'tokfy') || str_contains($productCode, 'tockfy');
        if (!$isTokfyProvision && is_array($itemsInput)) {
            foreach ($itemsInput as $it) {
                $c = strtolower((string) ($it['code'] ?? ''));
                $d = strtolower((string) ($it['description'] ?? ''));
                if (str_contains($c, 'tokfy') || str_contains($c, 'tockfy')
                    || str_contains($d, 'tokfy') || str_contains($d, 'tockfy')) {
                    $isTokfyProvision = true;
                    break;
                }
            }
        }
        if ($isTokfyProvision) {
            Log::warning('[InternalSubscription][HUB-153] Skipping Tokfy provision (nao e HubAI)', [
                'email'     => $email,
                'charge_id' => $chargeId,
                'amount'    => $amount,
            ]);
            return response()->json([
                'ok'      => false,
                'skipped' => 'tokfy_provision_guard',
            ], 200);
        }
        $pagarmeSubId  = $request->input('subscription_id');
        $paymentMethod = $request->input('payment_method', 'credit_card');
        $customerName  = $request->input('customer_name');

        // Idempotencia: charge_id ja processado
        $existing = Subscription::where('external_payment_id', $chargeId)
            ->where('status', 'active')
            ->first();

        if ($existing) {
            Log::info('[InternalSubscription] Charge ja processado -- idempotencia', [
                'charge_id'       => $chargeId,
                'subscription_id' => $existing->id,
            ]);
            return response()->json([
                'ok'              => true,
                'skipped'         => 'already_processed',
                'subscription_id' => $existing->id,
            ]);
        }

        try {
            $result = DB::transaction(function () use (
                $email, $chargeId, $amount, $planSlug, $pagarmeSubId,
                $paymentMethod, $customerName
            ) {
                // 1. Resolver ou criar User
                $user = User::where('email', $email)->first();
                if (!$user) {
                    $user = User::create([
                        'name'      => $customerName ?? explode('@', $email)[0],
                        'email'     => $email,
                        'password'  => bcrypt('123456'),
                        'role'      => 'client',
                        'is_active' => true,
                    ]);
                    Log::info('[InternalSubscription] Usuario criado', ['user_id' => $user->id, 'email' => $email]);
                } else {
                    $user->update(['is_active' => true]);
                }

                // 2. Resolver ou criar Client
                // MUL-269 fase 2: company_name removido de clients — nome vem do user (accessor).
                $client = Client::firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'is_active'    => true,
                    ]
                );

                // 3. Resolver Plan
                $planId = null;
                if ($planSlug) {
                    $plan = Plan::where('slug', $planSlug)
                        ->orWhere('name', 'like', "%{$planSlug}%")
                        ->first();
                    $planId = $plan?->id;
                }

                // Fallback: resolver pelo valor arredondado
                if (!$planId && $amount > 0) {
                    $roundedAmount = (int) round($amount);
                    $inferredSlug  = self::AMOUNT_TO_PLAN_SLUG[$roundedAmount] ?? null;

                    if ($inferredSlug) {
                        $plan   = Plan::where('slug', $inferredSlug)->first();
                        $planId = $plan?->id;
                        Log::info('[InternalSubscription] Plan resolvido por valor', [
                            'amount'        => $amount,
                            'inferred_slug' => $inferredSlug,
                            'plan_id'       => $planId,
                        ]);
                    }

                    // Ultimo recurso: buscar por faixa de preco
                    if (!$planId) {
                        $plan = Plan::where('price_monthly', '>=', $amount - 10)
                            ->where('price_monthly', '<=', $amount + 10)
                            ->where('is_active', true)
                            ->orderBy('price_monthly')
                            ->first();
                        $planId = $plan?->id;
                    }
                }

                // 4. Criar ou atualizar Subscription (30 dias)
                $periodEnd = now()->addDays(30);

                $subData = [
                    'status'                  => 'active',
                    'pagarme_status'          => 'paid',
                    'pagarme_subscription_id' => $pagarmeSubId ?? $chargeId,
                    'external_payment_id'     => $chargeId,
                    'current_period_start'    => now(),
                    'current_period_end'      => $periodEnd,
                    'trial_ends_at'           => null,
                    'cancelled_at'            => null,
                    'payment_method'          => $paymentMethod,
                ];
                if ($planId) {
                    $subData['plan_id'] = $planId;
                }

                $sub = Subscription::where('client_id', $client->id)->first();
                if ($sub) {
                    $sub->update($subData);
                    $action = 'updated';
                } else {
                    $subData['client_id'] = $client->id;
                    $sub = Subscription::create($subData);
                    $action = 'created';
                }

                Log::info('[InternalSubscription] Subscription provisionada', [
                    'action'          => $action,
                    'subscription_id' => $sub->id,
                    'client_id'       => $client->id,
                    'plan_id'         => $planId,
                    'email'           => $email,
                    'charge_id'       => $chargeId,
                    'active_until'    => $periodEnd->toDateTimeString(),
                ]);

                return [
                    'subscription_id' => $sub->id,
                    'client_id'       => $client->id,
                    'plan_id'         => $planId,
                    'action'          => $action,
                    'active_until'    => $periodEnd->toIso8601String(),
                ];
            });

            return response()->json(['ok' => true] + $result, 201);

        } catch (\Throwable $e) {
            Log::error('[InternalSubscription] Erro ao provisionar subscription', [
                'email'     => $email,
                'charge_id' => $chargeId,
                'error'     => $e->getMessage(),
                'trace'     => substr($e->getTraceAsString(), 0, 500),
            ]);

            return response()->json([
                'ok'    => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /internal/subscriptions/by-email/{email} -- consulta subscription ativa por email.
     * Util para diagnostico sem entrar no banco diretamente.
     */
    public function show(string $email): JsonResponse
    {
        $email = strtolower(trim(urldecode($email)));
        $user  = User::where('email', $email)->first();

        if (!$user) {
            return response()->json(['ok' => false, 'error' => 'user_not_found'], 404);
        }

        $client = Client::where('user_id', $user->id)->first();
        if (!$client) {
            return response()->json(['ok' => false, 'error' => 'client_not_found'], 404);
        }

        $sub = Subscription::where('client_id', $client->id)
            ->orderByDesc('created_at')
            ->first();

        return response()->json([
            'ok'           => true,
            'client_id'    => $client->id,
            'subscription' => $sub,
        ]);
    }
}
