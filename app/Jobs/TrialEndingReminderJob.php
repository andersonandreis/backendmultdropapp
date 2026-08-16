<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * SEL-200C: aviso trial acabando.
 * Roda a cada 2h.
 * Busca subscriptions com trial_ends_at nas proximas 12h e sem push/email enviado ainda.
 * Envia push + email "seu teste acaba, oferta 12× R$29,90".
 */
class TrialEndingReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public function handle(): void
    {
        // Busca trials expirando nas proximas 12h que ainda nao foram avisados
        $subs = DB::table('subscriptions as s')
            ->join('clients as c', 'c.id', '=', 's.client_id')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->join('plans as p', 'p.id', '=', 's.plan_id')
            ->where('p.slug', 'tt_shop_trial_3d')
            ->whereNotNull('s.trial_ends_at')
            ->where('s.trial_ends_at', '>', now())
            ->where('s.trial_ends_at', '<', now()->addHours(12))
            ->whereRaw("NOT EXISTS (SELECT 1 FROM email_logs el WHERE el.user_id = u.id AND el.email_type = 'trial_ending')")
            ->select('u.id as user_id','u.email','u.name','s.trial_ends_at','s.id as subscription_id')
            ->limit(500)
            ->get();

        Log::info('[SEL-200C] TrialEndingReminderJob — ' . $subs->count() . ' trials expirando 12h');

        foreach ($subs as $row) {
            $hoursLeft = max(1, (int) round(now()->diffInHours($row->trial_ends_at)));

            $subject = "Seu teste do TikTok Shop acaba em $hoursLeft horas";
            $body = "Olá {$row->name},\n\nSeu teste de 3 dias do TikTok Shopping acaba em $hoursLeft horas.\n\nPra continuar recebendo os dados de produtos em alta, criadores e fornecedores, ative agora por apenas R$29,90/mês OU R$297/ano (economize e ainda ganhe 5 créditos de vídeo IA de brinde).\n\nAcesse: https://seller.global/checkout/tt-shop\n\nSeller Global";

            try {
                Mail::raw($body, function ($m) use ($row, $subject) {
                    $m->to($row->email)->subject($subject);
                });

                // Push notification via WebPush (mesmo padrão do SalePushNotifier)
                $subs = DB::table('push_subscriptions')->where('user_id', $row->user_id)->get();
                if ($subs->isNotEmpty() && config('services.vapid.public') && config('services.vapid.private')) {
                    try {
                        $wp = new \Minishlink\WebPush\WebPush(['VAPID' => [
                            'subject'    => 'mailto:contato@hubai.io',
                            'publicKey'  => config('services.vapid.public'),
                            'privateKey' => config('services.vapid.private'),
                        ]]);
                        $payload = json_encode([
                            'title' => "Teste acaba em $hoursLeft h",
                            'body'  => 'R$29,90/mês continua tudo. Toque pra ativar.',
                            'url'   => 'https://seller.global/checkout/tt-shop',
                            'tag'   => 'trial_ending',
                        ]);
                        foreach ($subs as $ps) {
                            $wp->queueNotification(\Minishlink\WebPush\Subscription::create([
                                'endpoint'        => $ps->endpoint,
                                'publicKey'       => $ps->public_key,
                                'authToken'       => $ps->auth_token,
                                'contentEncoding' => 'aesgcm',
                            ]), $payload);
                        }
                        foreach ($wp->flush() as $rep) { /* fire-and-forget */ }
                    } catch (\Throwable $pe) {
                        Log::warning('[SEL-200C] push falhou u='.$row->user_id.' — '.$pe->getMessage());
                    }
                }

                DB::table('email_logs')->insert([
                    'user_id' => $row->user_id,
                    'email_type' => 'trial_ending',
                    'to_email' => $row->email,
                    'status' => 'sent',
                    'sent_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('[SEL-200C] falha enviar trial ending u='.$row->user_id.' — '.$e->getMessage());
            }
        }
    }
}
