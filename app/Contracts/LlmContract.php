<?php
namespace App\Contracts;

/**
 * SEL-429 -- Contrato para motores LLM (chat, roteiro, analise).
 */
interface LlmContract
{
    public function chat(array $messages, float $temperature = 0.7, int $maxTokens = 800): string;
}
