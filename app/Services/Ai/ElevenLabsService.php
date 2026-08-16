<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * SEL-040: ElevenLabs — TTS narracao + dublagem + voice clone + sound FX + audio isolation.
 * Vozes padrao: Lily (pFZP5JQG7iQjIQuC4Bku) e Daniel (onwK4e9ZLuTAKqWW03F9) em PT-BR.
 */
class ElevenLabsService
{
    private function key(): ?string { return config('services.elevenlabs.api_key'); }
    private function base(): string { return rtrim(config('services.elevenlabs.base_url') ?: 'https://api.elevenlabs.io', '/'); }
    private function defaultVoice(): string { return config('services.elevenlabs.default_voice_id') ?: 'pFZP5JQG7iQjIQuC4Bku'; }
    private function defaultModel(): string { return config('services.elevenlabs.default_model') ?: 'eleven_multilingual_v2'; }

    public function isConfigured(): bool { return !empty($this->key()); }

    private function http(int $timeout = 60)
    {
        return Http::withHeaders(['xi-api-key' => $this->key(), 'Accept' => 'application/json'])->timeout($timeout);
    }

    /** Retorna vozes padrao seguras pra oferecer no UI (mascaradas). */
    public function defaultVoicesCatalog(): array
    {
        return [
            ['id' => 'pFZP5JQG7iQjIQuC4Bku', 'label' => 'Voz F1 · Feminina PT-BR', 'lang' => 'pt-BR'],
            ['id' => 'onwK4e9ZLuTAKqWW03F9', 'label' => 'Voz M1 · Masculina PT-BR', 'lang' => 'pt-BR'],
            ['id' => 'XB0fDUnXU5powFXDhCwa', 'label' => 'Voz F2 · Feminina EN', 'lang' => 'en'],
            ['id' => 'pqHfZKP75CvOlQylNhV4', 'label' => 'Voz M2 · Masculina EN', 'lang' => 'en'],
        ];
    }

    /** TTS narracao. Retorna base64 do MP3. */
    public function tts(string $text, ?string $voiceId = null, ?string $modelId = null, array $voiceSettings = []): array
    {
        if (!$this->isConfigured()) throw new RuntimeException('elevenlabs_not_configured');
        $vid = $voiceId ?: $this->defaultVoice();
        $body = [
            'text' => $text,
            'model_id' => $modelId ?: $this->defaultModel(),
            'voice_settings' => array_merge([
                'stability' => 0.5,
                'similarity_boost' => 0.75,
                'style' => 0.0,
            ], $voiceSettings),
        ];
        $res = Http::withHeaders(['xi-api-key' => $this->key(), 'Accept' => 'audio/mpeg'])
            ->timeout(90)
            ->post($this->base() . '/v1/text-to-speech/' . $vid, $body);
        if (!$res->successful()) throw new RuntimeException('elevenlabs_error:' . $res->status());
        return ['audio_base64' => base64_encode($res->body()), 'format' => 'mp3', 'voice_id' => $vid];
    }

    /** Sound effects. */
    public function soundEffects(string $text, ?float $durationSeconds = null): array
    {
        if (!$this->isConfigured()) throw new RuntimeException('elevenlabs_not_configured');
        $body = ['text' => $text];
        if ($durationSeconds !== null) $body['duration_seconds'] = $durationSeconds;
        $res = Http::withHeaders(['xi-api-key' => $this->key(), 'Accept' => 'audio/mpeg'])
            ->timeout(120)
            ->post($this->base() . '/v1/sound-generation', $body);
        if (!$res->successful()) throw new RuntimeException('elevenlabs_error:' . $res->status());
        return ['audio_base64' => base64_encode($res->body()), 'format' => 'mp3'];
    }

    /** Dublagem — cria task async. Retorna dubbing_id. */
    public function createDubbing(string $sourceUrl, string $targetLang, ?string $sourceLang = null, int $numSpeakers = 0): array
    {
        if (!$this->isConfigured()) throw new RuntimeException('elevenlabs_not_configured');
        $body = ['source_url' => $sourceUrl, 'target_lang' => $targetLang, 'num_speakers' => $numSpeakers];
        if ($sourceLang) $body['source_lang'] = $sourceLang;
        $res = $this->http(120)->post($this->base() . '/v1/dubbing', $body);
        if (!$res->successful()) throw new RuntimeException('elevenlabs_error:' . $res->status());
        return $res->json();
    }

    public function getDubbing(string $id): array
    {
        $res = $this->http(30)->get($this->base() . '/v1/dubbing/' . $id);
        if (!$res->successful()) throw new RuntimeException('elevenlabs_error:' . $res->status());
        return $res->json();
    }
}
