<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * SEL-040: Seedance 2.0 (ByteDance/BytePlus ModelArk) — video img2vid + txt2vid.
 * Assincrono: POST create task -> GET poll -> content.video_url (MP4 24h).
 */
class SeedanceService
{
    // SEL-073: key pode vir da settings table (admin configura via UI) e o modelo
    // default vem do grupo video_billing (default = mais barato do catalogo).
    private function cfg(): array
    {
        $dbKey = null;
        $dbModel = null;
        try {
            $val = \DB::table('settings')->where('key', 'ark_api_key')->value('value');
            if ($val) { $dbKey = decrypt($val); }
        } catch (\Throwable $e) { $dbKey = null; }
        try {
            $dbModel = \DB::table('settings')->where('group', 'video_billing')->where('key', 'default_model')->value('value');
        } catch (\Throwable $e) { $dbModel = null; }

        return [
            'key' => $dbKey ?: config('services.ark.api_key'),
            'endpoint' => rtrim(config('services.ark.endpoint') ?: 'https://ark.ap-southeast.bytepluses.com/api/v3', '/'),
            'model' => $dbModel ?: (config('services.ark.video_model') ?: SeedanceCatalog::DEFAULT_MODEL),
        ];
    }

    public function isConfigured(): bool
    {
        return !empty($this->cfg()['key']);
    }

    public function providerModel(): string
    {
        return $this->cfg()['model'];
    }

    private function http(): PendingRequest
    {
        $c = $this->cfg();
        return Http::withToken($c['key'])
            ->timeout(30)
            ->withHeaders(['Accept' => 'application/json']);
    }

    /**
     * Cria a task. $content = array de blocos (text + image_url first_frame).
     * Retorna id da task provedor.
     */
    public function createTask(array $content, array $opts = []): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('seedance_not_configured');
        }
        $c = $this->cfg();
        $body = [
            'model' => $opts['model'] ?? $c['model'],
            'content' => $content,
            'ratio' => $opts['ratio'] ?? '9:16',
            'resolution' => $opts['resolution'] ?? '720p',
            'duration' => (int) ($opts['duration'] ?? 5),
            'watermark' => false,
        ];
        $res = $this->http()->post($c['endpoint'] . '/contents/generations/tasks', $body);
        if (!$res->successful()) {
            throw new RuntimeException('seedance_error:' . $res->status() . ':' . substr($res->body(), 0, 200));
        }
        return $res->json();
    }

    public function getTask(string $id): array
    {
        $c = $this->cfg();
        $res = $this->http()->get($c['endpoint'] . '/contents/generations/tasks/' . $id);
        if (!$res->successful()) {
            throw new RuntimeException('seedance_error:' . $res->status() . ':' . substr($res->body(), 0, 200));
        }
        return $res->json();
    }
}
