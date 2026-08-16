<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * GabrielController - endpoints internos para o agente de vendas Gabriel.
 *
 * Autenticado via middleware gabriel.auth (X-Gabriel-Token).
 * NUNCA retorna: password, cpf, tokens de acesso, dados bancarios.
 */
class GabrielController extends Controller
{
    // =========================================================================
    // TAREFA 1: GET /api/v1/gabriel/client-status?email={email}
    // =========================================================================

    public function clientStatus(Request $request): JsonResponse
    {
        $ip    = $request->ip();
        $email = trim((string) $request->query('email', ''));

        Log::channel('gabriel')->info('gabriel.client_status', [
            'ip'       => $ip,
            'endpoint' => 'GET /api/v1/gabriel/client-status',
            'email'    => $email,
        ]);

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['error' => 'Parametro email invalido.'], 422);
        }

        $user = DB::table('users')->where('email', $email)->first(['id']);

        if (! $user) {
            return response()->json(['found' => false]);
        }

        $client = DB::table('clients')
            ->where('user_id', $user->id)
            ->first(['id', 'is_active', 'ia_trial_until']);

        if (! $client) {
            return response()->json(['found' => false]);
        }

        // Plano ativo
        $planNome  = null;
        $planLimit = null;

        $subscription = DB::table('subscriptions')
            ->where('client_id', $client->id)
            ->whereIn('status', ['active', 'trialing'])
            ->orderByDesc('created_at')
            ->first(['plan_id']);

        if ($subscription) {
            $plan = DB::table('plans')
                ->where('id', $subscription->plan_id)
                ->first(['name', 'max_skus']);
            if ($plan) {
                $planNome  = $plan->name;
                $planLimit = (int) $plan->max_skus;
            }
        }

        // Marketplaces
        $mpAccounts = DB::table('marketplace_accounts')
            ->where('client_id', $client->id)
            ->whereIn('status', ['active', 'connected'])
            ->get(['platform']);

        $conectouMarketplace   = $mpAccounts->isNotEmpty();
        $plataformasConectadas = $mpAccounts->pluck('platform')->unique()->values()->toArray();

        // Planilha enviada (customer_downloaded_products e tabela do legado;
        // pode nao existir no NovoHubAI — tratar com fallback)
        $subiuPlanilha = false;
        try {
            if (Schema::hasTable('customer_downloaded_products')) {
                $subiuPlanilha = DB::table('customer_downloaded_products')
                    ->where('user_email', $email)
                    ->exists();
            }
        } catch (\Throwable $e) {
            Log::channel('gabriel')->warning('gabriel.cdp_table_missing', [
                'error' => $e->getMessage(),
            ]);
        }

        // Produtos vinculados ao catalogo
        $produtosVinculados = (int) DB::table('client_products')
            ->where('client_id', $client->id)
            ->whereNotNull('product_id')
            ->count();

        // Ultima atividade (mais recente entre client_products e marketplace_accounts)
        $lastClientProduct = DB::table('client_products')
            ->where('client_id', $client->id)
            ->max('created_at');

        $lastMpAccount = DB::table('marketplace_accounts')
            ->where('client_id', $client->id)
            ->max('created_at');

        $dates = array_filter([$lastClientProduct, $lastMpAccount]);
        $ultimaAtividade = null;
        if (! empty($dates)) {
            $ultimaAtividade = substr(max($dates), 0, 10);
        }

        // Trial IA
        $temTrialIa = ! empty($client->ia_trial_until)
            && strtotime($client->ia_trial_until) > time();

        return response()->json([
            'found'                  => true,
            'plano_nome'             => $planNome,
            'plan_limit'             => $planLimit,
            'conectou_marketplace'   => $conectouMarketplace,
            'plataformas_conectadas' => $plataformasConectadas,
            'subiu_planilha'         => $subiuPlanilha,
            'produtos_vinculados'    => $produtosVinculados,
            'ultima_atividade'       => $ultimaAtividade,
            'tem_trial_ia'           => (bool) $temTrialIa,
        ]);
    }

    // =========================================================================
    // TAREFA 2: POST /api/v1/gabriel/grant-ia-trial
    // =========================================================================

    public function grantIaTrial(Request $request): JsonResponse
    {
        $ip = $request->ip();

        Log::channel('gabriel')->info('gabriel.grant_ia_trial', [
            'ip'       => $ip,
            'endpoint' => 'POST /api/v1/gabriel/grant-ia-trial',
            'email'    => $request->input('email'),
        ]);

        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'hours' => ['required', 'integer', 'min:1', 'max:72'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $email = $request->input('email');
        $hours = (int) $request->input('hours');

        $user = DB::table('users')->where('email', $email)->first(['id']);
        if (! $user) {
            return response()->json(['error' => 'Cliente nao encontrado.'], 404);
        }

        $client = DB::table('clients')
            ->where('user_id', $user->id)
            ->first(['id', 'ia_trial_until']);
        if (! $client) {
            return response()->json(['error' => 'Cliente nao encontrado.'], 404);
        }

        // Verifica trial ja ativo
        if (! empty($client->ia_trial_until) && strtotime($client->ia_trial_until) > time()) {
            $dataFormatada = date('d/m/Y H:i', strtotime($client->ia_trial_until));
            return response()->json([
                'success' => false,
                'message' => "Cliente ja tem trial ativo ate {$dataFormatada}",
            ]);
        }

        $trialUntil = now()->addHours($hours);

        DB::table('clients')
            ->where('id', $client->id)
            ->update([
                'ia_trial_until' => $trialUntil,
                'updated_at'     => now(),
            ]);

        Log::channel('gabriel')->info('gabriel.ia_trial_granted', [
            'ip'          => $ip,
            'client_id'   => $client->id,
            'email'       => $email,
            'hours'       => $hours,
            'trial_until' => $trialUntil->toISOString(),
        ]);

        return response()->json([
            'success'     => true,
            'trial_until' => $trialUntil->toISOString(),
        ]);
    }

    // =========================================================================
    // TAREFA 3: POST /api/v1/gabriel/demo-products
    // =========================================================================

    public function demoProducts(Request $request): JsonResponse
    {
        $ip = $request->ip();

        Log::channel('gabriel')->info('gabriel.demo_products', [
            'ip'       => $ip,
            'endpoint' => 'POST /api/v1/gabriel/demo-products',
            'email'    => $request->input('email'),
        ]);

        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 422);
        }

        $email = $request->input('email');

        $user = DB::table('users')->where('email', $email)->first(['id']);
        if (! $user) {
            return response()->json(['error' => 'Cliente nao encontrado.'], 404);
        }

        $client = DB::table('clients')->where('user_id', $user->id)->first(['id']);
        if (! $client) {
            return response()->json(['error' => 'Cliente nao encontrado.'], 404);
        }

        // Maximo 1 rodada de demo por cliente
        $jaTemDemo = DB::table('client_products')
            ->where('client_id', $client->id)
            ->where('listing_status', 'demo')
            ->exists();

        if ($jaTemDemo) {
            return response()->json([
                'success' => false,
                'message' => 'Cliente ja recebeu produtos de demonstracao.',
            ]);
        }

        // 3 produtos mais populares (is_active=1, ORDER BY id LIMIT 3)
        $products = DB::table('products')
            ->where('is_active', 1)
            ->orderBy('id')
            ->limit(3)
            ->get(['id', 'sku', 'name', 'price']);

        if ($products->isEmpty()) {
            return response()->json(['error' => 'Nenhum produto disponivel no catalogo.'], 500);
        }

        $criados = [];
        $now     = now();

        foreach ($products as $product) {
            $clientProductId = DB::table('client_products')->insertGetId([
                'client_id'              => $client->id,
                'product_id'             => $product->id,
                'external_listing_id'    => 'DEMO-' . $product->sku,
                'custom_title'           => $product->name,
                'custom_sku'             => $product->sku,
                'custom_price'           => $product->price,
                'listing_status'         => 'demo',
                'sync_status'            => 'demo',
                'marketplace_account_id' => null,
                'is_active'              => false,
                'created_at'             => $now,
                'updated_at'             => $now,
            ]);

            $criados[] = [
                'id'   => $clientProductId,
                'sku'  => $product->sku,
                'nome' => $product->name,
            ];
        }

        Log::channel('gabriel')->info('gabriel.demo_products_created', [
            'ip'               => $ip,
            'client_id'        => $client->id,
            'email'            => $email,
            'produtos_criados' => count($criados),
        ]);

        return response()->json([
            'success'          => true,
            'produtos_criados' => count($criados),
            'produtos'         => $criados,
        ]);
    }


    // =========================================================================
    // NOV-032 v2: GET /api/v1/client/status?email={email}
    // Auth: Authorization: Bearer {GABRIEL_API_KEY}
    // Retorna status do cliente para o agente de vendas Gabriel.
    // NUNCA retorna: senha, hash, token de marketplace, cpf, dados de pagamento.
    // =========================================================================

    public function clientStatusV2(Request $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->query("email", "")));
        $phone = trim((string) $request->query("phone", ""));

        Log::channel("gabriel")->info("gabriel.client_status_v2", [
            "ip"    => $request->ip(),
            "email" => $email,
            "phone" => $phone ?: null,
        ]);

        // Validacao: email obrigatorio
        if (empty($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(["error" => "Parametro email invalido ou ausente."], 422);
        }

        // Busca usuario por email
        $user = DB::table("users")->where("email", $email)->first(["id", "created_at"]);

        if (! $user) {
            return response()->json(["found" => false]);
        }

        // Busca client vinculado
        $client = DB::table("clients")
            ->where("user_id", $user->id)
            ->first(["id", "phone"]);

        if (! $client) {
            return response()->json(["found" => false]);
        }

        // Busca subscription mais recente (qualquer status)
        $subscription = DB::table("subscriptions")
            ->where("client_id", $client->id)
            ->orderByDesc("created_at")
            ->first([
                "plan_id",
                "status",
                "trial_ends_at",
                "cancelled_at",
                "current_period_end",
                "created_at",
            ]);

        $planName   = null;
        $planSlug   = null;
        $planLimit  = null;
        $subStatus  = "trial"; // sem subscription = trial/sem plano

        if ($subscription) {
            // Resolve nome/slug do plano
            $plan = DB::table("plans")
                ->where("id", $subscription->plan_id)
                ->first(["name", "slug", "max_skus"]);

            if ($plan) {
                $planName  = trim($plan->name);
                $planSlug  = $plan->slug;
                $planLimit = (int) $plan->max_skus;
            }

            // Calcula status legivel
            $now = now();

            if (! empty($subscription->cancelled_at)) {
                $subStatus = "cancelled";
            } elseif ($subscription->status === "trialing" ||
                      (! empty($subscription->trial_ends_at) && $now->lt($subscription->trial_ends_at))
            ) {
                $subStatus = "trial";
            } elseif ($subscription->status === "active") {
                // Verifica se periodo atual ainda nao expirou
                if (! empty($subscription->current_period_end) &&
                    $now->gt($subscription->current_period_end)
                ) {
                    $subStatus = "overdue";
                } else {
                    $subStatus = "active";
                }
            } elseif (in_array($subscription->status, ["past_due", "unpaid", "paused"], true)) {
                $subStatus = "overdue";
            } elseif ($subscription->status === "canceled") {
                $subStatus = "cancelled";
            } else {
                $subStatus = $subscription->status;
            }
        }

        // Conta produtos vinculados ao catalogo (todos, nao apenas ativos)
        $productsUsed = (int) DB::table("client_products")
            ->where("client_id", $client->id)
            ->whereNotNull("product_id")
            ->count();

        // Data de entrada (created_at do user, formato Y-m-d)
        $memberSince = $user->created_at
            ? substr($user->created_at, 0, 10)
            : null;

        return response()->json([
            "found"          => true,
            "plan_name"      => $planName,
            "plan_slug"      => $planSlug,
            "status"         => $subStatus,
            "products_used"  => $productsUsed,
            "products_limit" => $planLimit,
            "member_since"   => $memberSince,
        ]);
    }
}
