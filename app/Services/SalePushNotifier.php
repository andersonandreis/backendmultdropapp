<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SEL-072 — push web pros admins quando uma assinatura é paga/ativada.
 * Nunca deve quebrar o fluxo de pagamento: tudo em try/catch.
 */
class SalePushNotifier
{
    public static function notifySale(int $subscriptionId): void
    {
        try {
            if (!config('services.vapid.private')) {
                return;
            }

            // SEL-137 Ruan 00:45: seleciona push_admin_enabled do plano pra respeitar toggle admin.
            $s = DB::table('subscriptions as s')
                ->leftJoin('clients as c', 'c.id', '=', 's.client_id')
                ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
                ->leftJoin('plans as p', 'p.id', '=', 's.plan_id')
                ->where('s.id', $subscriptionId)
                ->select('s.id', 's.payment_method', 'u.name', 'u.email', 'p.name as plan', 'p.price_monthly', 'p.push_admin_enabled')
                ->first();
            if (!$s) {
                return;
            }

            // SEL-137: admin desabilitou push desse plano especifico? nao envia.
            if (isset($s->push_admin_enabled) && !((int) $s->push_admin_enabled)) {
                return;
            }

            $adminIds = DB::table('users')->whereIn('role', ['admin', 'super_admin'])->pluck('id');
            $devices  = DB::table('push_subscriptions')->whereIn('user_id', $adminIds)->get();
            if ($devices->isEmpty()) {
                return;
            }

            $method  = ['credit_card' => 'cartão', 'pix' => 'PIX', 'boleto' => 'boleto'][$s->payment_method] ?? ($s->payment_method ?? '');
            $price   = number_format((float) ($s->price_monthly ?? 0), 2, ',', '.');
            // SEL-226 Ruan 18/07: primeiro nome só + email mascarado (live/vídeo)
            $nameFirst = trim(explode(' ', (string) ($s->name ?? ''))[0] ?? '');
            if ($nameFirst === '') { $nameFirst = 'Cliente'; }
            // SEL-241 Ruan 18/07: removido email do push (parecia fake com .com final)
            $emailMasked = '';
            $payload = json_encode([
                'title' => '💰 Nova venda — Plano ' . ($s->plan ?? '?'),
                'body'  => $nameFirst . ($emailMasked ? " ({$emailMasked})" : '') . ' assinou o ' . ($s->plan ?? '?')
                    . "\nR$ {$price}/mês · {$method} · " . now()->format('d/m H:i'),
                'url'   => '/admin/clients',
                // SEL-137 Ruan 00:45: som caixa registradora — sw.js faz postMessage pras janelas
                // abertas, useSaleSound toca /sounds/cash-register.mp3.
                'type'  => 'sale',
                'sound' => '/sounds/cash-register.mp3',
                'tag'   => 'sale-' . $s->id,
            ], JSON_UNESCAPED_UNICODE);

            $wp = new \Minishlink\WebPush\WebPush(['VAPID' => [
                'subject'    => config('services.vapid.subject'),
                'publicKey'  => config('services.vapid.public'),
                'privateKey' => config('services.vapid.private'),
            ]]);

            foreach ($devices as $d) {
                $wp->queueNotification(
                    \Minishlink\WebPush\Subscription::create([
                        'endpoint' => $d->endpoint,
                        'keys'     => ['p256dh' => $d->p256dh, 'auth' => $d->auth],
                    ]),
                    $payload
                );
            }

            foreach ($wp->flush() as $report) {
                if (!$report->isSuccess()) {
                    $code = $report->getResponse()?->getStatusCode();
                    if (in_array($code, [404, 410], true)) {
                        DB::table('push_subscriptions')
                            ->where('endpoint', (string) $report->getRequest()->getUri())
                            ->delete();
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('SalePushNotifier: ' . $e->getMessage());
        }
    }

    /**
     * SEL-226: mascara email pra privacidade (live/vídeo). Ex "ruanipanema@gmail.com" → "r****a@g****.com".
     */
    private static function maskEmail(string $email): string
    {
        if ($email === '' || !str_contains($email, '@')) return $email;
        [$local, $domain] = explode('@', $email, 2);
        $localMasked = strlen($local) <= 2
            ? substr($local, 0, 1) . '*'
            : substr($local, 0, 1) . str_repeat('*', max(3, strlen($local) - 2)) . substr($local, -1);
        $dotPos = strrpos($domain, '.');
        if ($dotPos === false) {
            $domainMasked = substr($domain, 0, 1) . str_repeat('*', max(1, strlen($domain) - 1));
        } else {
            $left = substr($domain, 0, $dotPos);
            $tld = substr($domain, $dotPos);
            $domainMasked = substr($left, 0, 1) . str_repeat('*', max(2, strlen($left) - 1)) . $tld;
        }
        return $localMasked . '@' . $domainMasked;
    }

    /**
     * SEL-388: avisa o AFILIADO quando uma indicacao dele vira comissao.
     * Mesmo canal e mesmo som da venda do vendedor (/sounds/cash-register.mp3).
     */
    public static function notifyAffiliateCommission(int $userId, float $comissao, string $clienteNome = 'Cliente'): void
    {
        try {
            $devices = DB::table('push_subscriptions')->where('user_id', $userId)->get();
            if ($devices->isEmpty()) {
                return; // afiliado nao ativou notificacao no navegador
            }

            $valor   = number_format($comissao, 2, ',', '.');
            $primeiro = trim(explode(' ', trim($clienteNome))[0] ?? '') ?: 'Cliente';

            $payload = json_encode([
                'title' => '💰 Comissão de R$ ' . $valor,
                'body'  => $primeiro . ' assinou pelo seu link · ' . now()->format('d/m H:i'),
                'url'   => '/afiliados',
                'type'  => 'sale',
                'sound' => '/sounds/cash-register.mp3',
                'tag'   => 'affiliate-commission-' . $userId . '-' . now()->timestamp,
            ], JSON_UNESCAPED_UNICODE);

            $wp = new \Minishlink\WebPush\WebPush(['VAPID' => [
                'subject'    => config('services.vapid.subject'),
                'publicKey'  => config('services.vapid.public'),
                'privateKey' => config('services.vapid.private'),
            ]]);

            foreach ($devices as $d) {
                $wp->queueNotification(
                    \Minishlink\WebPush\Subscription::create([
                        'endpoint' => $d->endpoint,
                        'keys'     => ['p256dh' => $d->p256dh, 'auth' => $d->auth],
                    ]),
                    $payload
                );
            }
            foreach ($wp->flush() as $report) {
                if (! $report->isSuccess()) {
                    Log::warning('[SEL-388] push de comissao falhou', ['erro' => $report->getReason()]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[SEL-388] push de comissao: excecao', ['error' => $e->getMessage()]);
        }
    }

}
