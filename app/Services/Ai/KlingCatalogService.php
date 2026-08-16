<?php

namespace App\Services\Ai;

/**
 * SEL-361 - KlingCatalogService
 * Catalogo completo de capacidades Kling AI com custo estimado (BRL).
 * Exposto via GET /api/v1/studio/kling-catalog (auth:sanctum).
 */
class KlingCatalogService
{
    public static function catalog(): array
    {
        return [
            // IMAGE TO VIDEO
            ['id'=>'i2v_v1','name_pt'=>'Animar Foto (Rapido)','category'=>'image2video','model'=>'kling-v1','mode'=>'std','best_for'=>'Animacao simples, teste rapido','cost_per_5s'=>0.44,'max_duration'=>10,'aspect_ratios'=>['9:16','16:9','1:1'],'supports_camera'=>true,'notes'=>'Modelo mais rapido. Qualidade basica.'],
            ['id'=>'i2v_v1_6','name_pt'=>'Animar Foto (Recomendado)','category'=>'image2video','model'=>'kling-v1-6','mode'=>'std','best_for'=>'Produto fisico com movimento natural, POV, demonstracao','cost_per_5s'=>0.88,'max_duration'=>10,'aspect_ratios'=>['9:16','16:9','1:1','3:4','4:3'],'supports_camera'=>true,'notes'=>'Melhor custo-beneficio para TikTok Shop.'],
            ['id'=>'i2v_v2_1','name_pt'=>'Animar Foto (Alta Qualidade)','category'=>'image2video','model'=>'kling-v2-1','mode'=>'pro','best_for'=>'Moda, beleza, produto premium com detalhes finos','cost_per_5s'=>2.20,'max_duration'=>10,'aspect_ratios'=>['9:16','16:9','1:1'],'supports_camera'=>true,'notes'=>'Pro mode. Melhor para rosto e textura de tecido.'],
            ['id'=>'i2v_v3','name_pt'=>'Multi-Cortes IA (v3)','category'=>'image2video','model'=>'kling-v3','mode'=>'std','best_for'=>'Video com multiplos planos cinematograficos, maxima qualidade','cost_per_5s'=>3.50,'max_duration'=>10,'aspect_ratios'=>['9:16','16:9','1:1'],'supports_camera'=>true,'supports_multi_shot'=>true,'supports_elements'=>true,'notes'=>'Modelo mais avancado. Suporta multi-shot e elements (avatar+produto).'],
            // TEXT TO VIDEO
            ['id'=>'t2v_v1_6','name_pt'=>'Video do Zero (Texto)','category'=>'text2video','model'=>'kling-v1-6','mode'=>'std','best_for'=>'Cenario imaginado sem foto base, intro de marca','cost_per_5s'=>0.88,'max_duration'=>10,'notes'=>'Apenas descricao textual gera o video.'],
            ['id'=>'t2v_v2_1','name_pt'=>'Video do Zero (Alta Qualidade)','category'=>'text2video','model'=>'kling-v2-1','mode'=>'pro','best_for'=>'Cenas complexas, efeitos visuais, produto abstrato','cost_per_5s'=>2.20,'max_duration'=>10,'notes'=>'Pro mode text-to-video.'],
            // MULTI-IMAGE
            ['id'=>'mi2v_v1_6','name_pt'=>'Cena com Referencias Multiplas','category'=>'multi_image2video','model'=>'kling-v1-6','mode'=>'std','best_for'=>'Produto com muitos angulos, look book, variantes de cor','cost_per_5s'=>1.10,'max_duration'=>10,'max_images'=>7,'notes'=>'Ate 7 imagens de referencia mantem fidelidade do produto.'],
            // MULTI-SHOT V3
            ['id'=>'multishot_v3','name_pt'=>'Diretor IA - Multi-Shot (v3)','category'=>'multi_shot','model'=>'kling-v3','mode'=>'std','best_for'=>'Video roteirizado em 3-5 shots diferentes, narrativa completa','cost_per_5s'=>3.50,'max_duration'=>30,'max_shots'=>5,'notes'=>'Cada shot tem prompt, duracao e imagem de referencia proprios.'],
            // CAMERA CONTROL
            ['id'=>'camera_control','name_pt'=>'Camera Cinematografica','category'=>'camera_control','model'=>'kling-v1-6','mode'=>'std','best_for'=>'Dolly, pan, tilt, orbit sobre produto parado','cost_per_5s'=>0.88,'max_duration'=>10,'camera_types'=>['tilt','pan','roll','zoom','shake','master'],'notes'=>'Ideal para produto estatico com camera em movimento.'],
            // ELEMENTS
            ['id'=>'elements_avatar','name_pt'=>'Avatar Apresentando Produto (Elements)','category'=>'elements','model'=>'kling-v3','mode'=>'std','best_for'=>'Avatar exclusivo segurando e apresentando produto','cost_per_5s'=>3.50,'max_duration'=>30,'requires_avatar'=>true,'requires_product_image'=>true,'notes'=>'v3 exclusive. Exige avatar exclusivo do cliente.'],
            // LIP SYNC
            ['id'=>'lip_sync','name_pt'=>'Fazer Falar (Lip Sync)','category'=>'lip_sync','model'=>'kling-lip-sync','mode'=>'std','best_for'=>'Video com rosto + roteiro TTS sincronizado labialmente','cost_per_5s'=>0.88,'max_duration'=>60,'input_types'=>['video+audio','video+text'],'notes'=>'Sincroniza labios de qualquer video com novo audio.'],
            // FACE SWAP
            ['id'=>'face_swap','name_pt'=>'Trocar Rosto (Face Swap)','category'=>'face_swap','model'=>'kling-face-swap','mode'=>'std','best_for'=>'Substituir pessoa de video viral pelo avatar do cliente','cost_per_5s'=>1.32,'max_duration'=>30,'requires_avatar'=>true,'notes'=>'Anti-celebridade guard ativo. Exige avatar exclusivo.'],
            // VIRTUAL TRYON
            ['id'=>'virtual_tryon','name_pt'=>'Provador Virtual (KolorsVirtualTryOn)','category'=>'virtual_tryon','model'=>'kolors-virtual-try-on-v1-5','mode'=>'std','best_for'=>'Moda: roupa no corpo sem sessao de fotos','cost_per_5s'=>0.66,'max_duration'=>null,'output_type'=>'image','notes'=>'Gera imagem estatica. Foto pessoa + foto roupa.'],
            // VIDEO EXTEND
            ['id'=>'video_extend','name_pt'=>'Estender Video','category'=>'video_extend','model'=>'kling-v1-6','mode'=>'std','best_for'=>'Adicionar 4-5s a um video existente com continuidade','cost_per_5s'=>0.88,'notes'=>'Estende video mantendo contexto visual do ultimo frame.'],
            // EFFECTS
            ['id'=>'effect_unbox','name_pt'=>'Efeito Unboxing Viral','category'=>'effects','template_id'=>'unbox_2026','model'=>'kling-effects','mode'=>'std','best_for'=>'Produto sendo revelado em caixa','cost_per_5s'=>0.66,'max_duration'=>5,'notes'=>'Template fixo.'],
            ['id'=>'effect_cheers','name_pt'=>'Efeito Brinde','category'=>'effects','template_id'=>'cheers','model'=>'kling-effects','mode'=>'std','best_for'=>'Bebidas, celebracao, produto de lifestyle','cost_per_5s'=>0.66,'max_duration'=>5],
            ['id'=>'effect_teleport','name_pt'=>'Teletransporte','category'=>'effects','template_id'=>'teleport','model'=>'kling-effects','mode'=>'std','best_for'=>'Gadgets, eletronicos, produto com magia','cost_per_5s'=>0.66,'max_duration'=>5],
            ['id'=>'effect_countdown','name_pt'=>'Countdown CTA','category'=>'effects','template_id'=>'countdown','model'=>'kling-effects','mode'=>'std','best_for'=>'Oferta relampago, urgencia de compra','cost_per_5s'=>0.66,'max_duration'=>5],
        ];
    }

