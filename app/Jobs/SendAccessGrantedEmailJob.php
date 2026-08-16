<?php

namespace App\Jobs;

use App\Enums\EmailStatus;
use App\Mail\SellerWelcomeMail;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Mail\SmtpConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * SEL-ACESSO (12/08): e-mail de ACESSO LIBERADO — o que faltava pra fechar o
 * buraco "o cliente paga e nao recebe acesso".
 *
 * POR QUE ESTE JOB EXISTE (o diagnostico que estava errado no briefing):
 * o UserObserver dispara o welcome so na CRIACAO do usuario, mas o e-mail de
 * compra nunca dependeu dele — quem manda "plano ativado" e o SellerWelcomeMail,
 * disparado em DOIS lugares:
 *   1) CheckoutController::finalizeCheckout, mas SO quando $gatewayStatus==='paid';
 *   2) PagarmeWebhookController::onChargePaid.
 *
 * Acontece que no PIX o checkout devolve 'pending' (o cliente ainda nem pagou),
 * entao (1) nunca dispara; e o webhook (2) NAO recebe trafego real nesta conta
 * — esta escrito no proprio codigo (ReconcilePagarmePixSubscriptions, e o
 * comentario em PagarmeWebhookController::onPlanUpgradePaid). Quem de fato
 * ativa PIX em producao sao os DOIS reconciliadores por cron, e NENHUM dos dois
 * manda e-mail pro cliente: so chamam SalePushNotifier, que e push pro Ruan.
 *
 * Resultado: TODO cliente que paga por PIX vira 'active' no banco e nao recebe
 * absolutamente nada. Foi o caso do cliente 1334 (user 1261).
 *
 * Este job e o unico ponto de saida do e-mail de acesso, chamavel de qualquer
 * caminho de ativacao (webhook, reconciliador, liberacao manual), e e
 * IDEMPOTENTE: dois caminhos ativando o mesmo cliente mandam UM e-mail so.
 *
 * INTERRUPTOR (fica no banco, NAO em env/config — com config:cache o env()
 * volta vazio e o interruptor nao funciona; foi o que derrubou INF-067 e
 * SEL-411 neste mesmo servidor):
 *   Ligar:    UPDATE settings SET value='1' WHERE `group`='billing' AND `key`='access_granted_mail_enabled';
 *   Desligar: UPDATE settings SET value='0' WHERE `group`='billing' AND `key`='access_granted_mail_enabled';
 * Nasce DESLIGADO de proposito: mandar e-mail de verdade pra cliente e decisao
 * do dono, nao do agente.
 */
class SendAccessGrantedEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int> */
    public array $backoff = [30, 120, 300];

    public bool $deleteWhenMissingModels = true;

    public const TIPO = 'access_granted';

    /**
     * @param int          $subscriptionId assinatura ja ATIVA que gerou o acesso
     * @param string|null  $overrideEmail  SO pra teste interno: manda pra este
     *                                     endereco em vez do cliente e grava o
     *                                     log como '<tipo>_test', pra nao sujar
     *                                     a idempotencia do envio real.
     */
    public function __construct(
        public int $subscriptionId,
        public ?string $overrideEmail = null,
    ) {}

    public function handle(): void
    {
        $ehTeste = filled($this->overrideEmail);

        // O interruptor vale pro envio REAL. Teste dirigido a um endereco nosso
        // passa direto — nenhum cliente e alcancado nesse caminho.
        if (! $ehTeste && ! self::habilitado()) {
            Log::info('[SEL-ACESSO] envio desligado pelo interruptor; nada enviado', [
                'subscription_id' => $this->subscriptionId,
            ]);
            return;
        }

        $sub = Subscription::find($this->subscriptionId);
        if (! $sub) {
            Log::warning('[SEL-ACESSO] assinatura sumiu antes do envio', ['subscription_id' => $this->subscriptionId]);
            return;
        }

        // So anuncia acesso que existe de verdade.
        if ($sub->status !== 'active') {
            Log::info('[SEL-ACESSO] assinatura nao esta ativa; e-mail nao enviado', [
                'subscription_id' => $sub->id, 'status' => $sub->status,
            ]);
            return;
        }

        $client = Client::find($sub->client_id);
        $user   = $client ? User::find($client->user_id) : null;
        $plan   = $sub->plan_id ? Plan::find($sub->plan_id) : null;

        if (! $client || ! $user || ! $plan) {
            Log::warning('[SEL-ACESSO] faltou client/user/plan; e-mail nao enviado', [
                'subscription_id' => $sub->id,
                'tem_client' => (bool) $client, 'tem_user' => (bool) $user, 'tem_plan' => (bool) $plan,
            ]);
            return;
        }

        $tipo    = $ehTeste ? self::TIPO . '_test' : self::TIPO;
        $destino = $ehTeste ? $this->overrideEmail : $user->email;

        if (! filled($destino)) {
            return;
        }

        // Idempotencia: o mesmo cliente nao recebe dois "acesso liberado".
        // Vale so pro envio real; teste sempre roda pra dar pra verificar.
        if (! $ehTeste) {
            $jaEnviado = EmailLog::where('user_id', $user->id)
                ->where('email_type', $tipo)
                ->whereIn('status', [
                    EmailStatus::Sent->value,
                    EmailStatus::Delivered->value,
                    EmailStatus::Opened->value,
                    EmailStatus::Clicked->value,
                ])
                ->exists();

            if ($jaEnviado) {
                Log::info('[SEL-ACESSO] cliente ja recebeu acesso liberado; nao reenvia', [
                    'user_id' => $user->id, 'subscription_id' => $sub->id,
                ]);
                return;
            }
        }

        $log = EmailLog::create([
            'user_id'    => $user->id,
            'email_type' => $tipo,
            'to_email'   => $destino,
            'token'      => Str::uuid()->toString(),
            'status'     => EmailStatus::Queued,
        ]);

        try {
            app(SmtpConfigService::class)->apply();

            // Senha inicial so entra quando ela AINDA e a padrao de cadastro —
            // se o cliente ja trocou, mandar '123456' no e-mail seria mentira
            // (e um vazamento de instrucao errada).
            $senhaInicial = \Illuminate\Support\Facades\Hash::check('123456', (string) $user->password)
                ? '123456'
                : null;

            $grupoWhatsapp = DB::table('whatsapp_group_configs')->where('id', 1)->value('group_url') ?: null;

            Mail::to($destino)->send(new SellerWelcomeMail(
                $user, $client, $plan, $senhaInicial, $grupoWhatsapp
            ));

            $log->update(['status' => EmailStatus::Sent, 'sent_at' => now()]);

            Log::info('[SEL-ACESSO] e-mail de acesso liberado enviado', [
                'user_id' => $user->id, 'subscription_id' => $sub->id,
                'to' => $destino, 'teste' => $ehTeste, 'email_log_id' => $log->id,
            ]);
        } catch (\Throwable $e) {
            $log->update([
                'status'        => EmailStatus::Failed,
                'failed_reason' => Str::limit($e->getMessage(), 500),
            ]);
            Log::error('[SEL-ACESSO] falha ao enviar acesso liberado', [
                'user_id' => $user->id, 'subscription_id' => $sub->id, 'erro' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /** Interruptor no banco. Consultado a cada execucao, de proposito. */
    public static function habilitado(): bool
    {
        try {
            $v = DB::table('settings')
                ->where('group', 'billing')
                ->where('key', 'access_granted_mail_enabled')
                ->value('value');

            return in_array((string) $v, ['1', 'true', 'on', 'sim'], true);
        } catch (\Throwable $e) {
            Log::warning('[SEL-ACESSO] nao consegui ler o interruptor', ['erro' => $e->getMessage()]);
            return false;
        }
    }
}
