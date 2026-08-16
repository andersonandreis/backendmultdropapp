<?php

namespace App\Services;

use App\Mail\VideoReadyMail;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * SEL (08/08 Ruan): "quando o cliente gerar vídeo, tem que chegar palavras
 * motivacionais no push + e-mail toda vez, mesmo se ele fechar a tela".
 *
 * A geração já roda em background (fila no servidor), então o cliente pode
 * fechar a aba. Este serviço avisa quando o vídeo fica pronto: PUSH motivacional
 * + E-MAIL (canal garantido). Fail-open e idempotente (1x por pipeline, mesmo se
 * o job repetir).
 */
class VideoReadyNotifier
{
    /** Frases motivacionais — variam por pipeline pra não repetir sempre. */
    private const FRASES = [
        '🎬 Seu vídeo tá pronto! Agora é postar e vender. Bora?',
        '🚀 Vídeo pronto! Cada vídeo é uma chance de venda — posta esse agora.',
        '🔥 Saiu quentinho! Seu próximo vídeo que vende já está pronto.',
        '✨ Prontinho! Baixa, posta no TikTok e deixa a venda acontecer.',
        '💪 Mais um vídeo pronto! Consistência vira dinheiro. Segue postando!',
        '🎯 Vídeo na mão! Quem posta todo dia vende todo dia. Vai com esse.',
    ];

    public static function notify($pipeline): void
    {
        try {
            if (empty($pipeline->user_id) || empty($pipeline->output_url)) {
                return;
            }
            // idempotência: 1 aviso por pipeline (job tem tries=3)
            if (! Cache::add('vid_notif_' . $pipeline->id, 1, 86400)) {
                return;
            }

            $user = User::find($pipeline->user_id);
            if (! $user) {
                return;
            }

            $frase = self::FRASES[$pipeline->id % count(self::FRASES)];
            $productName = null;
            try {
                $p = is_array($pipeline->payloads) ? $pipeline->payloads : (array) json_decode($pipeline->payloads ?? '[]', true);
                $productName = $p['produto_nome'] ?? ($p['product_name'] ?? null);
            } catch (\Throwable $e) { /* ignore */ }

            self::sendPush($user->id, $frase, $productName);
            self::sendEmail($user, $pipeline->output_url, $productName, $frase);
        } catch (\Throwable $e) {
            Log::warning('[VideoReadyNotifier] falhou (não-fatal)', ['pipe' => $pipeline->id ?? null, 'err' => $e->getMessage()]);
        }
    }

    private static function sendPush(int $userId, string $frase, ?string $productName): void
    {
        try {
            if (! config('services.vapid.public') || ! config('services.vapid.private')) {
                return;
            }
            $subs = DB::table('push_subscriptions')->where('user_id', $userId)->get();
            if ($subs->isEmpty()) {
                return;
            }
            $wp = new WebPush([
                'VAPID' => [
                    'subject'    => config('services.vapid.subject'),
                    'publicKey'  => config('services.vapid.public'),
                    'privateKey' => config('services.vapid.private'),
                ],
            ], [], 10);
            $wp->setReuseVAPIDHeaders(true);

            $payload = json_encode([
                'title' => '🎬 Vídeo pronto!',
                'body'  => $frase,
                'url'   => '/meus-videos',
            ], JSON_UNESCAPED_UNICODE);

            foreach ($subs as $s) {
                $wp->queueNotification(
                    Subscription::create(['endpoint' => $s->endpoint, 'keys' => ['p256dh' => $s->p256dh, 'auth' => $s->auth]]),
                    $payload
                );
            }
            foreach ($wp->flush(25) as $report) {
                if ($report->isSuccess()) {
                    continue;
                }
                $status = optional($report->getResponse())->getStatusCode();
                if (in_array($status, [403, 404, 410], true)) {
                    DB::table('push_subscriptions')->where('endpoint_hash', hash('sha256', $report->getEndpoint()))->delete();
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[VideoReadyNotifier] push falhou', ['user' => $userId, 'err' => $e->getMessage()]);
        }
    }

    private static function sendEmail(User $user, string $videoUrl, ?string $productName, string $frase): void
    {
        try {
            if (empty($user->email) || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                return;
            }
            Mail::to($user->email)->queue(new VideoReadyMail($user, $videoUrl, $productName, $frase));
        } catch (\Throwable $e) {
            Log::warning('[VideoReadyNotifier] email falhou', ['user' => $user->id, 'err' => $e->getMessage()]);
        }
    }
}
