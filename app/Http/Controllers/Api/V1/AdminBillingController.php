<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-054 — Billing/enrich/exclusão de clientes no painel admin seller.global.
 * Mantido fora do AdminController (82KB) de propósito.
 */
class AdminBillingController extends Controller
{
    private function requireSuperAdmin(Request $request): void
    {
        if ($request->user()?->role !== 'super_admin') {
            abort(403, 'Acesso restrito a super_admin.');
        }
    }

    private function isDemoEmail(?string $email): bool
    {
        $email = strtolower((string) $email);
        if ($email === '') {
            return false;
        }

        return (bool) preg_match('/(^|[.@_+-])(demo|teste?|qa)([.@_+-]|\d|$)/i', $email)
            || str_ends_with($email, '@seller.global')
            || str_ends_with($email, '@example.com');
    }

    // GET /api/v1/admin/clients/enrich — mapa client_id => dados que o index não traz
    public function clientsEnrich(Request $request)
    {
        $this->requireSuperAdmin($request);

        $rows = DB::table('clients')
            ->leftJoin('users', 'users.id', '=', 'clients.user_id')
            ->select('clients.id', 'clients.user_id', 'users.email')
            ->addSelect(['last_login_at' => DB::table('personal_access_tokens')
                ->select(DB::raw('MAX(last_used_at)'))
                ->whereColumn('tokenable_id', 'clients.user_id')
                ->where('tokenable_type', 'App\\Models\\User')])
            ->get();

        $latestSubs = DB::table('subscriptions')
            ->leftJoin('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->select(
                'subscriptions.id', 'subscriptions.client_id', 'subscriptions.status',
                'subscriptions.pagarme_status', 'subscriptions.payment_method',
                'subscriptions.current_period_start', 'subscriptions.current_period_end',
                'subscriptions.trial_ends_at', 'subscriptions.updated_at',
                'plans.name as plan_name', 'plans.price_monthly as amount'
            )
            ->orderByDesc('subscriptions.id')
            ->get()
            ->groupBy('client_id')
            ->map(fn ($g) => $g->first());

        $now = now();
        $out = [];
        foreach ($rows as $r) {
            $sub = $latestSubs->get($r->id);

            $lastPaymentAt = null;
            $lastPaymentStatus = null;
            if ($sub) {
                if (in_array($sub->pagarme_status, ['paid', 'received'], true)) {
                    $lastPaymentStatus = 'paid';
                    $lastPaymentAt = $sub->updated_at ?: $sub->current_period_start;
                } elseif ($sub->pagarme_status === 'pending' || $sub->status === 'pending') {
                    $lastPaymentStatus = 'pending';
                } elseif ($sub->current_period_end && $sub->current_period_end < $now->toDateTimeString()) {
                    $lastPaymentStatus = 'overdue';
                }
            }

            $out[$r->id] = [
                'plan_name'           => $sub->plan_name ?? null,
                'subscription_status' => $sub->status ?? null,
                'amount'              => isset($sub->amount) ? (float) $sub->amount : null,
                'cycle'               => $sub ? 'monthly' : null,
                'last_payment_at'     => $lastPaymentAt,
                'last_payment_status' => $lastPaymentStatus,
                'next_due_at'         => $sub->current_period_end ?? null,
                'trial_ends_at'       => $sub->trial_ends_at ?? null,
                'last_login_at'       => $r->last_login_at,
                'is_demo'             => $this->isDemoEmail($r->email),
            ];
        }

        return response()->json(['clients' => $out]);
    }

