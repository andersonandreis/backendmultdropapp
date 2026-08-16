<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SEL-129: Rota publica GET /api/v1/suppliers/{slug}.
 *
 * Retorna dados completos de um fornecedor operacional (suppliers) +
 * catalogo paginado (products) + estatisticas basicas. Nao usa
 * TenantSupplierScope — o endpoint e publico (landing/vitrine), sem
 * exigir Sanctum. Somente fornecedores ativos e nao-privados sao
 * expostos.
 */
class SupplierDetailController extends Controller
{
    private const PER_PAGE = 20;

    public function show(Request $request, string $slug): JsonResponse
    {
        $supplier = Supplier::where('slug', $slug)
            ->where('is_active', true)
            ->where('is_private', false)
            ->first();

        if (!$supplier) {
            return response()->json(['message' => 'Fornecedor nao encontrado.'], 404);
        }

        return response()->json([
            'supplier' => $this->mapSupplier($supplier),
            'products' => $this->paginatedProducts($request, $supplier),
            'stats'    => $this->stats($supplier),
        ]);
    }

    /**
     * Mapeamento do supplier para o payload publico.
     * Campos sensiveis (openai_api_key, pix_key, etc.) NUNCA sao expostos.
     */
    private function mapSupplier(Supplier $s): array
    {
        return [
            'id'            => $s->id,
            'display_name'  => $s->display_name ?? $s->company_name,
            'slug'          => $s->slug,
            'description'   => $s->description,
            'logo_url'      => $s->logo,
            'cover_url'     => null, // suppliers nao tem cover; front cai no logo
            'categories'    => $this->supplierCategories($s),
            'contact_only'  => $this->isContactOnly($s),
            'whatsapp'      => $this->publicPhone($s),
            'email'         => null, // suppliers nao expoe email operacional
        ];
    }

    /**
     * Retorna nomes distintos das categorias dos produtos ativos do supplier.
     * Chave estavel para o front consumir sem depender de category_id.
     */
    private function supplierCategories(Supplier $s): array
    {
        return \DB::table('products')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->where('products.supplier_id', $s->id)
            ->where('products.is_active', true)
            ->distinct()
            ->orderBy('categories.name')
            ->pluck('categories.name')
            ->all();
    }

    /**
     * Um supplier e "contact_only" quando nao tem produtos ativos —
     * so serve pra contato/atacado, sem catalogo publico. Adaptacao
     * do briefing (nao existe coluna contact_only em suppliers).
     */
    private function isContactOnly(Supplier $s): bool
    {
        return !Product::withoutTenantSupplierScope()
            ->where('supplier_id', $s->id)
            ->where('is_active', true)
            ->exists();
    }

    private function publicPhone(Supplier $s): ?string
    {
        return $s->phone ?: null;
    }

    /**
     * Catalogo paginado (20 por pagina). Filtros basicos herdados do
     * padrao do SupplierController::catalog para conveniencia (search,
     * category_id, price min/max, in_stock).
     */
    private function paginatedProducts(Request $request, Supplier $s): array
    {
        $perPage = min((int) $request->query('per_page', self::PER_PAGE), 100);
        $page    = max(1, (int) $request->query('page', 1));

        // Bypass do TenantSupplierScope: endpoint publico, sem
        // contexto de tenant HTTP header.
        $query = Product::withoutTenantSupplierScope()
            ->where('products.supplier_id', $s->id)
            ->where('products.is_active', true)
            ->with([
                'media' => fn ($q) => $q->select('id', 'product_id', 'url', 'type', 'position', 'is_cover')
                    ->orderBy('position')->orderBy('id'),
                'category:id,name',
            ]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('ean', 'like', "%{$search}%");
            });
        }

        if ($categoryId = $request->query('category_id')) {
            $query->where('category_id', (int) $categoryId);
        }

        if ($request->filled('price_min')) {
            $query->where('price', '>=', (float) $request->query('price_min'));
        }
        if ($request->filled('price_max')) {
            $query->where('price', '<=', (float) $request->query('price_max'));
        }

        $query->orderByRaw('(EXISTS(SELECT 1 FROM inventory inv WHERE inv.product_id = products.id AND inv.quantity > 0)) DESC')
              ->orderBy('products.created_at', 'desc');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $items = $paginator->getCollection()->map(function (Product $p) {
            $cover = $p->media->firstWhere('is_cover', true) ?? $p->media->first();
            return [
                'id'            => $p->id,
                'name'          => $p->name,
                'sku'           => $p->sku,
                'price'         => (float) $p->price,
                'brand'         => $p->brand,
                'category_id'   => $p->category_id,
                'category_name' => $p->category?->name,
                'description'   => $p->description,
                'cover_image'   => $cover?->url,
                'imageUrl'      => $cover?->url,
            ];
        });

        return [
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ];
    }

    private function stats(Supplier $s): array
    {
        $productsCount = Product::withoutTenantSupplierScope()
            ->where('supplier_id', $s->id)
            ->where('is_active', true)
            ->count();

        $categoriesCount = \DB::table('products')
            ->where('supplier_id', $s->id)
            ->where('is_active', true)
            ->whereNotNull('category_id')
            ->distinct('category_id')
            ->count('category_id');

        $avgPrice = (float) Product::withoutTenantSupplierScope()
            ->where('supplier_id', $s->id)
            ->where('is_active', true)
            ->where('price', '>', 0)
            ->avg('price');

        return [
            'products_count'   => $productsCount,
            'categories_count' => $categoriesCount,
            'avg_price'        => round($avgPrice, 2),
        ];
    }
}
