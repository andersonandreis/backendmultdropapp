<?php

namespace App\Exceptions\Ai;

use RuntimeException;
use Throwable;

/**
 * INF-066: base das excecoes da camada central de resiliencia da API da
 * Anthropic (app/Services/Ai/AnthropicClient.php).
 *
 * Nunca deixar a mensagem real (getMessage()) chegar ao usuario final --
 * usar sempre userMessage() nas respostas HTTP.
 */
abstract class AnthropicException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $model = null,
        public readonly ?string $endpoint = null,
        public readonly int $attempts = 1,
        public readonly int $totalWaitMs = 0,
        public readonly ?string $anthropicRequestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Mensagem segura para exibir ao usuario final -- nunca expor status
     * HTTP, request-id ou texto cru da Anthropic.
     */
    abstract public function userMessage(): string;
}
