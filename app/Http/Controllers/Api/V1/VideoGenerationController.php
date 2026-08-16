<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Ai\PromptMasterService;
use App\Services\AiWalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SEL-033: geracao de video de produto via Seedance 2.0 (ByteDance / BytePlus ModelArk).
 * Proxy fino: o frontend cria a task e faz polling; nada persiste aqui.
 * Config: ARK_API_KEY no .env (console BytePlus > ModelArk > API Keys). Sem key => 503.
 * Docs: https://docs.byteplus.com/en/docs/ModelArk/1520757
 */
class VideoGenerationController extends Controller
{
    // MUL-214 pos: le settings table como fallback (admin pode configurar via UI sem tocar .env)
    private function cfg(): array
    {
        $dbKey = null;
        try {
            $val = \DB::table('settings')->where('key', 'ark_api_key')->value('value');
            if ($val) { $dbKey = decrypt($val); }
        } catch (\Throwable $e) { $dbKey = null; }
        return [
            'key' => $dbKey ?: config('services.ark.api_key'),
            'endpoint' => rtrim(config('services.ark.endpoint') ?: 'https://ark.ap-southeast.bytepluses.com/api/v3', '/'),
            'model' => config('services.ark.video_model') ?: 'seedance-1-0-pro-fast-251015',
        ];
    }

    public function status()
    {
        $cfg = $this->cfg();

        return response()->json([
            'configured' => (bool) $cfg['key'],
            'provider' => 'byteplus-seedance',
            'model' => $cfg['model'],
        ]);
    }

    public function generate(Request $request)
    {
        $data = $request->validate([
            'prompt' => 'required|string|max:2000',
            'image_url' => 'nullable|url|max:2000',
            'ratio' => 'nullable|string|in:16:9,9:16,1:1,4:3,3:4,21:9',
            'resolution' => 'nullable|string|in:480p,720p,1080p',
            'duration' => 'nullable|integer|min:4|max:15',
            'mode' => 'nullable|string|in:animate,text2video,avatar_swap,scene',
        ]);

        $cfg = $this->cfg();
        if (! $cfg['key']) {
            return response()->json([
                'configured' => false,
                'message' => 'Geracao de video ainda nao conectada. Configure ARK_API_KEY (BytePlus ModelArk) e adicione creditos.',
            ], 503);
        }

        // SEL-249 Ruan 18/07: cost gate — checar wallet antes de queimar credito provider
        $user = $request->user();
        $client = $user?->client;
        if ($client) {
            $cost = $this->estimateCost($data);
            $balance = AiWalletService::getBalance($client->id);
            $planSlug = strtolower((string)($client->plan_slug ?? $client->plan ?? ''));
            $freeOnPlan = in_array($planSlug, ['drop_top', 'drop_meio', 'pro'], true);
            if (!$freeOnPlan && $balance < $cost) {
                return response()->json([
                    'error' => 'insufficient_credits',
                    'message' => 'Saldo insuficiente. Adicione crédito ou faça upgrade pra Pro.',
                    'balance' => $balance,
                    'cost' => $cost,
                ], 402);
            }
        }

        // SEL-249 Ruan 18/07: enhance com PromptMaster (video_kling). Antes ia RAW pro provider.
        $master = app(PromptMasterService::class);
        $mode = $data['mode'] ?? (!empty($data['image_url']) ? 'animate' : 'text2video');
        $enhancedPrompt = $master->enhance($data['prompt'], 'video_kling');
        Log::info('[SEL-249] video generate', [
            'mode' => $mode,
            'client_id' => $client?->id,
            'original_len' => strlen($data['prompt']),
            'enhanced_len' => strlen($enhancedPrompt),
        ]);

        $content = [['type' => 'text', 'text' => $enhancedPrompt]];
        if (! empty($data['image_url'])) {
            $content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => $data['image_url']],
                'role' => 'first_frame',
            ];
        }

        try {
            $resp = Http::withToken($cfg['key'])->timeout(30)->post(
                $cfg['endpoint'].'/contents/generations/tasks',
                [
                    'model' => $cfg['model'],
                    'content' => $content,
                    'ratio' => $data['ratio'] ?? '9:16',
                    'resolution' => $data['resolution'] ?? '720p',
                    'duration' => (int) ($data['duration'] ?? 5),
                    'watermark' => false,
                ]
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Falha ao contatar o provedor de video: '.$e->getMessage()], 502);
        }

        if (! $resp->successful()) {
            return response()->json([
                'message' => 'Provedor recusou a criacao da task de video',
                'provider_error' => $resp->json() ?? $resp->body(),
            ], 502);
        }

        // SEL-249: debita wallet somente se provider aceitou
        if ($client && isset($cost)) {
            $taskId = $resp->json('id') ?? $resp->json('task_id') ?? null;
            AiWalletService::debit($client->id, $cost, 'video_gen', $taskId, "mode={$mode}");
        }

        return response()->json($resp->json());
    }

    /**
     * SEL-249 — custo estimado por vídeo (BRL).
     * Baseado em duração x resolução. Refinar quando bater na conta Kling real.
     */
    private function estimateCost(array $data): float
    {
        $duration = (int) ($data['duration'] ?? 5);
        $resolution = $data['resolution'] ?? '720p';
        $per_sec = match ($resolution) {
            '480p' => 1.20,
            '720p' => 2.00,
            '1080p' => 4.00,
            default => 2.00,
        };
        return round($per_sec * $duration, 2);
    }

    public function task(Request $request, string $id)
    {
        $cfg = $this->cfg();
        if (! $cfg['key']) {
            return response()->json(['configured' => false], 503);
        }
        if (! preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $id)) {
            return response()->json(['message' => 'ID de task invalido'], 422);
        }

        try {
            $resp = Http::withToken($cfg['key'])->timeout(20)->get(
                $cfg['endpoint'].'/contents/generations/tasks/'.$id
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Falha ao consultar o provedor: '.$e->getMessage()], 502);
        }

        return response()->json($resp->json() ?? [], $resp->status());
    }
}
