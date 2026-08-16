<?php

namespace App\Services;

use App\Models\WebhookLog;
use App\Services\Integrations\Contracts\WebhookHandlerInterface;
use App\Services\Webhooks\BlingWebhookHandler;
use App\Services\Webhooks\LegacyMLRelayService;
use App\Services\Webhooks\MercadoLivreWebhookHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatcher central de webhooks.
 *
 * Responsavel por:
 * 1. Resolver o handler correto para a plataforma informada
 * 2. Validar a assinatura da requisicao
 * 3. Verificar deduplicacao via ProcessedWebhookId (HUB-131)
 * 4. Extrair topico, resource e user_id
 * 5. Despachar o job correto via handler
 * 6. Registrar o log na tabela webhook_logs
 * 7. Relay do evento ML para o legado (se conta for de usuario migrado) -- HUB-077
 */
class WebhookDispatcherService
{
    /**
     * Mapa de plataforma -> handler.
     * Para adicionar um novo marketplace, basta registrar aqui.
     *
     * @var array<string, class-string<WebhookHandlerInterface>>
     */
    protected array $handlers = [
        'mercadolivre' => MercadoLivreWebhookHandler::class,
        'bling'     => BlingWebhookHandler::class,
        // 'shopee'    => ShopeeWebhookHandler::class,
        // 'amazon'    => AmazonWebhookHandler::class,
    ];

    /**
     * INF-034: modo async. Recebe requisicao, enfileira WebhookIngestJob e
     * retorna 200 em <5ms. Worker "webhook-ingest" faz signature/dedup/log/dispatch.
     *
     * Feature flag WEBHOOK_ASYNC_MODE=true no .env. Se false, cai no processSync
     * (mesmo comportamento antigo — rollback instantaneo).
     */
    public function process(string $platform, Request $request): JsonResponse
    {
        if (! config('webhook.async_mode')) {
            return $this->processSync($platform, $request);
        }

        try {
            \App\Jobs\WebhookIngestJob::dispatch(
                $platform,
                $request->getContent(),
                collect($request->headers->all())->map(fn ($v) => is_array($v) && count($v) === 1 ? $v[0] : $v)->all(),
                $request->ip() ?? '0.0.0.0',
                $request->method(),
                $request->getRequestUri()
            );
        } catch (Throwable $e) {
            Log::error("[WebhookDispatcher] falha ao enfileirar WebhookIngestJob — caindo pra sync", [
                'platform' => $platform,
                'error'    => $e->getMessage(),
            ]);
            return $this->processSync($platform, $request);
        }

        return response()->json(['status' => 'ok', 'mode' => 'async']);
    }

