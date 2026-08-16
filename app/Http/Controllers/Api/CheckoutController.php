<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Plan;
use App\Models\Client;
use App\Models\Subscription;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Services\AppLoggerService;
use App\Support\BrandKit;

class CheckoutController extends Controller
{
    private const CARD_MARKUP_PERCENT = 40;

    private string $apiKey;
    private string $baseUrl = 'https://api.pagar.me/core/v5';

    public function __construct()
    {
        $this->apiKey = config('payment.pagarme.api_key', '');
    }

    private function headers(): array
    {
        return [
            'Authorization' => 'Basic ' . base64_encode($this->apiKey . ':'),
            'Content-Type'  => 'application/json',
        ];
    }

    public function getPlans(): JsonResponse
    {
        $plans = Plan::where('is_active', true)->get();
        return response()->json(['data' => $plans]);
    }

    public function createSubscription(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_id'          => 'required|exists:plans,id',
            'name'             => 'required|string|max:255',
            'email'            => 'required|email',
            'document'         => 'required|string',
            'phone'            => 'required|string',
            'payment_method'   => 'required|in:credit_card,boleto,pix',
            'card_number'      => 'required_if:payment_method,credit_card|nullable|string',
            'card_holder_name' => 'required_if:payment_method,credit_card|nullable|string',
            'card_exp_month'   => 'required_if:payment_method,credit_card|nullable|string',
            'card_exp_year'    => 'required_if:payment_method,credit_card|nullable|string',
            'card_cvv'         => 'required_if:payment_method,credit_card|nullable|string',
        ]);

        // INF-064: gateway por backend via PAYMENT_DEFAULT_GATEWAY (.env).
        // Rollback = voltar flag pra pagarme; fluxo Pagar.me abaixo intocado.
        if (config('payment.default_gateway') === 'asaas') {
            return $this->createSubscriptionAsaas($request, $validated);
        }

        // INF-030-MARCA (13/08): de qual marca (seller.global ou tokfy.io) essa
        // venda veio, medido pelo Origin/Referer real da requisicao (nao existe
        // outro sinal — o front nao manda campo de marca e o plano nao serve de
        // proxy, ver App\Support\BrandKit). Usado pra: (1) descricao/metadata
        // que vao pro Pagar.me, (2) coluna marca em subscriptions/clients, que
        // e o que o e-mail de boas-vindas (SellerWelcomeMail via BrandKit) e um
        // futuro disparo segmentado vao ler.
        $marca = BrandKit::fromRequest($request);

        $plan        = Plan::findOrFail($validated['plan_id']);
        $basePrice = (float) $plan->price_monthly > 0 ? (float) $plan->price_monthly : (float) $plan->price_yearly; $amountCents = (int) round($basePrice * 100);
        // Cartao de credito tem markup sobre o preco PIX (anti-chargeback, mesmo padrao do checkout hubai.io)
        if ($validated['payment_method'] === 'credit_card') {
            $basePrice = (float) $plan->price_monthly > 0 ? (float) $plan->price_monthly : (float) $plan->price_yearly; $amountCents = (int) round($basePrice * (1 + self::CARD_MARKUP_PERCENT / 100) * 100);
        }

        // SEL-ACESSO (12/08): IDEMPOTENCIA DO PIX.
        // O cliente gera um PIX, acha que nao entrou, volta e gera outro. Cada
        // clique criava uma order NOVA no Pagar.me, e finalizeCheckout faz
        // updateOrCreate por client_id — ou seja, a order nova SOBRESCREVE a
        // anterior em pagarme_subscription_id. Se ele pagar a PRIMEIRA, o nosso
        // banco esta apontando pra SEGUNDA (que nunca sera paga): o dinheiro
        // entra, o reconciliador consulta a order errada, ve 'pending' e o
        // acesso nunca sai. Caso real: user 1261 pagou or_bo2ndLcjRHmKnlvQ
        // (R$149, 08/08) e o banco guardou or_q7W1aQH5Yc331Bz8, pendente ate hoje.
        // Aqui: mesmo cliente + mesmo plano nas ultimas 24h reaproveita a order
        // que ja existe (e o MESMO QR), em vez de criar outra.
        if ($validated['payment_method'] === 'pix') {
            $reuso = $this->reaproveitarPixRecente($validated['email'], (int) $plan->id);
            if ($reuso !== null) {
                return $this->finalizeCheckout(
                    $validated,
                    $plan,
                    $reuso['customer_id'],
                    $reuso['order_id'],
                    $reuso['status'],
                    preg_replace('/\D/', '', $validated['document']),
                    isset($validated['phone']) ? preg_replace('/\D/', '', $validated['phone']) : null,
                    $reuso['pix_qr_code'],
                    $reuso['pix_qr_code_url'],
                    null,
                    $marca
                );
            }
        }

        // Criar customer no Pagar.me
        $docDigits   = preg_replace('/\D/', '', $validated['document']);
        $phoneDigits = isset($validated['phone']) ? preg_replace('/\D/', '', $validated['phone']) : null;
        $customerPayload = [
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            // CNPJ (14 digitos) exige type=company na API v5 — individual com CNPJ e rejeitado
            'type'     => strlen($docDigits) > 11 ? 'company' : 'individual',
            'document' => $docDigits,
        ];
        if ($phoneDigits && strlen($phoneDigits) >= 10) {
            $customerPayload['phones'] = [
                'mobile_phone' => [
                    'country_code' => '55',
                    'area_code'    => substr($phoneDigits, 0, 2),
                    'number'       => substr($phoneDigits, 2),
                ],
            ];
        }

        $customerResp = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/customers", $customerPayload);

        if (!$customerResp->successful()) {
            Log::error('Pagar.me customer error', ['status' => $customerResp->status(), 'body' => $customerResp->body()]);
            AppLoggerService::error('payment', 'checkout.customer_failed', 'Pagar.me customer creation failed', ['http_status' => $customerResp->status()]);
            return response()->json(['error' => 'Erro ao criar cliente no gateway de pagamento.'], 422);
        }

        $customer   = $customerResp->json();
        $customerId = $customer['id'];

        $order = null;

        if ($validated['payment_method'] === 'credit_card') {
            // Cartao = assinatura recorrente (POST /subscriptions), mesmo padrao do hubai.io.
            // Order avulso nao renova; a UI promete "Renovacao automatica".
            $subscriptionPayload = [
                'customer_id'          => $customerId,
                'payment_method'       => 'credit_card',
                'interval'             => 'month',
                'interval_count'       => 1,
                'billing_type'         => 'prepaid',
                'minimum_price'        => $amountCents,
                'installments'         => 1,
                'statement_descriptor' => 'SELLERGLOBAL',
                'card' => [
                    'number'      => preg_replace('/\D/', '', $validated['card_number']),
                    'holder_name' => strtoupper($validated['card_holder_name']),
                    'exp_month'   => (int) $validated['card_exp_month'],
                    'exp_year'    => (int) $validated['card_exp_year'],
                    'cvv'         => $validated['card_cvv'],
                    // CEP 00000000 reprova no antifraude; placeholder valido (padrao hubai.io)
                    'billing_address' => [
                        'line_1'   => '123, Rua Principal, Centro',
                        'zip_code' => '01001000',
                        'city'     => 'Sao Paulo',
                        'state'    => 'SP',
                        'country'  => 'BR',
                    ],
                ],
                'pricing_scheme' => ['scheme_type' => 'unit', 'price' => $amountCents],
                'items' => [[
                    // INF-030-MARCA: descricao nao e mais fixa em "Seller Global" —
                    // reflete a marca real de onde veio a venda (Origin/Referer).
                    'description'    => "Assinatura {$plan->name} - " . $this->brandLabel($marca),
                    'quantity'       => 1,
                    'pricing_scheme' => ['scheme_type' => 'unit', 'price' => $amountCents],
                ]],
                'metadata' => ['plan_id' => (string) $plan->id, 'marca' => $marca, 'source' => 'seller-global-checkout'],
            ];

            $subResp = Http::withHeaders($this->headers())
                ->post("{$this->baseUrl}/subscriptions", $subscriptionPayload);

            if (!$subResp->successful()) {
                Log::error('Pagar.me subscription error', ['status' => $subResp->status(), 'body' => $subResp->body()]);
                AppLoggerService::error('payment', 'checkout.card_failed', 'Pagar.me subscription creation failed', ['http_status' => $subResp->status()]);
                return response()->json(['error' => 'Cartão recusado. Confira os dados ou tente outro cartão.'], 422);
            }

            $sub          = $subResp->json();
            $gatewayId    = $sub['id'];
            $subStatus    = $sub['status'] ?? 'pending';

            if (in_array($subStatus, ['canceled', 'failed'], true)) {
                Log::warning('Pagar.me subscription declined', ['id' => $gatewayId, 'status' => $subStatus]);
                return response()->json(['error' => 'Cartão recusado. Confira os dados ou tente outro cartão.'], 422);
            }

            // active = cobranca aprovada (billing_type prepaid cobra na criacao)
            $pagarmeStatus = $subStatus === 'active' ? 'paid' : 'pending';
        } else {
            // PIX/boleto = order avulso; renovacao tratada via webhook/cobranca manual
            $chargePayload = [
                'customer_id' => $customerId,
                'items'       => [[
                    // INF-030-MARCA: descricao nao e mais fixa em "Seller Global".
                    'description' => "Assinatura {$plan->name} - " . $this->brandLabel($marca),
                    'amount'      => $amountCents,
                    'quantity'    => 1,
                ]],
                'payments' => [],
                // INF-030-MARCA: antes esse payload NAO mandava metadata nenhum —
                // toda order PIX/boleto saia sem nenhum jeito de saber a marca no
                // proprio Pagar.me. Mesma chave 'marca' do fluxo de cartao acima.
                'metadata' => ['plan_id' => (string) $plan->id, 'marca' => $marca, 'source' => 'seller-global-checkout'],
            ];

            if ($validated['payment_method'] === 'pix') {
                $chargePayload['payments'][] = [
                    'payment_method' => 'pix',
                    'pix'            => ['expires_in' => 3600],
                ];
            } else {
                $chargePayload['payments'][] = [
                    'payment_method' => 'boleto',
                    'boleto'         => [
                        'instructions' => 'Pagar ate o vencimento',
                        'due_at'       => now()->addDays(3)->toIso8601String(),
                    ],
                ];
            }

            $orderResp = Http::withHeaders($this->headers())
                ->post("{$this->baseUrl}/orders", $chargePayload);

            if (!$orderResp->successful()) {
                Log::error('Pagar.me order error', ['body' => $orderResp->body()]);
                AppLoggerService::error('payment', 'checkout.payment_failed', 'Pagar.me order creation failed', ['http_status' => $orderResp->status()]);
                return response()->json(['error' => 'Erro ao processar pagamento.'], 422);
            }

            $order         = $orderResp->json();
            $gatewayId     = $order['id'];
            $pagarmeStatus = $order['status'] ?? 'pending';
        }

        // Se PIX, extrair QR code do order Pagar.me
        $pixQrCode    = null;
        $pixQrCodeUrl = null;
        $boletoUrl    = null;
        if (
            $validated['payment_method'] === 'pix' &&
            isset($order['charges'][0]['last_transaction']['qr_code'])
        ) {
            $pixQrCode    = $order['charges'][0]['last_transaction']['qr_code'];
            $pixQrCodeUrl = $order['charges'][0]['last_transaction']['qr_code_url'] ?? null;
        }
        if (
            $validated['payment_method'] === 'boleto' &&
            isset($order['charges'][0]['last_transaction']['url'])
        ) {
            $boletoUrl = $order['charges'][0]['last_transaction']['url'];
        }

        return $this->finalizeCheckout(
            $validated, $plan, $customerId, $gatewayId, $pagarmeStatus,
            $docDigits, $phoneDigits, $pixQrCode, $pixQrCodeUrl, $boletoUrl, $marca
        );
    }

    /** INF-030-MARCA: nome de exibicao da marca pra descricao do item no gateway. */
    private function brandLabel(string $marca): string
    {
        return $marca === BrandKit::TOKFY ? 'Tokfy' : 'Seller Global';
    }

    /**
     * SEL-ACESSO: procura um PIX ainda VALIDO do mesmo cliente/plano nas
     * ultimas 24h pra reaproveitar em vez de criar outra order.
     *
     * Devolve null (= segue o fluxo normal e cria order nova) quando:
     *   - nao existe order recente desse cliente/plano;
     *   - a order sumiu/deu erro no Pagar.me;
     *   - a order nao esta mais em 'pending'/'paid' (ex: canceled, failed);
     *   - o QR expirou — nesse caso o cliente PRECISA de um novo mesmo.
     *
     * Se a order ja estiver PAGA, devolve 'paid': finalizeCheckout ativa o
     * acesso na hora, o que tambem conserta o caso do cliente que pagou e
     * voltou pro checkout achando que nao tinha entrado.
     *
     * Qualquer excecao aqui e engolida de proposito: idempotencia e otimizacao,
     * nunca motivo pra derrubar uma venda.
     *
     * @return array{order_id:string,customer_id:string,status:string,pix_qr_code:?string,pix_qr_code_url:?string}|null
     */
    private function reaproveitarPixRecente(string $email, int $planId): ?array
    {
        try {
            $sub = \Illuminate\Support\Facades\DB::table('subscriptions as s')
                ->join('clients as c', 'c.id', '=', 's.client_id')
                ->join('users as u', 'u.id', '=', 'c.user_id')
                ->whereRaw('LOWER(u.email) = ?', [strtolower(trim($email))])
                ->where('s.plan_id', $planId)
                ->where('s.payment_method', 'pix')
                ->where('s.pagarme_subscription_id', 'like', 'or\_%')
                ->where('s.updated_at', '>=', now()->subHours(24))
                ->orderByDesc('s.id')
                ->first(['s.pagarme_subscription_id', 's.pagarme_customer_id']);

            if (! $sub || empty($sub->pagarme_subscription_id)) {
                return null;
            }

            $resp = Http::withHeaders($this->headers())->timeout(15)
                ->get("{$this->baseUrl}/orders/{$sub->pagarme_subscription_id}");

            if (! $resp->successful()) {
                return null;
            }

            $order  = $resp->json();
            $status = (string) ($order['status'] ?? '');

            if (! in_array($status, ['pending', 'paid'], true)) {
                return null;
            }

            $tx         = $order['charges'][0]['last_transaction'] ?? [];
            $qrCode     = $tx['qr_code'] ?? null;
            $qrCodeUrl  = $tx['qr_code_url'] ?? null;

            if ($status === 'pending') {
                if (empty($qrCode)) {
                    return null;
                }
                $expiraEm = $tx['expires_at'] ?? null;
                if ($expiraEm) {
                    try {
                        if (now()->greaterThan(\Carbon\Carbon::parse($expiraEm))) {
                            return null; // QR morto — cria outro
                        }
                    } catch (\Throwable $e) {
                        // data ilegivel: nao arrisca devolver QR vencido
                        return null;
                    }
                }
            }

            $customerId = $order['customer']['id'] ?? $sub->pagarme_customer_id;
            if (empty($customerId)) {
                return null;
            }

            Log::info('[SEL-ACESSO] PIX reaproveitado em vez de criar order nova', [
                'email'    => $email,
                'plan_id'  => $planId,
                'order_id' => $order['id'] ?? $sub->pagarme_subscription_id,
                'status'   => $status,
            ]);

            return [
                'order_id'        => (string) ($order['id'] ?? $sub->pagarme_subscription_id),
                'customer_id'     => (string) $customerId,
                'status'          => $status === 'paid' ? 'paid' : 'pending',
                'pix_qr_code'     => $qrCode,
                'pix_qr_code_url' => $qrCodeUrl,
            ];
        } catch (\Throwable $e) {
            Log::warning('[SEL-ACESSO] reaproveitamento de PIX falhou; seguindo fluxo normal', [
                'erro' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * INF-064: fluxo Asaas (conta nova ruanipanema2). PIX/boleto = payment
     * avulso; cartao = subscription recorrente. QR PIX vem como base64
     * (encodedImage) + copia-e-cola (payload).
     */
    private function createSubscriptionAsaas(Request $request, array $validated): JsonResponse
    {
        $asaasBase = rtrim(config('services.asaas.base_url', 'https://api.asaas.com/v3'), '/');
        $asaasKey  = config('services.asaas.api_key', '');

        $plan      = Plan::findOrFail($validated['plan_id']);
        $basePrice = (float) $plan->price_monthly > 0 ? (float) $plan->price_monthly : (float) $plan->price_yearly;
        if ($validated['payment_method'] === 'credit_card') {
            $basePrice = $basePrice * (1 + self::CARD_MARKUP_PERCENT / 100);
        }
        $amount = round($basePrice, 2);

        // INF-030-MARCA (13/08): mesma logica do fluxo Pagar.me — marca real
        // da venda pelo Origin/Referer, nao mais fixa em seller.global.
        $marca = BrandKit::fromRequest($request);

        $docDigits   = preg_replace('/\D/', '', $validated['document']);
        $phoneDigits = preg_replace('/\D/', '', $validated['phone']);
        $headers     = ['access_token' => $asaasKey, 'Accept' => 'application/json'];
        $description = "Assinatura {$plan->name} - " . $this->brandLabel($marca);
        $externalRef = "{$marca}_plan_{$plan->id}";

        // Customer: reusa por cpfCnpj pra nao duplicar no painel Asaas
        $customerId = null;
        $lookup = Http::withHeaders($headers)->get("{$asaasBase}/customers", ['cpfCnpj' => $docDigits]);
        if ($lookup->successful() && !empty($lookup->json('data.0.id'))) {
            $customerId = $lookup->json('data.0.id');
        } else {
            $custResp = Http::withHeaders($headers)->post("{$asaasBase}/customers", [
                'name'        => $validated['name'],
                'email'       => $validated['email'],
                'cpfCnpj'     => $docDigits,
                'mobilePhone' => $phoneDigits,
            ]);
            if (!$custResp->successful()) {
                Log::error('Asaas customer error', ['status' => $custResp->status(), 'body' => $custResp->body()]);
                AppLoggerService::error('payment', 'checkout.customer_failed', 'Asaas customer creation failed', ['http_status' => $custResp->status()]);
                return response()->json(['error' => 'Erro ao criar cliente no gateway de pagamento.'], 422);
            }
            $customerId = $custResp->json('id');
        }

        $gatewayStatus = 'pending';
        $pixQrCode     = null;
        $pixQrCodeUrl  = null;
        $boletoUrl     = null;

        if ($validated['payment_method'] === 'credit_card') {
            $subResp = Http::withHeaders($headers)->post("{$asaasBase}/subscriptions", [
                'customer'          => $customerId,
                'billingType'       => 'CREDIT_CARD',
                'value'             => $amount,
                'cycle'             => 'MONTHLY',
                'nextDueDate'       => now()->format('Y-m-d'),
                'description'       => $description,
                'externalReference' => $externalRef,
                'creditCard' => [
                    'holderName'  => strtoupper($validated['card_holder_name']),
                    'number'      => preg_replace('/\D/', '', $validated['card_number']),
                    'expiryMonth' => str_pad((string) (int) $validated['card_exp_month'], 2, '0', STR_PAD_LEFT),
                    'expiryYear'  => strlen((string) $validated['card_exp_year']) === 2 ? '20' . $validated['card_exp_year'] : (string) $validated['card_exp_year'],
                    'ccv'         => $validated['card_cvv'],
                ],
                'creditCardHolderInfo' => [
                    'name'          => $validated['name'],
                    'email'         => $validated['email'],
                    'cpfCnpj'       => $docDigits,
                    // CEP placeholder valido (mesmo padrao anti-fraude do fluxo Pagar.me)
                    'postalCode'    => '01001000',
                    'addressNumber' => '123',
                    'mobilePhone'   => $phoneDigits,
                ],
                'remoteIp' => $request->ip(),
            ]);

            if (!$subResp->successful()) {
                Log::error('Asaas subscription error', ['status' => $subResp->status(), 'body' => $subResp->body()]);
                AppLoggerService::error('payment', 'checkout.card_failed', 'Asaas subscription creation failed', ['http_status' => $subResp->status()]);
                return response()->json(['error' => 'Cartão recusado. Confira os dados ou tente outro cartão.'], 422);
            }

            $gatewayId = $subResp->json('id');

            // Cobranca da 1a mensalidade e criada junto; status CONFIRMED = aprovada
            $firstPay    = Http::withHeaders($headers)->get("{$asaasBase}/payments", ['subscription' => $gatewayId]);
            $firstStatus = $firstPay->json('data.0.status');
            $gatewayStatus = in_array($firstStatus, ['CONFIRMED', 'RECEIVED'], true) ? 'paid' : 'pending';
        } else {
            $isPix   = $validated['payment_method'] === 'pix';
            $payResp = Http::withHeaders($headers)->post("{$asaasBase}/payments", [
                'customer'          => $customerId,
                'billingType'       => $isPix ? 'PIX' : 'BOLETO',
                'value'             => $amount,
                'dueDate'           => $isPix ? now()->format('Y-m-d') : now()->addDays(3)->format('Y-m-d'),
                'description'       => $description,
                'externalReference' => $externalRef,
            ]);

            if (!$payResp->successful()) {
                Log::error('Asaas payment error', ['status' => $payResp->status(), 'body' => $payResp->body()]);
                AppLoggerService::error('payment', 'checkout.payment_failed', 'Asaas payment creation failed', ['http_status' => $payResp->status()]);
                return response()->json(['error' => 'Erro ao processar pagamento.'], 422);
            }

            $gatewayId     = $payResp->json('id');
            $gatewayStatus = in_array($payResp->json('status'), ['CONFIRMED', 'RECEIVED'], true) ? 'paid' : 'pending';

            if ($isPix) {
                $qrResp = Http::withHeaders($headers)->get("{$asaasBase}/payments/{$gatewayId}/pixQrCode");
                if ($qrResp->successful()) {
                    $pixQrCode    = $qrResp->json('payload');
                    $pixQrCodeUrl = 'data:image/png;base64,' . $qrResp->json('encodedImage');
                }
            } else {
                $boletoUrl = $payResp->json('bankSlipUrl') ?: $payResp->json('invoiceUrl');
            }
        }

        return $this->finalizeCheckout(
            $validated, $plan, $customerId, $gatewayId, $gatewayStatus,
            $docDigits, $phoneDigits, $pixQrCode, $pixQrCodeUrl, $boletoUrl, $marca
        );
    }

    /**
     * Persistencia local + side effects pos-gateway (comum Pagar.me/Asaas).
     * $gatewayStatus: 'paid' | 'pending'.
     */
    private function finalizeCheckout(
        array $validated,
        Plan $plan,
        string $customerId,
        string $gatewayId,
        string $gatewayStatus,
        ?string $docDigits,
        ?string $phoneDigits,
        ?string $pixQrCode,
        ?string $pixQrCodeUrl,
        ?string $boletoUrl,
        // INF-030-MARCA (13/08): default defensivo SELLERGLOBAL — mantem o
        // comportamento de sempre (100% seller.global) caso algum caller novo
        // esqueca de passar a marca. Os 3 callers reais (fluxo principal,
        // reaproveitamento de PIX, fluxo Asaas) ja passam o valor resolvido.
        string $marca = BrandKit::SELLERGLOBAL
    ): JsonResponse {
        if (! BrandKit::isValid($marca)) {
            $marca = BrandKit::SELLERGLOBAL;
        }

        // Criar ou atualizar cliente/subscription no painel
        $isNewUser = false;
        $client = Client::whereHas('user', fn ($q) => $q->where('email', $validated['email']))->first();
        if (!$client) {
            $isNewUser = true;
            $user = \App\Models\User::create([
                'name'      => $validated['name'],
                'email'     => $validated['email'],
                'password'  => bcrypt('123456'),
                'role'      => 'client',
                'is_active' => $gatewayStatus === 'paid',
            ]);
            // MUL-269 fase 2: company_name removido de clients — nome vem do user (accessor).
            $client = Client::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'document'     => $docDigits,
                    'phone'        => $phoneDigits,
                    'is_active'    => true,
                    // INF-030-MARCA: marca do cliente fixada na CRIACAO (primeira
                    // compra define a marca "de casa" do cliente). Nao mexe em
                    // cliente ja existente pra nao reescrever historico.
                    'marca'        => $marca,
                ]
            );
        } elseif ($phoneDigits && strlen($phoneDigits) >= 10 && empty($client->phone)) {
            $client->update(['phone' => $phoneDigits]);
        }

        // INF-030-MARCA (13/08): preenche a marca SO quando ela esta em branco.
        //
        // Buraco medido hoje: quem se CADASTRA antes de comprar ganha o client
        // pelo EnsureClientExists, que ate hoje nao gravava marca — 14 clients
        // nasceram assim so no dia 13/08. Quando essa pessoa comprava, o
        // firstOrCreate acima achava o client existente e, de proposito, nao
        // reescrevia a marca. Resultado: client->marca ficava NULA pra sempre.
        // E o SellerWelcomeMail le exatamente client->marca; nula, ele cai no
        // palpite por plano, e o palpite manda quem comprou plano de Video IA
        // pra tokfy.io — cliente do seller.global recebendo e-mail de acesso de
        // outra marca.
        //
        // So preenche o VAZIO. Cliente que ja tem marca continua intocado — a
        // regra de "nao reescrever historico" segue valendo.
        if (empty($client->marca)) {
            $client->update(['marca' => $marca]);
        }

        $subscription = Subscription::updateOrCreate(
            ['client_id' => $client->id],
            [
                'plan_id'                  => $plan->id,
                'pagarme_customer_id'      => $customerId,
                'pagarme_subscription_id'  => $gatewayId,
                'pagarme_status'           => $gatewayStatus,
                // INF-064: id do gateway tambem em external_payment_id — e por
                // ele que ProcessAsaasWebhookJob acha a subscription pra ativar
                'external_payment_id'      => $gatewayId,
                'status'                   => $gatewayStatus === 'paid' ? 'active' : 'trialing',
                'trial_ends_at'            => $gatewayStatus === 'paid' ? null : now()->addDays(7),
                'payment_method'           => $validated['payment_method'],
                'current_period_start'     => now(),
                'current_period_end'       => now()->addMonth(),
                // INF-030-MARCA: marca DESTA compra especifica — atualizada a
                // cada checkout (reflete de onde a venda atual veio).
                'marca'                    => $marca,
            ]
        );

        // SEL-110/SEL-113 fix: hoisted pra fora do if pra ficar disponivel na
        // response + no Mail mesmo quando subscription nao foi recem-criada.
        $whatsappGroupUrl = null;
        if ($gatewayStatus === 'paid' && ($subscription->wasRecentlyCreated || $subscription->wasChanged('status'))) {
            \App\Services\SalePushNotifier::notifySale($subscription->id);
            // SEL-113-auto-invite Ruan 14:23: se config auto ativo E ainda tem
            // vaga (used<limit), marca cliente + incrementa contador (transacao
            // com lock evita passar do limit em pagamentos concorrentes).
            try {
                \DB::transaction(function () use ($client, &$whatsappGroupUrl) {
                    $cfg = \DB::table('whatsapp_group_configs')->where('id', 1)->lockForUpdate()->first();
                    if ($cfg && $cfg->auto_invite_enabled && $cfg->group_url && (int)$cfg->auto_invite_used < (int)$cfg->auto_invite_limit) {
                        \DB::table('clients')->where('id', $client->id)->update(['whatsapp_invited_at' => now()]);
                        \DB::table('whatsapp_group_configs')->where('id', 1)->increment('auto_invite_used');
                        $whatsappGroupUrl = $cfg->group_url;
                    }
                });
            } catch (\Throwable $e) {
                \Log::warning('SEL-113 auto-invite falhou', ['client_id' => $client->id, 'err' => $e->getMessage()]);
            }
        }
        // SEL-CICLO (12/08, ordem do dono): e-mail de boas-vindas fica PRONTO mas
        // DESARMADO ate a empresa estar 100%. Mesma chave usada pelo webhook, pra
        // nao existir um caminho de venda que manda e-mail e outro que nao manda:
        // PAGARME_WELCOME_MAIL_ENABLED=true no .env (ver config/payment.php).
        if ($gatewayStatus === 'paid' && config('payment.welcome_mail_enabled')) {
            try {
                $userForMail = $client->user ?? \App\Models\User::find($client->user_id);
                if ($userForMail) {
                    // SEL-110 fix: passar whatsapp_group_url pro Mail pra CTA aparecer no email
                    Mail::to($userForMail->email)->queue(new \App\Mail\SellerWelcomeMail(
                        $userForMail, $client, $plan, $isNewUser ? '123456' : null, $whatsappGroupUrl ?? null
                    ));
                }
            } catch (\Throwable $e) {
                Log::warning('SEL-111 SellerWelcomeMail dispatch failed', ['error' => $e->getMessage(), 'client_id' => $client->id]);
            }
        }


        $response = [
            'order_id'  => $gatewayId,
            'status'    => $gatewayStatus,
            'client_id' => $client->id,
            'email'            => $validated['email'],
            'initial_password' => $gatewayStatus === 'paid' ? '123456' : null,
            'whatsapp_group_url' => $whatsappGroupUrl ?? null, // SEL-113-response-url
        ];

        if ($pixQrCode !== null) {
            $response['pix_qr_code']     = $pixQrCode;
            $response['pix_qr_code_url'] = $pixQrCodeUrl;
        }
        if ($boletoUrl !== null) {
            $response['boleto_url'] = $boletoUrl;
        }

        return response()->json($response, $gatewayStatus === 'paid' ? 201 : 200);
    }
}
