<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SEL-166 — push web pros admins quando um novo cliente se cadastra em
 * api.seller.global. Espelha SalePushNotifier (SEL-072) e reusa o mesmo
 * type 'sale' + som caixa registradora — Service Worker faz demo mode
 * override, valor R$ 297,00 constante e intencional (nao existe cobranca
 * real no cadastro; e apenas UX de "toca a caixa quando aparece lead").
 * Nunca deve quebrar o fluxo de cadastro: tudo em try/catch.
 */
class RegisterPushNotifier
{
    public static function notifyRegister(int $userId): void
    {
        try {
            if (!config('services.vapid.private')) {
                return;
            }

            $u = DB::table('users')
                ->where('id', $userId)
                ->first(['id', 'name', 'email']);
            if (!$u) {
                return;
            }

            $adminIds = DB::table('users')->whereIn('role', ['admin', 'super_admin'])->pluck('id');
            $devices  = DB::table('push_subscriptions')->whereIn('user_id', $adminIds)->get();
            if ($devices->isEmpty()) {
                return;
            }

            // SEL-241 Ruan 18/07: só primeiro nome no push (email mascarado
            // terminava em .com sempre — parecia fake em vídeo/live).
            $nameFirst = trim(explode(' ', (string) ($u->name ?? ''))[0] ?? '');
            if ($nameFirst === '') { $nameFirst = 'Cliente'; }

            $payload = json_encode([
                'title' => '🎉 Novo cadastro',
                'body'  => $nameFirst . "\n"
                    . 'R$ 297,00 · ' . now()->format('d/m H:i'),
                'url'   => '/admin/clients',
                // SEL-166: type 'sale' pra reaproveitar useSaleSound do painel
                // (mesmo som de caixa registradora do SalePushNotifier).
                'type'  => 'sale',
                'sound' => '/sounds/cash-register.mp3',
                'tag'   => 'register-' . $u->id,
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
            Log::warning('RegisterPushNotifier: ' . $e->getMessage());
        }
    }

    /**
     * SEL-226: mascara email pra privacidade. "ruanipanema@gmail.com" → "r****a@g****.com"
     */
    private static function maskEmail(string $email): string
    {
        if ($email === '' || !str_contains($email, '@')) {
            return $email;
        }
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
}
