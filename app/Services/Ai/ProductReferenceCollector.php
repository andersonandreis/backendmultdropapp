<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SEL-361 Fase A -- ProductReferenceCollector
 *
 * Agrega ate 7 imagens de referencia para um produto a partir de multiplas fontes,
 * rankeadas por prioridade. Preenche galeria pobre via gpt-image-1 se necessario.
 */
class ProductReferenceCollector
{
    private const MAX_REFS = 7;
    private const MIN_REFS_BEFORE_GENERATE = 3;

    public function __construct(
        private OpenAiService $openai,
    ) {}

    public function collect(int $clientProductId): array
    {
        $images = [];

        try {
            $cp = DB::table("client_products")
                ->leftJoin("products", "products.id", "=", "client_products.product_id")
                ->where("client_products.id", $clientProductId)
                ->select([
                    "client_products.id",
                    "client_products.client_id",
                    "client_products.product_id",
                    "client_products.image_url",
                    "client_products.custom_images",
                    "client_products.custom_title",
                    "products.name as product_name",
                    "products.category_id as product_category_id",
                ])
                ->first();

            if (!$cp) {
                return $this->emptyResult();
            }

            $productName     = $cp->custom_title ?? $cp->product_name ?? "produto";
            $productCategory = $this->resolveCategoryName($cp->product_category_id ?? null);

            // P1 -- Cover
            if (!empty($cp->image_url)) {
                $images[] = ["url" => $cp->image_url, "kind" => "cover", "priority" => 1];
            }

            // P2 -- Custom images do cliente
            $customImages = $cp->custom_images ? json_decode($cp->custom_images, true) : [];
            if (is_array($customImages)) {
                foreach (array_slice($customImages, 0, 3) as $url) {
                    if (!empty($url)) {
                        $images[] = ["url" => $url, "kind" => "client_upload", "priority" => 2];
                    }
                }
            }

            // P3 -- Galeria produto base (generosa: cap final de 7 já limita)
            foreach (array_slice($this->fetchProductMediaUrls($cp->product_id ?? 0), 0, 6) as $url) {
                $images[] = ["url" => $url, "kind" => "gallery", "priority" => 3];
            }

            // P4 -- Kalodata reviews
            foreach (array_slice($this->fetchKalodataReviewImages($clientProductId), 0, 2) as $url) {
                $images[] = ["url" => $url, "kind" => "kalodata_review", "priority" => 4];
            }

            // P5 -- Variantes
            foreach (array_slice($this->fetchVariantImages($cp->product_id ?? 0), 0, 2) as $url) {
                $images[] = ["url" => $url, "kind" => "variant", "priority" => 5];
            }

            // Deduplicar
            $seen = []; $unique = [];
            foreach ($images as $img) {
                if (!isset($seen[$img["url"]])) {
                    $seen[$img["url"]] = true;
                    $unique[] = $img;
                }
            }
            $images = $unique;
            usort($images, fn($a, $b) => $a["priority"] <=> $b["priority"]);

            $usedAi = false;
            if (count($images) < self::MIN_REFS_BEFORE_GENERATE) {
                $aiImages = $this->generateSupplementImages($productName, $productCategory);
                foreach ($aiImages as $url) {
                    $images[] = ["url" => $url, "kind" => "ai_generated", "priority" => 6];
                }
                $usedAi = !empty($aiImages);
            }

            $images = array_slice($images, 0, self::MAX_REFS);

            return [
                "product"            => ["id" => $cp->id, "name" => $productName, "category" => $productCategory],
                "images"             => $images,
                "total_collected"    => count($images),
                "used_ai_supplement" => $usedAi,
            ];

        } catch (Throwable $e) {
            Log::error("[SEL-361 ProductReferenceCollector] error", ["err" => $e->getMessage()]);
            return $this->emptyResult();
        }
    }

    private function fetchProductMediaUrls(int $productId): array
    {
        if (!$productId) return [];
        try {
            return DB::table("product_media")->where("product_id", $productId)
                ->where("type", "image")->whereNotNull("url")
                ->orderByDesc("is_cover")->orderBy("position")
                ->limit(8)->pluck("url")->toArray();
        } catch (Throwable) {
            return [];
        }
    }

    private function fetchKalodataReviewImages(int $clientProductId): array
    {
        try {
            if (!DB::getSchemaBuilder()->hasTable("kalodata_reviews")) return [];
            return DB::table("kalodata_reviews")->where("client_product_id", $clientProductId)
                ->whereNotNull("review_image_url")->limit(3)->pluck("review_image_url")->toArray();
        } catch (Throwable) {
            return [];
        }
    }

    private function fetchVariantImages(int $productId): array
    {
        if (!$productId) return [];
        try {
            // Imagens de variante vivem em product_media (product_variation_id preenchido)
            return DB::table("product_media")->where("product_id", $productId)
                ->whereNotNull("product_variation_id")
                ->where("type", "image")->whereNotNull("url")
                ->orderBy("position")->limit(3)->pluck("url")->toArray();
        } catch (Throwable) {
            return [];
        }
    }

    private function resolveCategoryName(?int $categoryId): string
    {
        if (!$categoryId) return "geral";
        try {
            return (string) (DB::table("categories")->where("id", $categoryId)->value("name") ?: "geral");
        } catch (Throwable) {
            return "geral";
        }
    }

    private function generateSupplementImages(string $productName, string $category): array
    {
        try {
            $prompt = "Commercial product photography of {$productName} ({$category}). White studio background, professional lighting, product centered, e-commerce quality, ultra-realistic, no text.";
            $res = $this->openai->generateImage($prompt, size: "1024x1024", quality: "standard");
            $url = is_array($res) ? ($res["url"] ?? null) : $res;
            return $url ? [$url] : [];
        } catch (Throwable $e) {
            Log::warning("[SEL-361 ProductReferenceCollector] AI supplement failed", ["err" => $e->getMessage()]);
            return [];
        }
    }

    private function emptyResult(): array
    {
        return ["product" => [], "images" => [], "total_collected" => 0, "used_ai_supplement" => false];
    }
}
