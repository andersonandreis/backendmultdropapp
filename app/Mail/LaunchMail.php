<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * SEL (08/08 Ruan): e-mail de LANÇAMENTO pra base toda — leva o cliente pra
 * seller.global assistir a VSL e comprar. Curto, impactante, marca Seller Global.
 */
class LaunchMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $url = 'https://seller.global',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), 'Seller Global'),
            subject: '🎬 Gere seu vídeo que vende agora (só hoje)',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.launch');
    }

    public function attachments(): array
    {
        return [];
    }
}
