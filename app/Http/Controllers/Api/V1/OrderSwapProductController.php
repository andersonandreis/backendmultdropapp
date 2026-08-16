<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\FanoutOrderWebhookJob;
use App\Models\Order;
use App\Models\Product;
use App\Services\OrderSwapProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MUL-108 -- Troca de produto em pedido nao pago.
 * JT-009 -- FanoutOrderWebhookJob disparado apos swap para propagar pro JTDrop.
 */
class OrderSwapProductController extends Controller
{
    /**
     * POST /api/v1/orders/{id}/swap-product
     *
     * Body: { new_product_id: int, quantity: int }
     * Retorna: pedido atualizado com novos totais e items
     * Auth: apenas o dono do pedido (client) OU SUPER_ADMIN
     */
    public function swap(Request $request, int $id): JsonResponse
    {
        // INF-054 caminho 2: se este backend e um WL (nao hub), encaminha request pro hub central.
        // Hub grava, dispara fanout, WLs recebem de volta e sincronizam via letra B.
        if (config("federation.tenant", "hubai") !== "hubai") {
            return $this->proxySwapToHub($request, $id);
        }

        $request->validate([
            'new_product_id' => ['required', 'integer', 'min:1'],
            'quantity'       => ['required', 'integer', 'min:1'],
        ]);

        $order = Order::findOrFail($id);

        $user = $request->user();

        // Autorizacao: super_admin pode tudo; client so pode alterar seu proprio pedido
        if ($user->role !== 'super_admin') {
            $client = $user->client;
            if (!$client || $order->client_id !== $client->id) {
                return response()->json(['error' => 'Nao autorizado.'], 403);
            }
        }

        $newProduct = Product::find($request->input('new_product_id'));
        if (!$newProduct) {
            return response()->json(['error' => 'Produto nao encontrado.'], 404);
        }

        try {
            $svc     = app(OrderSwapProductService::class);
            $result  = $svc->swap(
                $order,
                $newProduct,
                (int) $request->input('quantity'),
                $actorUserId = $user->id
            );

            // JT-009: propagar troca para todos os tenants (incluindo JTDrop) via fanout
            FanoutOrderWebhookJob::dispatch($order->id, 'order.updated', ['swap_product' => true]);

            return response()->json([
                'success' => true,
                'message' => 'Produto trocado com sucesso.',
                'order'   => $result['order'],
            ]);
        } catch (\DomainException $e) {
            // MUL-298: regra de negocio (pedido repassado, multi-item, kit) -> 409
            return response()->json(['error' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro interno ao trocar produto.'], 500);
        }
    }


    /**
     * MUL-267: GET /api/v1/orders/{id}/swap-catalog?q=X
     * Lista produtos elegiveis pra troca — interseca client_supplier ∩ plan_supplier do plano ativo.
     * Retorna custo (price) + supplier badge pra UI mostrar de qual filial vem.
     */
    public function catalog(Request $request, int $id): JsonResponse
    {
        $order = Order::findOrFail($id);
        $user  = $request->user();
        if ($user->role !== 'super_admin') {
            $client = $user->client;
            if (!$client || $order->client_id !== $client->id) {
                return response()->json(['error' => 'Nao autorizado.'], 403);
            }
        }

        $q = trim((string) $request->query('q', ''));

        // Suppliers permitidos: client_supplier ∩ plan_supplier[cliente.plano.ativo]
        $allowed = \DB::table('client_supplier as cs')
            ->join('subscriptions as sub', function ($j) {
                $j->on('sub.client_id', '=', 'cs.client_id')
                  ->where('sub.status', '=', 'active');
            })
            ->join('plan_supplier as ps', function ($j) {
                $j->on('ps.plan_id', '=', 'sub.plan_id')
                  ->on('ps.supplier_id', '=', 'cs.supplier_id');
            })
            ->where('cs.client_id', $order->client_id)
            ->pluck('cs.supplier_id')
            ->toArray();

        if (empty($allowed)) {
            return response()->json(['data' => [], 'meta' => ['reason' => 'no_suppliers_for_client_plan']]);
        }

        $query = Product::query()
            ->whereIn('supplier_id', $allowed)
            ->where('is_active', 1);

        if ($q !== '') {
            $like = '%' . $q . '%';
            $query->where(function ($w) use ($like) {
                $w->where('sku', 'like', $like)
                  ->orWhere('name', 'like', $like);
            });
        }

        $rows = $query
            ->orderByRaw('CASE WHEN supplier_id = ? THEN 0 ELSE 1 END', [$order->supplier_id ?? 0])
            ->orderBy('name')
            ->limit(200) // MUL-281: catalogo pode ter 900+ produtos (Multdrop+Filial)
            ->get(['id','sku','name','price','supplier_id']);

        $supplierMap = \DB::table('suppliers')
            ->whereIn('id', $allowed)
            ->pluck('company_name', 'id')
            ->toArray();
        $prefixMap = \DB::table('suppliers')
            ->whereIn('id', $allowed)
            ->pluck('prefix', 'id')
            ->toArray();

        return response()->json([
            'data' => $rows->map(function ($p) use ($order, $supplierMap, $prefixMap) {
                return [
                    'id'               => $p->id,
                    'sku'              => $p->sku,
                    'name'             => $p->name,
                    'cost'             => (float) $p->price,
                    'supplier_id'      => $p->supplier_id,
                    'supplier_name'    => $supplierMap[$p->supplier_id] ?? '?',
                    'supplier_prefix'  => $prefixMap[$p->supplier_id] ?? null,
                    'is_current_supplier' => $order->supplier_id && $p->supplier_id === $order->supplier_id,
                ];
            }),
            'meta' => [
                'allowed_supplier_ids' => $allowed,
                'order_supplier_id'    => $order->supplier_id,
                'query'                => $q,
            ],
        ]);
    }

    /**
     * INF-054: WL encaminha swap pro hub via /api/federation/orders/{id}/swap-product.
     */
    private function proxySwapToHub(Request $request, int $id): JsonResponse
    {
        $order = Order::findOrFail($id);
        $hubOrderId = $order->hubai_order_id ?? $id;
        $user = $request->user();
        $clientId = $order->hubai_client_id ?? $order->client_id ?? null;

        // MUL-298 fix: trava tambem no WL -- wallet_paid_at do WL nao sobe pro hub.
        if (!\App\Policies\OrderEditPolicy::podeEditar($order)) {
            return response()->json([
                "error" => \App\Policies\OrderEditPolicy::motivo($order) . ": pedido ja repassado ao fornecedor neste WL.",
            ], 409);
        }

        $hubUrl   = rtrim((string) config("federation.hub_url"), "/");
        $hubToken = (string) config("federation.hub_token");

        if (!$hubUrl || !$hubToken) {
            return response()->json(["error" => "hub_not_configured"], 500);
        }

        // MUL-272: comunicacao HUB<->WL e sempre por SKU — id numerico so vale dentro do proprio banco.
        $localProduct = Product::find((int) $request->input("new_product_id"));
        if (!$localProduct) {
            return response()->json(["error" => "Produto nao encontrado."], 404);
        }

        try {
            $resp = Http::withToken($hubToken)
                ->timeout(15)
                ->acceptJson()
                ->post("$hubUrl/api/federation/orders/$hubOrderId/swap-product", [
                    "new_product_sku" => $localProduct->sku,
                    "quantity"       => (int) $request->input("quantity"),
                    "client_id"      => $clientId,
                    "actor_user_id"  => $user?->id,
                    "actor_tenant"   => config("federation.tenant"),
                ]);
        } catch (\Throwable $e) {
            Log::warning("[INF-054] proxy swap-product falhou", [
                "order_id" => $id, "hub_order_id" => $hubOrderId, "error" => $e->getMessage(),
            ]);
            return response()->json(["error" => "hub_unreachable", "detail" => $e->getMessage()], 502);
        }

        return response()->json($resp->json() ?? [], $resp->status());
    }

    /**
     * INF-054: hub recebe request de swap vindo de WL via auth.federation.
     * Nao usa $user (nao autenticado via Sanctum); autoriza via federation_tenant + client_id do payload.
     */
    public function swapFromFederation(Request $request, int $id): JsonResponse
    {
        $request->validate([
            "new_product_sku" => ["required", "string"],
            "quantity"       => ["required", "integer", "min:1"],
            "client_id"      => ["nullable", "integer"],
            "actor_user_id"  => ["nullable", "integer"],
            "actor_tenant"   => ["nullable", "string"],
        ]);

        $order = Order::findOrFail($id);

        // Guard: tenant que chama tem que enxergar este supplier (via tenant_supplier)
        $tenantSlug = $request->attributes->get("federation_tenant");
        if ($tenantSlug) {
            $tenantId = \DB::table("tenants")->where("slug", $tenantSlug)->value("id");
            if ($tenantId && $order->supplier_id) {
                $ok = \DB::table("tenant_supplier")
                    ->where("tenant_id", $tenantId)
                    ->where("supplier_id", $order->supplier_id)
                    ->exists();
                if (!$ok) {
                    return response()->json(["error" => "tenant_not_authorized_for_supplier"], 403);
                }
            }
        }

        // MUL-272: resolve por SKU (id de outro banco nao significa nada aqui).
        $sku = (string) $request->input("new_product_sku");
        $candidates = Product::where("sku", $sku)->get();
        if ($candidates->isEmpty()) {
            return response()->json(["error" => "Produto SKU {$sku} nao encontrado."], 404);
        }
        $newProduct = $candidates->firstWhere("supplier_id", $order->supplier_id) ?? $candidates->first();

        try {
            $svc = app(OrderSwapProductService::class);
            $result = $svc->swap(
                $order,
                $newProduct,
                (int) $request->input("quantity"),
                $actorUserId = (int) ($request->input("actor_user_id") ?? 0)
            );

            FanoutOrderWebhookJob::dispatch($order->id, "order.updated", [
                "swap_product" => true,
                "source_wl"    => $tenantSlug,
            ]);

            return response()->json([
                "success" => true,
                "message" => "Produto trocado com sucesso.",
                "order"   => $result["order"],
            ]);
        } catch (\DomainException $e) {
            // MUL-298: regra de negocio -> 409
            return response()->json(["error" => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(["error" => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(["error" => $e->getMessage()], 400);
        } catch (\Exception $e) {
            Log::error("[INF-054] swapFromFederation erro interno", [
                "order_id" => $id, "error" => $e->getMessage(),
            ]);
            return response()->json(["error" => "Erro interno ao trocar produto."], 500);
        }
    }
}
