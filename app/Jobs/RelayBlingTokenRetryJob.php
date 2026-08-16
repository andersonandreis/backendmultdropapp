<?php

namespace App\Jobs;

use App\Models\BridgeRelayQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * RelayBlingTokenRetryJob
 *
 * Faz POST HMAC-assinado dos tokens Bling para a WL de origem.
 * Em caso de falha, usa backoff exponencial (Laravel Queue retries).
 * Ao esgotar todas as tentativas (failed()), persiste em bridge_relay_queue
 * para auditoria - mesmo padrao NOV-046-B (ML/Shopee relays).
 *
 * NOV-046-G: substitui o Log::error silencioso no catch do
 * OAuthController::relayBlingTokenToWL, garantindo retry automatico.
 */
class RelayBlingTokenRetryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 5;
    public int $timeout = 30;

    public function backoff(): array
    {
        return [30, 300, 1800, 7200, 7200];
    }

    public function __construct(
        private readonly string  $tenant,
        private readonly int     $clientId,
        private readonly int     $supplierId,
        private readonly array   $tokenData,
        private readonly string  $accountName,
        private readonly string  $secret,
        private readonly string  $endpoint,
        private readonly ?string $accountType = null, // NOV-153: 'supplier_erp' | null
    ) {}

    public function handle(): void
    {
        if (empty($this->endpoint)) {
            $this->fail(new \RuntimeException('endpoint vazio para tenant ' . $this->tenant));
            return;
        }

        if (empty($this->secret)) {
            $this->fail(new \RuntimeException('BLING_RELAY_HMAC_SECRET nao configurado'));
            return;
        }

        $payload = [
            'tenant'        => $this->tenant,
            'client_id'     => $this->clientId,
            'supplier_id'   => $this->supplierId,
            'account_type'  => $this->accountType, // NOV-153
            'access_token'  => (string) ($this->tokenData['access_token']  ?? ''),
            'refresh_token' => (string) ($this->tokenData['refresh_token'] ?? ''),
            'expires_in'    => (int)    ($this->tokenData['expires_in']    ?? 21600),
            'scope'         => (string) ($this->tokenData['scope']         ?? ''),
            'account_name'  => $this->accountName,
            'relayed_by'    => 'api.hubai.io',
        ];

        $body = json_encode($payload);
        $sig  = hash_hmac('sha256', $body, $this->secret);

        Log::info('[RelayBlingTokenRetryJob] Tentativa POST tokens pra WL', [
            'tenant'    => $this->tenant,
            'attempt'   => $this->attempts(),
            'endpoint'  => $this->endpoint,
            'client_id' => $this->clientId,
        ]);

        $response = Http::timeout(15)
            ->withHeaders([
                'X-HubAI-Bridge-Sig' => $sig,
                'Content-Type'       => 'application/json',
            ])
            ->withBody($body, 'application/json')
            ->post($this->endpoint);

        if ($response->failed()) {
            $status  = $response->status();
            $excerpt = substr($response->body(), 0, 300);
            $msg = "[RelayBlingTokenRetryJob] WL retornou {$status}: {$excerpt}";
            Log::warning($msg, ['tenant' => $this->tenant, 'attempt' => $this->attempts()]);
            throw new \RuntimeException($msg);
        }

        Log::channel('marketplace')->info('[RelayBlingTokenRetryJob] Tokens enviados com sucesso', [
            'tenant'      => $this->tenant,
            'client_id'   => $this->clientId,
            'status'      => $response->status(),
            'wl_response' => $response->json(),
        ]);
    }

    /**
     * Chamado pelo Laravel Queue apos esgotadas todas as tentativas.
     * Persiste na bridge_relay_queue para auditoria e retry manual (NOV-046-B).
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('[RelayBlingTokenRetryJob] Esgotadas tentativas - gravando em bridge_relay_queue', [
            'tenant'    => $this->tenant,
            'client_id' => $this->clientId,
            'error'     => $exception->getMessage(),
        ]);

        try {
            BridgeRelayQueue::create([
                'platform'       => 'bling',
                'event_type'     => 'token_relay',
                'order_id'       => null,
                'legacy_user_id' => null,
                'payload'        => array_merge($this->tokenData, [
                    '_tenant'       => $this->tenant,
                    '_client_id'    => $this->clientId,
                    '_supplier_id'  => $this->supplierId,
                    '_account_name' => $this->accountName,
                    '_endpoint'     => $this->endpoint,
                    '_last_error'   => $exception->getMessage(),
                ]),
                'attempts'       => BridgeRelayQueue::MAX_ATTEMPTS,
                'next_try_at'    => now()->addHours(2),
                'last_error'     => substr($exception->getMessage(), 0, 500),
                'status'         => 'failed_max',
            ]);
        } catch (\Throwable $e) {
            Log::critical('[RelayBlingTokenRetryJob] Falha ao gravar em bridge_relay_queue', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
