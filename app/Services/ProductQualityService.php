<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ClientProduct;

/**
 * Calcula o quality score de um produto para Shopee e ML.
 *
 * Pontuacao maxima: 100
 *   +10  tem titulo (name ou ai_title)
 *   +10  descricao longa (>200 chars)
 *   +15  por imagem (max 5 imagens = 75 pontos max, mas limitado a 15 pois max total = 100)
 *        Regra real: 1 imagem=+15, 2=+20, 3=+25, 4=+30, 5=+30 (cap via sum logic abaixo)
 *   +20  tem video
 *   +10  tem marca
 *   +5   tem EAN/GTIN
 *   +10  tem peso e dimensoes (todos os 4 campos)
 *   +10  tem categoria definida
 *   +10  tem atributos preenchidos
 *
 * Total maximo antes do cap = 10+10+30+20+10+5+10+10+10 = 115, aplicamos min(score,100)
 */
class ProductQualityService
{
    /**
     * Calcula o quality score de um produto base (tabela products).
     *
     * @return array{score: int, issues: string[]}
     */
    public function calculateProductScore(Product $product): array
    {
        $score  = 0;
        $issues = [];

        // Titulo
        $title = $product->ai_title ?: $product->name;
        if ($title && mb_strlen(trim($title)) > 3) {
            $score += 10;
        } else {
            $issues[] = 'Sem titulo definido';
        }

        // Descricao
        $desc = $product->ai_description ?: $product->description;
        if ($desc && mb_strlen(trim($desc)) > 200) {
            $score += 10;
        } elseif ($desc && mb_strlen(trim($desc)) > 0) {
            $issues[] = 'Descricao muito curta (menos de 200 caracteres)';
        } else {
            $issues[] = 'Sem descricao';
        }

        // Imagens — +15 primeira, +5 por adicional (max +30 total)
        $imageCount = $product->media()->where('type', 'image')->count();
        if ($imageCount === 0) {
            $issues[] = 'Sem imagens';
        } else {
            $imgScore = min(15 + ($imageCount - 1) * 5, 30);
            $score   += $imgScore;
            if ($imageCount < 3) {
                $issues[] = 'Poucas imagens (' . $imageCount . '/3 recomendado)';
            }
        }

        // Video
        if ($product->video_url) {
            $score += 20;
        } else {
            $issues[] = 'Sem video do produto (adicionar aumenta conversao)';
        }

        // Marca
        if (!empty($product->brand) || !empty($product->model_name)) {
            $score += 10;
        } else {
            $issues[] = 'Sem marca definida';
        }

        // EAN/GTIN
        if (!empty($product->ean) || !empty($product->gtin)) {
            $score += 5;
        } else {
            $issues[] = 'Sem EAN/GTIN (codigo de barras)';
        }

        // Peso e dimensoes
        $hasDimensions = $product->weight_kg && $product->height_cm && $product->width_cm && $product->length_cm;
        if ($hasDimensions) {
            $score += 10;
        } else {
            $issues[] = 'Dimensoes incompletas (peso, altura, largura, comprimento)';
        }

        // Categoria
        if ($product->category_id || $product->shopee_category_id || $product->ml_category_id) {
            $score += 10;
        } else {
            $issues[] = 'Sem categoria definida';
        }

        // Atributos
        $hasAttrs = !empty($product->attributes) || !empty($product->ml_attributes) || !empty($product->shopee_attributes);
        if ($hasAttrs) {
            $score += 10;
        } else {
            $issues[] = 'Sem atributos de produto (marca, modelo, cor, etc.)';
        }

        return [
            'score'  => min($score, 100),
            'issues' => $issues,
        ];
    }

    /**
     * Calcula o quality score de um client_product (anuncio do lojista).
     * Combina dados do produto base com customizacoes do lojista.
     *
     * @return array{score: int, issues: string[]}
     */
    public function calculateClientProductScore(ClientProduct $cp): array
    {
        $score  = 0;
        $issues = [];

        // Titulo
        $title = $cp->custom_title ?: ($cp->product?->ai_title ?: $cp->product?->name);
        if ($title && mb_strlen(trim($title)) > 3) {
            $score += 10;
        } else {
            $issues[] = 'Sem titulo para o anuncio';
        }

        // Descricao
        $desc = $cp->custom_description ?: ($cp->product?->ai_description ?: $cp->product?->description);
        if ($desc && mb_strlen(trim($desc)) > 200) {
            $score += 10;
        } elseif ($desc && mb_strlen(trim($desc)) > 0) {
            $issues[] = 'Descricao muito curta (menos de 200 caracteres)';
        } else {
            $issues[] = 'Sem descricao no anuncio';
        }

        // Imagens (custom ou do produto)
        $customImages = $cp->custom_images ?? [];
        if (!empty($customImages)) {
            $imageCount = count($customImages);
        } else {
            $imageCount = $cp->product?->media()->where('type', 'image')->count() ?? 0;
        }

        if ($imageCount === 0) {
            $issues[] = 'Sem imagens no anuncio';
        } else {
            $imgScore = min(15 + ($imageCount - 1) * 5, 30);
            $score   += $imgScore;
            if ($imageCount < 3) {
                $issues[] = 'Poucas imagens (' . $imageCount . '/3 recomendado)';
            }
        }

        // Video
        if ($cp->product?->video_url) {
            $score += 20;
        } else {
            $issues[] = 'Sem video (aumenta conversao)';
        }

        // Marca
        $brand = $cp->custom_brand ?: $cp->product?->brand;
        if (!empty($brand)) {
            $score += 10;
        } else {
            $issues[] = 'Sem marca no anuncio';
        }

        // EAN/GTIN
        $gtin = $cp->custom_gtin ?: ($cp->product?->ean ?: $cp->product?->gtin);
        if (!empty($gtin)) {
            $score += 5;
        } else {
            $issues[] = 'Sem codigo de barras (EAN/GTIN)';
        }

        // Peso e dimensoes
        $hasDims = ($cp->custom_weight_kg || $cp->product?->weight_kg)
                && ($cp->custom_height_cm || $cp->product?->height_cm)
                && ($cp->custom_width_cm  || $cp->product?->width_cm)
                && ($cp->custom_length_cm || $cp->product?->length_cm);
        if ($hasDims) {
            $score += 10;
        } else {
            $issues[] = 'Dimensoes incompletas (necessario para calculo de frete)';
        }

        // Categoria externa
        if ($cp->external_category_id) {
            $score += 10;
        } else {
            $issues[] = 'Sem categoria do marketplace selecionada';
        }

        // Atributos
        $hasAttrs = !empty($cp->custom_attributes)
                 || !empty($cp->product?->ml_attributes)
                 || !empty($cp->product?->shopee_attributes);
        if ($hasAttrs) {
            $score += 10;
        } else {
            $issues[] = 'Sem atributos do marketplace (marca, modelo, etc.)';
        }

        return [
            'score'  => min($score, 100),
            'issues' => $issues,
        ];
    }

    /**
     * Retorna label textual do score.
     */
    public static function scoreLabel(int $score): string
    {
        if ($score >= 80) return 'Excelente';
        if ($score >= 60) return 'Bom';
        if ($score >= 40) return 'Regular';
        return 'Ruim';
    }
}