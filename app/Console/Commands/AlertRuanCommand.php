<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

/**
 * SEL-noite (07/08) — canal de alerta CRÍTICO pro Ruan no telefone via web push.
 * Uso: php artisan alert:ruan "Titulo" "Mensagem" [url] [--email=ruanipanema2@gmail.com]
 * Chamável por cron/jobs em eventos criticos (e-mail caiu, motor down, credito baixo...).
 */
class AlertRuanCommand extends Command
{
    protected $signature = "alert:ruan {title} {body} {url=/notifications} {--email=ruanipanema2@gmail.com}";
    protected $description = "Dispara um push de alerta critico pro telefone do Ruan";

    public function handle(): int
    {
        $email = $this->option("email");
        $user  = DB::table("users")->where("email", $email)->first();
        if (!$user) { $this->error("user nao encontrado: $email"); return 1; }

        $subs = DB::table("push_subscriptions")
            ->where("user_id", $user->id)->orWhere("client_id", $user->id)->get();
        if ($subs->isEmpty()) { $this->warn("sem inscricoes de push pra $email"); return 1; }

        $webPush = new WebPush(["VAPID" => [
            "subject"    => config("services.vapid.subject"),
            "publicKey"  => config("services.vapid.public"),
            "privateKey" => config("services.vapid.private"),
        ]]);
        $payload = json_encode([
            "title" => "🚨 " . $this->argument("title"),
            "body"  => $this->argument("body"),
            "url"   => $this->argument("url"),
            "type"  => "critical",
        ], JSON_UNESCAPED_UNICODE);

        foreach ($subs as $s) {
            $webPush->queueNotification(Subscription::create([
                "endpoint" => $s->endpoint,
                "keys"     => ["p256dh" => $s->p256dh, "auth" => $s->auth],
            ]), $payload);
        }

        $sent = 0; $failed = 0;
        foreach ($webPush->flush() as $r) {
            if ($r->isSuccess()) { $sent++; continue; }
            $failed++;
            $st = optional($r->getResponse())->getStatusCode();
            // limpa inscricao morta
            if (in_array($st, [404, 410], true)) {
                DB::table("push_subscriptions")->where("endpoint_hash", hash("sha256", $r->getEndpoint()))->delete();
            }
            Log::warning("[alert:ruan] fail", ["status" => $st, "reason" => $r->getReason()]);
        }
        $this->info("push enviado: $sent ok, $failed falhou");
        return 0;
    }
}
