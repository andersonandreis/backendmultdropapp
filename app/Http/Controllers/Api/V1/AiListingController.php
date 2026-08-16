<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\SupplierAiSetting;
use App\Services\AIProductContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Endpoints de IA por WL (MUL-142-H).
 *
 * POST /api/v1/ai/generate-listing  — titulo + descricao com prompt do marketplace
 * POST /api/v1/ai/generate-image    — gera imagem via gpt-image e salva em product_media
 *
 * Ambos usam a chave OpenAI do WL (supplier_ai_settings).
 * Sem chave configurada -> 422 com mensagem clara.
 * Rate limit: 20 req/min por supplier.
 */
class AiListingController extends Controller
{
    private const RATE_LIMIT_PER_MIN = 20;

    private static function normalizeMarketplaceSlug(string $marketplace): string
    {
        $slug = strtolower(trim($marketplace));
        return match ($slug) {
            'mercadolivre', 'mercado_livre', 'mercado-livre', 'meli' => 'ml',
            'magazineluiza', 'magazine_luiza'                        => 'magalu',
            default                                                  => $slug,
        };
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/ai/generate-listing
    // -------------------------------------------------------------------------

    public function generateListing(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id'  => 'required|integer',
            'marketplace' => 'required|string|max:30',
        ]);
        // MUL-214 item 8: front manda o platform da conta (mercadolivre/mercado_livre);
        // prompts do admin sao keyed por slug curto — normaliza antes de usar.
        // Slug desconhecido cai no prompt base (promptForMarketplace ja faz fallback).
        $validated['marketplace'] = self::normalizeMarketplaceSlug($validated['marketplace']);

        $product  = $this->resolveProduct($validated['product_id']);
        $aiConfig = $this->resolveAiConfig($product);

        if (! $aiConfig->isReady()) {
            return response()->json([
                'error' => 'Configure sua chave OpenAI no painel admin para usar a IA.',
                'code'  => 'AI_NOT_CONFIGURED',
            ], 422);
        }

        if (! $this->checkRateLimit($aiConfig->supplier_id)) {
            return response()->json([
                'error' => 'Limite de 20 requisicoes por minuto atingido. Aguarde.',
                'code'  => 'RATE_LIMIT',
            ], 429);
        }

