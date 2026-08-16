<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MUL-190: POST HMAC-assinado da config de importacao Bling pro backend gemeo
 * (hub->WL ou WL->hub). Mesmo transporte do RelayBlingTokenRetryJob (MUL-188).
 */
class PushBlingConfigSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 5;
    public int $timeout = 30;

    public function backoff(): array
    {
        return [30, 300, 1800, 7200, 7200];
    }

    public function __construct(
        private readonly string $sourceTenant,
        private readonly string $targetTenant,
        private readonly int    $clientId,
        private readonly ?int   $supplierId,
        private readonly array  $config,
        private readonly string $endpoint,
        private readonly string $secret,
    ) {}

    public function handle(): void
    {
        $payload = [
            'type'                 => 'bling_config_sync',
            'tenant'               => $this->targetTenant,
            'source'               => $this->sourceTenant,
            'client_id'            => $this->clientId,
            'supplier_id'          => $this->supplierId,
            'allowed_integrations' => $this->config['allowed_integrations'] ?? null,
            'data_inicial_import'  => $this->config['data_inicial_import'] ?? null,
            'relayed_by'           => (string) config('app.url'),
        ];

        $body = json_encode($payload);
        $sig  = hash_hmac('sha256', $body, $this->secret);

        $response = Http::timeout(15)
            ->withHeaders([
                'X-HubAI-Bridge-Sig' => $sig,
                'Content-Type'       => 'application/json',
            ])
            ->withBody($body, 'application/json')
            ->post($this->endpoint);

        if ($response->failed()) {
            $msg = sprintf(
                '[PushBlingConfigSyncJob] %s retornou %d: %s',
                $this->targetTenant,
                $response->status(),
                substr($response->body(), 0, 300)
            );
            Log::warning($msg, ['attempt' => $this->attempts()]);
            throw new \RuntimeException($msg);
        }

        Log::channel('marketplace')->info('[PushBlingConfigSyncJob] config sincronizada', [
            'source'    => $this->sourceTenant,
            'target'    => $this->targetTenant,
            'client_id' => $this->clientId,
            'response'  => $response->json(),
        ]);
    }
}
