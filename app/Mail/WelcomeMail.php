<?php

namespace App\Mail;

use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class WelcomeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public EmailLog $emailLog,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('mail.from.address'),
                config('mail.from.name'),
            ),
            replyTo: [new Address('gabriel@seller.global', 'Gabriel . Seller.Global')],
            subject: 'Seus dados de acesso ao Seller.Global',
        );
    }

    /**
     * SEL-184 antispam: List-Unsubscribe (Gmail/Yahoo exigem pra bulk sender).
     * Message-ID unico ajuda tracking + reputacao.
     */
    public function headers(): Headers
    {
        $unsubUrl = 'https://seller.global/unsubscribe?email='.urlencode($this->user->email).'&token='.$this->emailLog->token;
        return new Headers(
            messageId: 'welcome-'.$this->emailLog->id.'-'.$this->emailLog->token.'@seller.global',
            text: [
                'List-Unsubscribe' => '<'.$unsubUrl.'>, <mailto:unsubscribe@seller.global?subject=unsub-'.$this->emailLog->token.'>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                'X-Entity-Ref-ID' => 'sg-welcome-'.$this->emailLog->id,
                'X-Mailer' => 'Seller.Global Transactional',
            ],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.welcome',
            text: 'emails.welcome-text',
        );
    }
}