        try {
            $service = (new AIProductContentService())->setSupplierAiConfig($aiConfig);

            $marketplace  = $validated['marketplace'];
            $systemPrompt = $aiConfig->promptForMarketplace($marketplace);

            $title       = $service->generateTitleForMarketplace($product, $marketplace, $systemPrompt);
            $description = $service->generateDescriptionForMarketplace($product, $marketplace, $systemPrompt);

            Log::info('[AI-Listing] Gerado', [
                'supplier_id' => $aiConfig->supplier_id,
                'product_id'  => $product->id,
                'marketplace' => $marketplace,
            ]);

            return response()->json([
                'title'       => $title,
                'description' => $description,
                'marketplace' => $marketplace,
            ]);
        } catch (\RuntimeException $e) {
            Log::error('[AI-Listing] Erro', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'Falha ao gerar conteudo: ' . $e->getMessage()], 500);
        }
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/ai/generate-image
    // -------------------------------------------------------------------------

    public function generateImage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id'   => 'required|integer',
            'prompt_extra' => 'nullable|string|max:500',
        ]);

        $product  = $this->resolveProduct($validated['product_id']);
        $aiConfig = $this->resolveAiConfig($product);

        if (! $aiConfig->isReady()) {
            return response()->json([
                'error' => 'Configure sua chave OpenAI no painel admin para usar a IA.',
                'code'  => 'AI_NOT_CONFIGURED',
            ], 422);
        }

        if (! $this->checkRateLimit($aiConfig->supplier_id)) {
            return response()->json([
                'error' => 'Limite de 20 requisicoes por minuto atingido. Aguarde.',
                'code'  => 'RATE_LIMIT',
            ], 429);
        }

        try {
            $service = (new AIProductContentService())->setSupplierAiConfig($aiConfig);

            $imageUrl = $service->generateImage($product, $validated['prompt_extra'] ?? null);

            // Salva em product_media com content_hash para dedup (guard MUL-135)
            $hash   = md5($imageUrl);
            $exists = ProductMedia::where('product_id', $product->id)
                ->where('content_hash', $hash)
                ->exists();

            if (! $exists) {
                $position = (int) (ProductMedia::where('product_id', $product->id)->max('position') ?? -1) + 1;
                ProductMedia::create([
                    'product_id'   => $product->id,
                    'type'         => 'image',
                    'url'          => $imageUrl,
                    'content_hash' => $hash,
                    'position'     => $position,
                    'is_cover'     => false,
                ]);
            }

            Log::info('[AI-Image] Gerada', [
                'supplier_id' => $aiConfig->supplier_id,
                'product_id'  => $product->id,
                'duplicate'   => $exists,
            ]);

            return response()->json([
                'url'       => $imageUrl,
                'duplicate' => $exists,
            ]);
        } catch (\RuntimeException $e) {
            Log::error('[AI-Image] Erro', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'Falha ao gerar imagem: ' . $e->getMessage()], 500);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function resolveProduct(int $productId): Product
    {
        $product = Product::find($productId);
        if (! $product) {
            abort(404, 'Produto nao encontrado.');
        }
        return $product;
    }

    private function resolveAiConfig(Product $product): SupplierAiSetting
    {
        $supplierId = $product->supplier_id;
        $config     = SupplierAiSetting::firstOrNew(['supplier_id' => $supplierId]);

        // Fallback: chave global do servidor se WL nao tiver configurado
        // config() e nao env(): com config cacheado, env() retorna null em runtime
        if (empty($config->openai_api_key) && ! empty(config('services.openai.api_key'))) {
            $config->openai_api_key = config('services.openai.api_key');
            $config->ai_enabled     = true;
        }

        return $config;
    }

    private function checkRateLimit(int $supplierId): bool
    {
        $key = "ai_wl_{$supplierId}";
        return RateLimiter::attempt(
            $key,
            self::RATE_LIMIT_PER_MIN,
            fn() => true,
            60
        );
    }
    // MUL-161-BE1 #11a: POST /api/v1/ai/generate-carousel
    public function generateCarousel(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'product_id'  => 'required|integer',
            'marketplace' => 'nullable|string',
        ]);

        $product  = $this->resolveProduct($validated['product_id']);
        $aiConfig = $this->resolveAiConfig($product);

        if (! $aiConfig->isReady()) {
            return response()->json(['error' => 'Configure sua chave OpenAI no painel admin.', 'code' => 'AI_NOT_CONFIGURED'], 422);
        }

        if (! $this->checkRateLimit($aiConfig->supplier_id)) {
            return response()->json(['error' => 'Limite de requisicoes atingido.', 'code' => 'RATE_LIMIT'], 429);
        }

        try {
            set_time_limit(700);
            $service = (new \App\Services\AIProductContentService())->setSupplierAiConfig($aiConfig);

            $prompts = $service->generateCarouselPlan($product);

            $urls = [];
            foreach ($prompts as $prompt) {
                try {
                    $urls[] = $service->generateImage($product, $prompt);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[AI-Carousel] imagem falhou', ['error' => $e->getMessage()]);
                }
            }

            return response()->json(['data' => ['urls' => array_values($urls)]]);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => 'Falha ao gerar carrossel: ' . $e->getMessage()], 500);
        }
    }

    // MUL-161-BE1 #11b: POST /api/v1/ai/suggest-kits
    public function suggestKits(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $client = $request->user()?->client;
        if (! $client) {
            return response()->json(['error' => 'Perfil de lojista nao encontrado.'], 422);
        }

        $products = \App\Models\ClientProduct::where('client_id', $client->id)
            ->where('excluido', false)
            ->with('product:id,name,price,brand,sku,supplier_id')
            ->limit(50)->get()
            ->map(fn ($cp) => $cp->product)->filter()->values();

        if ($products->isEmpty()) {
            return response()->json(['data' => ['suggestions' => []]]);
        }

        $aiConfig = $this->resolveAiConfig($products->first());
        if (! $aiConfig->isReady()) {
            return response()->json(['error' => 'Configure sua chave OpenAI no painel admin.', 'code' => 'AI_NOT_CONFIGURED'], 422);
        }
        if (! $this->checkRateLimit($aiConfig->supplier_id)) {
            return response()->json(['error' => 'Limite atingido.', 'code' => 'RATE_LIMIT'], 429);
        }

        try {
            $service = (new \App\Services\AIProductContentService())->setSupplierAiConfig($aiConfig);

            $list = $products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'price' => $p->price])->toArray();
            $sys  = 'Especialista em kits e combos para e-commerce brasileiro. Sugira 3-5 kits que aumentem ticket medio.';
            $usr  = 'Produtos: ' . json_encode($list, JSON_UNESCAPED_UNICODE)
                . '. Retorne APENAS JSON array: [{name,items:[{product_id,qty}],rationale}]. Sem markdown.';

            $raw = $service->chat($sys, $usr, 1000);
            $raw = preg_replace(['/^```(?:json)?\s*/m', '/```\s*$/m'], '', trim($raw));
            if (!str_starts_with(ltrim($raw), '[')) {
                if (preg_match('/\[.*\]/s', $raw, $m)) $raw = $m[0];
            }
            $suggestions = json_decode(trim($raw), true) ?? [];

            return response()->json(['data' => ['suggestions' => $suggestions]]);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => 'Falha ao sugerir kits: ' . $e->getMessage()], 500);
        }
    }


}