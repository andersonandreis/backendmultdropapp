<?php

namespace App\Jobs;

use App\Services\WebhookDispatcherService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * INF-034: recebe payload bruto do webhook em background pra liberar LSPHP em <5ms.
 *
 * Antes: controller síncrono → validateSignature + dedup + log + dispatch em 30-100ms
 *        por request. Com 25-30 webhooks/s ML = 46 lsphp presos = admin fora.
 *
 * Agora: controller faz apenas WebhookIngestJob::dispatch() + return 200.
 * Este job reconstrói a Request no worker e chama WebhookDispatcherService::process
 * com todos os checks originais (signature, dedup, guard user_id órfão FOR-053-G).
 *
 * Feature flag WEBHOOK_ASYNC_MODE=true no .env pra ligar. Se false, dispatcher
 * cai no fluxo síncrono legado (rollback instantâneo).
 */
class WebhookIngestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;
    public int $backoff = 30;

    public function __construct(
        public string $platform,
        public string $rawBody,
        public array $headers,
        public string $ip,
        public string $method = 'POST',
        public string $uri = '/webhooks'
    ) {
        // Fila dedicada high-prio pra webhooks nunca ficarem atrás de jobs pesados
        $this->onQueue('webhook-ingest');
    }

    public function handle(WebhookDispatcherService $dispatcher): void
    {
        // Reconstroi Request pra dispatcher::process rodar exatamente como antes
        $request = Request::create(
            $this->uri,
            $this->method,
            [],  // parameters
            [],  // cookies
            [],  // files
            array_merge(
                $this->headersToServer($this->headers),
                ['REMOTE_ADDR' => $this->ip]
            ),
            $this->rawBody
        );

        // Se payload é JSON, seta como input pra $request->input() funcionar
        if (str_starts_with(trim($this->rawBody), '{') || str_starts_with(trim($this->rawBody), '[')) {
            $decoded = json_decode($this->rawBody, true);
            if (is_array($decoded)) {
                $request->replace($decoded);
            }
        }

        // Preserva header x-signature e outros importantes
        foreach ($this->headers as $key => $val) {
            $request->headers->set($key, is_array($val) ? implode(',', $val) : $val);
        }

        try {
            // Chama o dispatcher em modo síncrono (evita loop infinito)
            $dispatcher->processSync($this->platform, $request);
        } catch (\Throwable $e) {
            Log::error('[WebhookIngestJob] falha processando webhook', [
                'platform' => $this->platform,
                'ip'       => $this->ip,
                'error'    => $e->getMessage(),
            ]);
            throw $e; // deixa o retry_after tratar
        }
    }

    /**
     * Converte HTTP headers pra formato $_SERVER (HTTP_X_HEADER_NAME).
     */
    private function headersToServer(array $headers): array
    {
        $server = [];
        foreach ($headers as $key => $val) {
            $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
            $server[$serverKey] = is_array($val) ? implode(',', $val) : $val;
        }
        return $server;
    }
}
