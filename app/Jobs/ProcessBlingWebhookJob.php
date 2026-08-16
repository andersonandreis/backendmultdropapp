<?php

namespace App\Jobs;

use App\Models\MarketplaceAccount;
use App\Models\Product;
use App\Models\ClientProduct;
use App\Models\Order;
use App\Services\Integrations\Erps\Bling\BlingApiClient;
use App\Services\Integrations\Erps\Bling\BlingAuthService;
use App\Services\Integrations\Erps\Bling\BlingOrderSync;
use App\Services\Integrations\Erps\Bling\BlingProductSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessBlingWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 3;

    public function __construct(
        protected string $topic,
        protected string $resourceId,
        protected ?string $userId = null
    ) {}

    public function handle(BlingApiClient $client, BlingProductSync $productSync, BlingOrderSync $orderSync): void
    {
        Log::info("[BlingWebhook] Processing", [
            "topic"      => $this->topic,
            "resourceId" => $this->resourceId,
            "userId"     => $this->userId,
        ]);

        $account = $this->resolveAccount();

        if (!$account) {
            Log::warning("[BlingWebhook] No active Bling account found", [
                "userId"     => $this->userId,
                "resourceId" => $this->resourceId,
            ]);
            return;
        }

        match (true) {
            str_contains($this->topic, "order") || str_contains($this->topic, "pedidos") => $this->handleOrder($account, $client, $orderSync),
            str_contains($this->topic, "stock") || str_contains($this->topic, "estoque") => $this->handleStock($account, $client, $productSync),
            str_contains($this->topic, "product") || str_contains($this->topic, "produto") => $this->handleProduct($account, $client, $productSync),
            str_contains($this->topic, "invoice") || str_contains($this->topic, "nfe") => $this->handleInvoice($account, $client),
            default => Log::info("[BlingWebhook] Unhandled topic: {$this->topic}"),
        };
    }

    /**
     * Pedido criado/atualizado no Bling.
     */
    protected function handleOrder(MarketplaceAccount $account, BlingApiClient $client, BlingOrderSync $orderSync): void
    {
        if (empty($this->resourceId)) {
            return;
        }

        try {
            // MUL-135: delega pro BlingOrderSync — mesma lógica do sync periódico
            // (status por situacao.id, rastreio DBA, canal/marketplace, endereço,
            // dedup legado, guard de tenant e capture_payload). force=true no
            // syncSingle mantém o JSON bruto sempre sincronizado com o Bling.
            // A lógica duplicada que vivia aqui usava situacao.valor (bug do
            // status), Product sem escopo de supplier e não gravava rastreio.
            $result = $orderSync->syncSingle($account, (int) $this->resourceId);

            Log::info("[BlingWebhook] Order synced via BlingOrderSync", [
                "resource_id" => $this->resourceId,
                "result"      => $result,
            ]);

            // Preserva o auto-pay via wallet do fluxo antigo do webhook
            if (in_array($result, ["created", "updated"], true)) {
                $order = Order::where("source", "bling")
                    ->where("client_id", $account->client_id)
                    ->where("external_order_id", (string) $this->resourceId)
                    ->first();

                // MUL-363: autopay agora dispara SO no evento "ficou pagavel" (OrderObserver)
            }
        } catch (\Throwable $e) {
            Log::error("[BlingWebhook] Order processing error: " . $e->getMessage());
        }
    }

    /**
     * Estoque alterado no Bling.
     */
    protected function handleStock(MarketplaceAccount $account, BlingApiClient $client, BlingProductSync $productSync): void
    {
        // HubAI é fonte de verdade do estoque — apenas loga a notificação.
        // Não sobrescreve o estoque local pra evitar loop circular (HubAI push → Bling notifica → HubAI sobrescreve).
        Log::info("[BlingWebhook] Stock change notification from Bling (ignored, HubAI is source of truth)", [
            "resourceId" => $this->resourceId,
        ]);
    }

    /**
     * Produto criado/atualizado no Bling.
     */
    protected function handleProduct(MarketplaceAccount $account, BlingApiClient $client, BlingProductSync $productSync): void
    {
        if (empty($this->resourceId)) {
            $productSync->syncAll($account);
            return;
        }

        try {
            $response = $client->getProduct($account, (int) $this->resourceId);
            $blingProduct = $response["data"] ?? null;

            if (!$blingProduct) {
                return;
            }

            $sku = $blingProduct["codigo"] ?? null;
            if (!$sku || !$account->supplier_id) {
                return;
            }

            $product = Product::where("supplier_id", $account->supplier_id)
                ->where("sku", $sku)
                ->first();

            $data = [
                "supplier_id" => $account->supplier_id,
                "sku" => $sku,
                "name" => $blingProduct["nome"] ?? "Sem nome",
                "description" => $blingProduct["descricaoCurta"] ?? "",
                "price" => (float) ($blingProduct["preco"] ?? 0),
                "cost" => (float) ($blingProduct["precoCusto"] ?? 0),
                "gtin" => $blingProduct["gtin"] ?? null,
                "brand" => $blingProduct["marca"] ?? null,
                "is_active" => ($blingProduct["situacao"] ?? "") === "A",
            ];

            if ($product) {
                $product->update($data);
            } else {
                $product = Product::create($data);
            }

            // Atualiza ClientProduct se existir
            if ($account->client_id) {
                ClientProduct::updateOrCreate(
                    ["client_id" => $account->client_id, "product_id" => $product->id],
                    [
                        "supplier_product_sku" => $sku,
                        "custom_sku" => $sku,
                        "custom_title" => $product->name,
                        "custom_price" => $product->price,
                        "last_sync_at" => now(),
                    ]
                );
            }

            Log::info("[BlingWebhook] Product synced", ["sku" => $sku, "action" => $product->wasRecentlyCreated ? "created" : "updated"]);
        } catch (\Throwable $e) {
            // HUB-182: 404 (produto deletado no Bling) e refresh token morto sao
            // lado-cliente e recorrentes — warning pra nao poluir o canal de ERROR.
            $msg = $e->getMessage();
            if (str_contains($msg, '[404]') || str_contains($msg, 'token refresh failed')) {
                Log::warning("[BlingWebhook] Product processing skipped: " . $msg);
            } else {
                Log::error("[BlingWebhook] Product processing error: " . $msg);
            }
        }
    }

    /**
     * NF-e emitida no Bling — update invoice fields on related orders.
     */
    protected function handleInvoice(MarketplaceAccount $account, BlingApiClient $client): void
    {
        if (empty($this->resourceId)) {
            return;
        }

        try {
            $response = $client->getNfe($account, (int) $this->resourceId);
            $nfe = $response['data'] ?? null;

            if (!$nfe) {
                Log::warning("[BlingWebhook] NF-e not found", ['nfe_id' => $this->resourceId]);
                return;
            }

            $chaveAcesso = $nfe['chaveAcesso'] ?? null;
            $numero = $nfe['numero'] ?? null;
            $serie = $nfe['serie'] ?? null;

            // Try to find the related order via the NF-e's pedido reference
            $pedidoId = $nfe['pedido']['id'] ?? null;
            $order = null;

            if ($pedidoId) {
                $order = Order::where('source', 'bling')
                    ->where('external_order_id', (string) $pedidoId)
                    ->first();

                // MUL-252: pedido nativo (shopee/ml/...) com Bling anexado via dedup MUL-139
                if (!$order) {
                    $order = Order::where('bling_order_id', (int) $pedidoId)->first();
                }

                // MUL-252: fallback numeroLoja do pedido de venda Bling -> pedido nativo.
                // NF-e de pedidos marketplace nunca casava (matching era so source=bling).
                if (!$order) {
                    try {
                        $pedidoVenda = $client->getOrder($account, (int) $pedidoId)['data'] ?? null;
                        $numeroLoja = trim((string) ($pedidoVenda['numeroLoja'] ?? '')) ?: null;
                        if ($numeroLoja) {
                            $matches = Order::where('source', '!=', 'bling')
                                ->where(function ($q) use ($numeroLoja) {
                                    $q->where('marketplace_order_id', $numeroLoja)
                                        ->orWhere('external_order_id', $numeroLoja);
                                })
                                ->limit(3)
                                ->get();
                            if ($matches->count() === 1) {
                                $order = $matches->first();
                            } elseif ($matches->count() > 1) {
                                $order = $matches->firstWhere('client_id', $account->client_id);
                                if (!$order) {
                                    Log::warning('[BlingWebhook] numeroLoja ambiguo — NF-e nao vinculada', [
                                        'nfe_id' => $this->resourceId, 'numeroLoja' => $numeroLoja,
                                        'order_ids' => $matches->pluck('id')->all(),
                                    ]);
                                }
                            }
                            // anexa vinculo pro proximo webhook casar direto
                            if ($order && !$order->bling_order_id) {
                                $order->bling_order_id = (int) $pedidoId;
                            }
                        }
                    } catch (\Throwable $e) {
                        Log::warning('[BlingWebhook] numeroLoja lookup failed: ' . $e->getMessage(), [
                            'nfe_id' => $this->resourceId, 'pedido_id' => $pedidoId,
                        ]);
                    }
                }
            }

            if (!$order && $numero) {
                // Fallback: try matching by invoice_number
                $order = Order::where('invoice_number', $numero)->first();
            }

            if ($order) {
                // MUL-161-BE1 #8/#9: tipo=1 = saida (invoice_*); tipo=2 = entrada (nfe_entrada_*)
                $tipoNfe = $nfe['tipo'] ?? 1; // 1=saida, 2=entrada/devolucao por padrao
                if ($tipoNfe == 2) {
                    // NF-e de entrada (fornecedor): preenche nfe_entrada_* columns
                    $updateData = [
                        'nfe_entrada_status'     => 'received',
                        'nfe_entrada_access_key' => $chaveAcesso,
                        'nfe_entrada_pdf_url'    => $nfe['linkDanfe'] ?? $nfe['linkPDF'] ?? null,
                        'nfe_entrada_xml_url'    => $nfe['linkXml'] ?? $nfe['linkXML'] ?? null,
                        'nfe_entrada_received_at' => now(),
                        'nfe_entrada_updated_at'  => now(),
                    ];
                } else {
                    // NF-e de saida (venda): preenche invoice_* columns
                    $updateData = [
                        'invoice_number'     => $numero,
                        'invoice_series'     => $serie,
                        'invoice_status'     => 'issued', // MUL-252
                        'invoice_access_key' => $chaveAcesso,
                        'invoice_issued_at'  => isset($nfe['dataEmissao']) ? $nfe['dataEmissao'] : now(),
                        'invoice_url'        => $nfe['linkDanfe'] ?? $nfe['linkPDF'] ?? null,
                        'invoice_xml_url'    => $nfe['linkXml'] ?? $nfe['linkXML'] ?? null,
                    ];
                }

                $order->update(array_filter($updateData, fn ($v) => $v !== null));

                Log::info("[BlingWebhook] Invoice data saved to order", [
                    'order_id'    => $order->id,
                    'nfe_numero'  => $numero,
                    'chave'       => $chaveAcesso,
                ]);

                // Dispatch label job if the order needs a shipping label
                if (!$order->label_url && in_array($order->status, ['paid', 'pending'])) {
                    \App\Jobs\FetchShippingLabelJob::dispatch($order->id, 'bling_nfe_webhook');

                    Log::info("[BlingWebhook] Label job dispatched after NF-e", [
                        'order_id' => $order->id,
                    ]);
                }
            } else {
                Log::info("[BlingWebhook] No matching order found for NF-e", [
                    'nfe_id'    => $this->resourceId,
                    'pedido_id' => $pedidoId,
                    'numero'    => $numero,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error("[BlingWebhook] Invoice processing error: " . $e->getMessage());
        }
    }

    private function resolveAccount(): ?MarketplaceAccount
    {
        // Estrategia 1: seller_id explicito (payload ou query string ?seller_id=X)
        if ($this->userId) {
            $acct = MarketplaceAccount::where('platform', 'bling')
                ->where('seller_id', $this->userId)
                ->whereNotNull('bling_access_token')
                ->first();
            if ($acct) {
                return $acct;
            }
        }

        // Estrategia 2: lookup pelo pedido existente no banco (eventos de pedido)
        if ($this->resourceId && (str_contains($this->topic, 'order') || str_contains($this->topic, 'pedidos'))) {
            $existing = Order::where('source', 'bling')
                ->where('external_order_id', $this->resourceId)
                ->first();
            if ($existing && $existing->client_id) {
                $acct = MarketplaceAccount::where('platform', 'bling')
                    ->where('client_id', $existing->client_id)
                    ->whereNotNull('bling_access_token')
                    ->first();
                if ($acct) {
                    return $acct;
                }
            }
        }

        // Estrategia 3: fallback — usa primeira conta ativa (loga aviso se multiplas)
        $accounts = MarketplaceAccount::where('platform', 'bling')
            ->whereNotNull('bling_access_token')
            ->where('status', '!=', 'needs_reauth') // HUB-176: token morto (invalid_grant) nao entra no fallback
            ->whereNull('sync_blocked_at')
            ->get();

        if ($accounts->count() > 1) {
            Log::warning('[BlingWebhook] Multiplas contas Bling ativas sem seller_id; usando a primeira.', [
                'topic'         => $this->topic,
                'resourceId'    => $this->resourceId,
                'account_count' => $accounts->count(),
            ]);
        }

        return $accounts->first();
    }
}
