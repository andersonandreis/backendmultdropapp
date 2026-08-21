<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Order;
use App\Models\OrderLabelQueue;
use App\Services\ShippingLabelService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FetchShippingLabelJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * MUL-353: espacamento entre as 3 tentativas — 1min, 5min, 15min.
     * Sem backoff o retry era imediato: as 3 tentativas queimavam em segundos,
     * antes de a etiqueta ficar pronta do lado da Shopee. O motivo da falha fica
     * em orders.label_status_reason a cada tentativa, e o botao manual do painel
     * continua disponivel a qualquer momento.
     */
    public array $backoff = [60, 300, 900];

    /** Motivos padronizados (MES-046-A) — codigo => texto para frontend */
    public const REASON_MISSING_MARKETPLACE_ACCOUNT = 'missing_marketplace_account';
    public const REASON_AWAITING_MARKETPLACE        = 'awaiting_marketplace';
    public const REASON_PAYMENT_PENDING             = 'payment_pending';
    public const REASON_INVOICE_REQUIRED            = 'invoice_required';
    // FOR-053-D: reason especifico por tipo de documento do vendedor no ML
    public const REASON_INVOICE_REQUIRED_CPF        = 'invoice_required_cpf';  // DC-e
    public const REASON_INVOICE_REQUIRED_CNPJ       = 'invoice_required_cnpj'; // NF-e
    public const REASON_TOKEN_ERROR                 = 'token_error';
    public const REASON_FISCAL_DATA_MISSING         = 'fiscal_data_missing';
    // SEL-413: estados terminais — a etiqueta nao vai sair, e dizer "aguarde o
    // marketplace" nesses casos deixa o seller esperando para sempre.
    public const REASON_ALREADY_SHIPPED             = 'already_shipped';
    public const REASON_TRACKING_INVALID            = 'tracking_invalid';
    // SEL-413 (04/08): recusa seca do marketplace, sem motivo declarado.
    public const REASON_LABEL_UNAVAILABLE           = 'label_unavailable';

    public function uniqueId(): string
    {
        return (string) $this->orderId;
    }

    public function uniqueFor(): int
    {
        return 660; // INF-023: cron legacysync roda a cada 5min; uniqueFor > 5min previne loop de re-dispatch
    }

    public function __construct(
        protected int $orderId,
        protected ?string $trigger = 'webhook' // 'webhook', 'manual', 'fallback', 'order_created' (NOV-207: baixa antes do pagamento)
    ) {
        // NOV-150-A: fila dedicada label-fetch (4 workers); via onQueue() em vez de
        // public $queue property que conflita com Queueable trait no PHP 8.3.
        $this->onQueue('label-fetch');
    }

    public function handle(ShippingLabelService $labelService): void
    {
        // Deduplicacao: apenas um job por pedido por vez (lock de 300s = 5min).
        $lock = Cache::lock("fetch-label-{$this->orderId}", 300);
        if (! $lock->get()) {
            Log::info("[FetchLabel] Order #{$this->orderId} ja esta sendo processado (lock ativo), descartando.");
            return;
        }

        try {
            $order = Order::find($this->orderId);

            if (!$order) {
                return;
            }

            // =================================================================
            // MUL-451: em modo BRIDGE quem fala com o marketplace e o hub.
            //
            // A WL baixava etiqueta por conta propria mesmo com a plataforma em
            // bridge. Isso cria dois donos do mesmo dado: em 18/08/2026 esta WL
            // baixou a etiqueta do pedido 2000017999615042, o hub nao tinha, e o
            // espelho de volta apagou a de ca -- o painel do seller passou a dizer
            // "aguardando liberacao do marketplace" num pedido ja em transito.
            //
            // Nao precisa fallback local: o hub carimba label_url e o OrderObserver
            // de la dispara order.updated nesse campo, entao a etiqueta chega aqui
            // pelo fanout. Quem insiste com o marketplace e o CheckLabelAvailability
            // do hub. Aqui so registramos que estamos esperando, para a espera ficar
            // visivel na tela em vez de virar silencio.
            // =================================================================
            $instalacao = app(\App\Services\InstallationConfig::class);
            if ($order->source && $instalacao->usesBridge((string) $order->source)) {
                if (empty($order->label_url)) {
                    $order->forceFill(['label_status_reason' => 'aguardando_hub'])->saveQuietly();
                }
                Log::info('[FetchLabel] MUL-451: plataforma em bridge — quem baixa e o hub', [
                    'order_id' => $order->id,
                    'source'   => $order->source,
                    'trigger'  => $this->trigger,
                ]);

                return;
            }

            // MUL-091: pedido sem marketplace_account_id — legado importado sem conta associada.
            // Gravar motivo padronizado e descartar (MES-046-A).
            if (! $order->marketplace_account_id) {
                // MUL-288: pedido Bling nao usa marketplace_account_id — a conta vive em
                // erp_accounts (resolvida por supplier_id). Marcar "sem conta de marketplace"
                // e falso: dispara CTA "Conecte sua conta" no painel do seller para um pedido
                // cuja conta esta conectada. Etiqueta de Bling vem por outro caminho (MUL-248).
                if ($order->source !== 'bling') {
                    $this->setLabelReason($order, self::REASON_MISSING_MARKETPLACE_ACCOUNT);
                    // MUL-289: reagenda como o token_error ja faz (HUB-182). Sem isso o
                    // pedido ficava travado PARA SEMPRE mesmo depois do vinculo ser
                    // corrigido — nao havia retry nenhum neste ramo, so setLabelReason+return.
                    if ($order->created_at && $order->created_at->gt(now()->subDays(30))) {
                        self::dispatch($this->orderId, $this->trigger)->delay(now()->addMinutes(120));
                    }
                }
                return;
            }

            // HUB-182: conta needs_reauth/bloqueada — nao chamar API do marketplace
            // (evita spam de refresh/ERROR em conta morta). Reagenda com delay longo;
            // retoma sozinho quando o cliente reconectar. >30 dias nao reagenda (HUB-175).
            $account = $order->marketplaceAccount;
            if ($account && ($account->status === 'needs_reauth' || $account->sync_blocked_at)) {
                $this->setLabelReason($order, self::REASON_TOKEN_ERROR);
                if ($order->created_at && $order->created_at->gt(now()->subDays(30))) {
                    self::dispatch($this->orderId, $this->trigger)->delay(now()->addMinutes(120));
                }
                Log::info("[FetchLabel] Order #{$order->id} conta needs_reauth/bloqueada, pulando chamada de API", [
                    'account_id' => $account->id,
                ]);
                return;
            }

            // Guard: pedido muito antigo (>90 dias) com trigger legacysync
            if ($this->trigger === 'legacysync' && $order->created_at && $order->created_at->lt(now()->subDays(90))) {
                Log::info("[FetchLabel] Order #{$order->id} tem mais de 90 dias, pulando via legacysync");
                return;
            }

            // Gate 1: ja tem etiqueta local
            if ($order->label_url
                && !str_contains($order->label_url, 'mock')
                && !str_contains($order->label_url, 'sistemagrupoonline')
                && !str_contains($order->label_url, 'goolhub.io')) {
                // MUL-379: sair daqui e correto para a ETIQUETA (ja temos), mas este e
                // tambem o estado em que a logistica ja existe — e era exatamente aqui
                // que a transportadora se perdia. Completa antes de sair.
                $this->completarLogistica($order, $labelService);
                Log::info("[FetchLabel] Order #{$order->id} ja tem etiqueta local, ignorando");
                return;
            }

            // MUL-242: etiqueta baixa ANTES do pagamento (fluxo NOV-207: etiqueta -> pago -> impressa).
            // Gates de pagamento removidos; pedido cancelado nao emite etiqueta.
            if ($order->canonical_status === 'cancelled' || strtolower((string) $order->status) === 'cancelled') {
                Log::info("[FetchLabel] Order #{$order->id} cancelado, ignorando");
                return;
            }

            try {
                $result = $labelService->comTrigger($this->trigger)->checkLabelStatus($order);

                if ($result['ready']) {
                    // Etiqueta baixada com sucesso — limpar motivo de erro
                    $order->update([
                        'order_processing_status' => 'awaiting_dispatch',
                        'label_status_reason'     => null,
                        'label_error_at'          => null,
                    ]);

                    $this->completarLogistica($order, $labelService);

                    OrderLabelQueue::where('order_id', $order->id)
                        ->update(['status' => 'available', 'error_log' => null]);

                    Log::info("[FetchLabel] Etiqueta baixada via {$this->trigger}", [
                        'order_id'  => $order->id,
                        'label_url' => $result['label_url'],
                    ]);

                    // MUL-242: propagar etiqueta pros WLs (sem isso o gate NOV-207
                    // espelhado no frontend nunca libera o botao Pagar no WL)
                    \App\Jobs\FanoutOrderWebhookJob::dispatch($order->id, 'order.updated', ['action' => 'label_fetch']);
                } else {
                    // Gravar motivo padronizado (MES-046-A).
                    // SEL-413: quando o service ja sabe o codigo exato, usa ele. O
                    // mapReasonToCode e adivinhacao por palavra-chave em texto livre e
                    // so deve valer como ultimo recurso.
                    $reasonCode = $result['reason_code'] ?? $this->mapReasonToCode($result['reason'] ?? '');

                    // MUL-427: o marketplace recusou o documento, mas seller com Bling
                    // conectado costuma ter a etiqueta la (o proprio Bling recebe o AWB
                    // pela integracao dele). Mesmo desfecho do fluxo normal + anotacao
                    // de metodo alternativo no pedido. Receita provada manualmente na
                    // MUL-426 (21/08/2026, 3 etiquetas reais com a Shopee recusando).
                    // MUL-427b: a Shopee tem uma classe de pedidos que fica HORAS em
                    // "document not yet ready" na Open API enquanto o Seller Center e o
                    // Bling ja imprimem (medido 21/08: logistics READY + create recusando
                    // por 2h). Preso em awaiting_marketplace alem do razoavel tambem vai
                    // pro fallback — o transitorio real se resolve em minutos.
                    $refEspera = $order->wallet_paid_at ?: $order->created_at;
                    $presoHaMuito = $reasonCode === 'awaiting_marketplace'
                        && $refEspera
                        && \Illuminate\Support\Carbon::parse($refEspera)->lt(now()->subMinutes(45));

                    if (in_array($reasonCode, ['tracking_invalid', 'label_unavailable'], true) || $presoHaMuito) {
                        // MUL-454: antes do fallback de etiqueta, atacar a CAUSA — a NF do
                        // seller (o Bling nao transmitiu a invoice a Shopee e/ou nunca
                        // organizou o envio; ver MUL-429). Se a cadeia AGIU, o caminho
                        // primario resolve: retry curto e sai. Se as tentativas esgotaram,
                        // o alerta ja foi emitido la dentro e a fila encerrada.
                        $nfe = app(\App\Services\Invoices\SellerNfeSync::class)->garantir($order);
                        if (! empty($nfe['acted'])) {
                            $this->setLabelReason($order, 'awaiting_marketplace');
                            self::dispatch($this->orderId, $this->trigger)->delay(now()->addMinutes(5));
                            Log::info('[FetchLabel] Cadeia NF do seller agiu (MUL-454)', [
                                'order_id' => $order->id, 'state' => $nfe['state'],
                            ]);
                            return;
                        }
                        if (! empty($nfe['exhausted'])) {
                            return;
                        }
                        $alt = app(\App\Services\Labels\BlingSellerLabelFallback::class)->tentar($order);
                        if ($alt && ! empty($alt['ready'])) {
                            $order->update([
                                'order_processing_status' => 'awaiting_dispatch',
                                'label_status_reason'     => null,
                                'label_error_at'          => null,
                            ]);

                            OrderLabelQueue::where('order_id', $order->id)
                                ->update(['status' => 'available', 'error_log' => 'MUL-427: etiqueta via Bling do seller']);

                            Log::info('[FetchLabel] Etiqueta via Bling do seller (MUL-427)', [
                                'order_id'  => $order->id,
                                'label_url' => $alt['label_url'],
                            ]);

                            \App\Jobs\FanoutOrderWebhookJob::dispatch($order->id, 'order.updated', ['action' => 'label_fetch']);
                            return;
                        }
                    }

                    $this->setLabelReason($order, $reasonCode);

                    // MUL-354: estado terminal encerra o retry aqui.
                    // O ShippingLabelService marca skip_permanently em 7 pontos (ja despachado,
                    // rastreio invalidado, recusa seca, pedido inexistente) e NINGUEM lia. Sem
                    // retry_in_minutes no retorno, o default de 10 min reagendava assim mesmo:
                    // medido em 08/08, 481 pedidos ja despachados consumiram 1.216 chamadas de
                    // create, e dois deles bateram na API 13 vezes cada.
                    if (! empty($result['skip_permanently'])) {
                        OrderLabelQueue::where('order_id', $order->id)
                            ->update(['status' => 'failed', 'error_log' => $reasonCode]);

                        Log::warning('[FetchLabel] Estado terminal — retry encerrado', [
                            'order_id' => $order->id,
                            'reason'   => $reasonCode,
                            'trigger'  => $this->trigger,
                        ]);
                        return;
                    }

                    // FOR-043: retry automatico
                    // HUB-175: pedido >30 dias nunca reagenda — order_sn morto no marketplace loopava a cada 5min
                    $retryMin = (int) ($result['retry_in_minutes'] ?? 10);
                    if ($order->created_at && $order->created_at->lt(now()->subDays(30))) {
                        $retryMin = 0;
                        Log::info("[FetchLabel] Order #{$order->id} >30 dias sem etiqueta, retry automatico encerrado");
                    }
                    if ($retryMin > 0 && $retryMin <= 120) {
                        self::dispatch($this->orderId, $this->trigger)
                            ->delay(now()->addMinutes($retryMin));
                    }
                    Log::info("[FetchLabel] Etiqueta nao disponivel ainda, reagendado", [
                        'order_id'  => $order->id,
                        'reason'    => $result['reason'] ?? 'unknown',
                        'trigger'   => $this->trigger,
                        'retry_min' => $retryMin,
                    ]);
                }
            } catch (\Exception $e) {
                $this->setLabelReason($order, self::REASON_TOKEN_ERROR);
                Log::error("[FetchLabel] Erro ao buscar etiqueta", [
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                    'trigger'  => $this->trigger,
                ]);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Grava o motivo padronizado em orders.label_status_reason (MES-046-A).
     * Nunca lanca excecao — falha silenciosa com log.
     */
    /**
     * MUL-379 — completa transportadora e rastreio quando a etiqueta existe.
     *
     * QUANDO a transportadora aparece: no momento em que a logistica e arranjada, que e o
     * mesmo momento em que a etiqueta passa a existir. Esse evento ja e recebido e
     * processado — a Shopee empurra o code 4 (tracking_update), o ShopeeWebhookController
     * despacha este job, e ML/Bling chegam aqui por outros gatilhos. Entao nao ha processo
     * novo nem coletor por marketplace: o preenchimento mora nos dois pontos deste job em
     * que sabemos que ha etiqueta (Gate 1 e o ramo ready), e a leitura por canal fica no
     * ShippingLabelService::logisticaDoMarketplace, que ja fala com cada marketplace.
     *
     * Nunca sobrescreve valor existente.
     */
    private function completarLogistica(Order $order, ShippingLabelService $labelService): void
    {
        if (! empty($order->carrier_name) && ! empty($order->tracking_number)) {
            return;
        }

        $logistica = $labelService->logisticaDoMarketplace($order);
        if ($logistica === []) {
            return;
        }

        $completar = [];
        if (empty($order->carrier_name) && ! empty($logistica['carrier'])) {
            $completar['carrier_name'] = $logistica['carrier'];
        }
        if (empty($order->tracking_number) && ! empty($logistica['tracking'])) {
            $completar['tracking_number'] = $logistica['tracking'];
        }
        if ($completar === []) {
            return;
        }

        $order->forceFill($completar)->saveQuietly();
        Log::info('[MUL-379] logistica completada com a etiqueta', [
            'order_id' => $order->id,
            'source'   => $order->source,
            'campos'   => array_keys($completar),
        ]);
    }

    private function setLabelReason(Order $order, string $reason): void
    {
        try {
            $order->updateQuietly([
                'label_status_reason' => $reason,
                'label_error_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("[FetchLabel] Falha ao gravar label_status_reason", [
                'order_id' => $order->id,
                'reason'   => $reason,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mapeia string livre do ShippingLabelService para codigo padronizado (MES-046-A).
     */
    private function mapReasonToCode(string $reason): string
    {
        $lower = strtolower($reason);

        // FOR-053-D: DC-e (CPF) tem prioridade sobre NF-e generico
        if (str_contains($lower, 'dc-e') || str_contains($lower, 'declaração de conteúdo') || str_contains($lower, 'declaracao de conteudo')) {
            return self::REASON_INVOICE_REQUIRED_CPF;
        }
        if (str_contains($lower, 'nf-e') && (str_contains($lower, 'de saída') || str_contains($lower, 'de saida'))) {
            return self::REASON_INVOICE_REQUIRED_CNPJ;
        }
        if (str_contains($lower, 'bling') || str_contains($lower, 'nf') || str_contains($lower, 'nota')) {
            return self::REASON_INVOICE_REQUIRED;
        }
        if (str_contains($lower, 'fiscal') || str_contains($lower, 'cnpj') || str_contains($lower, 'cpf')) {
            return self::REASON_FISCAL_DATA_MISSING;
        }
        if (str_contains($lower, 'token') || str_contains($lower, 'auth') || str_contains($lower, 'expired')) {
            return self::REASON_TOKEN_ERROR;
        }

        return self::REASON_AWAITING_MARKETPLACE;
    }
}
