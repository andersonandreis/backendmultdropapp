<?php

namespace App\Services\Ai;

/**
 * SEL-073: catalogo oficial dos modelos Seedance na BytePlus ModelArk.
 * Precos USD por 1M tokens (online inference), coletados 13/07/2026 de
 * docs.byteplus.com/en/docs/ModelArk/1544106.
 *
 * Formula oficial: tokens = duracao(s) x largura x altura x fps / 1024 (fps 24).
 * Cobra apenas geracoes com sucesso; consumo real vem em usage.completion_tokens.
 */
class SeedanceCatalog
{
    public const DEFAULT_MODEL = 'seedance-1-0-pro-fast-251015';

    /** width x height por resolucao (validado contra exemplos oficiais de preco). */
    private const DIMS = [
        '480p'  => [864, 480],
        '720p'  => [1280, 720],
        '1080p' => [1920, 1080],
        '4k'    => [3840, 2160],
    ];

    private const FPS = 24;

    /**
     * usd_per_m pode ser float (flat) ou array por resolucao.
     * usd_per_m_audio: preco quando gera COM audio nativo (so 1.5 pro).
     * usd_per_m_video_input: preco quando o input inclui video (so SD 2.0).
     */
    public static function models(): array
    {
        return [
            [
                'id'          => 'seedance-1-0-pro-fast-251015',
                'label'       => 'Seedance 1.0 Pro Fast',
                'family'      => '1.0',
                'usd_per_m'   => 1.0,
                'audio'       => false,
                'video_input' => false,
                'resolutions' => ['480p', '720p', '1080p'],
                'cheapest'    => true,
                'notes'       => 'MAIS BARATO da ModelArk — 72% mais barato e ~3x mais rapido que o 1.0 Pro. Ideal pra volume.',
            ],
            [
                'id'                 => 'seedance-1-5-pro-251215',
                'label'              => 'Seedance 1.5 Pro',
                'family'             => '1.5',
                'usd_per_m'          => 1.2,
                'usd_per_m_audio'    => 2.4,
                'audio'              => true,
                'video_input'        => false,
                'resolutions'        => ['480p', '720p', '1080p'],
                'cheapest'           => false,
                'notes'              => 'UNICO com AUDIO nativo. Draft mode: x0.7 sem audio / x0.6 com audio.',
            ],
            [
                'id'          => 'seedance-1-0-pro-250528',
                'label'       => 'Seedance 1.0 Pro',
                'family'      => '1.0',
                'usd_per_m'   => 2.5,
                'audio'       => false,
                'video_input' => false,
                'resolutions' => ['480p', '720p', '1080p'],
                'cheapest'    => false,
                'notes'       => 'Classico 1.0 — qualidade consolidada, mais caro e mais lento que o Pro Fast.',
            ],
            [
                'id'                     => 'dreamina-seedance-2-0-mini-260615',
                'label'                  => 'Seedance 2.0 Mini',
                'family'                 => '2.0',
                'usd_per_m'              => 3.5,
                'usd_per_m_video_input'  => 2.1,
                'audio'                  => false,
                'video_input'            => true,
                'resolutions'            => ['480p', '720p'],
                'cheapest'               => false,
                'notes'                  => 'SD 2.0 de entrada — geracao nova geracao mais barata; sem 1080p.',
            ],
            [
                'id'                     => 'dreamina-seedance-2-0-fast-260128',
                'label'                  => 'Seedance 2.0 Fast',
                'family'                 => '2.0',
                'usd_per_m'              => 5.6,
                'usd_per_m_video_input'  => 3.3,
                'audio'                  => false,
                'video_input'            => true,
                'resolutions'            => ['480p', '720p'],
                'cheapest'               => false,
                'notes'                  => 'SD 2.0 rapido; sem 1080p.',
            ],
            [
                'id'                     => 'dreamina-seedance-2-0-260128',
                'label'                  => 'Seedance 2.0 (topo)',
                'family'                 => '2.0',
                'usd_per_m'              => ['480p' => 7.0, '720p' => 7.0, '1080p' => 7.7, '4k' => 4.0],
                'usd_per_m_video_input'  => ['480p' => 4.3, '720p' => 4.3, '1080p' => 4.7, '4k' => 2.4],
                'audio'                  => false,
                'video_input'            => true,
                'resolutions'            => ['480p', '720p', '1080p', '4k'],
                'cheapest'               => false,
                'notes'                  => 'Topo de linha SD 2.0 — melhor qualidade, aceita video como input, unico com 4k.',
            ],
        ];
    }

    /** @return string[] ids validos (whitelist do generate) */
    public static function ids(): array
    {
        return array_column(self::models(), 'id');
    }

    public static function find(?string $id): ?array
    {
        foreach (self::models() as $m) {
            if ($m['id'] === $id) return $m;
        }
        return null;
    }

    /** Preco USD por 1M tokens do modelo na resolucao dada. */
    public static function unitPriceUsd(?string $model, string $resolution = '720p', bool $audio = false, bool $videoInput = false): float
    {
        $m = self::find($model) ?? self::find(self::DEFAULT_MODEL);
        $price = $m['usd_per_m'];
        if ($audio && !empty($m['usd_per_m_audio'])) $price = $m['usd_per_m_audio'];
        if ($videoInput && !empty($m['usd_per_m_video_input'])) $price = $m['usd_per_m_video_input'];
        if (is_array($price)) $price = $price[$resolution] ?? ($price['720p'] ?? reset($price));
        return (float) $price;
    }

    /** Tokens estimados pela formula oficial. */
    public static function estimateTokens(string $resolution, int $seconds): int
    {
        [$w, $h] = self::DIMS[$resolution] ?? self::DIMS['720p'];
        return (int) round($seconds * $w * $h * self::FPS / 1024);
    }

    public static function estimateCostUsd(?string $model, string $resolution, int $seconds, bool $audio = false): float
    {
        return round(self::estimateTokens($resolution, $seconds) / 1_000_000 * self::unitPriceUsd($model, $resolution, $audio), 4);
    }

    /** Custo real a partir de usage.completion_tokens devolvido pela API. */
    public static function costFromTokens(?string $model, int $tokens, string $resolution = '720p', bool $audio = false): float
    {
        return round($tokens / 1_000_000 * self::unitPriceUsd($model, $resolution, $audio), 4);
    }
}
