<?php
namespace App\Contracts;

/**
 * SEL-429 -- Contrato para motores de scraping/analise de mercado.
 */
interface ScrapingContract
{
    public function scrape(string $url, string $sessionKey = 'default', array $options = []): array;
}
