<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Ai\AnthropicException;
use App\Http\Controllers\Controller;
use App\Services\Ai\AnthropicClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/v1/ai/generate-product
 *
 * JT-010: cache-first serve da reserva de titulos pre-gerados (used_at IS NULL),
 * marca used_at=now() atomicamente e retorna sem custo de API.
 * Fallback pro fluxo de APIs pagas (GPT/Gemini/Claude) quando reserva vazia.
 */
class ProductAiController extends Controller
{
    private const CACHE_DAYS = 30;

    private const OPENAI_URL   = 'https://api.openai.com/v1/chat/completions';
    private const OPENAI_MODEL = 'gpt-4o-mini';

    private const GEMINI_MODEL = 'gemini-1.5-flash';

    // INF-066: URL/versao da Anthropic agora vivem em AnthropicClient
    // (config/services.php 'anthropic'). So o modelo fica aqui pra manter
    // o valor exato ja usado neste endpoint.
    private const CLAUDE_MODEL = 'claude-haiku-4-5-20251001';

    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sku_codigo'         => 'required|string|max:100',
            'marketplace'        => 'required|string|in:mercadolivre,shopee',
            'titulo_original'    => 'nullable|string|max:255',
            'product_name'       => 'nullable|string|max:255',
            'descricao_original' => 'nullable|string',
            'custo'              => 'nullable|numeric|min:0',
            'ean'                => 'nullable|string|max:30',
            'category_hint'      => 'nullable|string|max:50',
            'images'             => 'nullable|array',
            'images.*'           => 'nullable|url',
        ]);

        $skuCodigo   = $validated['sku_codigo'];
        $marketplace = $validated['marketplace'];

        $productName = $validated['titulo_original']
            ?? $validated['product_name']
            ?? $skuCodigo;

        // ---------------------------------------------------------------
        // JT-010: AI-Bank - servir da reserva pre-gerada (used_at IS NULL)
        // Atomico via transaction + lockForUpdate para evitar race condition.
        // ---------------------------------------------------------------
        $bankEntry = $this->serveFromBank($skuCodigo, $marketplace);

        if ($bankEntry !== null) {
            $description = $bankEntry->description;
            if (empty($description)) {
                $altDesc = DB::table('product_ai_cache')
                    ->where('sku_codigo', $skuCodigo)
                    ->whereNotNull('description')
                    ->where('description', '!=', '')
                    ->value('description');
                $description = $altDesc ?? '';
            }

            return response()->json([
                'success'            => true,
                'from_cache'         => true,
                'provider'           => 'ai_bank',
                'title'              => $this->sanitizePrice($bankEntry->title ?? ''), // FOR-073
                'description'        => $this->sanitizePrice($description ?? ''), // FOR-073
                'suggested_category' => $bankEntry->suggested_category,
                'attributes'         => json_decode($bankEntry->attributes ?? '[]', true),
            ]);
        }

        // ---------------------------------------------------------------
        // Fallback final: chamar API de IA (GPT/Gemini/Claude)
        // ---------------------------------------------------------------
        $prompt    = $this->buildPrompt($productName, $marketplace, $validated);
        $providers = $this->buildProviderChain();

        foreach ($providers as $provider) {
            Log::info('[ProductAi] Tentando provider', ['provider' => $provider['name'], 'sku' => $skuCodigo]);
            $result = $this->callProvider($provider, $prompt);

            if ($result === null) {
                Log::warning('[ProductAi] Provider falhou, proximo', ['provider' => $provider['name']]);
                continue;
            }

            // Registros gerados por API marcamos used_at=now() imediatamente
            DB::table('product_ai_cache')->insert([
                'sku_codigo'         => $skuCodigo,
                'marketplace'        => $marketplace,
                'title'              => mb_substr($this->sanitizePrice($result['title'] ?? ''), 0, 255), // FOR-073
                'description'        => $result['description'] ?? '',
                'suggested_category' => $result['suggested_category'] ?? null,
                'attributes'         => json_encode($result['attributes'] ?? []),
                'generated_at'       => Carbon::now(),
                'used_at'            => Carbon::now(),
            ]);

            Log::info('[ProductAi] Gerado', ['provider' => $provider['name'], 'sku' => $skuCodigo, 'title' => $result['title'] ?? '']);

            return response()->json([
                'success'            => true,
                'from_cache'         => false,
                'provider'           => $provider['name'],
                'title'              => mb_substr($this->sanitizePrice($result['title'] ?? ''), 0, 255), // FOR-073
                'description'        => $result['description'] ?? '',
                'suggested_category' => $result['suggested_category'] ?? null,
                'attributes'         => $result['attributes'] ?? [],
            ]);
        }

        Log::error('[ProductAi] Todos os providers falharam', compact('skuCodigo'));

        return response()->json([
            'success' => false,
            'error'   => 'Servico de IA temporariamente indisponivel. Tente novamente em alguns minutos.',
        ], 503);
    }

    /**
     * JT-010: Serve titulo da reserva pre-gerada (AI-Bank).
     * Usa transaction + lockForUpdate para atomicidade.
     */
    private function serveFromBank(string $skuCodigo, string $marketplace): ?object
    {
        return DB::transaction(function () use ($skuCodigo, $marketplace) {
            $entry = DB::table('product_ai_cache')
                ->where('sku_codigo', $skuCodigo)
                ->where('marketplace', $marketplace)
                ->whereNull('used_at')
                ->lockForUpdate()
                ->first();

            if ($entry === null) {
                return null;
            }

            $updated = DB::table('product_ai_cache')
                ->where('id', $entry->id)
                ->whereNull('used_at')
                ->update(['used_at' => Carbon::now()]);

            if ($updated === 0) {
                return null;
            }

            Log::info('[AI-Bank] served from cache', [
                'sku'         => $skuCodigo,
                'marketplace' => $marketplace,
                'cache_id'    => $entry->id,
                'title'       => $entry->title,
            ]);

            return $entry;
        });
    }

    private function buildProviderChain(): array
    {
        $chain = [];

        if (! empty($k = config('services.openai.api_key')))    { $chain[] = ['name' => 'gpt',    'key' => $k]; }
        if (! empty($k = env('GEMINI_API_KEY')))    { $chain[] = ['name' => 'gemini', 'key' => $k]; }
        // INF-066: config() em vez de env() direto -- funciona com config:cache ligado.
        if (! empty($k = config('services.anthropic.api_key'))) { $chain[] = ['name' => 'claude', 'key' => $k]; }

        return $chain;
    }

    private function callProvider(array $provider, string $prompt): ?array
    {
        try {
            $raw = match ($provider['name']) {
                'gpt'    => $this->callGpt($provider['key'], $prompt),
                'gemini' => $this->callGemini($provider['key'], $prompt),
                'claude' => $this->callClaude($provider['key'], $prompt),
                default  => null,
            };
        } catch (\Throwable $e) {
            Log::error('[ProductAi] Excecao', ['provider' => $provider['name'], 'error' => $e->getMessage()]);
            return null;
        }

        return $raw !== null ? $this->parseAiJson($raw, $provider['name']) : null;
    }

    private function callGpt(string $key, string $prompt): ?string
    {
        $response = Http::timeout(30)
            ->withToken($key)
            ->post(self::OPENAI_URL, [
                'model'       => env('OPENAI_MODEL', self::OPENAI_MODEL),
                'max_tokens'  => 512,
                'temperature' => 0.3,
                'messages'    => [
                    ['role' => 'system', 'content' => 'Voce e especialista em e-commerce brasileiro. Responda APENAS com JSON puro, sem markdown.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if ($response->failed()) {
            Log::warning('[ProductAi][GPT] HTTP erro', ['status' => $response->status(), 'body' => substr($response->body(), 0, 300)]);
            return null;
        }

        return $response->json('choices.0.message.content');
    }

    private function callGemini(string $key, string $prompt): ?string
    {
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            self::GEMINI_MODEL,
            $key
        );

        $response = Http::timeout(30)
            ->post($url, [
                'contents'         => [['parts' => [['text' => "Voce e especialista em e-commerce brasileiro. Responda APENAS com JSON puro, sem markdown.\n\n{$prompt}"]]]],
                'generationConfig' => ['maxOutputTokens' => 512, 'temperature' => 0.3],
            ]);

        if ($response->failed()) {
            Log::warning('[ProductAi][Gemini] HTTP erro', ['status' => $response->status(), 'body' => substr($response->body(), 0, 300)]);
            return null;
        }

        return $response->json('candidates.0.content.parts.0.text');
    }

    /**
     * INF-066: delega para a camada central de resiliencia (AnthropicClient)
     * -- retry so em 429/500/502/503/529, backoff exponencial com jitter,
     * respeita Retry-After, nao repete erro permanente, limite de
     * concorrencia e log detalhado ao esgotar. $key ignorado (o client le
     * ANTHROPIC_API_KEY via config); mantido no assinatura por compat com
     * o match() em callProvider().
     */
    private function callClaude(string $key, string $prompt): ?string
    {
        try {
            $data = app(AnthropicClient::class)->messages([
                'model'      => self::CLAUDE_MODEL,
                'max_tokens' => 512,
                'messages'   => [
                    ['role' => 'user', 'content' => "Voce e especialista em e-commerce brasileiro. Responda APENAS com JSON puro, sem markdown.\n\n{$prompt}"],
                ],
            ]);
        } catch (AnthropicException $e) {
            // Log detalhado ja aconteceu dentro do AnthropicClient ao esgotar
            // tentativas. Aqui so registramos que este provider falhou, para
            // callProvider() seguir pro proximo da chain sem erro cru.
            Log::warning('[ProductAi][Claude] provider indisponivel', [
                'status'   => $e->httpStatus,
                'attempts' => $e->attempts,
            ]);

            return null;
        }

        return $data['content'][0]['text'] ?? null;
    }

    private function buildPrompt(string $productName, string $marketplace, array $validated): string
    {
        $label = $marketplace === 'mercadolivre' ? 'Mercado Livre (Brasil)' : 'Shopee (Brasil)';
        $parts = [
            "Gere dados otimizados para publicacao de produto no {$label}.",
            '',
            "Produto: {$productName}",
        ];

        if (! empty($validated['ean']))               { $parts[] = "EAN/GTIN: {$validated['ean']}"; }
        if (! empty($validated['descricao_original'])) { $parts[] = "Descricao original: {$validated['descricao_original']}"; }
        if (! empty($validated['category_hint']))      { $parts[] = "Categoria sugerida: {$validated['category_hint']}"; }

        $parts = array_merge($parts, [
            '',
            'Retorne APENAS um JSON valido (sem markdown, sem backticks) com os campos:',
            '- title: string de 40 a 60 caracteres, persuasivo, palavras-chave no inicio — NUNCA inclua precos, valores em R$ ou comparacoes monetarias', // FOR-073
            '- description: string de 150 a 300 caracteres, beneficios do produto — NUNCA inclua precos, valores em R$ ou comparacoes monetarias',
            '- suggested_category: ID ou nome da categoria adequada para o marketplace',
            '- attributes: objeto com BRAND e outros atributos relevantes',
            '',
            'Formato exato:',
            '{"title":"...","description":"...","suggested_category":"...","attributes":{"BRAND":"Sem marca"}}',
        ]);

        return implode("\n", $parts);
    }

    private function parseAiJson(string $raw, string $provider): ?array
    {
        $clean = preg_replace('/^```(?:json)?\s*/m', '', $raw);
        $clean = preg_replace('/```\s*$/m', '', $clean);
        $clean = trim($clean);

        if (! str_starts_with($clean, '{')) {
            preg_match('/\{.*\}/s', $clean, $m);
            $clean = $m[0] ?? $clean;
        }

        $decoded = json_decode($clean, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            Log::warning("[ProductAi][{$provider}] JSON invalido", ['raw' => substr($raw, 0, 500)]);
            return null;
        }

        return $decoded;
    }
    /**
     * FOR-073: Remove referencias de preco do texto gerado pela IA.
     */
    private function sanitizePrice(string $text): string
    {
        $text = preg_replace('/R\\$\\s*[\\d.,]+(?:\\s+a\\s+R\\$\\s*[\\d.,]+)?/', '', $text);
        $text = preg_replace('/\\b[\\d.,]+\\s+reais\\b/i', '', $text);
        $text = preg_replace('/\\s{2,}/', ' ', $text);
        return trim($text);
    }

}