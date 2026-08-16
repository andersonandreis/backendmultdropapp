<?php
namespace App\Services\Ai;

use App\Contracts\LlmContract;
use App\Models\AiEngine;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SEL-429 -- Adapter OpenAI direto (fallback LLM, priority=99).
 *
 * Usa OPENAI_API_KEY do .env. So entra quando DicloakGptAdapter
 * (priority=10) nao estiver disponivel ou falhar.
 *
 * Feature flag: AI_ENGINE_LLM_MODE=direct|pool
 *   direct = bypassar pool, chamar OpenAI diretamente (rollout seguro)
 *   pool   = usar AiEnginePool::for('llm') (producao plena)
 */
class OpenAiDirectAdapter implements LlmContract
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;

    public function __construct(private ?AiEngine $engine = null)
    {
        $cfg           = $engine?->config_json ?? [];
        $this->apiKey  = $cfg['api_key']  ?? (string) config('services.openai.api_key',   env('OPENAI_API_KEY', ''));
        $this->model   = $cfg['model']    ?? (string) config('services.openai.chat_model', 'gpt-4o-mini');
        $this->baseUrl = rtrim($cfg['base_url'] ?? config('services.openai.base_url', 'https://api.openai.com'), '/');
    }

    public function chat(array $messages, float $temperature = 0.7, int $maxTokens = 800): string
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('openai_not_configured: OPENAI_API_KEY nao definida no .env');
        }

        $resp = Http::withToken($this->apiKey)
            ->timeout(60)
            ->post("{$this->baseUrl}/v1/chat/completions", [
                'model'       => $this->model,
                'messages'    => $messages,
                'max_tokens'  => $maxTokens,
                'temperature' => $temperature,
            ]);

        if ($resp->failed()) {
            Log::warning('[SEL-429][OpenAIDirect] falha na API', [
                'status' => $resp->status(),
                'body'   => mb_substr($resp->body(), 0, 200),
            ]);
            throw new \RuntimeException('[HTTP:' . $resp->status() . '] OpenAI chat falhou: ' . mb_substr($resp->body(), 0, 200));
        }

        return trim((string) ($resp->json('choices.0.message.content') ?? ''));
    }
}
