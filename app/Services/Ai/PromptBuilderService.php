<?php

namespace App\Services\Ai;

/**
 * SEL-115: consolida as selecoes do wizard num prompt final em INGLES para o Seedance.
 * Prompts em EN para compatibilidade otima com modelos BytePlus (treinados em EN).
 * Traducao PT->EN: internamente por templates (sem custo extra de API).
 */
class PromptBuilderService
{
    /**
     * SEL-115: 9 presets EN ricos (5-8 linhas por estilo).
     * Rotulos PT (Cinematografico/Hook viral/etc) sao traduzidos aqui internamente.
     */
    public const STYLE_TEMPLATES = [
        'cinematic' =>
            'Premium cinematic product video, vertical 9:16 frame. ' .
            'Shot on full-frame DSLR with 50mm prime lens, shallow depth of field, product in sharp focus. ' .
            'Dramatic side lighting with subtle rim light highlighting product edges. ' .
            'Smooth slow dolly-forward camera movement gracefully revealing product. ' .
            'Precise editorial cuts every 1.5-2 seconds, each frame a composed photograph. ' .
            'Teal and orange color grading, rich saturation, deep cinematic blacks. ' .
            'Studio-clean background, aspirational desirable atmosphere. No text overlay, no watermark.',

        'hook' =>
            'Viral hook video engineered for maximum TikTok attention. ' .
            'First 3 seconds: aggressive zoom-in on product, bright light flash, explosive fast movement. ' .
            'Jarring cuts every 0.5-1 second in the opening sequence. ' .
            'Vibrant oversaturated colors, extreme contrast, maximum visual impact. ' .
            'Product revealed from unexpected extreme close-up angle. ' .
            'Dynamic motion blur on every transition, energy matching trending beats. ' .
            'Designed to stop the scroll on the very first frame. No text, no watermark.',

        'unboxing' =>
            'Premium ASMR unboxing video, slow and deeply satisfying product reveal. ' .
            'Hands carefully lifting product from pristine packaging in foreground. ' .
            'Soft diffused overhead lighting with zero harsh shadows. ' .
            'Slow deliberate cuts emphasizing texture, weight, and craftsmanship quality. ' .
            'Extreme close-up macro shots of product surface details and materials. ' .
            'Clean minimal background, muted neutral tones, luxury unboxing aesthetic. ' .
            'Tactile visual storytelling creating aspirational product desire. No text, no watermark.',

        'demo' =>
            'Slow motion product demonstration, 120-240fps cinematic capture. ' .
            'Stabilized camera showing product in active real-world use revealing key benefit. ' .
            'Hero slow-motion moment: drops, splashes, texture interaction captured frame by frame. ' .
            'Natural daylight or calibrated studio lighting, accurate neutral colors. ' .
            'Three-angle coverage: hero shot, extreme detail close-up, in-use perspective. ' .
            'Cuts synchronized with implied sound design impact moments. ' .
            'Clean composition, product as unambiguous visual protagonist. No text, no watermark.',

        'before_after' =>
            'Before and after transformation video with powerful visual contrast. ' .
            'Left half: cold desaturated blue tones representing the problem state. ' .
            'Right half: warm golden tones revealing the product solution result. ' .
            'Hard vertical split wipe transition at exact video midpoint, synchronized cut. ' .
            'Dramatic lighting shift between halves amplifying perceived product impact. ' .
            'Rhythmic anticipation-building cuts leading into the reveal moment. ' .
            'Product as the unmistakable agent of positive transformation. No text, no watermark.',

        'lifestyle' =>
            'Aspirational lifestyle product video in elevated real-world setting. ' .
            'Person naturally using product in bright modern apartment, outdoor terrace, or nature trail. ' .
            'Natural golden hour lighting, warm positive emotional color palette. ' .
            'Candid spontaneous shots blended with composed hero product frames. ' .
            'Product integrated seamlessly into aspirational daily life narrative. ' .
            'Color palette: warm whites, soft greens, lifestyle magazine editorial aesthetic. ' .
            'Story arc: relatable moment, product discovery, visible life improvement. No text, no watermark.',

        'asmr' =>
            'Tactile ASMR product video, deeply satisfying close-up sensory experience. ' .
            'Extreme macro close-up on product surface: fabric weave, liquid sheen, skin texture. ' .
            'Soft studio lighting with gentle sculpting shadows emphasizing three-dimensionality. ' .
            'Contemplative slow rhythm with long meditative holds on satisfying visual details. ' .
            'Camera moves minimally: slight rack focus shifts, millimeter push-ins. ' .
            'Product textures, edges, and materials rendered in museum-gallery quality detail. ' .
            'Silent film aesthetic where visuals carry the full sensory satisfaction. No text, no watermark.',

        'ugc' =>
            'Authentic UGC-style product video with genuine real-creator energy. ' .
            'Shot on smartphone aesthetic, slightly unstable natural handheld movement. ' .
            'Available ambient room lighting in realistic home or urban environment. ' .
            'Casual direct-to-camera spontaneous unscripted feel, authentic human presence. ' .
            'Genuine discovery moment: person picks up product, inspects, reacts positively. ' .
            'Slight overexposure, Instagram-real color with no professional grading. ' .
            'Trustworthy peer recommendation aesthetic that builds instant credibility. No text, no watermark.',

        'custom' => '',
    ];