    // GET /api/v1/admin/clients/{id}/billing
    public function clientBilling(Request $request, $id)
    {
        $this->requireSuperAdmin($request);

        $client = DB::table('clients')
            ->leftJoin('users', 'users.id', '=', 'clients.user_id')
            ->where('clients.id', $id)
            ->select('clients.id', 'users.name', 'users.email', 'clients.phone', 'clients.created_at')
            ->first();
        if (! $client) {
            return response()->json(['message' => 'Cliente não encontrado'], 404);
        }

        $subs = DB::table('subscriptions')
            ->leftJoin('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('subscriptions.client_id', $id)
            ->orderByDesc('subscriptions.created_at')
            ->select(
                'subscriptions.id', 'subscriptions.plan_id', 'plans.name as plan_name',
                'plans.price_monthly as amount',
                'subscriptions.status', 'subscriptions.payment_method',
                'subscriptions.pagarme_subscription_id as pagarme_order_code',
                'subscriptions.pagarme_status', 'subscriptions.trial_ends_at',
                'subscriptions.current_period_start', 'subscriptions.current_period_end',
                'subscriptions.created_at'
            )
            ->get();

        // Lookup live Pagar.me do pedido da assinatura mais recente (order code or_...)
        $payments = [];
        foreach ($subs as $i => $sub) {
            $sub->pagarme_paid_at = null;
            if ($i === 0 && $sub->pagarme_order_code && str_starts_with($sub->pagarme_order_code, 'or_')) {
                try {
                    $res = Http::withBasicAuth((string) env('PAGARME_API_KEY'), '')
                        ->timeout(5)
                        ->get('https://api.pagar.me/core/v5/orders/' . $sub->pagarme_order_code);
                    if ($res->ok()) {
                        $order = $res->json();
                        $sub->pagarme_status = $order['status'] ?? $sub->pagarme_status;
                        foreach (($order['charges'] ?? []) as $charge) {
                            $paidAt = $charge['paid_at'] ?? null;
                            if ($paidAt && ! $sub->pagarme_paid_at) {
                                $sub->pagarme_paid_at = $paidAt;
                            }
                            $payments[] = [
                                'id'                 => $charge['id'] ?? uniqid('ch_'),
                                'amount'             => isset($charge['amount']) ? $charge['amount'] / 100 : null,
                                'method'             => $charge['payment_method'] ?? $sub->payment_method,
                                'status'             => $charge['status'] ?? null,
                                'pagarme_order_code' => $sub->pagarme_order_code,
                                'paid_at'            => $paidAt,
                                'created_at'         => $charge['created_at'] ?? null,
                                'plan_name'          => $sub->plan_name,
                            ];
                        }
                    }
                } catch (\Throwable $e) {
                    // Pagar.me fora/timeout: segue com dados locais
                }
            }
        }

        $current = $subs->first(fn ($s) => in_array($s->status, ['active', 'trialing', 'trial'], true)) ?? $subs->first();

        $trialNote = null;
        if ($subs->contains(fn ($s) => ! empty($s->trial_ends_at))) {
            $trialNote = 'O checkout concede trial automático de 7 dias ao criar a assinatura, '
                . 'antes da confirmação do pagamento — status "Em teste" não significa liberação manual.';
        }

        return response()->json([
            'client' => [
                'id'         => $client->id,
                'name'       => $client->name,
                'email'      => $client->email,
                'phone'      => $client->phone,
                'created_at' => $client->created_at,
                'is_demo'    => $this->isDemoEmail($client->email),
            ],
            'subscription'  => $current,
            'subscriptions' => $subs->values(),
            'payments'      => $payments,
            'trial_note'    => $trialNote,
        ]);
    }

    // POST /api/v1/admin/clients/bulk-delete
    public function bulkDeleteClients(Request $request)
    {
        $this->requireSuperAdmin($request);

        $data = $request->validate([
            'client_ids'   => 'required|array|min:1|max:200',
            'client_ids.*' => 'integer',
            // SEL-390: exclusao forcada — o guard protege de acidente, mas o super admin
            // precisa conseguir excluir de proposito. Nunca ignora o guard de usuario admin.
            'force'        => 'sometimes|boolean',
        ]);
        $forcar = (bool) ($data['force'] ?? false);

        $deleted = [];
        $skipped = [];

        if ($forcar) {
            \Illuminate\Support\Facades\Log::warning('[SEL-390] exclusao FORCADA de clientes', [
                'admin_id'   => $request->user()?->id,
                'client_ids' => $data['client_ids'],
            ]);
            // SEL-383: exclusao forcada tambem precisa deixar rastro consultavel.
            DB::table('audit_logs')->insert([
                'user_id'    => $request->user()?->id,
                'event'      => 'admin.exclusao_forcada',
                'metadata'   => json_encode(['client_ids' => $data['client_ids']], JSON_UNESCAPED_UNICODE),
                'ip'         => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($data['client_ids'] as $cid) {
            $client = DB::table('clients')->where('id', $cid)->first();
            if (! $client) {
                $skipped[] = ['id' => $cid, 'reason' => 'não encontrado'];
                continue;
            }

            $user = $client->user_id ? DB::table('users')->where('id', $client->user_id)->first() : null;
            if ($user && in_array($user->role ?? null, ['admin', 'super_admin'], true)) {
                $skipped[] = ['id' => $cid, 'reason' => 'usuário admin'];
                continue;
            }

            // Conta demo/QA é sempre deletável — funil de teste cria subscription active,
            // então o guard de assinatura/pagamento só vale pra cliente real.
            $isDemo = $this->isDemoEmail($user->email ?? null);

            $hasActiveSub = ! $forcar && ! $isDemo && DB::table('subscriptions')->where('client_id', $cid)
                ->where(function ($q) {
                    $q->whereIn('status', ['active', 'trialing'])
                      ->orWhereIn('pagarme_status', ['paid', 'received']);
                })->exists();
            if ($hasActiveSub) {
                $skipped[] = ['id' => $cid, 'reason' => 'assinatura ativa ou paga'];
                continue;
            }

            $hasPaidPayment = ! $forcar && ! $isDemo && Schema::hasTable('payments') && DB::table('orders')
                ->join('payments', 'payments.order_id', '=', 'orders.id')
                ->where('orders.client_id', $cid)
                ->whereIn('payments.status', ['paid', 'received', 'approved'])
                ->exists();
            if ($hasPaidPayment) {
                $skipped[] = ['id' => $cid, 'reason' => 'pagamento confirmado'];
                continue;
            }

            try {
                DB::transaction(function () use ($cid, $client) {
                    if (Schema::hasColumn('orders', 'client_id')) {
                        $orderIds = DB::table('orders')->where('client_id', $cid)->pluck('id');
                        if ($orderIds->isNotEmpty()) {
                            foreach (['order_items', 'payments'] as $childTable) {
                                if (Schema::hasTable($childTable) && Schema::hasColumn($childTable, 'order_id')) {
                                    DB::table($childTable)->whereIn('order_id', $orderIds)->delete();
                                }
                            }
                            DB::table('orders')->whereIn('id', $orderIds)->delete();
                        }
                    }

                    foreach ([
                        'client_products', 'marketplace_accounts', 'subscriptions',
                        'client_supplier_balances', 'stores',
                    ] as $table) {
                        if (Schema::hasTable($table) && Schema::hasColumn($table, 'client_id')) {
                            DB::table($table)->where('client_id', $cid)->delete();
                        }
                    }

                    DB::table('clients')->where('id', $cid)->delete();

                    if ($client->user_id) {
                        DB::table('personal_access_tokens')
                            ->where('tokenable_type', 'App\\Models\\User')
                            ->where('tokenable_id', $client->user_id)
                            ->delete();
                        DB::table('users')->where('id', $client->user_id)->delete();
                    }
                });
                $deleted[] = $cid;
            } catch (\Throwable $e) {
                $skipped[] = ['id' => $cid, 'reason' => 'erro: ' . substr($e->getMessage(), 0, 120)];
            }
        }

        return response()->json(['deleted' => $deleted, 'skipped' => $skipped]);
    }

    // GET /api/v1/admin/recovery-leads — quem gerou checkout e não pagou (recuperação de vendas)
    public function recoveryLeads(Request $request)
    {
        $this->requireSuperAdmin($request);

        $paidClientIds = DB::table('subscriptions')
            ->where(function ($q) {
                $q->whereIn('status', ['active', 'trialing'])
                  ->orWhereIn('pagarme_status', ['paid', 'received']);
            })
            ->distinct()
            ->pluck('client_id');

        $leads = DB::table('subscriptions')
            ->leftJoin('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->leftJoin('clients', 'clients.id', '=', 'subscriptions.client_id')
            ->leftJoin('users', 'users.id', '=', 'clients.user_id')
            ->whereNotIn('subscriptions.client_id', $paidClientIds)
            ->orderByDesc('subscriptions.id')
            ->select(
                'subscriptions.client_id', 'subscriptions.status', 'subscriptions.pagarme_status',
                'subscriptions.payment_method', 'subscriptions.created_at',
                'plans.name as plan_name', 'plans.price_monthly as amount',
                'users.name', 'users.email', 'clients.phone'
            )
            ->limit(500)
            ->get()
            ->unique('client_id')
            ->filter(fn ($s) => ! $this->isDemoEmail($s->email))
            ->values()
            ->take(100);

        return response()->json(['leads' => $leads]);
    }

    // POST /api/v1/admin/clients/{id}/reset-password — define a senha do cliente.
    // SEL-184R (Ruan 21/07 "ver e redefinir a senha de todos"): aceita `password`
    // custom no body (default 123456) e devolve a senha aplicada pro admin ver/copiar.
    public function resetClientPassword(Request $request, $id)
    {
        $this->requireSuperAdmin($request);

        $client = DB::table('clients')->where('id', $id)->first();
        if (! $client || ! $client->user_id) {
            return response()->json(['message' => 'Cliente não encontrado'], 404);
        }

        $user = DB::table('users')->where('id', $client->user_id)->first();
        if (! $user) {
            return response()->json(['message' => 'Usuário não encontrado'], 404);
        }
        if (in_array($user->role ?? null, ['admin', 'super_admin'], true)) {
            return response()->json(['message' => 'Não é permitido resetar senha de admin'], 422);
        }

        $newPassword = trim((string) $request->input('password', '123456'));
        if (mb_strlen($newPassword) < 6 || mb_strlen($newPassword) > 64) {
            return response()->json(['message' => 'Senha deve ter entre 6 e 64 caracteres'], 422);
        }

        DB::table('users')->where('id', $user->id)->update(['password' => bcrypt($newPassword)]);

        // SEL-383: este reset era invisivel — nao havia log nem auditoria, e o
        // DB::table()->update() nem toca no updated_at. Agora fica registrado quem fez.
        DB::table('audit_logs')->insert([
            'user_id'        => $request->user()?->id,
            'event'          => 'admin.reset_senha',
            'auditable_type' => \App\Models\User::class,
            'auditable_id'   => $user->id,
            'metadata'       => json_encode([
                'alvo_email' => $user->email,
                'por_admin'  => $request->user()?->email,
            ], JSON_UNESCAPED_UNICODE),
            'ip'             => $request->ip(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return response()->json(['email' => $user->email, 'password' => $newPassword]);
    }
    /**
     * SEL-095 (Ruan 20:11): DELETE individual pro admin excluir cliente pelo painel.
     * Reusa lógica de guard do bulkDeleteClients (bloqueia admin, ativos e pagantes).
     */
    public function deleteSingleClient(Request $request, int $id)
    {
        $this->requireSuperAdmin($request);

        $client = DB::table('clients')->where('id', $id)->first();
        if (! $client) {
            return response()->json(['error' => 'not_found', 'message' => 'Cliente não encontrado'], 404);
        }

        $user = $client->user_id ? DB::table('users')->where('id', $client->user_id)->first() : null;
        if ($user && in_array($user->role ?? null, ['admin', 'super_admin'], true)) {
            return response()->json(['error' => 'protected', 'message' => 'Não é possível excluir usuário admin'], 403);
        }

        $isDemo = $this->isDemoEmail($user->email ?? null);
        $force = $request->boolean('force', false);

        if (! $force) {
            $hasActiveSub = ! $isDemo && DB::table('subscriptions')->where('client_id', $id)
                ->where(function ($q) {
                    $q->whereIn('status', ['active', 'trialing'])
                      ->orWhereIn('pagarme_status', ['paid', 'received']);
                })->exists();
            if ($hasActiveSub) {
                return response()->json([
                    'error' => 'has_active_subscription',
                    'message' => 'Cliente tem assinatura ativa. Passe ?force=1 pra excluir mesmo assim.',
                ], 409);
            }

            $hasPaidPayment = ! $isDemo && Schema::hasTable('payments') && DB::table('orders')
                ->join('payments', 'payments.order_id', '=', 'orders.id')
                ->where('orders.client_id', $id)
                ->whereIn('payments.status', ['paid', 'received', 'approved'])
                ->exists();
            if ($hasPaidPayment) {
                return response()->json([
                    'error' => 'has_paid_payments',
                    'message' => 'Cliente tem pagamento confirmado. Passe ?force=1 pra excluir mesmo assim.',
                ], 409);
            }
        }

        try {
            DB::transaction(function () use ($id, $client) {
                // Cascade: apaga dados vinculados antes do client/user
                DB::table('subscriptions')->where('client_id', $id)->delete();
                if (Schema::hasTable('payments')) {
                    $orderIds = DB::table('orders')->where('client_id', $id)->pluck('id');
                    if (count($orderIds) > 0) DB::table('payments')->whereIn('order_id', $orderIds)->delete();
                }
                DB::table('orders')->where('client_id', $id)->delete();
                if (Schema::hasTable('client_products')) DB::table('client_products')->where('client_id', $id)->delete();
                if (Schema::hasTable('marketplace_accounts')) DB::table('marketplace_accounts')->where('client_id', $id)->delete();
                if (Schema::hasTable('client_wallet_transactions')) DB::table('client_wallet_transactions')->where('client_id', $id)->delete();
                if (Schema::hasTable('client_wallets')) DB::table('client_wallets')->where('client_id', $id)->delete();
                if (Schema::hasTable('surveys')) DB::table('surveys')->where('client_id', $id)->delete();
                if (Schema::hasTable('personal_access_tokens') && $client->user_id) {
                    DB::table('personal_access_tokens')->where('tokenable_id', $client->user_id)->delete();
                }
                DB::table('clients')->where('id', $id)->delete();
                if ($client->user_id) DB::table('users')->where('id', $client->user_id)->delete();
            });
            return response()->json(['ok' => true, 'deleted_client_id' => $id, 'forced' => $force]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'db_error',
                'message' => 'Erro ao excluir: ' . $e->getMessage(),
            ], 500);
        }
    }

}
