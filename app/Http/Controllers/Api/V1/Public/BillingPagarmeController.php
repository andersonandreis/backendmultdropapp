<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NOV-217 — Proxy Laravel do billing-pagarme (Supabase edge function).
 *
 * POST /api/v1/public/billing-pagarme
 *
 * Rota publica (sem auth). Repassa o body para a edge function original do Supabase.
 * Fallback imediato enquanto a edge function Supabase segue com bug 404.
 *
 * Arquitetura: este controller e um thin proxy. Toda a logica de pagamento
 * (PIX/cartao Pagar.me, assinaturas, coupon, reconciliacao de telefone) permanece
 * na edge function billing-pagarme que usa a SUPABASE_SERVICE_ROLE_KEY do proprio
 * Supabase runtime. Quando a edge function voltar a funcionar, o front pode chamar
 * diretamente sem passar por este proxy.
 *
 * CORS: aceita apenas origens hubai.io e app2.hubai.io (mesma restricao da edge function).
 */
class BillingPagarmeController extends Controller
{
    private const ALLOWED_ORIGINS = ['https://hubai.io', 'https://app2.hubai.io'];

    // URL da edge function original (fallback para quando bug for resolvido)
    private const EDGE_FUNCTION_URL = 'https://omvstizxjosygkcolzzl.supabase.co/functions/v1/billing-pagarme';

    private string $supabaseUrl;
    private string $supabaseServiceKey;
    private string $supabaseAnonKey;
    private string $pagarmeApiKey;

    public function __construct()
    {
        $this->supabaseUrl        = config('services.supabase_hubai.url', 'https://omvstizxjosygkcolzzl.supabase.co');
        $this->supabaseServiceKey = config('services.supabase_hubai.service_role', '');
        $this->supabaseAnonKey    = config('services.supabase_hubai.anon_key', '');
        $this->pagarmeApiKey      = config('services.pagarme.api_key', '');
    }

    private function key(): string
    {
        return $this->supabaseServiceKey ?: $this->supabaseAnonKey;
    }

    private function sbHeaders(): array
    {
        $k = $this->key();
        return [
            'apikey'        => $k,
            'Authorization' => 'Bearer ' . $k,
            'Content-Type'  => 'application/json',
        ];
    }

    private function corsHeaders(Request $request): array
    {
        $origin = $request->header('Origin', '');
        $allowed = in_array($origin, self::ALLOWED_ORIGINS) ? $origin : 'https://hubai.io';
        return [
            'Access-Control-Allow-Origin'  => $allowed,
            'Access-Control-Allow-Headers' => 'authorization, x-client-info, apikey, content-type',
        ];
    }

    public function handle(Request $request)
    {
        $cors = $this->corsHeaders($request);

        if ($request->isMethod('OPTIONS')) {
            return response('', 200)->withHeaders($cors);
        }

        // Estrategia: tenta primeiro a edge function Supabase original.
        // Se retornar 404 (bug NOT_FOUND_FUNCTION_BLOB), processa localmente via Pagar.me.
        $body = $request->json()->all();

        try {
            $edgeResp = Http::withHeaders(array_merge($this->sbHeaders(), [
                'Origin' => $request->header('Origin', 'https://hubai.io'),
            ]))->timeout(15)->post(self::EDGE_FUNCTION_URL, $body);

            if ($edgeResp->successful()) {
                Log::info('[BillingPagarme] edge function OK, proxying response');
                return response()->json($edgeResp->json())->withHeaders($cors);
            }

            $status = $edgeResp->status();
            // 404 = bug NOT_FOUND_FUNCTION_BLOB — cai para processamento local
            if ($status === 404) {
                Log::warning('[BillingPagarme] edge function 404 (NOT_FOUND_FUNCTION_BLOB) — usando fallback Laravel');
                return $this->processLocally($request, $body, $cors);
            }

            // Outros erros: repassa o erro da edge function
            Log::error('[BillingPagarme] edge function erro HTTP ' . $status . ': ' . $edgeResp->body());
            return response()->json($edgeResp->json() ?? ['success' => false, 'error' => 'Erro no gateway'], $status)->withHeaders($cors);
        } catch (\Throwable $e) {
            Log::error('[BillingPagarme] edge function timeout/excecao: ' . $e->getMessage() . ' — usando fallback Laravel');
            return $this->processLocally($request, $body, $cors);
        }
    }

