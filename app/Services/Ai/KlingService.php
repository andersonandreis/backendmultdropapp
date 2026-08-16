<?php

namespace App\Services\Ai;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * SEL-121 — Kling AI: img2vid, txt2vid, Virtual Try-On, Lip Sync.
 *
 * Suporta 2 formatos de auth:
 * - NOVO (preferido): api_key unico Bearer, endpoint api-app.klingai.com,
 *   suporta todos os modelos incluindo v3-0 e v3-0-turbo.
 * - LEGACY: access_key + secret_key com JWT HS256 (30min TTL).
 *   So suporta v1..v2-1.
 *
 * Se ambos configurados, prefere Bearer.
 */
class KlingService
{
    private function apiKey(): ?string { return config('services.kling.api_key'); }
    private function accessKey(): ?string { return config('services.kling.access_key'); }
    private function secretKey(): ?string { return config('services.kling.secret_key'); }
    private function baseUrl(): string { return rtrim(config('services.kling.base_url') ?: 'https://api-app.klingai.com', '/'); }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey())
            || (!empty($this->accessKey()) && !empty($this->secretKey()));
    }

    private function useBearer(): bool
    {
        return !empty($this->apiKey());
    }

    /** Retorna Bearer token (api_key direto ou JWT gerado). */
    private function token(): string
    {
        if (!$this->isConfigured()) throw new RuntimeException('kling_not_configured');
        if ($this->useBearer()) return $this->apiKey();
        $ck = 'kling_jwt_' . substr(md5($this->accessKey()), 0, 10);
        return Cache::remember($ck, 1500, function () {
            $now = time();
            $payload = [
                'iss' => $this->accessKey(),
                'exp' => $now + 1800,
                'nbf' => $now - 5,
            ];
            if (!class_exists(JWT::class)) throw new RuntimeException('kling_jwt_lib_missing');
            return JWT::encode($payload, $this->secretKey(), 'HS256');
        });
    }

    private function http(int $timeout = 30)
    {
        return Http::withToken($this->token())
            ->timeout($timeout)
            ->withHeaders(['Accept' => 'application/json']);
    }

    /** IMAGE -> VIDEO. Modelos: kling-v1, v1-5, v1-6, v2, v2-1, kling-v3 (multi-shot/elements). */
    public function imageToVideo(array $payload): array
    {
        $body = array_filter([
            'model_name'      => $payload['model_name'] ?? 'kling-v1-6',
            'mode'            => $payload['mode'] ?? 'std',
            'duration'        => (string) ($payload['duration'] ?? 5),
            'aspect_ratio'    => $payload['aspect_ratio'] ?? '9:16',
            'image'           => $payload['image'] ?? null,
            'image_tail'      => $payload['image_tail'] ?? null,
            'prompt'          => isset($payload['prompt']) ? mb_substr($payload['prompt'], 0, 2500) : null,
            'negative_prompt' => isset($payload['negative_prompt']) ? mb_substr($payload['negative_prompt'], 0, 2500) : null,
            'cfg_scale'       => $payload['cfg_scale'] ?? 0.5,
            'camera_control'  => $payload['camera_control'] ?? null,
            // v3 multi-shot + elements (avatar/produto)
            'multi_shot'      => $payload['multi_shot'] ?? null,
            'multi_prompt'    => $payload['multi_prompt'] ?? null,
            'shot_type'       => $payload['shot_type'] ?? null,
            'elements'        => $payload['elements'] ?? null,
            'external_task_id'=> $payload['external_task_id'] ?? null,
        ], fn($v) => $v !== null);
        $res = $this->http(60)->post($this->baseUrl() . '/v1/videos/image2video', $body);
        if (!$res->successful()) throw new RuntimeException('kling_error:' . $res->status() . ':' . substr($res->body(), 0, 240));
        return $res->json();
    }

    /** TEXT -> VIDEO. */
    public function textToVideo(array $payload): array
    {
        $body = array_filter([
            'model_name'      => $payload['model_name'] ?? 'kling-v1-6',
            'mode'            => $payload['mode'] ?? 'std',
            'duration'        => (string) ($payload['duration'] ?? 5),
            'aspect_ratio'    => $payload['aspect_ratio'] ?? '9:16',
            'prompt'          => isset($payload['prompt']) ? mb_substr($payload['prompt'], 0, 2500) : null,
            'negative_prompt' => isset($payload['negative_prompt']) ? mb_substr($payload['negative_prompt'], 0, 2500) : null,
            'cfg_scale'       => $payload['cfg_scale'] ?? 0.5,
            'camera_control'  => $payload['camera_control'] ?? null,
            'external_task_id'=> $payload['external_task_id'] ?? null,
        ], fn($v) => $v !== null);
        $res = $this->http(60)->post($this->baseUrl() . '/v1/videos/text2video', $body);
        if (!$res->successful()) throw new RuntimeException('kling_error:' . $res->status() . ':' . substr($res->body(), 0, 240));
        return $res->json();
    }

    public function virtualTryOn(array $payload): array
    {
        $body = array_filter([
            'model_name'  => $payload['model_name'] ?? 'kolors-virtual-try-on-v1-5',
            'human_image' => $payload['human_image'] ?? null,
            'cloth_image' => $payload['cloth_image'] ?? null,
        ], fn($v) => $v !== null);
        $res = $this->http(60)->post($this->baseUrl() . '/v1/images/kolors-virtual-try-on', $body);
        if (!$res->successful()) throw new RuntimeException('kling_error:' . $res->status());
        return $res->json();
    }

    /** Lip-Sync. API oficial: campos aninhados em `input`; audio2video exige audio_type url|file. */
    public function lipSync(array $payload): array
    {
        $input = array_filter([
            'video_id'  => $payload['video_id'] ?? null,
            'video_url' => $payload['video_url'] ?? null,
            'mode'      => $payload['mode'] ?? 'audio2video',
            'text'      => $payload['text'] ?? null,
            'voice_id'  => $payload['voice_id'] ?? null,
        ], fn($v) => $v !== null);
        if (($input['mode'] ?? '') === 'audio2video' && !empty($payload['audio_url'])) {
            $input['audio_type'] = 'url';
            $input['audio_url']  = $payload['audio_url'];
        }
        // video_id e video_url são mutuamente exclusivos
        if (!empty($input['video_id'])) unset($input['video_url']);
        $res = $this->http(60)->post($this->baseUrl() . '/v1/videos/lip-sync', ['input' => $input]);
        if (!$res->successful()) throw new RuntimeException('kling_error:' . $res->status() . ':' . substr($res->body(), 0, 240));
        return $res->json();
    }

    /** GET task by id. type = image2video (default) | text2video | lip-sync. */
    public function getVideoTask(string $taskId, string $type = 'image2video'): array
    {
        $paths = [
            'image2video' => '/v1/videos/image2video/',
            'text2video'  => '/v1/videos/text2video/',
            'lip-sync'    => '/v1/videos/lip-sync/',
        ];
        $path = $paths[$type] ?? $paths['image2video'];
        $res = $this->http(20)->get($this->baseUrl() . $path . $taskId);
        if (!$res->successful()) throw new RuntimeException('kling_error:' . $res->status());
        return $res->json();
    }

    /**
     * SEL-329: consulta uso/saldo da conta Kling (últimos N dias).
     * Endpoint documentado /account/costs — retorna array de custos por período.
     */
    public function accountCosts(?int $startMs = null, ?int $endMs = null): array
    {
        if (!$this->isConfigured()) throw new RuntimeException('kling_not_configured');
        $now = time();
        $start = $startMs ?? (($now - 86400 * 30) * 1000);
        $end   = $endMs ?? ($now * 1000);
        $res = $this->http(15)->get($this->baseUrl() . '/account/costs', [
            'start_time' => (int) $start,
            'end_time'   => (int) $end,
        ]);
        if (!$res->successful()) {
            throw new RuntimeException('kling_error:' . $res->status() . ':' . substr($res->body(), 0, 200));
        }
        return $res->json();
    }


    /**
     * SEL-360 — MULTI IMAGE TO VIDEO (Kling v1-6 anti-slideshow).
     * Endpoint: POST /v1/videos/multi-image2video
     * Payload key: image_list (array of {image: url|base64})
     */
    public function multiImageToVideo(array $payload): array
    {
        $body = array_filter([
            'model_name'   => $payload['model_name'] ?? 'kling-v1-6',
            'mode'         => $payload['mode'] ?? 'std',
            'duration'     => (string) ($payload['duration'] ?? 5),
            'aspect_ratio' => $payload['aspect_ratio'] ?? '9:16',
            'image_list'   => $payload['image_list'] ?? null,
            'prompt'       => isset($payload['prompt']) ? mb_substr($payload['prompt'], 0, 2500) : null,
            'cfg_scale'    => $payload['cfg_scale'] ?? 0.5,
        ], fn($v) => $v !== null);

        $res = $this->http(60)->post($this->baseUrl() . '/v1/videos/multi-image2video', $body);
        if (!$res->successful()) {
            throw new RuntimeException('kling_multi_image_error:' . $res->status() . ':' . substr($res->body(), 0, 240));
        }
        return $res->json();
    }

    /**
     * SEL-360 — Alias de getVideoTask pra compatibilidade com StudioGenerationJob.
     */
    public function getVideoStatus(string $taskId, string $type = 'image2video'): array
    {
        return $this->getVideoTask($taskId, $type);
    }

    /**
     * SEL-360 Fase 2 -- FACE SWAP.
     * Moderacao anti-celebridade ANTES de chamar.
     */
    public function faceSwap(array $payload): array
    {
        $body = array_filter([
            "face_reference" => $payload["face_reference"] ?? null,
            "face_target"    => $payload["face_target"] ?? null,
            "target_type"    => $payload["target_type"] ?? "video",
        ], fn($v) => $v !== null);
        $res = $this->http(60)->post($this->baseUrl() . "/v1/images/face-swap", $body);
        if (!$res->successful()) throw new \RuntimeException("kling_face_swap_error:" . $res->status() . ":" . substr($res->body(), 0, 240));
        return $res->json();
    }

    /** SEL-360 Fase 2 -- VIDEO EXTEND (+5s). */
    public function videoExtend(array $payload): array
    {
        $body = array_filter([
            "video_id"  => $payload["video_id"] ?? null,
            "video_url" => $payload["video_url"] ?? null,
            "prompt"    => isset($payload["prompt"]) ? mb_substr($payload["prompt"], 0, 2500) : null,
            "cfg_scale" => $payload["cfg_scale"] ?? 0.5,
        ], fn($v) => $v !== null);
        if (!empty($body["video_id"])) unset($body["video_url"]);
        $res = $this->http(60)->post($this->baseUrl() . "/v1/videos/video-extend", $body);
        if (!$res->successful()) throw new \RuntimeException("kling_video_extend_error:" . $res->status() . ":" . substr($res->body(), 0, 240));
        return $res->json();
    }

    /** GET task de imagem (face-swap / virtual-try-on polling). */
    public function getImageTask(string $taskId, string $type = "face-swap"): array
    {
        $paths = ["face-swap" => "/v1/images/face-swap/", "kolors-virtual-try-on" => "/v1/images/kolors-virtual-try-on/"];
        $res   = $this->http(20)->get($this->baseUrl() . ($paths[$type] ?? $paths["face-swap"]) . $taskId);
        if (!$res->successful()) throw new \RuntimeException("kling_error:" . $res->status());
        return $res->json();
    }

    /** GET task de video-extend. */
    public function getVideoExtendTask(string $taskId): array
    {
        $res = $this->http(20)->get($this->baseUrl() . "/v1/videos/video-extend/" . $taskId);
        if (!$res->successful()) throw new \RuntimeException("kling_error:" . $res->status());
        return $res->json();
    }

}