    /** Movimento de camera - descricao EN para o Seedance. */
    public const CAMERA_MOVES = [
        'dolly-forward' => 'smooth slow dolly-forward camera movement advancing toward the product',
        'zoom-in'       => 'progressive digital zoom-in gradually revealing product details',
        'pan-lateral'   => 'horizontal pan sweep showing product from multiple angles',
        'static'        => 'static locked-off camera, fixed composition, product fully in frame',
        'handheld'      => 'slight natural handheld camera movement adding organic energy',
    ];

    /**
     * Overlays TikTok Shop - descricao textual pra guiar composicao da cena.
     * Nao sao queimados no video; sobrepostos depois no frontend via ffmpeg.wasm.
     */
    public const OVERLAY_HINTS = [
        'ts_cart'     => 'leave clear negative space in bottom-right corner for shopping cart button overlay',
        'ts_price'    => 'leave clear negative space in top-left corner for price badge overlay',
        'ts_cta'      => 'leave clear negative space in bottom area for call-to-action text overlay',
        'ts_discount' => 'visual emphasis on product as a value highlight for discount badge overlay',
    ];

    /** Aspect ratio. */
    public const ASPECT_LABELS = [
        '9:16' => 'vertical 9:16 format (TikTok Shop / Reels / Stories)',
        '16:9' => 'horizontal 16:9 format (YouTube / feed)',
        '1:1'  => 'square 1:1 format',
        '4:3'  => '4:3 format',
        '3:4'  => 'portrait 3:4 format',
    ];

    /**
     * Recebe wizard_payload e retorna { prompt, negative_prompt, ratio, duration }.
     * Prompts sao gerados em EN para melhor qualidade no Seedance.
     */
    public function build(array $w): array
    {
        $parts = [];

        $aspect = $w['aspect_ratio'] ?? '9:16';
        $parts[] = self::ASPECT_LABELS[$aspect] ?? self::ASPECT_LABELS['9:16'];

        $style = $w['style'] ?? 'cinematic';
        if (!empty(self::STYLE_TEMPLATES[$style])) {
            $parts[] = self::STYLE_TEMPLATES[$style];
        }

        $camera = $w['camera_move'] ?? null;
        if ($camera && isset(self::CAMERA_MOVES[$camera])) {
            $parts[] = self::CAMERA_MOVES[$camera];
        }

        $product = $w['product_name'] ?? null;
        if ($product) {
            $parts[] = 'Hero product: ' . $product . '.';
        }

        $description = $w['product_description'] ?? null;
        if ($description) {
            $parts[] = $description;
        }

        $overlays = $w['overlays'] ?? [];
        if (is_array($overlays)) {
            foreach ($overlays as $ov) {
                if (isset(self::OVERLAY_HINTS[$ov])) {
                    $parts[] = self::OVERLAY_HINTS[$ov];
                }
            }
        }

        $hook = $w['hook'] ?? null;
        if ($hook) {
            $parts[] = 'Opening hook concept (first 3s): ' . $hook;
        }

        $custom = $w['custom_prompt'] ?? null;
        if ($custom) {
            $parts[] = $custom;
        }

        // SEL-220: master de realismo embutido no builder — aplica em TODOS os videos wizard
        $parts[] = 'Ultra high quality, professional studio lighting, no text overlay, no watermark, no subtitles.' .
                   ' Photorealistic rendering, no CGI artifacts, no plastic skin on any human subject.' .
                   ' If human subject present: visible skin pore detail, natural Brazilian phenotype default, catchlight in eyes, shallow depth of field.';

        $negative = trim(
            ($w['negative_prompt'] ?? '') .
            ' text overlay, watermark, subtitle, distorted hands, distorted product, blurry, ugly, low quality'
        );

        return [
            'prompt'          => trim(implode(' ', array_filter($parts))),
            'negative_prompt' => $negative,
            'ratio'           => $aspect,
            'duration'        => (int) ($w['duration'] ?? 5),
        ];
    }
}
