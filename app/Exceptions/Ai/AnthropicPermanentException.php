<?php

namespace App\Exceptions\Ai;

/**
 * INF-066: erro permanente da API da Anthropic -- NUNCA deve ser repetido.
 * Cobre: chave ausente/invalida (401), sem permissao (403), payload invalido
 * (400/422) e configuracao ausente (sem ANTHROPIC_API_KEY).
 */
class AnthropicPermanentException extends AnthropicException
{
    public function userMessage(): string
    {
        return 'Servico de IA temporariamente indisponivel. Tente novamente em alguns minutos.';
    }
}
