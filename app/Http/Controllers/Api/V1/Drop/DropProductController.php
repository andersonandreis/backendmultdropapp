<?php

namespace App\Http\Controllers\Api\V1\Drop;

use App\Http\Controllers\Controller;
use App\Models\Drop\ImportedProduct;
use App\Services\Drop\DropPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Drop Internacional - CRUD de produtos importados de fornecedores.
 */
class DropProductController extends Controller
{
    public function __construct(
        private DropPricingService $pricingService
    ) {}

    private function clientOrFail(Request $request)
    {
        $client = $request->user()->client;
        if (!$client) {
            abort(403, 'Usuario nao possui perfil de lojista.');
        }
        return $client;
    }

    /**
     * Listar produtos importados do cliente (paginados).
     */
    public function index(Request $request): JsonResponse
    {
        $client = $this->clientOrFail($request);

        $query = ImportedProduct::where('client_id', $client->id)
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('supplier')) {
            $query->where('supplier_slug', $request->input('supplier'));
        }
        if ($request->filled('search')) {
            $query->where('title', 'like', '%' . $request->input('search') . '%');
        }

        return response()->json($query->paginate((int) $request->input('per_page', 15)));
    }

    /**
     * Importar um novo produto de um fornecedor.
     * Aplica automaticamente a regra de precificacao ativa do cliente.
     */
    public function store(Request $request): JsonResponse
    {
        $client = $this->clientOrFail($request);

        $validated = $request->validate([
            'supplier_slug'       => 'required|string|in:aliexpress,cj,zendrop',
            'external_product_id' => 'required|string|max:255',
            'title'               => 'required|string|max:500',
            'description'         => 'nullable|string',
            'cost_usd'            => 'required|numeric|min:0',
            'shipping_cost_usd'   => 'nullable|numeric|min:0',
            'images'              => 'nullable|array',
            'variants'            => 'nullable|array',
        ]);

        $validated['client_id'] = $client->id;
        $validated['status']    = 'draft';

        $product = new ImportedProduct($validated);

        $rule    = $this->pricingService->getActiveRule($client->id, $validated['supplier_slug']);
        $product = $this->pricingService->applyRuleToProduct($product, $rule);

        $product->save();

        return response()->json(['data' => $product], 201);
    }

    /**
     * Atualizar produto importado (preco, descricao, markup, etc.).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $client  = $this->clientOrFail($request);
        $product = ImportedProduct::where('client_id', $client->id)->findOrFail($id);

        $validated = $request->validate([
            'title'             => 'sometimes|string|max:500',
            'description'       => 'nullable|string',
            'sell_price'        => 'sometimes|numeric|min:0',
            'markup_pct'        => 'sometimes|numeric|min:0|max:500',
            'shipping_cost_usd' => 'nullable|numeric|min:0',
        ]);

        $product->fill($validated)->save();

        return response()->json(['data' => $product]);
    }

    /**
     * Publicar produto na loja Shopify via ShopifyProductService.
     */
    public function publish(Request $request, int $id): JsonResponse
    {
        $client  = $this->clientOrFail($request);
        $product = ImportedProduct::where('client_id', $client->id)->findOrFail($id);

        $productService = app(\App\Services\Drop\Shopify\ShopifyProductService::class);
        $result = $productService->publish($client->id, $product);

        return response()->json(['data' => $result, 'message' => 'Produto publicado na loja Shopify.']);
    }

    /**
     * Remover produto importado (apenas se nao vinculado a pedidos).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $client  = $this->clientOrFail($request);
        $product = ImportedProduct::where('client_id', $client->id)->findOrFail($id);

        if ($product->dropOrders()->exists()) {
            return response()->json(['message' => 'Nao e possivel remover produto com pedidos vinculados.'], 422);
        }

        $product->delete();

        return response()->json(['message' => 'Produto removido.']);
    }
}
