<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * SEL-408 — acesso "só-live": conta que entra na extensão de LIVE e no painel
 * /lives e mais nada do resto do sistema.
 *
 * Mesma ideia do AffiliateAccessService (SEL-386): a concessão nasce marcada
 * com payment_method proprio (nunca 'affiliate_grant', nunca confundida com
 * receita de verdade) e e revogavel a qualquer momento sem tocar em
 * assinatura paga.
 *
 * Serve pra dois casos que o admin de /admin/live-access cobre:
 *  1) Cliente NOVO (ex: amigo pra testar) — cria user+client com senha
 *     temporaria e concede so a live.
 *  2) Cliente JA EXISTENTE mas sem assinatura ativa — a concessao vira a
 *     assinatura ativa mais recente dele, e o RestrictLiveOnlyAccess cerca o
 *     resto do sistema (quem ja tem assinatura paga ativa NAO e afetado,
 *     porque nesse caso ha uma assinatura de plano de verdade mais forte que
 *     a concessao — ver middleware).
 */
class LiveOnlyAccessService
{
    public const GRANT_METHOD = 'live_only_grant';
    public const PLAN_SLUG = 'live_only';

    /** Garante o plano-sentinela. is_active=false: nunca aparece no checkout. */
    public static function plan(): Plan
    {
        return Plan::firstOrCreate(
            ['slug' => self::PLAN_SLUG],
            [
                'name'          => 'Acesso Live (concessão)',
                'description'   => 'Plano interno, não vendável — usado só pra conceder acesso exclusivo à LIVE (extensão + /lives), sem o resto do sistema.',
                'price_monthly' => 0,
                'price_yearly'  => 0,
                'is_active'     => false,
            ]
        );
    }

    /**
     * Concede (ou renova) o acesso pra um e-mail. Idempotente: nao duplica
     * concessao ativa, so atualiza.
     *
     * @return array{client: Client, user: User, subscription: Subscription, senha_temporaria: ?string, criado: bool}
     */
    public static function grant(string $email, ?string $name = null, ?\DateTimeInterface $expiraEm = null): array
    {
        $email = mb_strtolower(trim($email));
        $user = User::where('email', $email)->first();
        $criado = false;
        $senha = null;

        if ($user && $user->role !== 'client') {
            throw new \InvalidArgumentException("O e-mail {$email} já existe com role '{$user->role}' — concessão só-live é só pra contas de cliente.");
        }

        if (! $user) {
            $senha = Str::password(14);
            $user = User::create([
                'name'      => $name ?: Str::before($email, '@'),
                'email'     => $email,
                'password'  => $senha, // cast 'hashed' no modelo
                'role'      => 'client',
                'is_active' => true,
            ]);
            $criado = true;
            // UserObserver::created ja cria o Client (firstOrCreate) de forma sincrona.
        }

        $client = $user->client;
        if (! $client) {
            // usuario existente com role=client mas sem client (nao deveria acontecer,
            // mas cobre o caso pra nao quebrar a concessao)
            $client = Client::create(['user_id' => $user->id, 'is_active' => true]);
        } elseif (! $client->is_active) {
            $client->update(['is_active' => true]);
        }

        $plan = self::plan();
        $inicio = now();

        $sub = Subscription::where('client_id', $client->id)
            ->where('payment_method', self::GRANT_METHOD)
            ->whereNull('cancelled_at')
            ->first();

        $dados = [
            'plan_id'              => $plan->id,
            'status'               => 'active',
            'current_period_start' => $inicio,
            'current_period_end'   => $expiraEm,
        ];

        if ($sub) {
            $sub->update($dados);
        } else {
            $sub = Subscription::create($dados + [
                'client_id'      => $client->id,
                'payment_method' => self::GRANT_METHOD,
            ]);
        }

        Log::info('[SEL-408] acesso só-live concedido', [
            'client_id'       => $client->id,
            'user_id'         => $user->id,
            'email'           => $email,
            'criado'          => $criado,
            'subscription_id' => $sub->id,
        ]);

        return [
            'client'            => $client,
            'user'              => $user,
            'subscription'      => $sub,
            'senha_temporaria'  => $senha,
            'criado'            => $criado,
        ];
    }

    /** Revoga SOMENTE a concessao só-live — nunca toca em assinatura paga. */
    public static function revoke(Client $client): int
    {
        $n = Subscription::where('client_id', $client->id)
            ->where('payment_method', self::GRANT_METHOD)
            ->whereNull('cancelled_at')
            ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        Log::info('[SEL-408] acesso só-live revogado', [
            'client_id' => $client->id,
            'revogadas' => $n,
        ]);

        return $n;
    }
}
