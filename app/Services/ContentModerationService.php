<?php

namespace App\Services;

use App\Models\ForbiddenWord;
use Illuminate\Support\Facades\Cache;

/**
 * SEL-361 Fase E — Moderação de conteúdo para Modo Prompt Livre.
 *
 * Verifica se um prompt de texto viola regras de ToS:
 *  - Celebridades e marcas registradas (tabela forbidden_words)
 *  - Padrões de nome próprio suspeito via heurística simples
 *
 * Retorna { flagged: bool, reason: string|null, matched: string|null }
 */
class ContentModerationService
{
    // Cache de 5 minutos para não bater no banco a cada request
    private const CACHE_TTL = 300;

    /**
     * Verifica prompt.
     *
     * @return array{ flagged: bool, reason: string|null, matched: string|null }
     */
    public function moderate(string $prompt): array
    {
        $words = $this->loadForbiddenWords();

        $promptLower = mb_strtolower($prompt);

        foreach ($words as $entry) {
            $wordLower = mb_strtolower($entry['word']);
            if (str_contains($promptLower, $wordLower)) {
                $context = $entry['context'] ?? 'conteudo proibido';
                $word    = $entry['word'];
                return [
                    'flagged' => true,
                    'reason'  => "Prompt bloqueado: mencao a \"{$word}\" ({$context}) nao e permitida.",
                    'matched' => $word,
                ];
            }
        }

        return ['flagged' => false, 'reason' => null, 'matched' => null];
    }

    /**
     * Carrega palavras proibidas com cache.
     */
    private function loadForbiddenWords(): array
    {
        return Cache::remember('forbidden_words_moderation', self::CACHE_TTL, function () {
            return ForbiddenWord::where('is_active', true)
                ->select('word', 'context')
                ->get()
                ->toArray();
        });
    }
}
