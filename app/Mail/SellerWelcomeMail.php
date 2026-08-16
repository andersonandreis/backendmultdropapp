<?php

namespace App\Mail;

use App\Models\Client;
use App\Models\Plan;
use App\Models\User;
use App\Support\BrandKit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * E-mail de "acesso liberado / plano ativado".
 *
 * SEL-EMAILTOKFY (12/08): passou a ser BIMARCA. A marca NAO e escolhida por
 * quem chama — e deduzida do PLANO aqui dentro, por App\Support\BrandKit.
 * Isso e de proposito: existem 5 pontos que disparam este Mailable
 * (SendAccessGrantedEmailJob, ProcessAsaasWebhookJob, CheckoutController,
 * PagarmeWebhookController, AdminController) e nenhum deles precisa saber de
 * marca — se a regra virasse parametro, bastaria UM caller esquecer pra um
 * cliente Tokfy receber e-mail do seller.global de novo.
 *
 * Plano 99/100/101 (Video IA) -> Tokfy: assunto, remetente (suporte@tokfy.io),
 * cores, links e assinatura da Tokfy, versao HTML + texto puro, zero mencao a
 * seller.global. Qualquer outro plano -> exatamente o e-mail de antes.
 */
class SellerWelcomeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @var array<string,mixed> configuracao da marca resolvida pelo plano */
    public array $brand;

    public function __construct(
        public User $user,
        public Client $client,
        public Plan $plan,
        public ?string $initialPassword = null,
        public ?string $whatsappGroupUrl = null,
    ) {
        // INF-030-MARCA (13/08): a marca REAL do cliente (gravada pelo
        // CheckoutController a partir do Origin/Referer no momento da compra)
        // tem prioridade sobre o palpite por plano — o palpite por plano
        // (BrandKit::forPlan) provou estar errado pra 73 clientes seller.global
        // que compraram o mesmo plano de video que a Tokfy usa (ver docblock
        // de BrandKit). So cai no palpite por plano quando o registro e
        // anterior a essa coluna existir (client->marca ainda null).
        $marcaDoCliente = $this->client->marca ?? null;
        $this->brand = BrandKit::isValid($marcaDoCliente)
            ? BrandKit::config($marcaDoCliente)
            : BrandKit::forPlan($this->plan);

        // Marca com remetente proprio sai por outro mailer (outra conta SMTP).
        // null = mailer padrao, comportamento identico ao de antes.
        if (! empty($this->brand['mailer'])) {
            $this->mailer = $this->brand['mailer'];
        }

        // Grupo de WhatsApp e comunidade do seller.global — nao convida cliente
        // Tokfy pra ele (mesma decisao do front: showWhatsappCommunity=false).
        if ($this->brand['id'] === BrandKit::TOKFY) {
            $this->whatsappGroupUrl = null;
        }
    }

    public function envelope(): Envelope
    {
        [$fromAddress, $fromName] = BrandKit::fromAddress($this->brand['id']);

        return new Envelope(
            from: new Address($fromAddress, $fromName),
            subject: BrandKit::accessSubject($this->brand['id'], (string) $this->plan->name),
        );
    }

    /**
     * SEL-EMAILTOKFY: definir $this->mailer no construtor NAO basta.
     * Illuminate\Mail\Mailer::sendMailable() faz `$mailable->mailer($this->name)`
     * e SOBRESCREVE a escolha da marca pelo mailer padrao — foi exatamente o que
     * aconteceu no 1o teste: o e-mail saiu com From Tokfy, mas autenticado no
     * SMTP como gabriel@seller.global (maillog: sasl_username=gabriel@seller.global).
     * Aqui a escolha da marca e restaurada no ultimo momento, valendo tanto pro
     * envio direto quanto pro que passa pela fila.
     *
     * @param  \Illuminate\Contracts\Mail\Factory|\Illuminate\Contracts\Mail\Mailer  $mailer
     */
    public function send($mailer)
    {
        $mailerDaMarca = $this->brand['mailer'] ?? null;

        if (! empty($mailerDaMarca)) {
            $this->mailer = $mailerDaMarca;
            $mailer = app(MailFactory::class)->mailer($mailerDaMarca);
        }

        return parent::send($mailer);
    }

    public function content(): Content
    {
        return new Content(
            view: $this->brand['view_html'],
            text: $this->brand['view_text'] ?: null,
            with: ['brand' => $this->brand],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
