<?php
namespace App\Services\Ai;

use App\Contracts\ScrapingContract;
use App\Models\AiEngine;

/**
 * SEL-429 -- Adapter DICloak Scraping (Kalodata/Viral via perfil compartilhado).
 *
 * PLACEHOLDER MVP: aguarda mapeamento dos profile_ids DICloak (SEL-426).
 * Lanca DicloakNotConfiguredException se profile_id nao definido.
 */
class DicloakScrapingAdapter implements ScrapingContract
{
    public function __construct(private AiEngine $engine) {}

    public function scrape(string $url, string $sessionKey = 'default', array $options = []): array
    {
        $cfg       = $this->engine->config_json ?? [];
        $profileId = $cfg['profile_id'] ?? null;

        if (empty($profileId)) {
            throw new DicloakNotConfiguredException(
                'DicloakScrapingAdapter: profile_id nao configurado. Aguarda SEL-426 mapear profile_id.'
            );
        }

        // SEL-429 F2: implementar via CDP + Kalodata no perfil DICloak
        throw new \RuntimeException('dicloak_scraping_stub: DicloakScrapingAdapter em desenvolvimento (SEL-429 F2).');
    }
}
