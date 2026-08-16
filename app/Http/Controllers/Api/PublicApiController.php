<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientProduct;
use App\Models\Inventory;
use App\Models\MarketplaceAccount;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\Supplier;
use App\Services\MercadoLivreService;
use App\Services\Integrations\Marketplaces\ShopeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class PublicApiController extends Controller
{
    // =========================================================================
    // Helper privado — normaliza platform para alias curto
    // =========================================================================
    private function normalizePlatform(string $platform): string
    {
        return match ($platform) {
            'mercado_livre', 'mercadolivre' => 'ml',
            'shopee'                        => 'shopee',
            'bling'                         => 'bling',
            default                         => $platform,
        };
    }

    // =========================================================================
    // Helper privado — calcula score de qualidade do anuncio
    // =========================================================================
    private function calculateScore(array $data): int
    {
        $score = 0;
        if (strlen($data['title'] ?? '') >= 60)        $score += 15;
        if (strlen($data['description'] ?? '') >= 200)  $score += 20;
        if (count($data['images'] ?? []) >= 3)          $score += 20;
        if (!empty($data['video_url']))                  $score += 15;
        if (!empty($data['ean']))                        $score += 10;
        if (!empty($data['attributes']))                 $score += 10;
        $score += 10; // assume active apos update
        return $score;
    }

    // =========================================================================
    // Health check
    // =========================================================================
    #[OA\Get(
        path: '/api/health',
        operationId: 'healthCheck',
        summary: 'Health check da API',
        description: 'Retorna status simples indicando que a API esta operacional. Nao requer autenticacao.',
        tags: ['Publico'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'API operacional',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'HubAI API is up and running'),
                    ]
                )
            ),
        ]
    )]
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'HubAI API is up and running']);
    }

    // =========================================================================
    // Fornecedores
    // =========================================================================
    public function suppliers(): JsonResponse
    {
        $allowedFields = [
            'id', 'slug', 'company_name', 'display_name', 'logo',
            'type', 'city', 'state', 'description', 'is_active',
        ];

        $suppliers = Supplier::withCount('products')
            ->where('is_active', true)
            ->orderByDesc('products_count')
            ->get($allowedFields)
            ->map(fn ($s) => array_merge($s->only($allowedFields), ['products_count' => $s->products_count]));

        return response()->json(['data' => $suppliers]);
    }

    // =========================================================================
    // Catalogo de produtos
    // =========================================================================
    public function catalog(Request $request, int $supplier_id): JsonResponse
    {
        $supplier = Supplier::find($supplier_id);
        if (!$supplier) {
            return response()->json(['error' => 'Fornecedor nao encontrado'], 404);
        }

        $search  = $request->get('search', '');
        $perPage = min((int) $request->get('limit', 25), 50);

        $query = Product::where('supplier_id', $supplier_id)
            ->where('is_active', true);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('ean', 'like', "%{$search}%");
            });
        }

        $products   = $query->orderBy('name')->paginate($perPage);
        $productIds = $products->pluck('id');

        $images = ProductMedia::whereIn('product_id', $productIds)
            ->where('type', 'image')
            ->orderBy('position')
            ->get()
            ->groupBy('product_id');

        $inventory = Inventory::whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');

        $items = $products->through(function ($product) use ($images, $inventory) {
            $productImages = $images->get($product->id, collect());
            $stock         = $inventory->get($product->id);

            return [
                'id'           => $product->id,
                'sku'          => $product->sku,
                'name'         => $product->name,
                'description'  => Str::limit($product->description, 100),
                'price'        => (float) $product->price,
                'ean'          => $product->ean,
                'brand'        => $product->brand,
                'weight_kg'    => (float) $product->weight_kg,
                'image'        => $productImages->first()?->url,
                'images_count' => $productImages->count(),
                'stock'        => $stock?->quantity ?? 0,
                'is_active'    => $product->is_active,
            ];
        });

        return response()->json([
            'supplier'   => [
                'id'             => $supplier->id,
                'name'           => $supplier->company_name,
                'products_count' => $products->total(),
            ],
            'data'       => $items->items(),
            'pagination' => [
                'current_page' => $products->currentPage(),
                'last_page'    => $products->lastPage(),
                'per_page'     => $products->perPage(),
                'total'        => $products->total(),
            ],
        ]);
    }

    // =========================================================================
    // FIX 1 + FIX 2 — marketplace-accounts com legacy_id_login e payload rico
    // =========================================================================
    public function marketplaceAccounts(Request $request): JsonResponse
    {
        $clientId = $request->get('client_id');
        if (!$clientId) {
            return response()->json(['error' => 'client_id required'], 422);
        }

        // FIX 1: aceita id direto, document (goolhub legado) ou legacy_id_login
        $clientIds = Client::where('id', $clientId)
            ->orWhere('document', (string) $clientId)
            ->orWhere('legacy_id_login', $clientId)
            ->pluck('id');

        $accounts = MarketplaceAccount::whereIn('client_id', $clientIds)
            ->where('status', 'active')
            ->get();

        $enriched = $accounts->map(function ($acc) {
            // FIX 2: payload padronizado com campos ricos
            $data = [
                'id'           => $acc->id,
                'marketplace'  => $this->normalizePlatform($acc->platform),
                'connected'    => $acc->status === 'active',
                'account_name' => $acc->account_name ?? $acc->seller_nickname ?? null,
                'seller_id'    => $acc->seller_id ?? $acc->ml_user_id ?? $acc->shop_id ?? null,
                'last_sync'    => $acc->last_sync_at?->toIso8601String(),
                // campos legados mantidos para retrocompatibilidade
                'platform'        => $acc->platform,
                'seller_nickname' => $acc->seller_nickname,
                'status'          => $acc->status,
                'created_at'      => $acc->created_at,
            ];

            if ($acc->platform === 'mercadolivre' && $acc->ml_access_token) {
                try {
                    $token = decrypt($acc->ml_access_token);
                    $me    = Http::withToken($token)
                        ->timeout(5)
                        ->get('https://api.mercadolibre.com/users/me')
                        ->json();

                    if ($me && isset($me['id'])) {
                        $data['ml_profile'] = [
                            'nickname'          => $me['nickname'] ?? null,
                            'first_name'        => $me['first_name'] ?? null,
                            'last_name'         => $me['last_name'] ?? null,
                            'email'             => $me['email'] ?? null,
                            'country_id'        => $me['country_id'] ?? null,
                            'permalink'         => $me['permalink'] ?? null,
                            'registration_date' => $me['registration_date'] ?? null,
                            'seller_experience' => $me['seller_experience'] ?? null,
                            'site_status'       => $me['status']['site_status'] ?? null,
                            'confirmed_email'   => $me['status']['confirmed_email'] ?? false,
                            'company_name'      => $me['company']['corporate_name'] ?? null,
                            'company_brand'     => $me['company']['brand_name'] ?? null,
                            'cnpj'              => $me['company']['identification'] ?? $me['identification']['number'] ?? null,
                            'address'           => $me['address'] ?? null,
                            'phone'             => $me['registration_identifiers'][0]['metadata'] ?? null,
                            'reputation'        => [
                                'level'             => $me['seller_reputation']['level_id'] ?? null,
                                'power_seller'      => $me['seller_reputation']['power_seller_status'] ?? null,
                                'completed'         => $me['seller_reputation']['transactions']['completed'] ?? 0,
                                'cancelled'         => $me['seller_reputation']['transactions']['canceled'] ?? 0,
                                'ratings_positive'  => $me['seller_reputation']['transactions']['ratings']['positive'] ?? 0,
                                'ratings_negative'  => $me['seller_reputation']['transactions']['ratings']['negative'] ?? 0,
                                'sales_365d'        => $me['seller_reputation']['metrics']['sales']['completed'] ?? 0,
                                'claims_rate'       => $me['seller_reputation']['metrics']['claims']['rate'] ?? 0,
                                'cancellation_rate' => $me['seller_reputation']['metrics']['cancellations']['rate'] ?? 0,
                            ],
                            'tags' => $me['tags'] ?? [],
                        ];
                    }
                } catch (\Throwable $e) {
                    $data['ml_profile'] = ['error' => 'Token expired or invalid'];
                }
            }

            return $data;
        });

        return response()->json(['data' => $enriched]);
    }


    // =========================================================================
    // NOV-CLIENT-INTEGRATIONS — GET /api/public/client-integrations
    // Endpoint PUBLICO (sem internal.key) para o painel Lovable (hubai.io)
    // exibir integracoes do cliente. Aceita client_id real OU legacy_id_login.
    // Retorna APENAS campos safe — sem tokens, sem ML profile, sem CNPJ.
    // Fix: bug 401 quando Lovable passava legacy_id_login no lugar de client_id.
    // =========================================================================
    public function clientIntegrations(Request $request): JsonResponse
    {
        $clientIdParam = $request->get("client_id");
        if (!$clientIdParam) {
            return response()->json(["error" => "client_id required"], 422);
        }

        // Resolve client_id real: aceita clients.id, legacy_id_login ou users.id
        $client = Client::where("id", $clientIdParam)->first()
            ?? Client::where("legacy_id_login", $clientIdParam)->first()
            ?? Client::whereHas("user", fn($q) => $q->where("id", $clientIdParam))->first();

        if (!$client) {
            return response()->json(["data" => [], "resolved_client_id" => null], 200);
        }

        $accounts = MarketplaceAccount::where("client_id", $client->id)
            ->where("status", "active")
            ->get();

        $safe = $accounts->map(fn($acc) => [
            "id"                    => $acc->id,
            "platform"              => $acc->platform,
            "account_name"          => $acc->account_name ?? $acc->seller_nickname ?? null,
            "status"                => $acc->status,
            "needs_reauth"          => (bool) $acc->needs_reauth,
            "is_token_broken"       => (bool) $acc->is_token_broken,
            "token_expires_at"      => $acc->token_expires_at?->toIso8601String(),
            "ml_token_expires_at"   => $acc->ml_token_expires_at?->toIso8601String(),
            "last_token_refresh_at" => $acc->last_token_refresh_at?->toIso8601String(),
        ]);

        return response()->json([
            "data"               => $safe,
            "resolved_client_id" => $client->id,
        ]);
    }

    // =========================================================================
    // ENDPOINT 3.2 — GET /api/public/marketplace-items
    // Lista ClientProducts de uma conta de marketplace
    // =========================================================================
    public function marketplaceItems(Request $request): JsonResponse
    {
        $accountId = $request->query('account_id');
        if (!$accountId) {
            return response()->json(['error' => 'account_id required'], 422);
        }

        $account = MarketplaceAccount::find($accountId);
        if (!$account) {
            return response()->json(['error' => 'account_not_found'], 404);
        }

        $platform = $account->platform;

        $items = ClientProduct::where('marketplace_account_id', $accountId)
            ->whereNotNull('external_listing_id')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($p) use ($platform, $account) {
                $images = json_decode($p->custom_images ?? '[]', true);
                $imageUrl = is_array($images) && count($images) > 0 ? $images[0] : null;
                return [
                    'id'           => $p->id,
                    'item_id'      => $p->external_listing_id,
                    'platform'     => $platform,
                    'account_id'   => $account->id,
                    'sku'          => $p->custom_sku ?? $p->supplier_product_sku,
                    'title'        => $p->custom_title ?? 'Sem titulo',
                    'price'        => (float) ($p->custom_price ?? 0),
                    'status'       => $p->listing_status ?? 'active',
                    'image_url'    => $imageUrl,
                    'external_url' => $p->external_listing_url,
                    'product_id'   => $p->product_id,
                    'sync_status'  => $p->sync_status,
                    'created_at'   => $p->created_at,
                ];
            })
            ->values();

        return response()->json(['data' => $items, 'total' => $items->count()]);
    }

    // =========================================================================
    // ENDPOINT 3.3 — GET /api/public/marketplace-listings/{accountId}/{itemId}
    // Busca detalhes de um anuncio no marketplace via API externa
    // =========================================================================
    public function getMarketplaceListing(Request $request, $accountId, $itemId): JsonResponse
    {
        $account = MarketplaceAccount::find($accountId);
        if (!$account) {
            return response()->json(['error' => 'account_not_found'], 404);
        }

        $platform = $account->platform;

        try {
            if (in_array($platform, ['mercado_livre', 'mercadolivre'])) {
                $data = app(MercadoLivreService::class)->fetchItemDetail($account, $itemId);
            } elseif ($platform === 'shopee') {
                $data = app(ShopeeService::class)->fetchItemDetail($account, (int) $itemId);
            } else {
                return response()->json(['error' => 'not_supported', 'platform' => $platform], 422);
            }
        } catch (\Throwable $e) {
            Log::error('[marketplace-listings] Erro ao buscar anuncio', [
                'account_id' => $accountId,
                'item_id'    => $itemId,
                'error'      => $e->getMessage(),
            ]);
            return response()->json(['error' => 'fetch_failed', 'message' => $e->getMessage()], 500);
        }

        return response()->json($data);
    }

    // =========================================================================
    // ENDPOINT 3.4 — PUT /api/public/marketplace-listings/{accountId}/{itemId}
    // Atualiza campos de um anuncio no marketplace
    // =========================================================================
    public function updateMarketplaceListing(Request $request, $accountId, $itemId): JsonResponse
    {
        $account = MarketplaceAccount::find($accountId);
        if (!$account) {
            return response()->json(['error' => 'account_not_found'], 404);
        }

        $payload  = $request->only(['title', 'description', 'images', 'video_url', 'price', 'stock', 'attributes', 'ean']);
        $platform = $account->platform;

        try {
            if (in_array($platform, ['mercado_livre', 'mercadolivre'])) {
                $result = app(MercadoLivreService::class)->updateItemDetail($account, $itemId, $payload);
            } elseif ($platform === 'shopee') {
                $result = app(ShopeeService::class)->updateItemDetail(
                    $account,
                    (int) $itemId,
                    $payload['title'] ?? '',
                    $payload['description'] ?? '',
                    (float) ($payload['price'] ?? 0),
                    null
                );
            } else {
                return response()->json(['error' => 'not_supported', 'platform' => $platform], 422);
            }
        } catch (\Throwable $e) {
            Log::error('[marketplace-listings] Erro ao atualizar anuncio', [
                'account_id' => $accountId,
                'item_id'    => $itemId,
                'error'      => $e->getMessage(),
            ]);
            return response()->json(['error' => 'update_failed', 'message' => $e->getMessage()], 500);
        }

        // Recalcular score e persistir
        $score = $this->calculateScore($payload);
        $field = in_array($platform, ['mercado_livre', 'mercadolivre'])
            ? 'ml_external_item_id'
            : 'shopee_external_item_id';

        ClientProduct::where($field, $itemId)
            ->update(['listing_quality_score' => $score]);

        return response()->json(['success' => true, 'result' => $result, 'score_after' => $score]);
    }

    // =========================================================================
    // ENDPOINT 3.5 — GET /api/public/marketplace-listings/{accountId}/{itemId}/score
    // Score de qualidade baseado em dados locais (ClientProduct)
    // =========================================================================
    public function getMarketplaceScore(Request $request, $accountId, $itemId): JsonResponse
    {
        $account = MarketplaceAccount::find($accountId);
        if (!$account) {
            return response()->json(['error' => 'account_not_found'], 404);
        }

        $platform = $account->platform;
        $field    = in_array($platform, ['mercado_livre', 'mercadolivre'])
            ? 'ml_external_item_id'
            : 'shopee_external_item_id';

        $product     = ClientProduct::where($field, $itemId)->first();
        $score       = 0;
        $suggestions = [];

        if ($product) {
            if (strlen($product->custom_title ?? '') >= 60) {
                $score += 15;
            } else {
                $suggestions[] = 'Titulo com 60+ caracteres (+15pts)';
            }

            if (strlen($product->custom_description ?? '') >= 200) {
                $score += 20;
            } else {
                $suggestions[] = 'Descricao com 200+ caracteres (+20pts)';
            }

            $rawImages = $product->custom_images;
            $images    = is_array($rawImages) ? $rawImages : json_decode($rawImages ?? '[]', true);
            if (count($images ?? []) >= 3) {
                $score += 20;
            } else {
                $suggestions[] = 'Adicionar 3+ imagens (+20pts)';
            }

            // video_url: campo nao existe em client_products ainda
            $suggestions[] = 'Adicionar video ao anuncio (+15pts)';

            if (!empty($product->custom_gtin)) {
                $score += 10;
            } else {
                $suggestions[] = 'Informar EAN/codigo de barras (+10pts)';
            }

            $rawAttrs = $product->custom_attributes;
            $attrs    = is_array($rawAttrs) ? $rawAttrs : json_decode($rawAttrs ?? '[]', true);
            if (!empty($attrs)) {
                $score += 10;
            } else {
                $suggestions[] = 'Preencher atributos do produto (+10pts)';
            }

            if (($product->listing_status ?? '') === 'active') {
                $score += 10;
            } else {
                $suggestions[] = 'Anuncio ativo (+10pts)';
            }
        }

        return response()->json([
            'score'       => $score,
            'max'         => 100,
            'suggestions' => $suggestions,
            'item_id'     => $itemId,
            'account_id'  => $accountId,
        ]);
    }

    // =========================================================================
    // Webhooks legados (mantidos do original)
    // =========================================================================
    public function legacySync(Request $request): JsonResponse
    {
        $apiKey = $request->header('X-Api-Key');
        if ($apiKey !== config('services.legacy.sync_key')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $type     = $request->input('type', 'stock');
        $skuPaiId = $request->input('sku_pai_id');

        if (!$skuPaiId) {
            return response()->json(['error' => 'sku_pai_id required'], 422);
        }

        if ($type === 'delete') {
            $product = Product::where('legacy_sku_pai_id', $skuPaiId)->first();
            if ($product) {
                $product->update(['is_active' => false]);
                return response()->json(['ok' => true, 'action' => 'deactivated', 'product_id' => $product->id]);
            }
            return response()->json(['ok' => true, 'action' => 'not_found']);
        }

        if ($type === 'stock') {
            $newStock = (int) $request->input('estoque_real', 0);
            $product  = Product::where('legacy_sku_pai_id', $skuPaiId)->first();
            if (!$product) {
                return response()->json(['error' => 'Product not found'], 404);
            }
            Inventory::updateOrCreate(
                ['product_id' => $product->id, 'warehouse_id' => $product->supplier_id],
                ['producer_id' => $product->supplier_id, 'quantity' => max(0, $newStock)]
            );
            return response()->json(['ok' => true, 'action' => 'stock_updated', 'product_id' => $product->id, 'stock' => $newStock]);
        }

        if ($type === 'product') {
            $deposito = (int) $request->input('id_deposito', 0);
            $supplier = Supplier::where('legacy_id', $deposito)->first();
            if (!$supplier) {
                return response()->json(['error' => 'Supplier not found for deposito ' . $deposito], 404);
            }

            $sku         = $request->input('sku', "SKU-{$skuPaiId}");
            $prefixedSku = "D{$deposito}-{$sku}";

            $product = Product::where('legacy_sku_pai_id', $skuPaiId)->first()
                ?: Product::where('sku', $prefixedSku)->first();

            $data = [
                'legacy_sku_pai_id' => $skuPaiId,
                'supplier_id'       => $supplier->id,
                'sku'               => $prefixedSku,
                'name'              => $request->input('descricao', 'Sem nome'),
                'description'       => $request->input('desc_produto', ''),
                'price'             => (float) $request->input('custo_curso', 0),
                'cost'              => (float) $request->input('custo', 0),
                'ean'               => $request->input('ean'),
                'brand'             => $request->input('marca'),
                'weight_kg'         => (float) $request->input('peso', 0),
                'height_cm'         => (float) $request->input('altura', 0),
                'width_cm'          => (float) $request->input('largura', 0),
                'length_cm'         => (float) $request->input('comprimento', 0),
                'is_active'         => true,
            ];

            if ($product) {
                $product->update($data);
                $action = 'updated';
            } else {
                $product = Product::create($data);
                $action  = 'created';
            }

            $estoque = (int) $request->input('estoque_real', 0);
            Inventory::updateOrCreate(
                ['product_id' => $product->id, 'warehouse_id' => $supplier->id],
                ['producer_id' => $supplier->id, 'quantity' => max(0, $estoque)]
            );

            return response()->json(['ok' => true, 'action' => $action, 'product_id' => $product->id]);
        }

        return response()->json(['error' => 'Invalid type. Use: stock, product, delete'], 422);
    }

    public function legacyStock(Request $request): JsonResponse
    {
        $request->merge(['type' => 'stock']);
        return $this->legacySync($request);
    }
}
