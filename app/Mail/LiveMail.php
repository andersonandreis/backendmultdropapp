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
class LiveMail extends Mailable implements ShouldQueue {
    use Queueable, SerializesModels;
    public function __construct(public User $user, public string $liveUrl) {}
    public function envelope(): Envelope { return new Envelope(from: new Address(config("mail.from.address"), "Seller Global"), subject: "🔴 ESTAMOS AO VIVO AGORA - entre na live"); }
    public function content(): Content { return new Content(view: "emails.live"); }
    public function attachments(): array { return []; }
}
