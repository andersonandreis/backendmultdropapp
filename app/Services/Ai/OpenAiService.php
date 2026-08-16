<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * SEL-040: OpenAI — roteiro (chat), analise imagem (vision), DALL-E, TTS, Whisper.
 * Providers ja configurado no .env (OPENAI_API_KEY).
 */
class OpenAiService
{
    private function key(): ?string { return config('services.openai.api_key'); }
    private function base(): string { return rtrim(config('services.openai.base_url') ?: 'https://api.openai.com', '/'); }
    private function chatModel(): string { return config('services.openai.chat_model') ?: 'gpt-4o-mini'; }
    private function imageModel(): string { return config('services.openai.image_model') ?: 'dall-e-3'; }
    private function ttsModel(): string { return config('services.openai.tts_model') ?: 'tts-1'; }

    public function isConfigured(): bool { return !empty($this->key()); }

    private function http(int $timeout = 60)
    {
        return Http::withToken($this->key())->timeout($timeout)->withHeaders(['Accept' => 'application/json']);
    }

    /**
     * Gera roteiro viral pra TikTok Shop. Style: unboxing|antes_depois|social_proof|urgencia|depoimento|comparacao|promocao|tutorial|custom.
     */
    public function generateScript(string $prompt, ?string $productName = null, string $style = 'custom', ?string $model = null, int $maxTokens = 1024, ?int $durationSec = null): array
    {
        if (!$this->isConfigured()) throw new RuntimeException('openai_not_configured');

        // SEL-329: prompt de VENDA estilo Kalodata (gancho + apresentação + CTA carrinho).
        // Duração real do vídeo controla número de palavras (PT-BR ~2.4 pal/s).
        $dur = max(10, $durationSec ?? 15);
        $palavras = max(20, (int) round($dur * 2.4));

        $system = "Voce escreve pitch de VENDA falado para video TikTok Shop de {$dur} segundos "
            . "(equivalente a ~{$palavras} palavras). ESTRUTURA OBRIGATORIA (padrao Kalodata):\n"
            . "1) GANCHO (0-3s): frase que para o dedo do usuario — pergunta, choque ou promessa. Ex: "
            . "\"Voce sabia que...\", \"Para tudo!\", \"Nunca mais pague caro por...\".\n"
            . "2) APRESENTACAO DO PRODUTO (meio): fala 1-2 beneficios principais (o que resolve, "
            . "por que e melhor), usando primeira pessoa vendedora.\n"
            . "3) CTA (ultimos 2-3s): fecha com chamada pra clicar. Use uma dessas variacoes: "
            . "\"Clica no carrinho laranja aqui embaixo!\", \"Corre no carrinho embaixo antes de acabar!\", "
            . "\"Ta no carrinho aqui embaixo, garante o seu!\".\n\n"
            . "REGRAS RIGIDAS:\n"
            . "- Falar em PRIMEIRA PESSOA olho no olho (\"voce\", \"eu\").\n"
            . "- MAXIMO {$palavras} palavras, uma fala continua, sem pausas longas.\n"
            . "- NAO USE emojis nem marcacoes [CENA] ou (close). So a FALA que sai da boca.\n"
            . "- NAO descreva cenario/avatar/camera nem lugar (nada de praia, estudio).\n"
            . "- Portugues brasileiro natural. Cumpra os 3 momentos (gancho, produto, CTA).\n"
            . "Retorne SOMENTE o texto da fala, nada mais.";

        $hint = $productName ? "Produto: {$productName}. " : '';
        $hint .= ($style && $style !== 'custom') ? "Estilo: {$style}. " : '';
        $user = $hint . $prompt;

        // token budget: 1 palavra ~= 1.6 tokens PT-BR
        $tokBudget = min($maxTokens, max(60, (int) round($palavras * 2.2)));

        $body = [
            'model' => $model ?: $this->chatModel(),
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'max_tokens' => $tokBudget,
            'temperature' => 0.7,
        ];
        $res = $this->http(60)->post($this->base() . '/v1/chat/completions', $body);
        if (!$res->successful()) throw new RuntimeException('openai_error:' . $res->status());
        $data = $res->json();
        $content = $data['choices'][0]['message']['content'] ?? '';

        // SEL-329: sanitiza saída — tira emojis/tags/aspas que o TTS lê errado
        $content = self::sanitizeSpeech($content);

        return ['script' => $content, 'model' => $data['model'] ?? null, 'usage' => $data['usage'] ?? null];
    }

