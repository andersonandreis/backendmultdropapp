<?php

namespace App\Exceptions\Ai;

/**
 * INF-066: erro transitorio da API da Anthropic (429/500/502/503/529,
 * timeout ou reset de conexao) que esgotou o numero maximo de tentativas
 * do backoff exponencial com jitter.
 */
class AnthropicTransientException extends AnthropicException
{
    public function userMessage(): string
    {
        return 'Servico de IA temporariamente indisponivel. Tente novamente em alguns minutos.';
    }
}
