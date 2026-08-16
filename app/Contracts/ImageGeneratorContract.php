<?php
namespace App\Contracts;

/**
 * SEL-429 -- Contrato para motores de geracao de imagem.
 */
interface ImageGeneratorContract
{
    public function generate(string $prompt, array $refImages = [], string $size = '1024x1024'): array;
}
