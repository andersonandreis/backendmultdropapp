<?php

namespace App\Jobs;

use App\Enums\EmailStatus;
use App\Mail\WelcomeMail;
use App\Models\EmailLog;
use App\Models\User;
use App\Services\Mail\SmtpConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SendWelcomeEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Numero maximo de tentativas antes de marcar como falha definitiva.
     */
    public int $tries = 3;

    /**
     * Backoff em segundos entre as tentativas: 30s, 2min, 5min.
     *
     * @var array<int>
     */
    public array $backoff = [30, 120, 300];

    /**
     * SEL-401: usuario apagado entre o enfileiramento e a execucao fazia o job
     * estourar ModelNotFoundException e entupir failed_jobs. Nao ha e-mail de
     * boas-vindas pra mandar pra quem nao existe mais — descarta em silencio.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public User $user) {}

    public function handle(): void
    {
        // MUL-221: servico desativado — WLs recebiam boas-vindas com remetente
        // hubai.io. Reativar via WELCOME_MAIL_ENABLED=true quando repensado.
        if (! config('mail.welcome_enabled')) {
            return;
        }

        // Idempotencia: nao enviar se ja existe registro com status final positivo
        $alreadySent = EmailLog::where('user_id', $this->user->id)
            ->where('email_type', 'welcome')
            ->whereIn('status', [
                EmailStatus::Sent->value,
                EmailStatus::Opened->value,
                EmailStatus::Clicked->value,
            ])
            ->exists();

        if ($alreadySent) {
            return;
        }

        $log = EmailLog::create([
            'user_id'    => $this->user->id,
            'email_type' => 'welcome',
            'to_email'   => $this->user->email,
            'token'      => Str::uuid()->toString(),
            'status'     => EmailStatus::Queued,
        ]);

        try {
            // Aplica SMTP dinamico configurado no painel (se houver)
            app(SmtpConfigService::class)->apply();

            Mail::to($this->user->email)->send(new WelcomeMail($this->user, $log));

            $log->update([
                'status'  => EmailStatus::Sent,
                'sent_at' => now(),
            ]);
        } catch (\Exception $e) {
            $log->update([
                'status'        => EmailStatus::Failed,
                'failed_reason' => Str::limit($e->getMessage(), 500),
            ]);

            // Re-throw para acionar o mecanismo de retry da fila
            throw $e;
        }
    }
}
