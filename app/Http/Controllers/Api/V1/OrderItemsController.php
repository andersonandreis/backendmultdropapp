<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\FanoutOrderWebhookJob;
use App\Models\Order;
use App\Policies\OrderEditPolicy;
use App\Services\OrderItemEditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MUL-298 -- Edicao de pedido por item.
 *
 * Caminho: front (WL) -> api.<wl> -> federation -> api.hubai.io (grava)
 *          -> FanoutOrderWebhookJob -> WLs recebem o pedido atualizado.
 * O hub e fonte de verdade; o WL nunca grava item por conta propria nesta rota.
 *
 * Identidade entre bancos e por SKU, nunca por id local (MUL-272).
 */
class OrderItemsController extends Controller
{
    public function __construct(private OrderItemEditService $svc)
    {
    }

    // ------------------------------------------------------------------- v1

    /** POST /api/v1/orders/{id}/items */
    public function store(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'product_sku' => ['required_without:product_id', 'nullable', 'string', 'max:255'],
            'product_id'  => ['nullable', 'integer', 'min:1'],
            'quantity'    => ['required', 'integer', 'min:1'],
        ]);

        $order = Order::find($id);
        if (!$order) {
            return response()->json(['error' => 'Pedido nao encontrado.'], 404);
        }
        if ($negado = $this->autoriza($request, $order)) {
            return $negado;
        }

        $sku = $this->resolveSkuDoPayload($data);
        if ($sku === null) {
            return response()->json(['error' => 'Produto nao encontrado neste banco.'], 404);
        }

        return $this->executa(function () use ($order, $sku, $data, $request) {
            if ($this->ehWl()) {
                // Plano e assinatura sao locais do WL. Validar o catalogo AQUI --
                // no hub esse dado nao existe e a regra recusaria tudo.
                $this->svc->assertCatalogoLocal($order, $sku);

                return $this->proxy($request, $order, 'post', '/items', [
                    'product_sku' => $sku,
                    'quantity'    => (int) $data['quantity'],
                ]);
            }

            $r = $this->svc->addItem($order, $sku, (int) $data['quantity'], $this->actorId($request));
            $this->fanout($order);
            return $r;
        });
    }

    /** PATCH /api/v1/orders/{id}/items/{itemId} */
    public function update(Request $request, int $id, int $itemId): JsonResponse
    {
        $data = $request->validate([
            'product_sku' => ['nullable', 'string', 'max:255'],
            'product_id'  => ['nullable', 'integer', 'min:1'],
            'quantity'    => ['nullable', 'integer', 'min:1'],
        ]);

        $order = Order::find($id);
        if (!$order) {
            return response()->json(['error' => 'Pedido nao encontrado.'], 404);
        }
        if ($negado = $this->autoriza($request, $order)) {
            return $negado;
        }

        $novoSku = $this->resolveSkuDoPayload($data, true);

        return $this->executa(function () use ($request, $order, $itemId, $novoSku, $data) {
            $item = $this->resolveItem($order, $itemId, $request);

            if ($this->ehWl()) {
                if ($novoSku !== null) {
                    $this->svc->assertCatalogoLocal($order, $novoSku);
                }
                // Item identificado pelo SKU dele no pedido, nunca pelo id local.
                $respProxy = $this->proxyRaw($request, $order, 'patch', '/items/' . rawurlencode($item->sku), [
                    'product_sku' => $novoSku,
                    'quantity'    => isset($data['quantity']) ? (int) $data['quantity'] : null,
                ]);
                // MUL-422b: o evento de troca vivia so no hub; os guards (sync/explosao)
                // leem order_events LOCAIS, entao o WL continuava revertendo por conta
                // propria. Swap aceito no hub ganha o evento espelho aqui.
                if ($novoSku !== null && $novoSku !== $item->sku && $respProxy->getStatusCode() < 300) {
                    \Illuminate\Support\Facades\DB::table('order_events')->insert([
                        'order_id'   => $order->id,
                        'event_type' => 'item_product_swapped',
                        'metadata'   => json_encode(['antes' => $item->sku, 'depois' => $novoSku, 'via' => 'proxy_hub_mul422b']),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                return $respProxy;
            }

            $r = $this->svc->updateItem(
                $order,
                $item,
                $novoSku,
                isset($data['quantity']) ? (int) $data['quantity'] : null,
                $this->actorId($request)
            );
            $this->fanout($order);
            return $r;
        });
    }

    /** DELETE /api/v1/orders/{id}/items/{itemId} */
    public function destroy(Request $request, int $id, int $itemId): JsonResponse
    {
        $order = Order::find($id);
        if (!$order) {
            return response()->json(['error' => 'Pedido nao encontrado.'], 404);
        }
        if ($negado = $this->autoriza($request, $order)) {
            return $negado;
        }

        return $this->executa(function () use ($request, $order, $itemId) {
            $item = $this->resolveItem($order, $itemId, $request);

            if ($this->ehWl()) {
                return $this->proxyRaw($request, $order, 'delete', '/items/' . rawurlencode($item->sku), []);
            }

            $r = $this->svc->removeItem($order, $item, $this->actorId($request));
            $this->fanout($order);
            return $r;
        });
    }

    /**
     * MUL-297 -- alias da rota que o front do admin ja chama e que devolvia 405:
     * POST /api/v1/supplier-admin/orders/{id}/items/{itemId}/swap-sku
     */
    public function swapSkuAlias(Request $request, int $id, int $itemId): JsonResponse
    {
        return $this->update($request, $id, $itemId);
    }

    // ----------------------------------------------------------- federation

    /** POST /api/federation/orders/{id}/items */
    public function storeFromFederation(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'product_sku'   => ['required', 'string', 'max:255'],
            'quantity'      => ['required', 'integer', 'min:1'],
            'actor_user_id' => ['nullable', 'integer'],
            'actor_tenant'  => ['nullable', 'string'],
        ]);

        $order = Order::find($id);
        if (!$order) {
            return response()->json(['error' => 'Pedido nao encontrado no hub.'], 404);
        }

        return $this->executa(function () use ($order, $data) {
            // validarPlano = false: o WL ja validou o plano do cliente dele.
            $r = $this->svc->addItem($order, $data['product_sku'], (int) $data['quantity'], $data['actor_user_id'] ?? null, false);
            $this->fanout($order);
            return $r;
        });
    }

    /** PATCH /api/federation/orders/{id}/items/{sku} */
    public function updateFromFederation(Request $request, int $id, string $sku): JsonResponse
    {
        $data = $request->validate([
            'product_sku'   => ['nullable', 'string', 'max:255'],
            'quantity'      => ['nullable', 'integer', 'min:1'],
            'actor_user_id' => ['nullable', 'integer'],
            'actor_tenant'  => ['nullable', 'string'],
        ]);

        $order = Order::find($id);
        if (!$order) {
            return response()->json(['error' => 'Pedido nao encontrado no hub.'], 404);
        }

        return $this->executa(function () use ($order, $sku, $data) {
            $item = $this->svc->findItemBySku($order, rawurldecode($sku));
            $r = $this->svc->updateItem(
                $order,
                $item,
                $data['product_sku'] ?? null,
                isset($data['quantity']) ? (int) $data['quantity'] : null,
                $data['actor_user_id'] ?? null,
                false // WL ja validou o plano
            );
            $this->fanout($order);
            return $r;
        });
    }

    /** DELETE /api/federation/orders/{id}/items/{sku} */
    public function destroyFromFederation(Request $request, int $id, string $sku): JsonResponse
    {
        $order = Order::find($id);
        if (!$order) {
            return response()->json(['error' => 'Pedido nao encontrado no hub.'], 404);
        }

        return $this->executa(function () use ($request, $order, $sku) {
            $item = $this->svc->findItemBySku($order, rawurldecode($sku));
            $r = $this->svc->removeItem($order, $item, $request->input('actor_user_id'));
            $this->fanout($order);
            return $r;
        });
    }

    // --------------------------------------------------------------- interno

    private function ehWl(): bool
    {
        return config('federation.tenant', 'hubai') !== 'hubai';
    }

    /**
     * Id de order_items no WL e INSTAVEL: cada propagacao vinda do hub apaga e
     * recria os itens do pedido (medido no pedido 97758 em 31/07/2026 -- os ids
     * andaram 95666 -> 95707 -> 95711 -> 95715 -> 95727 em poucos minutos). Um
     * front que segura o id perde a referencia a qualquer momento.
     *
     * Por isso `item_sku` tem precedencia sobre o id da rota quando vier.
     */
    private function resolveItem(Order $order, int $itemId, Request $request): \App\Models\OrderItem
    {
        $sku = $request->input('item_sku', $request->query('item_sku'));
        if (!empty($sku)) {
            return $this->svc->findItemBySku($order, (string) $sku, $request->input('item_variation_sku'));
        }
        return $this->svc->findItemById($order, $itemId);
    }

    private function actorId(Request $request): ?int
    {
        return $request->user()?->id;
    }

    private function autoriza(Request $request, Order $order): ?JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Nao autorizado.'], 403);
        }
        if (in_array($user->role, ['super_admin', 'admin', 'supplier'], true)) {
            return null;
        }
        $client = $user->client;
        if (!$client || $order->client_id !== $client->id) {
            return response()->json(['error' => 'Nao autorizado.'], 403);
        }
        return null;
    }

    /**
     * Se o produto veio por id local, converte para SKU antes de sair deste banco.
     */
    private function resolveSkuDoPayload(array $data, bool $opcional = false): ?string
    {
        if (!empty($data['product_sku'])) {
            return (string) $data['product_sku'];
        }
        if (!empty($data['product_id'])) {
            $p = \App\Models\Product::find((int) $data['product_id']);
            return $p?->sku;
        }
        return $opcional ? null : null;
    }

    private function proxy(Request $request, Order $order, string $metodo, string $sufixo, array $payload): JsonResponse
    {
        return $this->proxyRaw($request, $order, $metodo, $sufixo, $payload);
    }

    /**
     * MUL-298: guard do vinculo com o hub. Sem hubai_order_id, o antigo
     * "hubai_order_id ?? id" mandava o id LOCAL e o hub aplicava a alteracao em
     * pedido alheio. 876 pedidos do multdrop estao nessa condicao.
     */
    private function proxyRaw(Request $request, Order $order, string $metodo, string $sufixo, array $payload): JsonResponse
    {
        // MUL-298 fix: o repasse ao fornecedor e registrado no WL e NUNCA sobe pro hub
        // (632 pedidos do multdrop tem wallet_paid_at preenchido no WL e NULL no hub).
        // Avaliar a trava so no hub deixaria todos eles editaveis. Os dois lados valem.
        if (!OrderEditPolicy::podeEditar($order)) {
            return response()->json([
                'error' => OrderEditPolicy::motivo($order) . ': pedido ja repassado ao fornecedor neste WL.',
            ], 409);
        }

        if (empty($order->hubai_order_id)) {
            return response()->json([
                'error' => 'pedido_sem_vinculo_hub: este pedido nao tem hubai_order_id. Editar aqui aplicaria a mudanca em outro pedido no hub.',
            ], 409);
        }

        $hubUrl   = rtrim((string) config('federation.hub_url'), '/');
        $hubToken = (string) config('federation.hub_token');
        if (!$hubUrl || !$hubToken) {
            return response()->json(['error' => 'hub_not_configured'], 500);
        }

        $url = $hubUrl . '/api/federation/orders/' . (int) $order->hubai_order_id . $sufixo;

        $corpo = array_filter($payload, function ($v) { return $v !== null; }) + [
            'actor_user_id' => $this->actorId($request),
            'actor_tenant'  => config('federation.tenant'),
        ];

        try {
            $resp = Http::withToken($hubToken)->timeout(20)->acceptJson()->{$metodo}($url, $corpo);
        } catch (\Throwable $e) {
            Log::warning('[MUL-298] proxy de item falhou', [
                'order_id' => $order->id, 'hub_order_id' => $order->hubai_order_id, 'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'hub_unreachable', 'detail' => $e->getMessage()], 502);
        }

        return response()->json($resp->json() ?? [], $resp->status());
    }

    private function fanout(Order $order): void
    {
        FanoutOrderWebhookJob::dispatch($order->id, 'order.updated', ['items_edited' => true]);
    }

    /**
     * DomainException -> 409 (regra de negocio), InvalidArgumentException -> 422.
     */
    private function executa(\Closure $fn): JsonResponse
    {
        try {
            $r = $fn();
            if ($r instanceof JsonResponse) {
                return $r;
            }
            return response()->json([
                'success' => true,
                'order'   => $r['order'] ?? null,
                'item'    => $r['item'] ?? null,
            ]);
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('[MUL-298] erro ao editar item', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Erro interno ao editar item do pedido.'], 500);
        }
    }
}
