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
 * SEL (08/08 Ruan): e-mail avisando o cliente que o vídeo ficou pronto — chega
 * TODA vez que ele gera um vídeo, mesmo que tenha fechado a tela. Canal
 * garantido (não depende de push). Marca nossa (Seller Global), zero menção a
 * motor externo.
 */
class VideoReadyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $videoUrl,
        public ?string $productName = null,
        public string $motiv = '',
        public string $appUrl = 'https://seller.global/meus-videos',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), 'Seller Global'),
            subject: '🎬 Seu vídeo tá pronto — bora vender!',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.video-ready');
    }

    public function attachments(): array
    {
        return [];
    }
}
