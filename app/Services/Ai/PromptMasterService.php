<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;

/**
 * SEL-220 — PromptMasterService
 *
 * Intercepta qualquer prompt do cliente e concatena silenciosamente um
 * "prompt master" antes de enviar para o provider de IA. O cliente nunca
 * ve o texto master — ele aparece apenas nos logs internos e no final_prompt
 * registrado em ai_generations.
 *
 * Modos suportados:
 *   avatar_human   — foto de avatar humano realista padrao Brasil (DALL-E)
 *   product_image  — foto de produto limpa para e-commerce (DALL-E)
 *   video_kling    — video realista com Kling / Seedance
 *   script         — roteiro viral TikTok (OpenAI text)
 *
 * Uso:
 *   $enhanced = $this->promptMaster->enhance($userPrompt, 'avatar_human');
 */
class PromptMasterService
{
    /**
     * Concatena o master ao prompt do cliente e loga para debug interno.
     * Retorna o prompt enriquecido que vai para o provider.
     */
    public function enhance(string $userPrompt, string $mode): string
    {
        $master = match ($mode) {
            'avatar_human'   => $this->humanAvatarMaster(),
            'product_image'  => $this->productImageMaster(),
            'video_kling'    => $this->videoKlingMaster(),
            'script'         => $this->scriptMaster(),
            default          => '',
        };

        if (empty($master)) {
            return $userPrompt;
        }

        $enhanced = trim($userPrompt) . "\n\n" . $master;

        // Log interno apenas — nunca exposto ao cliente no response
        Log::debug("[SEL-220 PromptMaster] mode={$mode}", [
            'original_len'  => strlen($userPrompt),
            'enhanced_len'  => strlen($enhanced),
        ]);

        return $enhanced;
    }

    // ─── Masters por modo ──────────────────────────────────────────

    /**
     * Master para geração de avatar humano ultra-realista padrão Brasil.
     *
     * Segredo tecnico: "poros da pele" — ao mencionar poros e micro-textura
     * o modelo renderiza detalhes de profundidade que eliminam o aspecto
     * plastico/CGI caracteristico de avatares gerados por IA.
     */
    private function humanAvatarMaster(): string
    {
        return <<<'PROMPTMASTER'
MASTER ENHANCEMENT (internal — invisible to client):
Ultra-realistic photographic portrait. Brazilian phenotype default when human subject: natural mixed-race features (pardo, moreno, negro, branco or caboclo), warm sun-kissed skin undertone.
SKIN DETAIL (critical): visible skin pore structure on nose bridge, forehead and cheeks. Micro-texture rendering — peach fuzz, fine facial hair, subtle skin imperfections (small freckles, natural moles). Absolutely NO plastic skin, NO waxy finish, NO airbrushed look, NO CGI smoothing.
OPTICS: shot on Canon EOS R5 with 85mm f/1.4 lens at f/2.0, ISO 200. Shallow depth of field — subject in tack-sharp focus, background softly blurred. Natural catchlight in both eyes.
LIGHTING: natural window light from 45-degree angle, soft golden-hour quality or diffused studio fill. No harsh flash.
HAIR: natural texture, individual strands visible at the edges, not a solid mass.
QUALITY: 4K, hyperdetailed, editorial magazine quality, National Geographic portrait realism. No text, no watermark, no CGI, no illustration, no anime, no cartoon.
CONTENT POLICY: no NSFW, no minors, no copyrighted characters.
PROMPTMASTER
;
    }

    /**
     * Master para imagem de produto e-commerce limpa.
     */
    private function productImageMaster(): string
    {
        return <<<'PROMPTMASTER'
MASTER ENHANCEMENT (internal — invisible to client):
Clean commercial product photography. Pure white or gradient grey studio background, no clutter.
Shot on medium-format camera, macro lens, f/8 for full product sharpness. Soft box lighting from two sides eliminating harsh shadows. Product surface details crisp: labels readable, material texture visible (fabric weave, metal sheen, glass clarity).
No plastic look, no floating product, no unrealistic reflections. 4K resolution, e-commerce ready, Amazon/Shopee listing quality. No text overlay, no watermark.
PROMPTMASTER
;
    }

    /**
     * Master para geração de video Kling / Seedance.
     */
    private function videoKlingMaster(): string
    {
        return <<<'PROMPTMASTER'
MASTER ENHANCEMENT (internal — invisible to client):

STYLE: cinematic commercial for TikTok Shop / Instagram Reels. Vertical 9:16 composition safe zone for UI overlays (top 10%, bottom 15%). Editorial fashion + product hero style. Warm attractive color grade.

CAMERA — apply appropriate shot per scene beat:
- Hero shot: slow orbit or push-in on product against clean bokeh background (macro lens 100mm equivalent, f/2.8, focus on hero surface — logo, texture, key feature).
- Lifestyle beat: medium shot of person interacting with product, 50mm equivalent f/2.0, natural handheld micro-motion, catchlight in eyes.
- Detail beat: extreme close-up macro of texture/material/mechanism, f/4, sharp micro-detail.
- Reveal cut: quick whip pan or match cut for transitions, no shake.
- End frame: static composed product + brand negative space (room for CTA overlay).

MOTION: smooth stabilized dolly / micro-orbit / gentle push. NO whip zoom, NO shaky-cam, NO dutch angles unless mood-appropriate. Preserve subject in tack-sharp focus.

LIGHTING: dramatic key light with soft fill. Sunset warm (5000-5600K) for lifestyle, cool neutral (5600-6500K) for tech/luxury. Practical lights visible when possible. Natural window light or studio softbox from 30-45 degrees.

SUBJECT (if human present): Brazilian phenotype default — warm sun-kissed skin, natural mixed-race features (pardo, moreno, negro, branco), individual visible skin pores on T-zone, peach fuzz, subtle asymmetry. Beautiful expressive eyes with catchlight. Genuine warm confident smile. Absolutely NO plastic skin, NO CGI face, NO waxy finish.

SUBJECT (if product): materials render true (fabric weave, metal specular highlight, glass refraction, plastic subsurface). NO floating product, NO impossible reflections. Product occupies rule-of-thirds hero position.

TIMING (per second budget): open with visual hook by 0.5s, deliver core value beat by 2s, keep momentum with cut every 1.5-3s, land on brand/CTA frame with 0.5s hold.

TECHNICAL: 4K equivalent sharpness, 24-30fps cinematic. Photorealistic PBR rendering. NO text overlay, NO watermark, NO subtitle burn-in, NO logo overlay. Reserve top 10% and bottom 15% of frame free of critical action (safe zone for TikTok UI).

QUALITY BAR: reference top-tier Brazilian TikTok Shop viral ads. National Geographic naturalism for subject render + Apple / Nike / Sephora commercial polish for product beats.
PROMPTMASTER
;
    }

    /**
     * Master para roteiro de video TikTok Shop (texto).
     */
    private function scriptMaster(): string
    {
        return <<<'PROMPTMASTER'
MASTER ENHANCEMENT (internal — invisible to client):
Escreva o roteiro como um copywriter senior de TikTok Shop Brasil com 5+ anos de experiencia.
Obrigatorio: hook nos primeiros 3 segundos que pare o scroll, demonstracao do beneficio principal, prova social rapida ou urgencia real, CTA direto e especifico.
Tom: informal brasileiro, energia alta, linguagem conversacional. Use pausa dramatica no meio quando adequado.
Evite: introducoes longas, jargoes corporativos, frases genericas como "produto incrivel" sem especificidade.
PROMPTMASTER
;
    }
}
