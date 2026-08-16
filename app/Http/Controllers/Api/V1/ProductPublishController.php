<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Services\Integrations\Marketplaces\MercadoLivreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Publicacao de produtos em marketplaces sem autenticacao Sanctum.
 * Usada pela migracao hubai.io → api.hubai.io (substitui goolhub.io/cad_produtos.php).
 */
class ProductPublishController extends Controller
{
    public function __construct(private readonly MercadoLivreService $mlService)
    {
    }

    /**
     * POST /api/v1/products/publish
     *
     * Publica um produto no marketplace indicado.
     * Endpoint publico (sem Sanctum) — autenticado via client_id + plataforma.
     */
    public function publish(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id'                  => 'required|integer',
            'platform'                   => 'required|string|in:mercadolivre,shopee',
            'product'                    => 'required|array',
            'product.title'              => 'required|string|max:255',
            'product.price'              => 'required|numeric|min:0',
            'product.category_id'        => 'required|max:50',
            'product.description'        => 'nullable|string',
            'product.available_quantity' => 'nullable|integer|min:1',
            'product.condition'          => 'nullable|string|in:new,used',
            'product.listing_type_id'    => 'nullable|string',
            'product.pictures'           => 'nullable|array',
            'product.pictures.*'         => 'nullable|url',
            'product.video_url'          => 'nullable|string',
            'product.currency_id'        => 'nullable|string|max:10',
            'product.ean'                => 'nullable|string|max:30',
            'product.attributes'         => 'nullable|array',
            'product.sku_codigo'          => 'nullable|string|max:100',
        ]);

        $clientId = (int) $validated['client_id'];
        $platform = $validated['platform'];
        $product  = $validated['product'];

        // Busca conta de marketplace ativa para este client_id e plataforma.
        // Guard NOV-156: ignora contas pending/fantasma (OAuth abandonado: status=pending, seller_id NULL, sem token).
        // withoutTenantSupplierScope: muitas contas legitimas tem supplier_id=NULL (antes de tenant_supplier),
        // o scope as filtraria — endpoint publico se autentica via client_id, scope nao faz sentido aqui.
        $account = MarketplaceAccount::withoutTenantSupplierScope()
            ->where('client_id', $clientId)
            ->where('platform', $platform)
            ->whereIn('status', ['active', 'connected'])
            ->whereNotNull('seller_id')
            ->first();

        // Fallback: frontend pode enviar legacy_id_login (login.id do goolhub)
        // enquanto marketplace_accounts usa clients.id do novo sistema
        if (! $account) {
            $legacyClient = DB::table('clients')->where('legacy_id_login', $clientId)->first();
            if ($legacyClient) {
                $account = MarketplaceAccount::withoutTenantSupplierScope()
                    ->where('client_id', $legacyClient->id)
                    ->where('platform', $platform)
                    ->whereIn('status', ['active', 'connected'])
                    ->whereNotNull('seller_id')
                    ->first();
                if ($account) {
                    Log::info('[ProductPublishController] Conta encontrada via legacy_id_login', [
                        'legacy_client_id' => $clientId,
                        'resolved_client_id' => $legacyClient->id,
                        'account_id' => $account->id,
                    ]);
                }
            }
        }

        if (! $account) {
            // Guard NOV-156: detecta caso em que existe conta pending/fantasma (OAuth abandonado),
            // mas nenhuma ativa — retorna mensagem clara para o cliente reconectar.
            // withoutTenantSupplierScope porque ghosts podem ter supplier_id NULL
            // e seriam filtrados pelo TenantSupplierScope, mascarando o problema.
            $pendingExists = MarketplaceAccount::withoutTenantSupplierScope()
                ->where('client_id', $clientId)
                ->where('platform', $platform)
                ->where(function ($q) {
                    $q->where('status', 'pending')->orWhereNull('seller_id');
                })
                ->exists();
            $platformLabel = $platform === 'mercadolivre' ? 'Mercado Livre' : ($platform === 'shopee' ? 'Shopee' : ucfirst($platform));
            $message = $pendingExists
                ? "Conexao com $platformLabel incompleta. Reconecte a integracao para publicar."
                : "Nenhuma conta $platformLabel conectada. Conecte a integracao para publicar.";
            Log::warning('[ProductPublishController] Conta nao encontrada', [
                'client_id' => $clientId,
                'platform'  => $platform,
                'pending_exists' => $pendingExists,
            ]);
            return response()->json([
                'success' => false,
                'error'   => $message,
                'cause'   => [['code' => $pendingExists ? 'account_pending_reauth' : 'account_not_found']],
            ], $pendingExists ? 422 : 404);
        }


        // ---------------------------------------------------------------
        // Shopee: delega para ShopeeService
        // ---------------------------------------------------------------
        if ($platform === 'shopee') {
            /** @var \App\Services\Integrations\Marketplaces\ShopeeService $shopeeService */
            $shopeeService = app(\App\Services\Integrations\Marketplaces\ShopeeService::class);
            $result = $shopeeService->publishProduct($account, $product);

            $itemId = $result['response']['item_id'] ?? null;
            if (!$itemId) {
                Log::error('[ProductPublishController] Shopee retornou erro', [
                    'client_id' => $clientId,
                    'error'     => $result['error'] ?? 'unknown',
                    'message'   => $result['message'] ?? null,
                ]);
                return response()->json([
                    'success' => false,
                    'error'   => $result['message'] ?? 'Erro ao publicar na Shopee.',
                    'cause'   => [['code' => $result['error'] ?? 'shopee_error']],
                ], 422);
            }

            Log::info('[ProductPublishController] Produto publicado com sucesso na Shopee', [
                'client_id'  => $clientId,
                'account_id' => $account->id,
                'item_id'    => $itemId,
            ]);

            // Registrar em product_listings
            $skuCodigo = $product['sku_codigo'] ?? null;
            if ($skuCodigo) {
                DB::table('product_listings')->updateOrInsert(
                    ['client_id' => $account->client_id, 'sku_codigo' => $skuCodigo, 'marketplace' => 'shopee'],
                    ['item_id' => (string) $itemId, 'status' => 'active', 'updated_at' => now(), 'created_at' => now()]
                );
            }

            return response()->json([
                'success'    => true,
                'item_id'    => $itemId,
                'shopee_url' => "https://shopee.com.br/product/{$account->shop_id}/{$itemId}",
            ]);
        }

        // ---------------------------------------------------------------
        // Delega ao servico: getAccessToken ja renova se expirado
        $token = $this->mlService->getAccessToken($account);

        if (! $token) {
            Log::error('[ProductPublishController] Token invalido apos tentativa de refresh', [
                'client_id'  => $clientId,
                'account_id' => $account->id,
            ]);
            return response()->json([
                'success' => false,
                'error'   => 'Token do Mercado Livre invalido ou expirado. Reconecte a conta.',
                'cause'   => [['code' => 'invalid_token']],
            ], 401);
        }

        // Monta payload para a API do ML
        $pictures = collect($product['pictures'] ?? [])
            ->filter()
            ->map(fn($url) => ['source' => $url])
            ->values()
            ->toArray();

        $payload = [
            'title'              => mb_substr($product['title'], 0, 60),
            'category_id'        => $this->resolveMLCategory($product['category_id'] ?? '', $product['title'] ?? ''),
            'price'              => round((float) $product["price"], 2),
            'currency_id'        => $product['currency_id'] ?? 'BRL',
            'available_quantity' => (int) ($product['available_quantity'] ?? 1),
            'buying_mode'        => 'buy_it_now',
            'listing_type_id'    => $product['listing_type_id'] ?? 'gold_special',
            'condition'          => $product['condition'] ?? 'new',
            'description'        => ['plain_text' => $product['description'] ?? ''],
            'pictures'           => $pictures,
        ];

        // EAN/GTIN
        $ean = $product['ean'] ?? null;
        if ($ean) {
            $payload['attributes'][] = ['id' => 'GTIN', 'value_name' => $ean];
        }

        // Atributos extras passados pelo caller
        foreach ($product['attributes'] ?? [] as $attr) {
            if (! empty($attr['id']) && ! empty($attr['value_name'])) {
                $payload['attributes'][] = $attr;
            }
        }

        // Atributos obrigatorios da categoria (auto-preenchidos se ausentes)
        $categoryForAttrs = $payload['category_id'];
        $existingAttrIds  = array_column($payload['attributes'] ?? [], 'id');
        $autoAttrs        = $this->resolveMLAttributes($categoryForAttrs, $existingAttrIds);
        if (! empty($autoAttrs)) {
            $payload['attributes'] = array_merge($payload['attributes'] ?? [], $autoAttrs);
        }

        Log::info('[ProductPublishController] Publicando produto no ML', [
            'client_id'  => $clientId,
            'account_id' => $account->id,
            'title'      => $payload['title'],
            'category'   => $payload['category_id'],
        ]);

        [$mlOk, $data] = $this->publishMLWithRetry($token, $payload, $clientId, $account->id);

        if (!$mlOk) {
            return response()->json([
                'success' => false,
                'error'   => $data['message'] ?? $data['error'] ?? 'Erro ao publicar no Mercado Livre.',
                'cause'   => $data['cause'] ?? [],
            ], 422);
        }

        Log::info('[ProductPublishController] Produto publicado com sucesso no ML', [
            'client_id'   => $clientId,
            'account_id'  => $account->id,
            'item_id'     => $data['id'],
            'permalink'   => $data['permalink'] ?? null,
        ]);


        // NOV-020: registrar publicacao em product_listings
        $skuCodigo = $product['sku_codigo'] ?? null;
        if ($skuCodigo) {
            DB::table('product_listings')->updateOrInsert(
                ['client_id' => $clientId, 'sku_codigo' => $skuCodigo, 'marketplace' => 'mercadolivre'],
                [
                    'item_id'     => $data['id'],
                    'listing_url' => $data['permalink'] ?? null,
                    'status'      => 'active',
                    'updated_at'  => now(),
                    'created_at'  => now(),
                ]
            );
        }


        return response()->json([
            'success'   => true,
            'item_id'   => $data['id'],
            'permalink' => $data['permalink'] ?? null,
            'ml_url'    => $data['permalink'] ?? null,
        ]);
    }

    private function resolveMLAttributes(string $categoryId, array $existingIds): array
    {
        $result = [];
        try {
            $res = Http::timeout(5)->get("https://api.mercadolibre.com/categories/{$categoryId}/attributes");
            if (! $res->successful()) {
                return [];
            }
            foreach ($res->json() as $attr) {
                if (empty($attr['tags']['required'])) {
                    continue;
                }
                $id = $attr['id'];
                if (in_array($id, $existingIds, true)) {
                    continue;
                }
                if (! empty($attr['values'])) {
                    $result[] = [
                        'id'         => $id,
                        'value_id'   => (string) $attr['values'][0]['id'],
                        'value_name' => $attr['values'][0]['name'],
                    ];
                } else {
                    $fallback = ($id === 'BRAND') ? 'Sem marca' : 'Generico';
                    $result[] = ['id' => $id, 'value_name' => $fallback];
                }
            }
            Log::info('[ProductPublish] atributos obrigatorios auto-preenchidos', [
                'category' => $categoryId,
                'added'    => array_column($result, 'id'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[ProductPublish] falha ao buscar atributos ML', ['error' => $e->getMessage()]);
        }
        return $result;
    }

    private function resolveMLCategory(string $categoryId, string $title): string
    {
        if (str_starts_with(strtoupper($categoryId), 'MLB')) {
            return $categoryId;
        }
        try {
            $q = urlencode(mb_substr($title, 0, 80));
            $res = \Illuminate\Support\Facades\Http::timeout(5)
                ->get("https://api.mercadolibre.com/sites/MLB/domain_discovery/search?q={$q}&limit=1");
            if ($res->successful()) {
                $data = $res->json();
                $cat = $data[0]['category_id'] ?? null;
                if ($cat) {
                    \Illuminate\Support\Facades\Log::info('[ProductPublish] categoria ML resolvida', ['original' => $categoryId, 'resolved' => $cat]);
                    return $cat;
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[ProductPublish] falha domain discovery', ['error' => $e->getMessage()]);
        }
        return 'MLB1144';
    }

    // ---------------------------------------------------------------
    // ML Auto-Fix: publica com retry automatico para erros corrigiveis
    // ---------------------------------------------------------------
    private function publishMLWithRetry(string $token, array $payload, int $clientId, int $accountId): array
    {
        $maxAttempts = 3;
        $data = [];

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->post('https://api.mercadolibre.com/items', $payload);
            $data = $response->json() ?? [];

            if (!$response->failed()) {
                \Illuminate\Support\Facades\Log::info('[ProductPublish] Produto publicado no ML', [
                    'client_id'  => $clientId,
                    'account_id' => $accountId,
                    'attempt'    => $attempt,
                    'item_id'    => $data['id'] ?? null,
                ]);
                return [true, $data];
            }

            $causes = $data['cause'] ?? [];
            \Illuminate\Support\Facades\Log::warning('[ProductPublish] ML tentativa ' . $attempt . ' falhou', [
                'client_id'   => $clientId,
                'account_id'  => $accountId,
                'http_status' => $response->status(),
                'ml_message'  => $data['message'] ?? null,
                'cause_codes' => array_column($causes, 'code'),
            ]);

            if ($attempt >= $maxAttempts) break;

            $fixed = $this->applyMLAutoFixes($payload, $causes, $clientId);
            if (!$fixed) break;
        }

        \Illuminate\Support\Facades\Log::error('[ProductPublishController] ML falhou apos tentativas', [
            'client_id'  => $clientId,
            'account_id' => $accountId,
            'ml_message' => $data['message'] ?? null,
            'ml_cause'   => $data['cause'] ?? [],
        ]);

        return [false, $data];
    }

    private function applyMLAutoFixes(array &$payload, array $causes, int $clientId): bool
    {
        $fixed = false;
        $byCode = [];
        foreach ($causes as $c) {
            if (!empty($c['code'])) $byCode[$c['code']] = $c;
        }

        // Fix 1: EAN/GTIN ja usado em outra categoria — remover
        if (isset($byCode['item.attribute.invalid_product_identifier'])) {
            $payload['attributes'] = array_values(array_filter(
                $payload['attributes'] ?? [],
                fn($a) => ($a['id'] ?? '') !== 'GTIN'
            ));
            \Illuminate\Support\Facades\Log::info('[ProductPublish] Auto-fix: removeu GTIN conflitante', ['client_id' => $clientId]);
            $fixed = true;
        }

        // Fix 2: Atributos obrigatorios faltando
        if (isset($byCode['item.attributes.missing_required'])) {
            $msg = $byCode['item.attributes.missing_required']['message'] ?? '';
            if (preg_match('/\[([^\]]+)\]/', $msg, $m)) {
                $missingIds  = array_map('trim', explode(',', $m[1]));
                $existingIds = array_column($payload['attributes'] ?? [], 'id');
                foreach ($missingIds as $attrId) {
                    if (in_array($attrId, $existingIds, true)) continue;
                    $defaultVal = $this->getMLAttributeDefaultValue($payload['category_id'] ?? 'MLB1144', $attrId, (string) ($payload['title'] ?? $payload['family_name'] ?? ''));
                    if ($defaultVal) {
                        $payload['attributes'][] = $defaultVal;
                        \Illuminate\Support\Facades\Log::info('[ProductPublish] Auto-fix: atributo ' . $attrId . ' preenchido', ['client_id' => $clientId]);
                        $fixed = true;
                    }
                }
            }
        }

        // Fix 3: Precisao de preco invalida
        if (isset($byCode['item.price.invalid'])) {
            $payload['price'] = round((float) $payload['price'], 2);
            \Illuminate\Support\Facades\Log::info('[ProductPublish] Auto-fix: preco arredondado', ['client_id' => $clientId]);
            $fixed = true;
        }

        // Fix 4: Titulo muito longo
        if (isset($byCode['item.title.max_length'])) {
            $payload['title'] = mb_substr($payload['title'], 0, 60);
            \Illuminate\Support\Facades\Log::info('[ProductPublish] Auto-fix: titulo truncado', ['client_id' => $clientId]);
            $fixed = true;
        }


        // Fix 5: body.required_fields [family_name] -- categoria com catalogo obrigatorio no ML.
        // O ML exige family_name (campo raiz) quando o dominio tem catalogo obrigatorio (ex: MLB-KITCHEN_KNIVES).
        // Solucao: remover title do payload, adicionar family_name com o titulo original.
        // Testado em 26/06/2026 com conta REDEACHADOSBR (account_id=400, MLB194032).
        if (isset($byCode['body.required_fields'])) {
            $causeMsg = $byCode['body.required_fields']['message'] ?? '';
            if (str_contains($causeMsg, 'family_name')) {
                $originalTitle = $payload['title'] ?? '';
                if ($originalTitle) {
                    $payload['family_name'] = mb_substr($originalTitle, 0, 60);
                    unset($payload['title']);
                    \Illuminate\Support\Facades\Log::info('[ProductPublish] Auto-fix: convertido para fluxo catalogo ML (family_name)', [
                        'client_id'   => $clientId,
                        'family_name' => $payload['family_name'],
                    ]);
                    $fixed = true;
                }
            }
        }

        return $fixed;
    }

    private function getMLAttributeDefaultValue(string $categoryId, string $attrId, string $title = ''): ?array
    {
        $hardcoded = [
            'BRAND'            => ['id' => 'BRAND',            'value_name' => 'Sem marca'],
            'GENDER'           => ['id' => 'GENDER',           'value_name' => 'Unissex'],
            'COLOR'            => ['id' => 'COLOR',            'value_name' => 'Nao especificado'],
            'SIZE'             => ['id' => 'SIZE',             'value_name' => 'Unico'],
            'MODEL'            => ['id' => 'MODEL',            'value_name' => 'Generico'],
            'MAIN_MATERIAL'    => ['id' => 'MAIN_MATERIAL',    'value_name' => 'Aco inoxidavel'],
            'PASTRY_TIP_SHAPE' => ['id' => 'PASTRY_TIP_SHAPE', 'value_name' => 'Redondo'],
            'ITEM_CONDITION'   => ['id' => 'ITEM_CONDITION',   'value_name' => 'Novo'],
            'ORIGIN'           => ['id' => 'ORIGIN',           'value_name' => 'China'],
            'WITH_DIGITAL_LOCK'=> ['id' => 'WITH_DIGITAL_LOCK','value_name' => 'Nao'],
        ];

        if (isset($hardcoded[$attrId])) {
            return $hardcoded[$attrId];
        }

        try {
            $res = \Illuminate\Support\Facades\Http::timeout(5)
                ->get("https://api.mercadolibre.com/categories/{$categoryId}/attributes");
            if ($res->successful()) {
                foreach ((is_array($res->json()) ? $res->json() : []) as $attr) { // FOR-087
                    if (($attr['id'] ?? '') !== $attrId) continue;
                    // SEL-322/SEL-323: number_unit sem values -> extrai numero+unidade do titulo ou pula
                    if (($attr['value_type'] ?? '') === 'number_unit' && empty($attr['values'])) {
                        $fromTitle = $this->extractNumberUnitFromTitle($title, $attr);
                        return $fromTitle ? ['id' => $attrId, 'value_name' => $fromTitle] : null;
                    }
                    if (!empty($attr['values'])) {
                        return [
                            'id'         => $attrId,
                            'value_id'   => (string) $attr['values'][0]['id'],
                            'value_name' => $attr['values'][0]['name'],
                        ];
                    }
                    return ['id' => $attrId, 'value_name' => 'Nao especificado'];
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[ProductPublish] Falha ao buscar atributo ' . $attrId, ['error' => $e->getMessage()]);
        }

        return null;
    }



    private function extractNumberUnitFromTitle(string $title, array $attr): ?string
    {
        $units = [];
        foreach (($attr['allowed_units'] ?? []) as $u) {
            $id = (string) ($u['id'] ?? '');
            if ($id !== '') $units[mb_strtolower($id)] = $id;
        }
        if (!$units || $title === '') return null;
        $alias = ['metro' => 'm', 'metros' => 'm', 'litro' => 'l', 'litros' => 'l', 'mililitro' => 'ml', 'mililitros' => 'ml', 'grama' => 'g', 'gramas' => 'g', 'quilo' => 'kg', 'quilos' => 'kg'];
        if (!preg_match_all('/(\\d+(?:[.,]\\d+)?)\\s*([a-zA-Z"]{1,12})/u', $title, $ms, PREG_SET_ORDER)) return null;
        foreach ($ms as $m) {
            $u = mb_strtolower($m[2]);
            $u = $alias[$u] ?? $u;
            if (isset($units[$u])) {
                return str_replace(',', '.', $m[1]) . ' ' . $units[$u];
            }
        }
        return null;
    }

}
