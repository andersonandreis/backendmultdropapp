<?php
namespace App\Services\Ai;

use App\Contracts\ImageGeneratorContract;
use App\Models\AiEngine;

/**
 * SEL-429 -- Adapter DICloak Image (Midjourney/DALL-E via perfil compartilhado).
 *
 * PLACEHOLDER MVP: aguarda mapeamento dos profile_ids DICloak.
 * Lanca DicloakNotConfiguredException se profile_id nao definido,
 * permitindo o pool pular para outro adapter.
 *
 * F2 implementara: abrir perfil DICloak com Midjourney/DALL-E via CDP.
 */
class DicloakImageAdapter implements ImageGeneratorContract
{
    public function __construct(private AiEngine $engine) {}

    public function generate(string $prompt, array $refImages = [], string $size = '1024x1024'): array
    {
        $cfg       = $this->engine->config_json ?? [];
        $profileId = $cfg['profile_id'] ?? null;

        if (empty($profileId)) {
            throw new DicloakNotConfiguredException(
                'DicloakImageAdapter: profile_id nao configurado. Configure via /admin/ai-engines.'
            );
        }

        // SEL-429 F2: implementar via CDP + Midjourney/DALL-E no perfil DICloak
        throw new \RuntimeException('dicloak_image_stub: DicloakImageAdapter em desenvolvimento (SEL-429 F2).');
    }
}
