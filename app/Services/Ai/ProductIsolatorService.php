<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * SEL-361 Fase A -- ProductIsolatorService
 *
 * Remove background do produto para usar como referencia no Kling
 * (produto isolado = Kling gera cena NOVA em vez de animar a foto).
 *
 * Estrategia:
 *   1. remove.bg API (se KEY configurada)
 *   2. Fallback: gpt-image-1 com prompt de remocao de fundo
 *
 * Resultado salvo em storage, URL retornada e cacheada em client_products.isolated_image_url.
 */
class ProductIsolatorService
{
    public function __construct(
        private OpenAiService $openai,
    ) {}

    /**
     * Isola o produto da imagem de fundo.
     *
     * @param int    $clientProductId  ID do client_products
     * @param string $imageUrl         URL da imagem a isolar
     * @return string|null  URL do PNG isolado, ou null em falha
     */
    public function isolate(int $clientProductId, string $imageUrl): ?string
    {
        // Verificar cache
        $cached = DB::table("client_products")
            ->where("id", $clientProductId)
            ->value("isolated_image_url");

        if ($cached) {
            return $cached;
        }

        $isolatedUrl = $this->tryRemoveBg($imageUrl)
            ?? $this->fallbackGptImage($imageUrl);

        if ($isolatedUrl) {
            // Salvar na coluna (pode nao existir ainda -- fail silencioso)
            try {
                DB::table("client_products")
                    ->where("id", $clientProductId)
                    ->update(["isolated_image_url" => $isolatedUrl]);
            } catch (Throwable) {
                // Coluna pode nao existir ainda -- nao bloqueia
            }
        }

        return $isolatedUrl;
    }

    private function tryRemoveBg(string $imageUrl): ?string
    {
        $apiKey = config("services.remove_bg.key");
        if (!$apiKey) return null;

        try {
            $response = Http::withHeaders(["X-Api-Key" => $apiKey])
                ->timeout(30)
                ->attach("image_url", $imageUrl)
                ->post("https://api.remove.bg/v1.0/removebg", [
                    "image_url"   => $imageUrl,
                    "size"        => "auto",
                    "format"      => "png",
                ]);

            if ($response->failed()) {
                Log::warning("[SEL-361 ProductIsolator] remove.bg failed", ["status" => $response->status()]);
                return null;
            }

            // Salvar PNG em storage
            $key = "isolated/" . Str::uuid() . ".png";
            Storage::disk("public")->put($key, $response->body());
            return Storage::disk("public")->url($key);

        } catch (Throwable $e) {
            Log::warning("[SEL-361 ProductIsolator] remove.bg exception", ["err" => $e->getMessage()]);
            return null;
        }
    }

    private function fallbackGptImage(string $imageUrl): ?string
    {
        try {
            // Usar gpt-image-1 edit com mascara transparente
            $prompt = "Remove all background from this product image. Result must be the product on a pure transparent background. PNG format. Keep all product details exactly as-is. No shadows, no background elements.";
            $url = $this->openai->editImage($imageUrl, $prompt);
            return $url ?: null;
        } catch (Throwable $e) {
            Log::warning("[SEL-361 ProductIsolator] gpt-image-1 fallback failed", ["err" => $e->getMessage()]);
            return null;
        }
    }
}