    /**
     * Fallback: processa o checkout localmente via API Pagar.me direta.
     * Implementa apenas os fluxos criticos: PIX e cartao (one-time e subscription).
     * Para action=get-plans, consulta Supabase diretamente.
     */
    private function processLocally(Request $request, array $p, array $cors)
    {
        try {
            // action=get-plans
            if (($p['action'] ?? '') === 'get-plans') {
                return $this->getPlans($cors);
            }

            // action=attach-card-subscription e outros nao-checkout: retorna erro claro
            if (isset($p['action']) && $p['action'] !== '') {
                return response()->json(['success' => false, 'error' => 'Acao nao disponivel no fallback. Tente novamente mais tarde.'], 503)->withHeaders($cors);
            }

            // Validacao minima de inputs
            $c = $p['customer'] ?? [];
            if (empty($c['name']) || strlen(trim($c['name'])) < 2) {
                return response()->json(['success' => false, 'error' => 'Nome invalido'], 422)->withHeaders($cors);
            }
            if (empty($c['email']) || !filter_var($c['email'], FILTER_VALIDATE_EMAIL)) {
                return response()->json(['success' => false, 'error' => 'Email invalido'], 422)->withHeaders($cors);
            }

            // Busca plano no Supabase
            $planId  = $p['planId'] ?? '';
            // Fallback plans para quando Supabase RLS bloqueia anon key
            $fallbackPlans = [
                'hubai-start'   => ['name' => 'HubAI Start',   'billing_type' => 'subscription', 'price_monthly' => 97,    'price_yearly' => 970,  'price_once' => 97],
                'hubai-scaling' => ['name' => 'HubAI Scaling', 'billing_type' => 'subscription', 'price_monthly' => 197,   'price_yearly' => 1970, 'price_once' => 197],
                'hubai-scale'   => ['name' => 'HubAI Scale',   'billing_type' => 'subscription', 'price_monthly' => 197,   'price_yearly' => 1970, 'price_once' => 197],
                'hubai-pro'     => ['name' => 'HubAI Pro',     'billing_type' => 'subscription', 'price_monthly' => 297,   'price_yearly' => 2970, 'price_once' => 297],
                'plano-inicial' => ['name' => 'Plano Inicial', 'billing_type' => 'one_time',     'price_monthly' => 29.9,  'price_yearly' => 29.9, 'price_once' => 29.9],
                'mentoria'      => ['name' => 'Mentoria',      'billing_type' => 'one_time',     'price_monthly' => 29.9,  'price_yearly' => 29.9, 'price_once' => 29.9],
                'avancado'      => ['name' => 'Avancado',      'billing_type' => 'subscription', 'price_monthly' => 449,   'price_yearly' => 2997, 'price_once' => 449],
            ];
            $planResp = Http::withHeaders($this->sbHeaders())
                ->get(rtrim($this->supabaseUrl, '/') . '/rest/v1/plans', [
                    'slug'      => 'eq.' . $planId,
                    'is_active' => 'eq.true',
                    'limit'     => '1',
                ]);
            $planRaw = $planResp->json();
            $plan    = (is_array($planRaw) && !isset($planRaw['code']) && !empty($planRaw)) ? ($planRaw[0] ?? null) : null;
            if (!$plan) {
                // Supabase bloqueado (RLS/anon) — tenta fallback local
                $plan = $fallbackPlans[$planId] ?? null;
                if (!$plan) {
                    return response()->json(['success' => false, 'error' => 'Plano nao encontrado'], 404)->withHeaders($cors);
                }
                Log::info('[BillingPagarme] usando fallback hardcoded para plano: ' . $planId);
            }

            // Resolve credencial Pagar.me
            $pagarmeKey = $this->resolvePagarmeKey();
            if (!$pagarmeKey) {
                return response()->json(['success' => false, 'error' => 'Gateway de pagamento nao configurado'], 500)->withHeaders($cors);
            }

            $billingCycle = $p['billingCycle'] ?? 'monthly';
            $isOnce       = ($plan['billing_type'] ?? '') === 'one_time';
            $price        = $isOnce
                ? (float)($plan['price_once'] ?? $plan['price_monthly'] ?? 0)
                : ($billingCycle === 'monthly' ? (float)($plan['price_monthly'] ?? 0) : (float)($plan['price_yearly'] ?? 0));

            if (isset($p['priceOverride'])) $price = (int)$p['priceOverride'] / 100;

            // Markup cartao +30%
            $noMarkupPlans = ['plano-inicial', 'mentoria'];
            if (($p['paymentMethod'] ?? '') === 'credit_card' && !in_array($planId, $noMarkupPlans)) {
                $price = round($price * 1.30 * 100) / 100;
            }

            $amountInCents = (int)round($price * 100);
            $phoneDigits   = preg_replace('/\D/', '', $c['phone'] ?? '');
            $cpfClean      = preg_replace('/\D/', '', $c['cpfCnpj'] ?? '');
            $authHeader    = 'Basic ' . base64_encode($pagarmeKey . ':');

            $pagarmeCustomer = [
                'name'     => trim($c['name']),
                'email'    => trim($c['email']),
                'type'     => strlen($cpfClean) > 11 ? 'company' : 'individual',
                'document' => $cpfClean ?: '00000000000',
                'phones'   => ['mobile_phone' => [
                    'country_code' => '55',
                    'area_code'    => substr($phoneDigits, 0, 2),
                    'number'       => substr($phoneDigits, 2),
                ]],
            ];

            if (($p['paymentMethod'] ?? 'pix') === 'credit_card') {
                return $this->processCreditCard($p, $pagarmeCustomer, $plan, $price, $amountInCents, $billingCycle, $planId, $authHeader, $cors);
            }
            return $this->processPix($p, $pagarmeCustomer, $plan, $price, $amountInCents, $planId, $authHeader, $cors);
        } catch (\Throwable $e) {
            Log::error('[BillingPagarme] fallback erro: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500)->withHeaders($cors);
        }
    }

    /**
     * SEL-CHECKOUT-SEM-SUPABASE (16/08, ordem do Ruan: "tira o checkout do supabase tambem").
     *
     * Antes esta funcao buscava a chave do Pagar.me no SUPABASE (tabela
     * payment_gateway_config) e so caia pro nosso .env se nao achasse — ou seja, o
     * checkout dependia do Lovable pra saber em qual conta cobrar, e uma linha mexida la
     * mudava a cobranca de TODOS os backends de uma vez (foi o que aconteceu hoje).
     *
     * Agora a chave e a DO PROPRIO BACKEND, do .env. Sem chamada externa no meio do
     * pagamento: menos um ponto de falha e menos um dono de fora.
     *
     * Conferido antes de cortar: os 5 backends que usam este controller (hubai.io,
     * jtdrop, mestoredrop, multdrop, fornecefy) TEM chave no .env. Ninguem fica sem.
     */
    private function resolvePagarmeKey(): ?string
    {
        $chave = trim((string) $this->pagarmeApiKey);

        if ($chave === '') {
            // Antes isto devolvia null calado, e o erro so aparecia la na frente como uma
            // chamada sem autenticacao. Melhor gritar aqui, com o nome do problema.
            Log::error('[SEL-CHECKOUT-SEM-SUPABASE] PAGARME_API_KEY vazia no .env deste backend — checkout NAO vai cobrar', [
                'host' => request()->getHost(),
            ]);

            return null;
        }

        return $chave;
    }

    private function processPix(array $p, array $customer, array $plan, float $price, int $amountCents, string $planId, string $auth, array $cors)
    {
        $pagarmeApi = 'https://api.pagar.me/core/v5';
        $payload    = [
            'items'    => [['amount' => $amountCents, 'description' => $plan['name'], 'quantity' => 1, 'code' => $planId]],
            'customer' => $customer,
            'payments' => [['payment_method' => 'pix', 'pix' => ['expires_in' => 3600]]],
            'metadata' => ['plan_slug' => $planId, 'source' => 'laravel-fallback'],
        ];
        $resp = Http::withHeaders(['Content-Type' => 'application/json', 'Authorization' => $auth])
            ->timeout(20)->post($pagarmeApi . '/orders', $payload);
        $data = $resp->json();
        if (!$resp->successful()) {
            return response()->json(['success' => false, 'error' => $data['message'] ?? 'Erro PIX'], $resp->status())->withHeaders($cors);
        }
        $charge  = $data['charges'][0] ?? [];
        $lastTx  = $charge['last_transaction'] ?? [];
        return response()->json([
            'success'        => true,
            'paymentId'      => $charge['id'] ?? $data['id'] ?? '',
            'pixQrCode'      => $lastTx['qr_code_url'] ?? '',
            'pixCopyPaste'   => $lastTx['qr_code'] ?? '',
            'pixQrCodeImage' => $lastTx['qr_code_url'] ?? '',
            'amount'         => $price,
            'gateway'        => 'pagarme',
            '_source'        => 'laravel-fallback',
        ])->withHeaders($cors);
    }

    private function processCreditCard(array $p, array $customer, array $plan, float $price, int $amountCents, string $billingCycle, string $planId, string $auth, array $cors)
    {
        $pagarmeApi  = 'https://api.pagar.me/core/v5';
        $cardData    = $p['cardData'] ?? null;
        if (!$cardData) {
            return response()->json(['success' => false, 'error' => 'Dados do cartao nao informados'], 422)->withHeaders($cors);
        }
        $billingAddress = [
            'line_1'   => '123, Rua Principal, Centro',
            'zip_code' => preg_replace('/\D/', '', $cardData['address']['zipCode'] ?? '01001000'),
            'city'     => $cardData['address']['city'] ?? 'Sao Paulo',
            'state'    => $cardData['address']['state'] ?? 'SP',
            'country'  => 'BR',
        ];
        $payload = [
            'items'    => [['amount' => $amountCents, 'description' => $plan['name'], 'quantity' => 1, 'code' => $planId]],
            'customer' => $customer,
            'payments' => [['payment_method' => 'credit_card', 'credit_card' => [
                'installments'         => (int)($cardData['installments'] ?? 1),
                'statement_descriptor' => 'HUBAI',
                'card'                 => [
                    'number'          => $cardData['number'],
                    'holder_name'     => $cardData['holderName'],
                    'exp_month'       => (int)$cardData['expMonth'],
                    'exp_year'        => (int)$cardData['expYear'],
                    'cvv'             => $cardData['cvv'],
                    'billing_address' => $billingAddress,
                ],
            ]]],
            'metadata' => ['plan_slug' => $planId, 'source' => 'laravel-fallback'],
        ];
        $resp = Http::withHeaders(['Content-Type' => 'application/json', 'Authorization' => $auth])
            ->timeout(20)->post($pagarmeApi . '/orders', $payload);
        $data = $resp->json();
        if (!$resp->successful()) {
            return response()->json(['success' => false, 'error' => $data['message'] ?? 'Erro cartao'], $resp->status())->withHeaders($cors);
        }
        $charge      = $data['charges'][0] ?? [];
        $chargeStatus = $charge['status'] ?? '';
        if (in_array($chargeStatus, ['failed', 'canceled', 'declined'])) {
            $failMsg = $charge['last_transaction']['gateway_response']['message'] ?? 'Cartao recusado';
            return response()->json(['success' => false, 'error' => $failMsg])->withHeaders($cors);
        }
        return response()->json([
            'success'       => true,
            'paymentId'     => $charge['id'] ?? $data['id'] ?? '',
            'gateway'       => 'pagarme',
            'amount'        => $price,
            'status'        => $chargeStatus,
            'paymentStatus' => $chargeStatus === 'paid' ? 'CONFIRMED' : 'PENDING',
            '_source'       => 'laravel-fallback',
        ])->withHeaders($cors);
    }

    private function getPlans(array $cors)
    {
        $resp = Http::withHeaders($this->sbHeaders())
            ->get(rtrim($this->supabaseUrl, '/') . '/rest/v1/plans', [
                'is_active' => 'eq.true',
                'order'     => 'display_order.asc',
            ]);
        $rawData = $resp->json();
        // Supabase pode retornar erro RLS em vez de array de planos
        if (!is_array($rawData) || isset($rawData['code']) || isset($rawData['message']) || empty($rawData)) {
            \Illuminate\Support\Facades\Log::warning('[BillingPagarme] Supabase plans inacessivel: ' . json_encode($rawData));
            return response()->json(['plans' => []])->withHeaders($cors);
        }
        $plans = array_map(function ($x) {
            $bt = $x['billing_type'] ?? 'subscription';
            $pm = $bt === 'one_time' ? (float)($x['price_once'] ?? 0) : (float)($x['price_monthly'] ?? 0);
            $pa = $bt === 'one_time' ? (float)($x['price_once'] ?? 0) : (float)($x['price_yearly'] ?? 0);
            return [
                'id'          => $x['slug'],
                'slug'        => $x['slug'],
                'name'        => $x['name'],
                'description' => $x['description'] ?? '',
                'popular'     => $x['popular'] ?? false,
                'billingType' => $bt,
                'pricing'     => [
                    'monthly' => ['value' => (int)round($pm * 100), 'display' => 'R$ ' . number_format($pm, 2, ',', '.'), 'period' => $bt === 'one_time' ? 'unico' : 'mes'],
                    'annual'  => ['value' => (int)round($pa * 100), 'display' => 'R$ ' . number_format($pa, 2, ',', '.'), 'period' => $bt === 'one_time' ? 'unico' : 'ano'],
                ],
                'limits'   => ['products' => 0],
                'features' => $x['features'] ?? [],
            ];
        }, $rawData);
        return response()->json(['plans' => $plans])->withHeaders($cors);
    }
}
