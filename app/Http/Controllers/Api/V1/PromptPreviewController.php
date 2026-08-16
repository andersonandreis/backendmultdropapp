<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Ai\KaloclipStyleScriptService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SEL-387 — Prompt Preview no formato 10 secoes Kaloclip.
 *
 * POST /api/v1/ai/prompt-preview
 *
 * Gera JSON com as 10 secoes do padrao Kaloclip para previa antes de gerar
 * o video. Retorna tambem final_prompt (string concatenada pronta pro Kling),
 * duration_sec=12, language=pt-BR, aspect_ratio=9:16.
 *
 * Log obrigatorio (criterio §2.2 SEL-387):
 *   [PromptBuilder] format=kaloclip_10sections product_id=X duration=12 language=pt-BR aspect=9:16
 */
class PromptPreviewController extends Controller
{
    public function __construct(
        private KaloclipStyleScriptService $kaloclip,
    ) {}

    public function preview(Request $request)
    {
        $v = $request->validate([
            'product_id'         => 'nullable|integer',
            'product_key'        => 'nullable|string|max:200',
            'product_name'       => 'nullable|string|max:400',
            'product_category'   => 'nullable|string|max:100',
            'product_price'      => 'nullable|numeric',
            'product_desc'       => 'nullable|string|max:1000',
            'images'             => 'nullable|array|max:8',
            'images.*'           => 'url|max:2000',
            'mode'               => 'nullable|string|in:showcase_kaloclip,video_do_zero,pov_so_mao',
            'reference_viral_id' => 'nullable|integer',
            'tone'               => 'nullable|string|in:informativo,urgente,carinhoso,engracado',
            'duration_sec'       => 'nullable|integer|in:10,12,15,20,40',
        ]);

        $durationSec     = (int) ($v['duration_sec'] ?? 12);
        $tone            = $v['tone'] ?? 'informativo';
        $productName     = $v['product_name'] ?? null;
        $productCategory = $v['product_category'] ?? 'geral';
        $productPrice    = $v['product_price'] ?? null;
        $productDesc     = $v['product_desc'] ?? '';
        $images          = $v['images'] ?? [];

        // Busca dados do produto se product_id fornecido
        if (!empty($v['product_id'])) {
            try {
                $cp = DB::table('client_products')
                    ->leftJoin('products', 'products.id', '=', 'client_products.product_id')
                    ->where('client_products.id', $v['product_id'])
                    ->select([
                        DB::raw('COALESCE(client_products.custom_title, products.name) as name'),
                        DB::raw('COALESCE(client_products.custom_price, products.price) as price'),
                        'products.category',
                        'products.description',
                        'client_products.image_url',
                        'client_products.custom_images',
                    ])
                    ->first();
                if ($cp) {
                    $productName     = $productName ?: $cp->name;
                    $productCategory = ($productCategory !== 'geral') ? $productCategory : ($cp->category ?? 'geral');
                    $productPrice    = $productPrice ?: $cp->price;
                    $productDesc     = $productDesc ?: ($cp->description ?? '');
                    if (empty($images)) {
                        $imgs = [];
                        if ($cp->image_url) $imgs[] = $cp->image_url;
                        $customImages = [];
                        if (!empty($cp->custom_images)) {
                            $customImages = is_string($cp->custom_images)
                                ? (json_decode($cp->custom_images, true) ?? [])
                                : (array) $cp->custom_images;
                        }
                        $images = array_values(array_unique(array_merge($imgs, $customImages)));
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[PromptPreview] product_id lookup failed', [
                    'id'  => $v['product_id'],
                    'err' => $e->getMessage(),
                ]);
            }
        } elseif (!empty($v['product_key'])) {
            try {
                $rows = DB::table('tiktok_product_images')
                    ->where('product_key', $v['product_key'])
                    ->whereNotNull('url_local')
                    ->orderByDesc('quality_score')
                    ->limit(8)
                    ->pluck('url_local')
                    ->toArray();
                if (!empty($rows) && empty($images)) {
                    $images = $rows;
                }
            } catch (\Throwable $e) { /* tabela pode nao existir em outros backends */ }
        }

        // Hook do viral de referencia (se enviado)
        $referenceHook = null;
        if (!empty($v['reference_viral_id'])) {
            try {
                $viral = DB::table('tiktok_viral_videos')
                    ->where('id', $v['reference_viral_id'])
                    ->first(['caption', 'detected_product_title']);
                if ($viral) {
                    $referenceHook = mb_substr($viral->caption ?? $viral->detected_product_title ?? '', 0, 120);
                }
            } catch (\Throwable $e) { /* ignore */ }
        }

        // Gera roteiro 10 secoes via KaloclipStyleScriptService
        $product = [
            'name'        => $productName ?? 'Produto',
            'images'      => $images,
            'price'       => $productPrice,
            'category'    => $productCategory,
            'description' => $productDesc,
        ];

        $script = $this->kaloclip->generate($product, $durationSec, $tone);

        // Adapta action_timeline para formato do briefing (0-4s / 4-8s / 8-12s)
        $actionTimeline = [];
        if (is_array($script['action_timeline'] ?? null)) {
            foreach ($script['action_timeline'] as $shot) {
                $start = number_format((float) ($shot['start_s'] ?? 0), 0);
                $end   = number_format((float) ($shot['end_s'] ?? 4), 0);
                $key   = "{$start}-{$end}s";
                $actionTimeline[$key] = trim(($shot['shot'] ?? '') . ': ' . ($shot['description'] ?? ''));
            }
        } else {
            $actionTimeline = [
                '0-4s'   => 'Apresentacao do produto',
                '4-8s'   => 'Demonstracao de beneficio principal',
                '8-12s'  => 'CTA + urgencia',
            ];
        }

        // Monta as 10 secoes no formato canonico do briefing
        $sections = [
            'style'           => $script['style']       ?? 'Video UGC vertical para redes sociais, Brasil, estetica natural e autentica',
            'scene'           => $script['scene']       ?? 'Ambiente domestico claro e aconchegante, sem espelhos',
            'subject'         => $script['subject']     ?? 'Apresentadora brasileira 20-30 anos com o produto em maos',
            'action_timeline' => $actionTimeline,
            'camera'          => $script['camera']      ?? 'Camera de mao dinamica com movimentos sutis, nivel dos olhos',
            'framing'         => '9:16 vertical, produto sempre em quadro',
            'performance'     => $script['performance'] ?? 'Apresentadora falando diretamente para a camera, expressao natural e engajada',
            'lighting'        => $script['lighting_color'] ?? 'Iluminacao natural difusa de janela, cores quentes vibrantes',
            'audio_diegetic'  => $script['audio_diegetic'] ?? 'Voz feminina brasileira natural, portugues informal de rede social',
            'audio_timing'    => $script['audio_timing'] ?? 'Dialogo continuo bem ritmado, terminando 1s antes do fim do video',
            'negative'        => $script['negative']    ?? 'sem texto legivel, sem maos distorcidas, sem reflexos, sem bocas obstruidas, sem ingles',
        ];

        // Se tinha referencia viral, enriquece o style
        if ($referenceHook) {
            $sections['style'] .= ' (inspirado em viral: "' . mb_substr($referenceHook, 0, 60) . '...")';
        }

        // Gera prompt final concatenando as 10 secoes via KaloclipStyleScriptService::toKlingPrompt
        $scriptForPrompt = array_merge($script, [
            'framing'        => $sections['framing'],
            'lighting_color' => $sections['lighting'],
        ]);
        $finalPrompt = $this->kaloclip->toKlingPrompt($scriptForPrompt);

        // Log obrigatorio criterio §2.2 SEL-387
        Log::info(
            '[PromptBuilder] format=kaloclip_10sections product_id=' . ($v['product_id'] ?? 'null') .
            ' duration=' . $durationSec . ' language=pt-BR aspect=9:16'
        );

        return response()->json([
            'sections'     => $sections,
            'final_prompt' => $finalPrompt,
            'duration_sec' => $durationSec,
            'language'     => 'pt-BR',
            'aspect_ratio' => '9:16',
        ]);
    }
}
