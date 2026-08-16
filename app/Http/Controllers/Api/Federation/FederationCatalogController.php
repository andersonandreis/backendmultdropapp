<?php

namespace App\Http\Controllers\Api\Federation;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Federation\FederationProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * NOV-171-B — Endpoints de catálogo da Federation API (no hub api.hubai.io).
 *
 * Autenticação: Bearer FEDERATION_TOKEN_<WL> validado pelo middleware auth.federation.
 * Todas as rotas injetam $request->attributes->get('federation_tenant').
 */
class FederationCatalogController extends Controller
{
    public function __construct(
        private readonly FederationProductService $productService
    ) {}

    /**
     * POST /api/federation/catalog/push
     *
     * WL envia produto para criar/atualizar no hub.
     * WL MANDA — hub aceita incondicionalmente (decisão Ruan NOV-171 seção 10.3).
     * Retorna hub_product_id para o WL armazenar e referenciar futuras chamadas.
     */
    public function pushFromWl(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('federation_tenant');

        $validator = Validator::make($request->all(), [
            'sku'            => ['required', 'string', 'max:100'],
            'name'           => ['required', 'string', 'max:500'],
            'price'          => ['nullable', 'numeric', 'min:0'],
            'cost'           => ['nullable', 'numeric', 'min:0'],
            'stock'          => ['nullable', 'integer', 'min:0'],
            'supplier_id'    => ['required', 'integer', 'min:1'],
            'source_backend' => ['nullable', 'string', 'max:50'],
            'images'         => ['nullable', 'array'],
            'images.*'       => ['nullable', 'url', 'max:2000'],
            'description'    => ['nullable', 'string', 'max:10000'],
            'is_active'      => ['nullable', 'boolean'],
            'brand'          => ['nullable', 'string', 'max:100'],
            'weight_kg'      => ['nullable', 'numeric', 'min:0'],
            'height_cm'      => ['nullable', 'numeric', 'min:0'],
            'width_cm'       => ['nullable', 'numeric', 'min:0'],
            'length_cm'      => ['nullable', 'numeric', 'min:0'],
            'ncm'            => ['nullable', 'string', 'max:20'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Dados inválidos.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $product = $this->productService->pushFromWl($validator->validated(), $tenant);

            return response()->json([
                'hub_product_id' => $product->id,
                'sku'            => $product->sku,
                'updated_at'     => $product->updated_at,
            ], 200);

        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[FederationCatalog::push] erro', [
                'tenant' => $tenant,
                'error'  => $e->getMessage(),
                'trace'  => substr($e->getTraceAsString(), 0, 1000),
            ]);
            return response()->json(['message' => 'Erro interno ao processar produto.'], 500);
        }
    }

    /**
     * GET /api/federation/catalog/pull/{supplier_id}
     *
     * WL busca delta de produtos do hub (polling alternativo para quando webhook falha).
     * Retorna produtos do supplier atualizados após ?since= (ISO8601).
     * Paginado: 200 itens por página.
     */
    public function pullDelta(Request $request, int $supplierId): JsonResponse
    {
        $tenant = $request->attributes->get('federation_tenant');

        // Validar que o supplier pertence ao tenant
        $supplierBelongs = \Illuminate\Support\Facades\DB::table('tenant_supplier as ts')
            ->join('tenants as t', 't.id', '=', 'ts.tenant_id')
            ->where('t.slug', $tenant)
            ->where('ts.supplier_id', $supplierId)
            ->exists();

        if (! $supplierBelongs) {
            return response()->json(['message' => 'supplier_id não pertence ao seu tenant.'], 403);
        }

        $since = $request->query('since');
        $query = Product::withoutGlobalScopes()
            ->where('supplier_id', $supplierId)
            ->where('is_active', true)
            ->with(['media', 'inventory']);

        if ($since) {
            try {
                $sinceDate = \Carbon\Carbon::parse($since);
                $query->where('updated_at', '>', $sinceDate);
            } catch (\Throwable) {
                return response()->json(['message' => 'Parâmetro since inválido. Use ISO8601.'], 422);
            }
        }

        $products = $query
            ->orderBy('updated_at')
            ->paginate(200);

        $items = $products->map(function (Product $p) {
            return [
                'hub_product_id'   => $p->id,
                'sku'              => $p->sku,
                'name'             => $p->name,
                'price'            => $p->price,
                'cost'             => $p->cost,
                'stock'              => $p->inventory->sum('quantity'),
                'is_active'        => $p->is_active,
                'federation_source'=> $p->federation_source,
                'images'           => $p->media->pluck('url'),
                'description'      => $p->description,
                'brand'            => $p->brand,
                'weight_kg'        => $p->weight_kg,
                'updated_at'       => $p->updated_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'data'          => $items,
            'current_page'  => $products->currentPage(),
            'last_page'     => $products->lastPage(),
            'total'         => $products->total(),
            'supplier_id'   => $supplierId,
            'since'         => $since,
        ]);
    }
}
