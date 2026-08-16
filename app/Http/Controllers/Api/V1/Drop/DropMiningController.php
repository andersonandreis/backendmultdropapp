<?php

namespace App\Http\Controllers\Api\V1\Drop;

use App\Http\Controllers\Controller;
use App\Models\Drop\SupplierProduct;
use App\Services\Drop\SupplierProductMiningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Drop Internacional — Fase 2
 * Controller de mineracao de produtos de fornecedores externos.
 *
 * POST /api/v1/drop/mining/search — pesquisar produtos no fornecedor
 * POST /api/v1/drop/mining/import — importar SupplierProduct como rascunho de loja
 */
class DropMiningController extends Controller
{
    public function __construct(
        private SupplierProductMiningService $miningService
    ) {}

    /**
     * Pesquisar produtos em um fornecedor externo.
     *
     * Body: {supplier, keyword, category, page}
     *
     * @return JsonResponse  {data: SupplierProduct[], meta: {...}}
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier' => ['required', 'string', 'in:cj,aliexpress'],
            'keyword'  => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'page'     => ['nullable', 'integer', 'min:1'],
        ]);

        $clientId = $request->user()->client_id;

        if (! $clientId) {
            return response()->json(['message' => 'Conta de lojista nao encontrada.'], 422);
        }

        try {
            $filters = [
                'query'    => $validated['keyword'] ?? null,
                'category' => $validated['category'] ?? null,
                'page'     => $validated['page'] ?? 1,
            ];

            $products = $this->miningService->search($clientId, $validated['supplier'], $filters);

            return response()->json([
                'data' => $products,
                'meta' => [
                    'supplier' => $validated['supplier'],
                    'count'    => count($products),
                    'page'     => $filters['page'],
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('DropMiningController@search: erro', [
                'client_id' => $clientId,
                'error'     => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Erro ao consultar fornecedor. Tente novamente.'], 500);
        }
    }

    /**
     * Importar um SupplierProduct para uma loja Drop como rascunho de ImportedProduct.
     *
     * Body: {supplier_product_id, drop_store_id}
     *
     * @return JsonResponse  {data: ImportedProduct, message: string}
     */
    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_product_id' => ['required', 'integer', 'exists:supplier_products,id'],
            'drop_store_id'       => ['required', 'integer', 'exists:drop_stores,id'],
        ]);

        $clientId = $request->user()->client_id;

        if (! $clientId) {
            return response()->json(['message' => 'Conta de lojista nao encontrada.'], 422);
        }

        $supplierProduct = SupplierProduct::find($validated['supplier_product_id']);

        // Isolamento de tenant: garantir que o produto pertence ao cliente autenticado
        if ((int) $supplierProduct->client_id !== (int) $clientId) {
            return response()->json(['message' => 'Produto nao encontrado.'], 404);
        }

        try {
            $imported = $this->miningService->importToStore($supplierProduct, $validated['drop_store_id']);

            return response()->json([
                'data'    => $imported,
                'message' => 'Produto importado com sucesso como rascunho.',
            ], 201);
        } catch (\Throwable $e) {
            Log::error('DropMiningController@import: erro', [
                'client_id'           => $clientId,
                'supplier_product_id' => $validated['supplier_product_id'],
                'error'               => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Erro ao importar produto. Tente novamente.'], 500);
        }
    }
}
