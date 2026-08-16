<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GoolhubBridgeService
 *
 * Ponte de comunicacao com a API legada goolhub.io para plataformas que nao
 * possuem integracao OAuth direta (Shopee, Magalu, Amazon, TikTok, etc.).
 *
 * Todos os metodos retornam array com as chaves:
 *   - success (bool)
 *   - data    (array|null) — payload da resposta em caso de sucesso
 *   - error   (string|null) — mensagem de erro em caso de falha
 */
class GoolhubBridgeService
{
    private string $baseUrl;
    private int $timeoutSeconds = 30;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.goolhub.base_url', 'https://goolhub.io/api'), '/');
    }

    // =========================================================================
    // CONEXAO / AUTH
    // =========================================================================

    /**
     * Obtem a URL de conexao/autorizacao de uma integracao de marketplace
     * a partir da API legada.
     *
     * @param int $userId     ID do usuario no sistema legado (goolhub_id)
     * @param int $depositoId ID do deposito/loja no legado
     * @param int $canal      Canal do marketplace (ex: Shopee=3, Magalu=1, Amazon=10, TikTok=9)
     * @return array{success: bool, data: array|null, error: string|null}
     */
    public function getConnectionUrl(int $userId, int $depositoId, int $canal, ?string $email = null): array
    {
        $endpoint = '/bridge/connect_marketplace.php';

        return $this->postBridge($endpoint, [
            'id_login'    => $userId,
            'id_deposito' => $depositoId,
            'id_canal'    => $canal,
            'email'       => $email,
        ]);
    }

    // =========================================================================
    // PRODUTOS
    // =========================================================================

    /**
     * Publica um produto (SKU pai) em uma ou mais integracoes do marketplace legado.
     *
     * @param int   $skuPaiId     ID do SKU pai no sistema legado
     * @param array $integrations Lista de IDs de integracoes onde publicar
     * @return array{success: bool, data: array|null, error: string|null}
     */
    public function publishProduct(int $skuPaiId, array $integrations): array
    {
        $endpoint = '/app2/auth/produto_cadastro/cad_produtos.php';

        return $this->post($endpoint, [
            'sku_pai_id'   => $skuPaiId,
            'integrations' => $integrations,
        ]);
    }

    // =========================================================================
    // PEDIDOS
    // =========================================================================

    /**
     * Busca os pedidos de um usuario no sistema legado.
     *
     * @param int $userId ID do usuario no sistema legado (goolhub_id)
     * @return array{success: bool, data: array|null, error: string|null}
     */
    public function getOrders(int $userId): array
    {
        $endpoint = '/app2/get_pedidos.php';

        return $this->post($endpoint, [
            'user_id' => $userId,
        ]);
    }


    /**
     * Busca um pedido Shopee pelo order_sn via bridge legado.
     *
     * @param int    $userId   ID do usuario no sistema legado (goolhub_id)
     * @param string $orderSN  Numero do pedido Shopee (order_sn)
     * @return array{success: bool, data: array|null, error: string|null}
     */
    public function searchShopeeOrder(int $userId, string $orderSN): array
    {
        $endpoint = '/bridge/search_shopee_order.php';

        return $this->postBridge($endpoint, [
            'id_login'  => $userId,
            'order_sn'  => $orderSN,
        ]);
    }

    /**
     * Busca/solicita etiqueta de envio do pedido legado.
     *
     * Resposta data:
     *   - url: URL da etiqueta PDF (quando ready)
     *   - status: 'ready' | 'enqueued' | 'already_queued' | 'failed_max_retries' | 'no_canal_support'
     *   - canal: int (id_canal do legado)
     *   - tentativas: int
     */
    public function getOrRequestLabel(int $userId, int $pedidoLegacyId): array
    {
        return $this->postBridge('/bridge/get_label.php', [
            'id_login'  => $userId,
            'pedido_id' => $pedidoLegacyId,
        ]);
    }

    /**
     * Busca NF (apenas leitura do que o legado tem registrado).
     *
     * Resposta data:
     *   - numero, serie, chave (44), xml, status, emitida_em
     *   - status_resultado: 'issued' | 'not_issued'
     */
    public function getInvoice(int $userId, int $pedidoLegacyId): array
    {
        return $this->postBridge('/bridge/get_invoice.php', [
            'id_login'  => $userId,
            'pedido_id' => $pedidoLegacyId,
        ]);
    }

    /**
     * Importa pedido do marketplace pelo numero (order_sn/mlb) — enfileira
     * worker do legado que vai baixar via API e salvar em pedidos.
     *
     * @param int $userId       legacy_id_login do client
     * @param int $idCanal      3=Shopee, 6=ML, 7=Magalu, 20=Bling
     * @param string $nrPedido  numero do pedido no marketplace
     * @param string|null $shopId opcional, pra resolver integracao quando o
     *                            cliente tem mais de uma do mesmo canal
     */
    public function importOrderByNumber(int $userId, int $idCanal, string $nrPedido, ?string $shopId = null): array
    {
        return $this->postBridge('/bridge/import_order.php', [
            'id_login'  => $userId,
            'id_canal'  => $idCanal,
            'nr_pedido' => $nrPedido,
            'shop_id'   => $shopId ?? '',
        ]);
    }

    /**
     * Dashboard agregado do FORNECEDOR (loja) — replica dashboard-loja.php.
     *
     * Cliente passa loja.id no legado (MultDrop matriz=565, filial=840).
     * Retorna loja info + skus + pedidos 15d + etiquetas + integracoes +
     * vendas 30d.
     */
    public function getSupplierDashboard(int $idLoja): array
    {
        return $this->postBridge('/bridge/supplier_dashboard.php', [
            'id_loja' => $idLoja,
        ]);
    }

    // =========================================================================
    // PICKING / PACKING
    // =========================================================================

    public function getPickingQueue(int $idLoja, int $limit = 20): array
    {
        return $this->getBridge('/bridge/picking_packing.php', [
            'op' => 'fila', 'id_loja' => $idLoja, 'limit' => $limit,
        ]);
    }

    public function lookupTracking(int $idLoja, string $tracking): array
    {
        return $this->getBridge('/bridge/picking_packing.php', [
            'op' => 'lookup', 'id_loja' => $idLoja, 'tracking' => $tracking,
        ]);
    }

    /**
     * Busca lista de pedidos por CEP (op=lookup_cep no bridge).
     * Retorna lista para o operador selecionar o pedido correto (reembolso etc.).
     */
    public function lookupByCep(int $idLoja, string $cep, int $limit = 20): array
    {
        return $this->getBridge('/bridge/picking_packing.php', [
            'op' => 'lookup_cep', 'id_loja' => $idLoja, 'cep' => $cep, 'limit' => $limit,
        ]);
    }

    /** $op: 'ship'|'skip'|'problema'  $extra: ['motivo' => '...'] pra problema. */
    public function pickingAction(string $op, int $idPedido, array $extra = []): array
    {
        return $this->postBridge('/bridge/picking_packing.php', array_merge(
            ['op' => $op, 'id_pedido' => $idPedido],
            $extra
        ));
    }

    // =========================================================================
    // DEVOLUCOES (estorno conta_corrente_white)
    // =========================================================================

    public function getDevolucoes(int $idLoja, int $limit = 20): array
    {
        return $this->getBridge('/bridge/devolucao.php', [
            'op' => 'fila', 'id_loja' => $idLoja, 'limit' => $limit,
        ]);
    }

    public function getDevolucao(int $idDevolucao): array
    {
        return $this->getBridge('/bridge/devolucao.php', [
            'op' => 'detalhe', 'id_devolucao' => $idDevolucao,
        ]);
    }

    /** $op: 'aprovar'|'reprovar'. Aprovar dispara INSERT conta_corrente_white + recalc saldo. */
    public function respondDevolucao(string $op, int $idDevolucao, string $obs = ''): array
    {
        return $this->postBridge('/bridge/devolucao.php', [
            'op' => $op, 'id_devolucao' => $idDevolucao, 'obs' => $obs,
        ]);
    }

    // =========================================================================
    // INTEGRACOES (Shopee/ML/Bling/TikTok)
    // =========================================================================

    public function deleteSkuPai(int $idSkuPai): array
    {
        return $this->postBridge('/bridge/produto_delete.php', ['id_sku_pai' => $idSkuPai]);
    }

    public function upsertSkuPai(array $payload): array
    {
        return $this->postBridge("/bridge/produto_upsert.php", $payload);
    }

    public function popSkuPaiChanges(int $limit = 100, int $idDeposito = 0, ?string $since = null): array
    {
        $params = ['limit' => $limit, 'id_deposito' => $idDeposito];
        if ($since) $params['since'] = $since;
        return $this->getBridge("/bridge/produto_changes_pop.php", $params);
    }

    public function ackSkuPaiChanges(array $queueIds): array
    {
        return $this->postBridge("/bridge/produto_changes_pop.php", ["ack_ids" => array_values($queueIds)]);
    }

    public function getSkuImagens(array $ids): array
    {
        return $this->postBridge('/bridge/sku_imagens.php', ['ids' => array_values($ids)]);
    }

    public function searchShopeeCategories(string $q, int $limit = 50): array
    {
        return $this->getBridge('/bridge/cat_shopee_search.php', ['q' => $q, 'limit' => $limit]);
    }

    public function searchMeliCategories(string $q, int $limit = 50): array
    {
        return $this->getBridge('/bridge/cat_meli_search.php', ['q' => $q, 'limit' => $limit]);
    }

    public function getMeliAttributes(string $categoryId): array
    {
        return $this->getBridge('/bridge/cat_meli_attrs.php', ['cat' => $categoryId]);
    }


    // =========================================================================
    // PEDIDOS ADMIN (cancelar / cancelar etiqueta / reembolso / bloquear / swap sku)
    // =========================================================================

    /**
     * Cancela pedido no marketplace via worker legado.
     * id_login resolvido automaticamente no bridge via JOIN integracao.
     */
    public function orderCancel(int $pedidoId, string $motivo): array
    {
        return $this->postBridge('/bridge/order_cancel.php', [
            'pedido_id' => $pedidoId,
            'motivo'    => $motivo,
        ]);
    }

    /**
     * Cancela etiqueta de envio e limpa url_img/tentativas do pedido.
     * Permite re-solicitar etiqueta apos cancelamento.
     */
    public function orderCancelLabel(int $pedidoId, string $motivo = 'Cancelamento solicitado pelo painel'): array
    {
        return $this->postBridge('/bridge/order_cancel_label.php', [
            'pedido_id' => $pedidoId,
            'motivo'    => $motivo,
        ]);
    }

    /**
     * Solicita reembolso de pedido.
     * $valor opcional — se null, usa valor_total do pedido.
     */
    public function orderRefund(int $pedidoId, string $motivo, ?float $valor = null): array
    {
        $payload = ['pedido_id' => $pedidoId, 'motivo' => $motivo];
        if ($valor !== null) $payload['valor'] = $valor;
        return $this->postBridge('/bridge/order_refund.php', $payload);
    }

    /**
     * Bloqueia envio de pedido (impede picking).
     * $desbloquear=true remove o bloqueio.
     */
    public function orderBlock(int $pedidoId, string $motivo = '', bool $desbloquear = false): array
    {
        $payload = ['pedido_id' => $pedidoId];
        if ($desbloquear) {
            $payload['_method'] = 'DELETE';
        } else {
            $payload['motivo'] = $motivo;
        }
        return $this->postBridge('/bridge/order_block.php', $payload);
    }

    /**
     * Troca SKUs de um pedido (resolve galpao errado, item avulso etc.).
     * $itens: [[ 'id_sku_pai' => int, 'quantidade' => int, 'nome_produto' => string?, 'preco_unit' => float? ]]
     */
    public function orderSwapSku(int $pedidoId, array $itens, string $motivo = 'Troca de SKU solicitada pelo painel'): array
    {
        return $this->postBridge('/bridge/order_swap_sku.php', [
            'pedido_id' => $pedidoId,
            'itens'     => $itens,
            'motivo'    => $motivo,
        ]);
    }

    public function getIntegracoes(int $idDeposito): array
    {
        return $this->getBridge('/bridge/integracoes_list.php', [
            'op' => 'list', 'id_deposito' => $idDeposito,
        ]);
    }

    public function disconnectIntegracao(int $idIntegracao): array
    {
        return $this->postBridge('/bridge/integracoes_list.php', [
            'op' => 'disconnect', 'id_integracao' => $idIntegracao,
        ]);
    }

    // =========================================================================
    // PUSH RELAY (Shopee → legado)
    // =========================================================================

    /**
     * Retransmite evento push da Shopee para o legado goolhub.io.
     *
     * Chamado de forma fire-and-forget pelo ShopeeWebhookController apos
     * processar o evento no NovoHubAI, para manter o legado atualizado
     * enquanto as 6.675 lojas Shopee ativas ainda rodam ali.
     *
     * @param int     Codigo do evento Shopee (3=ORDER_STATUS, 4=TRACKING, etc.)
     * @param array   Payload do evento (inclui shop_id se disponivel)
     * @return array{success: bool, data: array|null, error: string|null}
     */
    public function relayShopeeEvent(int $code, array $data): array
    {
        // NOV-041 (2026-06-23): shopee_event_relay.php no legado usa key DIFERENTE
        // da bridge key padrao (xK9mP3qR...). Usa events_bridge_key (8ANH8TF5...).
        $eventsKey = config('services.goolhub.events_bridge_key', 'hb-bridge-2026-8ANH8TF5');
        return $this->postBridgeWithKey('/bridge/shopee_event_relay.php', [
            'code'    => $code,
            'data'    => $data,
            'shop_id' => $data['shop_id'] ?? 0,
        ], $eventsKey);
    }

    /**
     * POST autenticado com X-Bridge-Key custom (pra endpoints que usam key diferente).
     */
    private function postBridgeWithKey(string $endpoint, array $payload, string $bridgeKey): array
    {
        $url = $this->baseUrl . $endpoint;
        try {
            $response = Http::withHeaders(['X-Bridge-Key' => $bridgeKey])
                ->timeout($this->timeoutSeconds)
                ->post($url, $payload);
            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json('data'), 'error' => null];
            }
            \Illuminate\Support\Facades\Log::warning("GoolhubBridge: erro HTTP {$response->status()} em {$endpoint}", ['payload' => $payload, 'body' => substr($response->body(), 0, 500)]);
            return ['success' => false, 'data' => null, 'error' => "HTTP {$response->status()}: {$response->body()}"];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("GoolhubBridge: excecao em {$endpoint}: {$e->getMessage()}");
            return ['success' => false, 'data' => null, 'error' => $e->getMessage()];
        }
    }


    // =========================================================================
    // BLING TOKEN RELAY (NovoHubAI -> legado)
    // =========================================================================

    /**
     * Retransmite tokens Bling renovados para a tabela integracao do legado.
     *
     * Chamado pelo TokenRefreshService apos renovacao bem-sucedida de token Bling
     * (platform=bling em marketplace_accounts), para manter o legado sincronizado.
     *
     * Bridge: POST /bridge/bling_save_tokens.php (HMAC-SHA256)
     * Identifica integracao no legado por: id_login (legacy_id_login) + id_canal=20
     */
    public function relayBlingTokens(int $legacyUserId, string $accessToken, string $refreshToken): array
    {
        $bridgeKey = config("services.goolhub.bridge_key", "hb-bridge-2026-8ANH8TF5");
        $sig = hash_hmac("sha256", "bling:{$legacyUserId}:{$accessToken}", $bridgeKey);

        return $this->postBridge("/bridge/bling_save_tokens.php", [
            "user_id"       => $legacyUserId,
            "access_token"  => $accessToken,
            "refresh_token" => $refreshToken,
            "sig"           => $sig,
        ]);
    }

    // =========================================================================
    // HTTP HELPER
    // =========================================================================

    /**
     * Executa POST autenticado na API legada.
     * Autentica via token fixo (config services.goolhub.token) no header Authorization.
     *
     * @return array{success: bool, data: array|null, error: string|null}
     */
    /**
     * POST autenticado via X-Bridge-Key (pra endpoints bridge do legado).
     */
    private function postBridge(string $endpoint, array $payload): array
    {
        $url = $this->baseUrl . $endpoint;
        $bridgeKey = config('services.goolhub.bridge_key', 'hb-bridge-2026-xK9mP3qR7vL2nW8');

        try {
            $response = Http::withHeaders(['X-Bridge-Key' => $bridgeKey])
                ->timeout($this->timeoutSeconds)
                ->post($url, $payload);

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json('data'), 'error' => null];
            }

            Log::warning("GoolhubBridge: erro HTTP {$response->status()} em {$endpoint}", [
                'payload' => $payload,
                'body'    => substr($response->body(), 0, 500),
            ]);

            return ['success' => false, 'data' => null, 'error' => "HTTP {$response->status()}: {$response->body()}"];
        } catch (\Throwable $e) {
            Log::error("GoolhubBridge: excecao em {$endpoint}: {$e->getMessage()}");
            return ['success' => false, 'data' => null, 'error' => $e->getMessage()];
        }
    }


    /**
     * GET autenticado via X-Bridge-Key (pra endpoints bridge GET do legado).
     */
    private function getBridge(string $endpoint, array $params = []): array
    {
        $url = $this->baseUrl . $endpoint;
        $bridgeKey = config('services.goolhub.bridge_key', 'hb-bridge-2026-xK9mP3qR7vL2nW8');

        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders(['X-Bridge-Key' => $bridgeKey])
                ->timeout($this->timeoutSeconds)
                ->get($url, $params);

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json('data'), 'next_cursor' => $response->json('next_cursor'), 'error' => null];
            }

            \Illuminate\Support\Facades\Log::warning("GoolhubBridge: GET {$response->status()} em {$endpoint}", [
                'params' => $params,
                'body'   => substr($response->body(), 0, 500),
            ]);

            return ['success' => false, 'data' => null, 'error' => "HTTP {$response->status()}: {$response->body()}"];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("GoolhubBridge: excecao GET {$endpoint}: {$e->getMessage()}");
            return ['success' => false, 'data' => null, 'error' => $e->getMessage()];
        }
    }

    private function post(string $endpoint, array $payload): array
    {
        $url   = $this->baseUrl . $endpoint;
        $token = config('services.goolhub.token', '');

        try {
            $response = Http::withToken($token)
                ->timeout($this->timeoutSeconds)
                ->post($url, $payload);

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json(), 'error' => null];
            }

            $status = $response->status();
            $body   = $response->body();

            Log::warning("GoolhubBridgeService: erro HTTP {$status} em {$endpoint}", [
                'payload' => $payload,
                'body'    => substr($body, 0, 500),
            ]);

            return ['success' => false, 'data' => null, 'error' => "HTTP {$status}: {$body}"];

        } catch (\Throwable $e) {
            Log::error("GoolhubBridgeService: excecao em {$endpoint}: {$e->getMessage()}", [
                'payload' => $payload,
            ]);

            return ['success' => false, 'data' => null, 'error' => $e->getMessage()];
        }
    }
}
