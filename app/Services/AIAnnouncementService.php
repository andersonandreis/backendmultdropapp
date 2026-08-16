<?php

namespace App\Services;

use App\Models\ClientProduct;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIAnnouncementService
{
    public function streamTitle(ClientProduct $clientProduct, callable $onChunk): void
    {
        $this->streamField($clientProduct, 'title', $onChunk);
    }

    public function streamDescription(ClientProduct $clientProduct, callable $onChunk): void
    {
        $this->streamField($clientProduct, 'description', $onChunk);
    }

    private function streamField(ClientProduct $clientProduct, string $field, callable $onChunk): void
    {
        $product      = $clientProduct->product;
        $account      = $clientProduct->marketplaceAccount;
        $platform     = $account?->platform ?? 'default';
        $instructions = trim($account?->ai_instructions ?? '');

        if ($field === 'title') {
            $platformRules = match ($platform) {
                'mercadolivre' => 'Máximo 60 caracteres. Sem emojis. Sem maiúsculas excessivas. Sem repetição de palavras. Sem símbolos especiais como $, ! ou ?. Inclua: produto, marca, modelo e característica principal.',
                'shopee'       => 'Máximo 120 caracteres. Inclua palavras-chave relevantes e seja descritivo.',
                default        => 'Máximo 80 caracteres. Seja claro e objetivo.',
            };
            $systemMsg = "Você é um especialista em SEO e e-commerce brasileiro. Responda APENAS com o título do anúncio, sem explicações, sem aspas, sem prefixos. Regras para {$platform}: {$platformRules}";
            $userMsg   = "Crie um título de anúncio otimizado para este produto:\n"
                . "Nome: " . ($product?->name ?? '') . "\n"
                . "Marca: " . ($product?->brand ?? '') . "\n"
                . "Modelo: " . ($product?->model ?? '') . "\n"
                . "Condição: " . ($clientProduct->custom_condition ?? 'new') . "\n"
                . ($instructions ? "\nInstruções da loja: {$instructions}" : '');
            $maxTokens = 80;
        } else {
            $descRules = match ($platform) {
                'mercadolivre' => 'Sem HTML. Use quebras de linha para separar seções. Máximo 4000 caracteres. Detalhe características, especificações e benefícios.',
                'shopee'       => 'Use emojis com moderação. Liste características com •. Máximo 3000 caracteres.',
                default        => 'Sem HTML. Formatação simples com quebras de linha. Máximo 2000 caracteres.',
            };
            $systemMsg = "Você é um especialista em e-commerce brasileiro. Responda APENAS com a descrição pronta para publicação. {$descRules} Não inclua título, instruções ou comentários.";
            $userMsg   = "Crie uma descrição completa para este produto:\n"
                . "Nome: " . ($product?->name ?? '') . "\n"
                . "Marca: " . ($product?->brand ?? '') . "\n"
                . "Modelo: " . ($product?->model ?? '') . "\n"
                . "GTIN/EAN: " . ($product?->gtin ?? '') . "\n"
                . "Descrição original: " . ($product?->description ?? '') . "\n"
                . "Atributos: " . json_encode($product?->attributes ?? [], JSON_UNESCAPED_UNICODE) . "\n"
                . ($instructions ? "\nInstruções da loja: {$instructions}" : '');
            $maxTokens = 800;
        }

        try {
            $response = Http::withToken(config('services.openai.api_key'))
                ->withOptions([
                    'stream'          => true,
                    'connect_timeout' => 10,
                    'timeout'         => 60,
                ])
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model'       => config('services.openai.model', 'gpt-4o-mini'),
                    'messages'    => [
                        ['role' => 'system', 'content' => $systemMsg],
                        ['role' => 'user',   'content' => $userMsg],
                    ],
                    'stream'      => true,
                    'max_tokens'  => $maxTokens,
                    'temperature' => 0.7,
                ]);

            $body   = $response->getBody();
            $buffer = '';

            while (!$body->eof()) {
                $chunk = $body->read(1024);
                if ($chunk === false || $chunk === '') continue;
                $buffer .= $chunk;

                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line   = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    $line   = trim($line);

                    if (!str_starts_with($line, 'data: ')) continue;
                    $data = substr($line, 6);
                    if ($data === '[DONE]') return;

                    $json = json_decode($data, true);
                    if (!$json) continue;

                    $text = $json['choices'][0]['delta']['content'] ?? null;
                    if ($text !== null && $text !== '') {
                        $onChunk($text);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('AIAnnouncementService stream error', ['error' => $e->getMessage()]);
        }
    }
}
