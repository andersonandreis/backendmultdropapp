<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Ai\KlingService;
use App\Services\AiWalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * SEL-245 — Wallet créditos IA (cliente + admin).
 */
class AiWalletController extends Controller
{
    /**
     * SEL-329: retorna dados no modelo "dois mundos" — cliente ve creditos, admin ve R$.
     * `balance` no client_ai_wallets agora guarda CREDITOS (nao R$). Migracao inerte:
     * hoje o campo esta zerado em todos os users, ninguem foi cobrado ainda.
     */
    public function summary(Request $request): JsonResponse
    {
        $client = $request->user()?->client;
        if (!$client) return response()->json(['error' => 'no client'], 403);

        $s = DB::table('settings')->where('group', 'video_billing')->pluck('value', 'key');
        $packages = json_decode((string) ($s['recharge_packages'] ?? '[]'), true) ?: [];
        $creditsPerVideo = (int) ($s['credits_per_video'] ?? 1500);
        $videosFreeMonth = (int) ($s['videos_free_per_month'] ?? 3);

        // Contador de videos gratis usados no mes (para saber se ja acabaram os incluidos)
        $videosGeneratedThisMonth = (int) DB::table('ai_generations')
            ->where('user_id', $request->user()->id)
            ->where('service', 'video')
            ->whereIn('status', ['succeeded', 'processing'])
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        $sum = AiWalletService::summary($client->id);

        return response()->json([
            'balance_credits'      => (int) round((float) $sum['balance']),
            'credits_per_video'    => $creditsPerVideo,
            'videos_free_per_month'=> $videosFreeMonth,
            'videos_used_this_month' => $videosGeneratedThisMonth,
            'videos_free_left'     => max(0, $videosFreeMonth - $videosGeneratedThisMonth),
            'recharge_packages'    => $packages,
            'min_deposit_brl'      => AiWalletService::MIN_DEPOSIT,
            'history'              => AiWalletService::history($client->id, 20),
        ]);
    }

    /**
     * POST /api/v1/ai-wallet/deposit {amount_brl}
     * Valida contra os pacotes configurados. Cria PIX via Pagar.me. Webhook credita.
     */
    public function deposit(Request $request): JsonResponse
    {
        $data = $request->validate(['amount_brl' => 'required|numeric|min:1']);
        $client = $request->user()?->client;
        if (!$client) return response()->json(['error' => 'no client'], 403);

        // SEL-329: valida contra os pacotes configurados no admin
        $s = DB::table('settings')->where('group', 'video_billing')->pluck('value', 'key');
        $packages = json_decode((string) ($s['recharge_packages'] ?? '[]'), true) ?: [];
        $amountBrl = (float) $data['amount_brl'];
        $pkg = null;
        foreach ($packages as $p) {
            if (abs((float) ($p['amount_brl'] ?? 0) - $amountBrl) < 0.005) { $pkg = $p; break; }
        }
        if (! $pkg) {
            return response()->json([
                'error'   => 'invalid_package',
                'message' => 'Valor não corresponde a um pacote disponível.',
                'available_packages' => $packages,
            ], 422);
        }

        // TODO SEL-329-B: integrar com Pagar.me — por ora retorna PIX simulado.
        // Fluxo real: cria order Pagar.me com payment_method=pix, valor em centavos,
        // metadata { ai_wallet_deposit_credits: pkg.credits, client_id }.
        // Webhook credita via AiWalletService::credit($clientId, $pkg['credits'], 'deposit_pix', $chargeId).
        return response()->json([
            'ok' => true,
            'pending_charge_id' => 'stub_' . uniqid(),
            'amount_brl' => $amountBrl,
            'credits_to_receive' => (int) $pkg['credits'],
            'message' => 'Integração Pagar.me pendente — sistema em finalização.',
        ]);
    }

    /**
     * SEL-329: saldo Kling em tempo real (JWT correto + endpoint correto).
     * Antes usava Bearer com KLING_ACCESS_KEY raw (dava 401/502). Agora usa o
     * KlingService que gera JWT HS256 corretamente e bate no base_url configurado
     * (api-app.klingai.com por default). Últimos 30 dias de custo.
     */
    public function adminKlingBalance(KlingService $kling): JsonResponse
    {
        if (!$kling->isConfigured()) {
            return response()->json(['error' => 'kling_not_configured'], 500);
        }
        try {
            $raw = $kling->accountCosts();
            // Kling retorna 'resource_pack_subscribe_infos' com pacotes ativos.
            // Somamos remaining_quantity + total_quantity de todos pacotes online.
            $packs = $raw['resource_pack_subscribe_infos']
                  ?? ($raw['data']['resource_pack_subscribe_infos'] ?? []);
            $totalRemaining = 0.0;
            $totalCapacity  = 0.0;
            $packageNames = [];
            foreach ($packs as $p) {
                if (($p['status'] ?? '') !== 'online') continue;
                $totalRemaining += (float) ($p['remaining_quantity'] ?? 0);
                $totalCapacity  += (float) ($p['total_quantity']     ?? 0);
                if (!empty($p['resource_pack_name'])) $packageNames[] = $p['resource_pack_name'];
            }
            $usedAmount = max(0, $totalCapacity - $totalRemaining);
            return response()->json([
                'code' => $raw['code'] ?? 0,
                'data' => [
                    // "balance" no contexto Kling = unidades restantes (consumo direto)
                    'balance'      => $totalRemaining,
                    'used_amount'  => round($usedAmount, 2),
                    'total_amount' => $totalCapacity,
                    'unit'         => 'unidades',
                    'currency'     => null,
                    'packages'     => $packageNames,
                    'window_days'  => 30,
                    'raw'          => $raw,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'kling_error', 'message' => $e->getMessage()], 502);
        }
    }

    /**
     * SEL-329: crédito manual — aceita client_id OU user_id.
     * amount agora significa CRÉDITOS (não R$), coerente com o modelo "dois mundos".
     */
    public function adminCredit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => 'nullable|integer',
            'user_id'   => 'nullable|integer',
            'amount'    => 'required|numeric|min:0.01',
            'note'      => 'nullable|string|max:255',
        ]);
        $clientId = $data['client_id'] ?? null;
        if (!$clientId && !empty($data['user_id'])) {
            $client = DB::table('clients')->where('user_id', (int) $data['user_id'])->first();
            if (!$client) {
                // Se o usuário não tem client_id, criamos um implicito (sem CPF etc)
                $now = now();
                $clientId = DB::table('clients')->insertGetId([
                    'user_id'    => (int) $data['user_id'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $clientId = $client->id;
            }
        }
        if (!$clientId) {
            return response()->json(['error' => 'missing_client_or_user'], 422);
        }
        $new = AiWalletService::credit(
            (int) $clientId,
            (float) $data['amount'],
            'admin_adjust',
            null,
            $data['note'] ?? 'Crédito manual admin'
        );
        return response()->json(['ok' => true, 'client_id' => (int) $clientId, 'balance' => $new]);
    }

    /**
     * Admin: consumo por cliente.
     */
    public function adminConsumption(): JsonResponse
    {
        $rows = \DB::table('client_ai_wallets as w')
            ->leftJoin('clients as c', 'c.id', '=', 'w.client_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->select('w.client_id', 'u.email', 'u.name', 'w.balance', 'w.lifetime_deposited', 'w.lifetime_consumed', 'w.updated_at')
            ->orderByDesc('w.lifetime_consumed')
            ->limit(100)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
        return response()->json(['data' => $rows]);
    }
}
