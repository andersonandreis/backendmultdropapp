<?php

namespace App\Services;

use App\Models\ClientProduct;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gera conteúdo de produto otimizado para marketplaces usando OpenAI.
 * Usa Http::post() nativo do Laravel — sem SDK externo.
 */
class AIProductContentService
{
    protected string $apiKey;
    protected string $model;
    protected ?\App\Models\Supplier $supplier = null;
    protected string $baseUrl = 'https://api.openai.com/v1';

    public function __construct()
    {
        $this->apiKey = (string) config('services.openai.api_key', env('OPENAI_API_KEY', ''));
        $this->model  = (string) config('services.openai.model',   env('OPENAI_MODEL', 'gpt-4o-mini'));
    }

    /** Define o fornecedor pra usar a chave OpenAI dele (override do global). */
    public function setSupplier(\App\Models\Supplier $supplier): self
    {
        $this->supplier = $supplier;
        if ($supplier->openai_api_key) {
            $this->apiKey = $supplier->openai_api_key;
        }
        if ($supplier->openai_model) {
            $this->model = $supplier->openai_model;
        }
        return $this;
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey !== '';
    }

    // -------------------------------------------------------------------------
    // API wrapper
    // -------------------------------------------------------------------------

    public function chat(string $systemPrompt, string $userPrompt, int $maxTokens = 500): string
    {
        $response = Http::withToken($this->apiKey)
            ->timeout(30)
            ->post("{$this->baseUrl}/chat/completions", [
                'model'      => $this->model,
                'max_tokens' => $maxTokens,
                'messages'   => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $userPrompt],
                ],
            ]);

        if ($response->failed()) {
            Log::error('[AI-Product] Falha na API OpenAI', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            // FOR-072: expoe HTTP status na excecao para o controller diferenciar 429 de outros erros
            throw new \RuntimeException('[HTTP:' . $response->status() . '] Falha ao chamar OpenAI: ' . substr($response->body(), 0, 300));
        }

        return trim($response->json('choices.0.message.content') ?? '');
    }

    protected function productContext(Product $product): string
    {
        return implode("\n", array_filter([
            "Nome do produto: {$product->name}",
            $product->description ? "Descrição atual: {$product->description}" : null,
            $product->brand       ? "Marca: {$product->brand}"                 : null,
            $product->model       ? "Modelo: {$product->model}"                : null,
            $product->gtin        ? "GTIN/EAN: {$product->gtin}"               : null,
            $product->category    ? "Categoria: {$product->category->name}"    : null,
        ]));
    }

    // -------------------------------------------------------------------------
    // Métodos públicos
    // -------------------------------------------------------------------------

    /**
     * Gera título otimizado para Mercado Livre / Shopee (máx 60 chars).
     */
    // -------------------------------------------------------------------------
    // JT-010: AI-Bank — reserva pre-gerada em product_ai_cache (cache-first)
    // -------------------------------------------------------------------------

    public function hasBankReserve(Product $product): bool
    {
        return \Illuminate\Support\Facades\DB::table('product_ai_cache')
            ->where('sku_codigo', (string) $product->sku)
            ->whereNull('used_at')
            ->exists();
    }

    private function serveFromBank(Product $product, string $field): ?string
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($product, $field) {
            $q = \Illuminate\Support\Facades\DB::table('product_ai_cache')
                ->where('sku_codigo', (string) $product->sku)
                ->where('marketplace', 'mercadolivre')
                ->whereNull('used_at');

            if ($field === 'description') {
                $q->whereNotNull('description')->where('description', '<>', '');
            } else {
                $q->whereNotNull('title')->where('title', '<>', '')
                    ->orderByRaw("(description is null or description = '') desc");
            }

            $entry = $q->lockForUpdate()->first();
            if ($entry === null) {
                return null;
            }

            \Illuminate\Support\Facades\DB::table('product_ai_cache')
                ->where('id', $entry->id)
                ->update(['used_at' => now()]);

            Log::info('[AI-Bank] served from cache (service)', [
                'sku' => $product->sku, 'field' => $field, 'cache_id' => $entry->id,
            ]);

            return $field === 'description' ? (string) $entry->description : (string) $entry->title;
        });
    }

    public function generateTitle(Product $product): string
    {
        if (($bank = $this->serveFromBank($product, 'title')) !== null && $bank !== '') {
            return mb_substr($this->sanitizePrice($bank), 0, 60); // FOR-073
        }

        $system = 'Você é especialista em SEO para Mercado Livre e Shopee no Brasil. '
            . 'Gere títulos de anúncio altamente clicáveis. '
            . 'REGRAS: máximo 60 caracteres, sem emojis, sem maiúsculas excessivas, '
            . 'inclua marca/modelo quando relevante, use palavras-chave de busca reais.';

        $user = "Crie um título de anúncio para o produto abaixo. Retorne APENAS o título, sem explicações.\n\n"
            . $this->productContext($product);

        // FOR-088: caminho do cliente NUNCA vai na OpenAI nem estoura. Banco-only
        // (flag por backend) ou sem chave -> devolve o nome do produto (degradacao).
        if ((bool) env('AI_CONTENT_BANK_ONLY', false) || ! $this->hasApiKey()) {
            return mb_substr($this->sanitizePrice((string) ($product->name ?? 'Produto')), 0, 60);
        }
        try {
            $title = $this->chat($system, $user, 60);
        } catch (\Throwable $e) {
            \Log::warning('[AI-Bank] title fallback gracioso: '.$e->getMessage());
            return mb_substr($this->sanitizePrice((string) ($product->name ?? 'Produto')), 0, 60);
        }

        // Garante o limite de 60 chars — FOR-073: remove preco do output
        return mb_substr($this->sanitizePrice($title), 0, 60);
    }

    /**
     * Gera descrição persuasiva em texto puro (máx 3000 chars).
     */
    public function generateDescription(Product $product): string
    {
        if (($bank = $this->serveFromBank($product, 'description')) !== null && $bank !== '') {
            return mb_substr($this->sanitizePrice($bank), 0, 3000); // FOR-073
        }

        $system = 'Você é copywriter especialista em e-commerce brasileiro. '
            . 'Escreva descrições persuasivas em TEXTO PURO (sem HTML, sem markdown, sem tags). '
            . 'Use quebras de linha para separar parágrafos. Use • para bullet points. '
            . 'REGRAS: máximo 3000 caracteres, foco em benefícios, linguagem acessível, '
            . 'inclua informações técnicas relevantes, CTA sutil no final.';

        $user = "Crie uma descrição em texto puro para o produto abaixo. Retorne APENAS o texto, sem HTML e sem markdown.\n\n"
            . $this->productContext($product);

        // FOR-088: idem descricao — nunca OpenAI/erro pro cliente.
        if ((bool) env('AI_CONTENT_BANK_ONLY', false) || ! $this->hasApiKey()) {
            return mb_substr($this->sanitizePrice((string) ($product->description ?: $product->name ?: '')), 0, 3000);
        }
        try {
            $desc = $this->chat($system, $user, 800);
        } catch (\Throwable $e) {
            \Log::warning('[AI-Bank] desc fallback gracioso: '.$e->getMessage());
            return mb_substr($this->sanitizePrice((string) ($product->description ?: $product->name ?: '')), 0, 3000);
        }

        // Trunca para garantir o limite — FOR-073: remove preco do output
        return mb_substr($this->sanitizePrice($desc), 0, 3000);
    }

    /**
     * Gera 5 bullet points de benefícios do produto.
     *
     * @return string[]
     */
    public function generateBulletPoints(Product $product): array
    {
        $system = 'Você é especialista em comunicação de benefícios de produtos para e-commerce. '
            . 'Gere bullet points concisos e persuasivos. '
            . 'REGRAS: exatamente 5 bullets, máximo 100 chars cada, começa com verbo ou substantivo forte, '
            . 'foco em benefício para o comprador (não apenas característica técnica). '
            . 'Retorne um bullet por linha, sem numeração, sem traço inicial.';

        $user = "Crie 5 bullet points de benefícios para o produto abaixo.\n\n"
            . $this->productContext($product);

        $raw    = $this->chat($system, $user, 300);
        $lines  = array_values(array_filter(
            array_map('trim', explode("\n", $raw)),
            fn($line) => $line !== ''
        ));

        // Garante exatamente 5 bullets
        return array_slice($lines, 0, 5);
    }

    // -------------------------------------------------------------------------
    // Métodos para ClientProduct (anúncio personalizado do cliente)
    // -------------------------------------------------------------------------

    /**
     * Monta o contexto combinado do ClientProduct + Product original para os prompts de IA.
     */
    protected function clientProductContext(ClientProduct $cp): string
    {
        $lines = [];

        // Dados do produto original do fornecedor como base
        if ($cp->product) {
            $lines[] = "Produto base (fornecedor): {$cp->product->name}";
            if ($cp->product->description) {
                $lines[] = "Descrição base: " . strip_tags($cp->product->description);
            }
            if ($cp->product->brand) {
                $lines[] = "Marca: {$cp->product->brand}";
            }
            if ($cp->product->model) {
                $lines[] = "Modelo: {$cp->product->model}";
            }
            if ($cp->product->gtin) {
                $lines[] = "GTIN/EAN: {$cp->product->gtin}";
            }
            if ($cp->product->category) {
                $lines[] = "Categoria: {$cp->product->category->name}";
            }
        }

        // Dados personalizados já preenchidos pelo cliente (se houver)
        if ($cp->custom_title) {
            $lines[] = "Título atual do anúncio: {$cp->custom_title}";
        }
        if ($cp->custom_brand) {
            $lines[] = "Marca personalizada: {$cp->custom_brand}";
        }
        if ($cp->custom_model) {
            $lines[] = "Modelo personalizado: {$cp->custom_model}";
        }

        return implode("\n", array_filter($lines));
    }

    /**
     * Detecta o limite de caracteres para título conforme a plataforma da conta.
     */
    protected function titleLimitForPlatform(?string $platform): int
    {
        return match (strtolower($platform ?? '')) {
            'shopee' => 120,
            default  => 60,  // Mercado Livre e padrão
        };
    }

    /**
     * Gera título otimizado para o anúncio do cliente, respeitando a plataforma.
     *
     * @param ClientProduct $cp
     * @param string|null   $customInstructions Instruções extras (da loja ou do usuário)
     */
    public function generateTitleForClientProduct(ClientProduct $cp, ?string $customInstructions = null): string
    {
        $platform = $cp->marketplaceAccount?->platform ?? 'mercadolivre';
        $limit    = $this->titleLimitForPlatform($platform);

        // Instruções da loja têm precedência; customInstructions pode complementar
        $storeInstructions = $cp->marketplaceAccount?->ai_instructions ?? '';
        $extra = implode(' ', array_filter([$storeInstructions, $customInstructions]));

        $system = "Você é especialista em SEO para {$platform} no Brasil. "
            . "Gere títulos de anúncio altamente clicáveis. "
            . "REGRAS: máximo {$limit} caracteres, sem emojis, sem maiúsculas excessivas, "
            . "inclua marca/modelo quando relevante, use palavras-chave de busca reais."
            . ($extra ? " INSTRUÇÕES ADICIONAIS: {$extra}" : '');

        $user = "Crie um título de anúncio para o produto abaixo. Retorne APENAS o título, sem explicações.\n\n"
            . $this->clientProductContext($cp);

        $title = $this->chat($system, $user, 100);

        $finalTitle = mb_substr($this->sanitizePrice(trim($title)), 0, $limit); // FOR-073

        // Disparar pré-geração de banco de reserva em background

        return $finalTitle;
    }

    /**
     * Gera descrição persuasiva em HTML para o anúncio do cliente.
     *
     * @param ClientProduct $cp
     * @param string|null   $customInstructions Instruções extras (da loja ou do usuário)
     */
    public function generateDescriptionForClientProduct(ClientProduct $cp, ?string $customInstructions = null): string
    {
        $platform = $cp->marketplaceAccount?->platform ?? 'mercadolivre';

        $storeInstructions = $cp->marketplaceAccount?->ai_instructions ?? '';
        $extra = implode(' ', array_filter([$storeInstructions, $customInstructions]));

        $system = "Você é copywriter especialista em e-commerce brasileiro para {$platform}. "
            . "Escreva descrições persuasivas em TEXTO PURO (sem HTML, sem markdown, sem tags). "
            . "Use quebras de linha para separar parágrafos. Use • para bullet points. "
            . "REGRAS: máximo 3000 caracteres, foco em benefícios, linguagem acessível, "
            . "inclua informações técnicas relevantes, CTA sutil no final."
            . ($extra ? " INSTRUÇÕES ADICIONAIS: {$extra}" : '');

        $user = "Crie uma descrição em texto puro para o produto abaixo. Retorne APENAS o texto, sem HTML e sem markdown.\n\n"
            . $this->clientProductContext($cp);

        $desc = $this->chat($system, $user, 800);

        return mb_substr(trim($desc), 0, 3000);
    }

    /**
     * Sugere categoria do marketplace com base no produto.
     */
    public function suggestCategory(Product $product): string
    {
        $system = 'Você é especialista em categorização de produtos para Mercado Livre Brasil. '
            . 'Retorne APENAS o nome da categoria mais adequada, sem explicações adicionais. '
            . 'Use as categorias reais do Mercado Livre (ex: "Eletrônicos > Celulares", "Casa e Jardim > Ferramentas").';

        $user = "Qual a categoria mais adequada para o produto abaixo?\n\n"
            . $this->productContext($product);

        return $this->chat($system, $user, 50);
    }