    /** SEL-329: remove emojis, colchetes de cena, aspas e markdown do texto pro TTS não ler símbolos. */
    public static function sanitizeSpeech(string $text): string
    {
        $text = preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{1F000}-\x{1F2FF}]/u', '', $text) ?? $text;
        $text = preg_replace('/\[[^\]]*\]/u', '', $text) ?? $text;
        $text = preg_replace('/\((?:cena|close|corte|camera|scene|shot)[^)]*\)/iu', '', $text) ?? $text;
        $text = str_replace(['**', '##', '~~', '"', "'"], '', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    /**
     * Analisa imagem via Vision — retorna JSON com nome/descricao/keywords/publico_alvo/sugestao_roteiro.
     */
    public function analyzeImage(string $imageUrl, ?string $question = null, ?string $model = null): array
    {
        if (!$this->isConfigured()) throw new RuntimeException('openai_not_configured');
        $q = $question ?: 'Analise esta imagem de produto para o TikTok Shop Brasil. Retorne em JSON: {nome, descricao, categoria, keywords, publico_alvo, sugestao_roteiro, preco_sugerido, pontos_fortes}';
        $body = [
            'model' => $model ?: 'gpt-4o-mini',
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $q],
                    ['type' => 'image_url', 'image_url' => ['url' => $imageUrl]],
                ],
            ]],
            'max_tokens' => 800,
        ];
        $res = $this->http(60)->post($this->base() . '/v1/chat/completions', $body);
        if (!$res->successful()) throw new RuntimeException('openai_error:' . $res->status());
        $data = $res->json();
        $content = $data['choices'][0]['message']['content'] ?? '';
        return ['analysis' => $content, 'model' => $data['model'] ?? null];
    }

    /** DALL-E 3 imagem. size: 1024x1024|1024x1792|1792x1024. */
    public function generateImage(string $prompt, string $size = '1024x1024', string $quality = 'standard', string $style = 'vivid'): array
    {
        // SEL-306: gpt-image-1 (DALL-E-3 descontinuado). Novo modelo aceita quality auto|low|medium|high, retorna b64_json.
        if (!$this->isConfigured()) throw new RuntimeException('openai_not_configured');
        $model = $this->imageModel();
        // Mapeia quality DALL-E antigo pro novo padrao gpt-image-*
        $qMap = ['standard' => 'medium', 'hd' => 'high', 'auto' => 'auto', 'low' => 'low', 'medium' => 'medium', 'high' => 'high'];
        $q = $qMap[$quality] ?? 'medium';
        $body = ['model' => $model, 'prompt' => $prompt, 'size' => $size, 'quality' => $q, 'n' => 1];
        $res = $this->http(120)->post($this->base() . '/v1/images/generations', $body);
        if (!$res->successful()) throw new RuntimeException('openai_error:' . $res->status());
        $data = $res->json();
        $item = $data['data'][0] ?? [];
        // gpt-image-* retorna b64_json (grava no storage local pro cliente ter URL persistente)
        if (!empty($item['b64_json'])) {
            $path = 'ai-images/' . date('Y/m/d') . '/' . uniqid('img_', true) . '.png';
            \Illuminate\Support\Facades\Storage::disk('public')->put($path, base64_decode($item['b64_json']));
            $url = rtrim(config('app.url'), '/') . '/storage/' . $path;
            return ['url' => $url, 'revised_prompt' => $item['revised_prompt'] ?? null];
        }
        return ['url' => $item['url'] ?? null, 'revised_prompt' => $item['revised_prompt'] ?? null];
    }

    /** TTS OpenAI — vozes: alloy|ash|coral|echo|fable|onyx|nova|sage|shimmer. Retorna base64. */
    public function tts(string $input, string $voice = 'nova', ?string $model = null, float $speed = 1.0, string $format = 'mp3'): array
    {
        if (!$this->isConfigured()) throw new RuntimeException('openai_not_configured');
        $body = ['model' => $model ?: $this->ttsModel(), 'input' => $input, 'voice' => $voice, 'response_format' => $format, 'speed' => $speed];
        $res = Http::withToken($this->key())->timeout(60)->post($this->base() . '/v1/audio/speech', $body);
        if (!$res->successful()) throw new RuntimeException('openai_error:' . $res->status());
        return ['audio_base64' => base64_encode($res->body()), 'format' => $format];
    }

    /** Whisper STT — transcreve arquivo de áudio local. */
    public function transcribe(string $filePath, string $language = 'pt'): array
    {
        if (!$this->isConfigured()) throw new RuntimeException('openai_not_configured');
        if (!is_file($filePath)) throw new RuntimeException('transcribe_file_missing');
        $res = Http::withToken($this->key())->timeout(180)
            ->attach('file', file_get_contents($filePath), basename($filePath))
            ->post($this->base() . '/v1/audio/transcriptions', [
                'model'    => 'whisper-1',
                'language' => $language,
            ]);
        if (!$res->successful()) throw new RuntimeException('openai_error:' . $res->status());
        $data = $res->json();
        return ['text' => $data['text'] ?? '', 'language' => $data['language'] ?? $language];
    }
    // SEL-361: chat() generico e editImage()

    public function chat(array $messages, string $model = "gpt-4o-mini", int $maxTokens = 800): string
    {
        if (!$this->isConfigured()) throw new RuntimeException("openai_not_configured");
        $body = ["model" => $model, "messages" => $messages, "max_tokens" => $maxTokens, "temperature" => 0.7];
        $res  = $this->http(60)->post($this->base() . "/v1/chat/completions", $body);
        if (!$res->successful()) throw new RuntimeException("openai_error:" . $res->status());
        return $res->json("choices.0.message.content") ?? "";
    }

    /** SEL-387: chat with response_format=json_object, returns decoded array.
     *  SEL-452: agora tenta AiEnginePool primeiro (Gemini/DICloak GPT) antes de cair no OpenAI direto.
     */
    public function chatJson(array $messages, string $model = "gpt-4o", int $maxTokens = 2000, float $temperature = 0.7): array
    {
        // SEL-452: fallback via pool LLM (Gemini/DICloak) quando OpenAI sem credito ou down
        try {
            $poolMessages = $messages;
            $lastKey = array_key_last($poolMessages);
            if ($lastKey !== null && isset($poolMessages[$lastKey]["content"])) {
                $poolMessages[$lastKey]["content"] .= "

IMPORTANTE: Responda APENAS com JSON valido puro, sem markdown, sem prefixos ou sufixos, sem comentarios. Comece com { e termine com }.";
            }
            $text = app(\App\Services\Ai\AiEnginePool::class)->for("llm")->chat($poolMessages, $temperature, $maxTokens);
            $text = trim($text);
            // Remove markdown fence se veio
            if (str_starts_with($text, "\`\`\`")) {
                $text = preg_replace("/^\`\`\`(?:json)?\s*|\s*\`\`\`$/m", "", $text);
                $text = trim($text);
            }
            $decoded = json_decode($text, true);
            if (is_array($decoded) && !empty($decoded)) return $decoded;
            // Tenta extrair primeiro {...} valido
            if (preg_match("/\{.*\}/s", $text, $m)) {
                $decoded = json_decode($m[0], true);
                if (is_array($decoded) && !empty($decoded)) return $decoded;
            }
            \Log::warning("[SEL-452][OpenAiService/chatJson] pool retornou nao-JSON, tentando OpenAI direto", ["snip" => mb_substr($text, 0, 200)]);
        } catch (\Throwable $e) {
            \Log::warning("[SEL-452][OpenAiService/chatJson] pool falhou, tentando OpenAI direto", ["err" => mb_substr($e->getMessage(), 0, 300)]);
        }

        // Fallback comportamento original OpenAI direto
        if (!$this->isConfigured()) throw new RuntimeException("openai_not_configured");
        $body = [
            "model"           => $model,
            "messages"        => $messages,
            "max_tokens"      => $maxTokens,
            "temperature"     => $temperature,
            "response_format" => ["type" => "json_object"],
        ];
        $res = $this->http(90)->post($this->base() . "/v1/chat/completions", $body);
        if (!$res->successful()) throw new RuntimeException("openai_error:" . $res->status() . " " . $res->body());
        $text = $res->json("choices.0.message.content") ?? "{}";
        return json_decode($text, true) ?? [];
    }

    public function editImage(string $imageUrl, string $prompt): ?string
    {
        if (!$this->isConfigured()) return null;
        try {
            $tmpFile = tempnam(sys_get_temp_dir(), "img_edit_");
            $imgData = @file_get_contents($imageUrl);
            if (!$imgData) return null;
            file_put_contents($tmpFile, $imgData);
            $res = Http::withToken($this->key())->timeout(120)
                ->attach("image", file_get_contents($tmpFile), basename($tmpFile) . ".png")
                ->post($this->base() . "/v1/images/edits", [
                    "model" => $this->imageModel(), "prompt" => $prompt, "n" => "1", "size" => "1024x1024",
                ]);
            @unlink($tmpFile);
            if (!$res->successful()) return null;
            $item = $res->json("data.0") ?? [];
            if (!empty($item["b64_json"])) {
                $path = "ai-images/" . date("Y/m/d") . "/" . uniqid("edit_", true) . ".png";
                \Illuminate\Support\Facades\Storage::disk("public")->put($path, base64_decode($item["b64_json"]));
                return rtrim(config("app.url"), "/") . "/storage/" . $path;
            }
            return $item["url"] ?? null;
        } catch (\Throwable) { return null; }
    }

    /** SEL-368: edicao com MULTIPLAS imagens de entrada (gpt-image aceita image[]). */
    public function editImageMulti(array $imageUrls, string $prompt, string $size = "1024x1536"): ?string
    {
        if (!$this->isConfigured()) return null;
        try {
            $req = Http::withToken($this->key())->timeout(180);
            $attached = 0;
            foreach (array_slice(array_values($imageUrls), 0, 4) as $i => $url) {
                $data = @file_get_contents($url);
                if (!$data) continue;
                $req = $req->attach("image[]", $data, "img" . $i . ".png");
                $attached++;
            }
            if ($attached === 0) return null;
            $res = $req->post($this->base() . "/v1/images/edits", [
                "model" => $this->imageModel(), "prompt" => $prompt, "n" => "1", "size" => $size,
            ]);
            if (!$res->successful()) return null;
            $item = $res->json("data.0") ?? [];
            if (!empty($item["b64_json"])) {
                $path = "ai-images/" . date("Y/m/d") . "/" . uniqid("editm_", true) . ".png";
                \Illuminate\Support\Facades\Storage::disk("public")->put($path, base64_decode($item["b64_json"]));
                return rtrim(config("app.url"), "/") . "/storage/" . $path;
            }
            return $item["url"] ?? null;
        } catch (\Throwable) { return null; }
    }
}