    public static function estimateCost(string $functionId, int $durationSec = 10, bool $withTts = false): float
    {
        $entry = collect(self::catalog())->firstWhere('id', $functionId);
        if (!$entry) return 0.0;
        $videoCost = round(($durationSec / 5) * ($entry['cost_per_5s'] ?? 0.44), 2);
        $ttsCost = $withTts ? round(($durationSec / 10) * 0.08, 2) : 0.0;
        return $videoCost + $ttsCost;
    }

    public static function suggestForContext(array $context): array
    {
        $cat = strtolower($context['category'] ?? 'geral');
        $imageCount = (int)($context['image_count'] ?? 0);
        $hasAvatar  = (bool)($context['has_avatar'] ?? false);
        $suggestions = [];
        if ($imageCount >= 2) {
            $suggestions[] = ['function_id'=>'mi2v_v1_6','reason'=>$imageCount.' fotos - fidelidade maxima ao produto','score'=>95];
        }
        if (in_array($cat, ['moda','roupa','calcado','vestuario','acessorios','fashion'])) {
            $suggestions[] = ['function_id'=>'virtual_tryon','reason'=>'Categoria moda - provador virtual gera conversao 3x maior','score'=>90];
        }
        if ($hasAvatar) {
            $suggestions[] = ['function_id'=>'elements_avatar','reason'=>'Avatar exclusivo disponivel - cena realista avatar+produto','score'=>88];
        }
        if ($imageCount >= 1) {
            $suggestions[] = ['function_id'=>'i2v_v1_6','reason'=>'Foto animada - melhor custo-beneficio para TikTok Shop','score'=>70];
        }
        $suggestions[] = ['function_id'=>'t2v_v1_6','reason'=>'Sem foto - gerado so com descricao','score'=>50];
        usort($suggestions, fn($a, $b) => $b['score'] - $a['score']);
        return array_slice($suggestions, 0, 4);
    }
}