// PATCH — métodos a adicionar em AIProductContentService

    public function generateImage(\App\Models\Product $product, ?string $prompt = null): string
    {
        set_time_limit(300);
        if (empty($this->apiKey)) { throw new \RuntimeException('Chave OpenAI não configurada.'); }
        $p = $prompt ?? ('Professional product photo for e-commerce: ' . $product->name . '. White background, studio lighting, no text, square 1:1 ratio.');
        $r = \Illuminate\Support\Facades\Http::withToken($this->apiKey)->timeout(90)
            ->post($this->baseUrl . '/images/generations', [
                'model' => 'gpt-image-1', 'prompt' => $p, 'n' => 1, 'size' => '1024x1024', 'quality' => 'low',
            ]);
        if ($r->failed()) { throw new \RuntimeException('Falha gpt-image-1: ' . $r->body()); }
        $b64 = $r->json('data.0.b64_json');
        if (empty($b64)) { throw new \RuntimeException('gpt-image-1 sem imagem.'); }
        $tmpFile = tempnam(sys_get_temp_dir(), 'ai_img_') . '.png';
        file_put_contents($tmpFile, base64_decode($b64));
        $remotePath = 'ai-generated/' . uniqid('img_', true) . '.png';
        $cdn = app(\App\Services\Integrations\Cdn\BunnyCdnService::class);
        $uploaded = $cdn->upload($tmpFile, $remotePath);
        @unlink($tmpFile);
        if (!$uploaded) { throw new \RuntimeException('Falha ao enviar imagem para CDN.'); }
        return $cdn->getUrl($remotePath);
    }

    public function generateCarouselPlan(\App\Models\Product $product): array
    {
        if (empty($this->apiKey)) { throw new \RuntimeException('Chave OpenAI não configurada.'); }

        $baseImg = $product->media()
            ->orderByRaw("CASE WHEN url LIKE '%fornecefy-images%' THEN 0 ELSE 1 END")
            ->orderBy('position')->value('url');

        $ctx = implode("\n", array_filter([
            'Product: ' . $product->name,
            $product->description ? 'Desc: ' . mb_substr(strip_tags($product->description), 0, 400) : null,
            $product->brand ? 'Brand: ' . $product->brand : null,
        ]));

        $user = "Product:\n{$ctx}\n\n"
            . "Generate exactly 7 DALL-E 3 prompts in English for a product image carousel:\n"
            . "1. COVER: close-up product on pure white background, professional studio lighting, no text\n"
            . "2. LIFESTYLE: product in use in real-life environment by a person\n"
            . "3. BENEFITS: clean infographic with 3-4 main benefits, benefit labels in Portuguese\n"
            . "4. BEFORE/AFTER: split image showing problem (left) vs solution with product (right), labels in Portuguese\n"
            . "5. DIMENSIONS: product with measurement lines and dimension numbers, text in Portuguese\n"
            . "6. OBJECTIONS: image visually addressing main customer objection/fear, text in Portuguese\n"
            . "7. LIFESTYLE 2: different lifestyle scene, different angle or context\n\n"
            . "All images must be square 1:1, JPG format, professional e-commerce quality.\n"
            . "Return ONLY a JSON array of 7 strings. No markdown, no explanation.";

        $sys = 'You are an expert e-commerce product photographer for Brazilian marketplaces (Shopee, Mercado Livre). Generate precise DALL-E 3 image prompts.';
        $msgs = [['role' => 'system', 'content' => $sys]];

        if ($baseImg) {
            $msgs[] = ['role' => 'user', 'content' => [
                ['type' => 'image_url', 'image_url' => ['url' => $baseImg, 'detail' => 'low']],
                ['type' => 'text', 'text' => $user],
            ]];
            $mdl = 'gpt-4o';
        } else {
            $msgs[] = ['role' => 'user', 'content' => $user];
            $mdl = $this->model;
        }

        $r = \Illuminate\Support\Facades\Http::withToken($this->apiKey)->timeout(45)
            ->post($this->baseUrl . '/chat/completions', ['model' => $mdl, 'max_tokens' => 2000, 'messages' => $msgs]);

        if ($r->failed()) { throw new \RuntimeException('Falha plano carrossel: ' . $r->body()); }

        $raw = trim($r->json('choices.0.message.content') ?? '');
        $raw = preg_replace(['/^```(?:json)?\s*/m', '/```\s*$/m'], '', trim($raw));
        // If GPT returned prose before the JSON array, extract just the array
        if (!str_starts_with(ltrim($raw), '[')) {
            if (preg_match('/\[.*\]/s', $raw, $m)) {
                $raw = $m[0];
            }
        }
        $arr = json_decode(trim($raw), true);
        if (!is_array($arr) || empty($arr)) { throw new \RuntimeException('IA sem prompts válidos: ' . mb_substr($raw, 0, 200)); }
        return array_values(array_slice($arr, 0, 7));
    }

    // -------------------------------------------------------------------------
    // MUL-142-H: suporte a SupplierAiSetting (config por WL)
    // -------------------------------------------------------------------------

    /** Define config de IA por WL (chave + modelo). */
    public function setSupplierAiConfig(\App\Models\SupplierAiSetting $config): self
    {
        if ($config->openai_api_key) {
            $this->apiKey = $config->openai_api_key;
        }
        if ($config->openai_model) {
            $this->model = $config->openai_model;
        }
        return $this;
    }

    /**
     * Gera titulo otimizado para marketplace especifico com prompt customizado.
     *
     * @param \App\Models\Product $product
     * @param string $marketplace  ml|shopee|magalu|americanas|bling
     * @param string $systemPrompt Prompt do WL (base ou por marketplace)
     */
    public function generateTitleForMarketplace(\App\Models\Product $product, string $marketplace, string $systemPrompt = ""): string
    {
        $limit = match ($marketplace) {
            "shopee"     => 120,
            "americanas" => 120,
            default       => 60,
        };

        $defaultSystem = "Voce e especialista em SEO para e-commerce brasileiro no marketplace {$marketplace}. "
            . "Gere titulos de anuncio altamente clikaveis. "
            . "REGRAS: maximo {$limit} caracteres, sem emojis, sem maiusculas excessivas, "
            . "inclua marca/modelo quando relevante, use palavras-chave de busca reais.";

        $system = $systemPrompt ?: $defaultSystem;

        $user = "Crie um titulo de anuncio para o produto abaixo. Retorne APENAS o titulo, sem explicacoes.

"
            . $this->productContext($product);

        return mb_substr($this->chat($system, $user, 100), 0, $limit);
    }

    /**
     * Gera descricao otimizada para marketplace especifico com prompt customizado.
     *
     * @param \App\Models\Product $product
     * @param string $marketplace  ml|shopee|magalu|americanas|bling
     * @param string $systemPrompt Prompt do WL (base ou por marketplace)
     */
    public function generateDescriptionForMarketplace(\App\Models\Product $product, string $marketplace, string $systemPrompt = ""): string
    {
        $defaultSystem = "Voce e copywriter especialista em e-commerce brasileiro para {$marketplace}. "
            . "Escreva descricoes persuasivas em TEXTO PURO (sem HTML, sem markdown, sem tags). "
            . "Use quebras de linha para separar paragrafos. Use bullet points com •. "
            . "REGRAS: maximo 3000 caracteres, foco em beneficios, linguagem acessivel, "
            . "inclua informacoes tecnicas relevantes, CTA sutil no final.";

        $system = $systemPrompt ?: $defaultSystem;

        $user = "Crie uma descricao em texto puro para o produto abaixo. Retorne APENAS o texto.

"
            . $this->productContext($product);

        return mb_substr($this->chat($system, $user, 800), 0, 3000);
    }
    // =========================================================================
    // FOR-073: sanitizacao de preco no output da IA
    // A IA por vezes injeta referencias monetarias (R$, reais) em titulos e
    // descricoes usando argumentos de venda como comparacao de preco.
    // Este metodo remove esses padroes de qualquer texto gerado.
    // =========================================================================

    /**
     * Remove referencias de preco do texto gerado pela IA.
     * Padroes tratados:
     *   - "R$ 1.234,56" / "R$1.234,56" / "R$500" / "R$ 0,00"
     *   - "R$ 30 a R$ 80" (faixas de preco)
     *   - "200 reais" / "1.200,00 reais"
     */
    protected function sanitizePrice(string $text): string
    {
        // Remove R$ com valor numerico (inclui faixas "R$ X a R$ Y")
        $text = preg_replace('/R\\$\\s*[\\d.,]+(?:\\s+a\\s+R\\$\\s*[\\d.,]+)?/', '', $text);

        // Remove valor + "reais" (ex: "200 reais", "1.200,00 reais")
        $text = preg_replace('/\\b[\\d.,]+\\s+reais\\b/i', '', $text);

        // Limpa espacos duplos deixados pela remocao
        $text = preg_replace('/\\s{2,}/', ' ', $text);

        return trim($text);
    }

}