    /**
     * Processa sincronamente (modo legado ou usado pelo WebhookIngestJob no worker).
     */
    public function processSync(string $platform, Request $request): JsonResponse
    {
        $handler = $this->resolveHandler($platform);

        if (! $handler) {
            Log::warning("[WebhookDispatcher] Plataforma nao suportada: {$platform}");
            return response()->json(['error' => 'Platform not supported'], 400);
        }

        // FOR-053-G: guard user_id órfão pra Mercado Livre.
        // Contas deletadas localmente mas ainda notificadas pelo ML (OAuth grant vivo
        // do lado deles) causavam flood. Um único user_id banido (2496758811) chegou
        // a gerar 25 webhooks/s sustentados por 21h. Cache 5min de ml_user_ids ativos
        // evita SELECT por webhook. Se user_id não bate, retorna 200 sem processar.
        if ($platform === 'mercadolivre') {
            $userId = $handler->extractUserId($request);
            if ($userId) {
                $activeIds = \Illuminate\Support\Facades\Cache::remember(
                    'ml_active_user_ids',
                    300, // 5 minutos
                    function () {
                        return \App\Models\MarketplaceAccount::where('platform', 'mercadolivre')
                            ->where('status', 'active')
                            ->whereNotNull('ml_user_id')
                            ->pluck('ml_user_id')
                            ->mapWithKeys(fn ($v) => [(string) $v => true])
                            ->all();
                    }
                );
                if (! isset($activeIds[(string) $userId])) {
                    // NOV-195: PEDIDO de seller órfão não pode ser descartado — clientes
                    // de WLs ainda no painel legado (DropKSR 100% legado, multdrop em
                    // migração) recebem webhooks de pedido neste app ML. Enfileira relay
                    // (INSERT barato, sem HTTP síncrono) e o RetryBridgeRelayJob entrega
                    // ao bridge do goolhub, que resolve o seller pelo ml user_id.
                    // Flood de items/estoque de órfão continua descartado (anti-flood).
                    $orphanTopic = (string) $handler->extractTopic($request);
                    if (in_array($orphanTopic, ['orders_v2', 'orders'], true)) {
                        app(LegacyMLRelayService::class)->enqueueOrphanOrder(
                            $orphanTopic,
                            (string) $handler->extractResource($request),
                            (string) $userId,
                            $request->all()
                        );
                        return response()->json(['status' => 'ok', 'relayed' => 'legacy_orphan_queued']);
                    }

                    // Log amostrado (1 em 500) pra evitar auto-flood do próprio log
                    if (random_int(1, 500) === 1) {
                        Log::info('[WebhookDispatcher] ML user_id órfão descartado (200 sem processar)', [
                            'user_id' => $userId,
                            'ip'      => $request->ip(),
                        ]);
                    }
                    return response()->json(['status' => 'ok', 'ignored' => 'unknown_user']);
                }
            }
        }

        // Valida assinatura antes de qualquer processamento
        if (! $handler->validateSignature($request)) {
            Log::warning("[WebhookDispatcher] Assinatura invalida [{$platform}]", [
                'ip'        => $request->ip(),
                'signature' => $request->header('x-signature'),
            ]);

            $this->log($platform, 'unknown', '', null, 'failed', $request->all(), 'Invalid signature');

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $topic    = $handler->extractTopic($request);
        $resource = $handler->extractResource($request);
        $userId   = $handler->extractUserId($request);

        // HUB-131: Deduplicacao por notification_id.
        // Handlers que implementam isDuplicate() verificam a tabela processed_webhook_ids.
        // Se o evento ja foi processado (marketplace reenviou apos timeout), retorna 200 sem processar.
        if (method_exists($handler, 'isDuplicate') && $handler->isDuplicate($request, $topic)) {
            $this->log($platform, $topic, $resource, $userId, 'processed', $request->all());
            return response()->json(['status' => 'ok', 'dedup' => true]);
        }

        Log::info("[WebhookDispatcher] [{$platform}] Notificacao recebida", compact('topic', 'resource', 'userId'));

        $logId = $this->log($platform, $topic, $resource, $userId, 'received', $request->all());

        try {
            $handler->dispatchJob($topic, $resource, $userId);

            $this->markProcessed($logId);
        } catch (Throwable $e) {
            Log::error("[WebhookDispatcher] [{$platform}] Erro ao despachar job", [
                'topic'     => $topic,
                'resource'  => $resource,
                'exception' => $e->getMessage(),
            ]);

            $this->markFailed($logId, $e->getMessage());
        }

        // HUB-077: relay do evento ML para o sistema legado (goolhub.io) se a conta
        // pertencer a um usuario migrado (legacy_id_login preenchido no Client).
        // Roda apos o dispatchJob para nao bloquear o loop principal.
        // Falhas no relay sao silenciosas -- o ML ja recebeu 200.
        if ($platform === 'mercadolivre') {
            app(LegacyMLRelayService::class)->relayIfLegacy(
                $topic,
                $resource,
                $userId,
                $request->all()
            );
        }

        // Retorna 200 imediatamente -- os jobs rodam em background
        return response()->json(['status' => 'ok']);
    }

    /**
     * Resolve a instancia do handler para a plataforma.
     */
    protected function resolveHandler(string $platform): ?WebhookHandlerInterface
    {
        $handlerClass = $this->handlers[$platform] ?? null;

        if (! $handlerClass) {
            return null;
        }

        return app($handlerClass);
    }

    /**
     * Registra a entrada no webhook_logs e retorna o ID gerado.
     */
    protected function log(
        string  $platform,
        string  $topic,
        string  $resource,
        ?string $userId,
        string  $status,
        array   $payload,
        ?string $errorMessage = null
    ): ?int {
        try {
            $log = WebhookLog::create([
                'platform'      => $platform,
                'topic'         => $topic,
                'resource'      => $resource,
                'user_id'       => $userId,
                'status'        => $status,
                'payload'       => $payload,
                'error_message' => $errorMessage,
                'processed_at'  => $status === 'processed' ? now() : null,
            ]);

            return $log->id;
        } catch (Throwable $e) {
            Log::error('[WebhookDispatcher] Falha ao registrar log', ['error' => $e->getMessage()]);
            return null;
        }
    }

    protected function markProcessed(?int $logId): void
    {
        if ($logId) {
            WebhookLog::where('id', $logId)->update([
                'status'       => 'processed',
                'processed_at' => now(),
            ]);
        }
    }

    protected function markFailed(?int $logId, string $errorMessage): void
    {
        if ($logId) {
            WebhookLog::where('id', $logId)->update([
                'status'        => 'failed',
                'error_message' => $errorMessage,
            ]);
        }
    }
}
